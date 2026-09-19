<?php

namespace Lenorix\FilamentAutosave;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Concerns\EvaluatesClosures;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;

class AutosavePlugin implements Plugin
{
    use EvaluatesClosures;

    protected int|Closure|null $debounce = null;

    /** @var array<string> */
    protected array $except = [];

    /** @var array<class-string> */
    protected array $exceptPages = [];

    protected bool|Closure|null $showTimestamp = null;

    protected ?string $indicatorPosition = null;

    protected int|Closure|null $cacheTtl = null;

    protected int|Closure|null $undoTtl = null;

    protected bool $indicatorRendered = false;

    /** @var array<class-string, 'edit'|'create'|null> */
    protected array $modeCache = [];

    public function getId(): string
    {
        return 'filament-autosave';
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        return filament(app(static::class)->getId());
    }

    public static function tryGet(): ?static
    {
        try {
            return static::get();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The plugin governing the current panel, or a bare instance that falls
     * back to the config when no panel is mounted.
     */
    public static function resolve(): static
    {
        return static::tryGet() ?? static::make();
    }

    protected int|Closure|null $pollInterval = null;

    /** Milliseconds between polls for other editors' changes; 0 disables polling. */
    public function pollInterval(int|Closure $milliseconds): static
    {
        $this->pollInterval = $milliseconds;

        return $this;
    }

    public function getPollInterval(): int
    {
        if ($this->pollInterval !== null) {
            return (int) $this->evaluate($this->pollInterval);
        }

        return (int) (config('filament-autosave.poll_interval') ?? self::shippedDefault('poll_interval'));
    }

    protected bool|Closure|null $pollRelationships = null;

    /**
     * Whether polls also refresh clean relationship, upload and media fields
     * (one extra query per poll); `null` defers to the config file.
     */
    public function pollRelationships(bool|Closure|null $condition = true): static
    {
        $this->pollRelationships = $condition;

        return $this;
    }

    public function getPollRelationships(): bool
    {
        if ($this->pollRelationships !== null) {
            return (bool) $this->evaluate($this->pollRelationships);
        }

        return (bool) (config('filament-autosave.poll_relationships') ?? self::shippedDefault('poll_relationships'));
    }

    /** @var array<string>|Closure|null */
    protected array|Closure|null $mergeFields = null;

    /**
     * Top-level plain-text fields merged word by word when two editors change
     * them at once; anything else stays last-write-wins.
     *
     * @param  array<string>|Closure  $fields
     */
    public function mergeFields(array|Closure $fields): static
    {
        $this->mergeFields = $fields;

        return $this;
    }

    /** @return array<string> */
    public function getMergeFields(): array
    {
        if ($this->mergeFields !== null) {
            return array_values((array) $this->evaluate($this->mergeFields));
        }

        return array_values((array) (config('filament-autosave.merge_fields') ?? self::shippedDefault('merge_fields')));
    }

    public function debounce(int|Closure $milliseconds): static
    {
        $this->debounce = $milliseconds;

        return $this;
    }

    /** @param  array<string>  $fields */
    public function except(array $fields): static
    {
        $this->except = $fields;

        return $this;
    }

    /** @param  array<class-string>  $pages */
    public function exceptPages(array $pages): static
    {
        $this->exceptPages = $pages;

        return $this;
    }

    public function showTimestamp(bool|Closure $condition = true): static
    {
        $this->showTimestamp = $condition;

        return $this;
    }

    public function indicatorPosition(string $position): static
    {
        $this->indicatorPosition = $position;

        return $this;
    }

    public function cacheTtl(int|Closure $hours): static
    {
        $this->cacheTtl = $hours;

        return $this;
    }

    public function undoCacheTtl(int|Closure $minutes): static
    {
        $this->undoTtl = $minutes;

        return $this;
    }

    public function getDebounce(): int
    {
        if ($this->debounce !== null) {
            return $this->evaluate($this->debounce);
        }

        return config('filament-autosave.debounce') ?? self::shippedDefault('debounce');
    }

    /** @return array<string> */
    public function getExcept(): array
    {
        return $this->except;
    }

    /** @return array<class-string> */
    public function getExceptPages(): array
    {
        return $this->exceptPages;
    }

    public function shouldShowTimestamp(): bool
    {
        if ($this->showTimestamp !== null) {
            return $this->evaluate($this->showTimestamp);
        }

        return (bool) (config('filament-autosave.show_saved_at') ?? self::shippedDefault('show_saved_at'));
    }

    public function getIndicatorPosition(): string
    {
        if ($this->indicatorPosition !== null) {
            return $this->indicatorPosition;
        }

        return config('filament-autosave.position') ?? self::shippedDefault('position');
    }

    public function getCacheTtl(): int
    {
        if ($this->cacheTtl !== null) {
            return $this->evaluate($this->cacheTtl);
        }

        return config('filament-autosave.draft_ttl') ?? self::shippedDefault('draft_ttl');
    }

    public function getUndoCacheTtl(): int
    {
        if ($this->undoTtl !== null) {
            return $this->evaluate($this->undoTtl);
        }

        return config('filament-autosave.undo_ttl') ?? self::shippedDefault('undo_ttl');
    }

    /**
     * The value the shipped config file gives a key, for a host whose
     * published config lacks it: one source for every default.
     */
    protected static function shippedDefault(string $key): mixed
    {
        static $shipped = null;

        $shipped ??= require __DIR__.'/../config/filament-autosave.php';

        return $shipped[$key] ?? null;
    }

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void
    {
        $hookName = $this->getIndicatorPosition() === 'after'
            ? PanelsRenderHook::PAGE_HEADER_ACTIONS_AFTER
            : PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE;

        FilamentView::registerRenderHook(PanelsRenderHook::PAGE_START, function (): string {
            $this->indicatorRendered = false;

            return '';
        });

        FilamentView::registerRenderHook(
            $hookName,
            fn (array $scopes): string => $this->renderIndicator($scopes),
        );

        // Pages without the standard header still need the controller.
        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_END,
            fn (array $scopes): string => $this->indicatorRendered ? '' : $this->renderIndicator($scopes),
        );
    }

    /** @param  array<mixed>  $scopes */
    protected function renderIndicator(array $scopes): string
    {
        $mode = $this->resolveAutosaveMode($scopes);

        if ($mode === null) {
            return '';
        }

        $this->indicatorRendered = true;

        return (string) new HtmlString(
            view('filament-autosave::autosave-indicator', [
                'debounce' => $this->getDebounce(),
                'showTimestamp' => $this->shouldShowTimestamp(),
                'mode' => $mode,
            ])->render()
        );
    }

    /** @param  array<mixed>  $scopes */
    protected function resolveAutosaveMode(array $scopes): ?string
    {
        foreach ($scopes as $scope) {
            if (! is_string($scope) || ! class_exists($scope)) {
                continue;
            }

            if (in_array($scope, $this->exceptPages, true)) {
                return null;
            }

            $mode = $this->modeCache[$scope]
                ??= $this->detectMode($scope);

            if ($mode !== null) {
                return $mode;
            }
        }

        return null;
    }

    protected function detectMode(string $class): ?string
    {
        $traits = class_uses_recursive($class);

        if (in_array(HasAutosaveForCreate::class, $traits, true)) {
            return 'create';
        }

        if (in_array(HasAutosave::class, $traits, true)) {
            return 'edit';
        }

        if (in_array(HasAutosaveForForm::class, $traits, true)) {
            return 'form';
        }

        return null;
    }
}
