<?php

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Contracts\Auth\Guard;
use Lenorix\FilamentAutosave\AutosavePlugin;
use Lenorix\FilamentAutosave\HasAutosave;
use Lenorix\FilamentAutosave\Tests\Fixtures\FakeCreatePage;
use Lenorix\FilamentAutosave\Tests\Fixtures\FakeEditPage;
use Lenorix\FilamentAutosave\Tests\Fixtures\FakeFormState;
use Lenorix\FilamentAutosave\Tests\Fixtures\FakeRecord;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\PlainRichPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\RichUploadPost;
use Livewire\Features\SupportTesting\Testable;

/**
 * Stand in for a Filament panel so the cache scope can be resolved.
 */
function fakeFilamentPanel(string $guard, int $id): void
{
    $authGuard = Mockery::mock(Guard::class);
    $authGuard->shouldReceive('id')->andReturn($id);

    $panel = Mockery::mock(Panel::class);
    $panel->shouldReceive('auth')->andReturn($authGuard);
    $panel->shouldReceive('getAuthGuard')->andReturn($guard);

    Filament::shouldReceive('getCurrentPanel')->andReturn($panel);
    Filament::shouldReceive('getTenant')->andReturnNull();
}

function autosavePlugin(): AutosavePlugin
{
    return AutosavePlugin::make();
}

/**
 * A page behaviour like as an edit page that logs every autosave write.
 */
function makeEditPage(array $formState = [], array $dbState = []): FakeEditPage
{
    return new FakeEditPage($formState, $dbState);
}

/**
 * A page that behaves like a create page and keeps a cached draft.
 */
function makeCreatePage(array $formState = []): FakeCreatePage
{
    return new FakeCreatePage($formState);
}

/** Records calls to Filament transaction helpers. */
function makeTransactionalEditPage(bool $failWrite = false): object
{
    return new class($failWrite)
    {
        use HasAutosave;

        public FakeFormState $form;

        public ?array $data = [];

        /** @var array<int, array<string, mixed>> */
        public array $dispatched = [];

        /** @var array<int, string> */
        public array $txLog = [];

        /** @var array<int, array<string, mixed>> */
        public array $updates = [];

        public bool $failWrite;

        public function __construct(bool $failWrite)
        {
            $this->failWrite = $failWrite;
            $this->form = new FakeFormState;
        }

        public function dispatch(string $event, ...$params): void
        {
            $this->dispatched[] = ['event' => $event, 'params' => $params];
        }

        public function beginDatabaseTransaction(): void
        {
            $this->txLog[] = 'begin';
        }

        public function commitDatabaseTransaction(): void
        {
            $this->txLog[] = 'commit';
        }

        public function rollBackDatabaseTransaction(): void
        {
            $this->txLog[] = 'rollback';
        }

        public function getRecord(): object
        {
            return new FakeRecord(['title' => 'old']);
        }

        /** @param  array<string, mixed>  $data */
        public function handleRecordUpdate(object $record, array $data): object
        {
            if ($this->failWrite) {
                throw new RuntimeException('write failed');
            }

            $this->updates[] = $data;

            return $record;
        }
    };
}

function lastStatus(object $page): ?string
{
    $last = end($page->dispatched);

    return $last['params']['status'] ?? null;
}

// --- RichEditor merge -------------------------------------------------------

/** @return array<string, mixed> A Tiptap doc of paragraphs (strings) and raw nodes (arrays). */
function richDoc(string|array ...$blocks): array
{
    return ['type' => 'doc', 'content' => array_map(
        static fn (string|array $block): array => is_array($block)
            ? $block
            : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $block]]],
        $blocks,
    )];
}

/** @return array<string, mixed> */
function richImage(string $id, ?string $src = null): array
{
    return ['type' => 'image', 'attrs' => ['id' => $id, 'src' => $src, 'alt' => null]];
}

/** @return list<string> The plain text of each block of a stored doc. */
function richTexts(array|string|null $doc): array
{
    if (! is_array($doc)) {
        return [];
    }

    return array_map(static fn (array $block): string => $block['type'] === 'image'
        ? 'image:'.$block['attrs']['id']
        : implode('', array_map(static fn (array $inline): string => $inline['text'] ?? '', $block['content'] ?? [])),
        $doc['content'] ?? []);
}

/** @return array<string, mixed>|null The payload of the last `autosave-status` event with the given status. */
function lastRichStatus(Testable $page, string $status): ?array
{
    $found = null;

    try {
        $page->assertDispatched('autosave-status', function (string $event, array $params) use (&$found, $status): bool {
            if (($params['status'] ?? null) === $status) {
                $found = $params;
            }

            return true;
        });
    } catch (Throwable) {
        // No status event dispatched at all.
    }

    return $found;
}

/** Change `$column` behind autosave's back right before its conditional write runs, `$times` times (null = every time). */
function contendRichColumn(RichUploadPost|PlainRichPost $post, string $column, callable $value, ?int $times = 1): Closure
{
    $remaining = $times;
    $busy = false;
    $active = true;

    DB::connection()->beforeExecuting(function (string $query) use ($post, $column, $value, &$remaining, &$busy, &$active): void {
        if (! $active || $busy || ! str_contains($query, 'update') || ! str_contains($query, "and \"{$column}\" = ?")) {
            return;
        }

        if ($remaining !== null && $remaining-- <= 0) {
            return;
        }

        $busy = true;
        DB::table($post->getTable())->where('id', $post->getKey())->update([$column => $value()]);
        $busy = false;
    });

    return function () use (&$active): void {
        $active = false;
    };
}

function richPost(array $doc): RichUploadPost
{
    return RichUploadPost::create(['title' => 'Post', 'body' => $doc]);
}
