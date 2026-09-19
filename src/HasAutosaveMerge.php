<?php

namespace Lenorix\FilamentAutosave;

use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Lenorix\FilamentAutosave\Events\AutosaveConflict;

/**
 * Word-level merging of plain-text fields two editors change at once.
 *
 * The server keeps no base. The browser sends, per dirty mergeable field, a
 * diff-match-patch patch of its own change (or the base it started from);
 * the server plays it on the column's current value and writes the result
 * with a compare-and-swap on that column alone, re-reading and re-merging
 * when someone else committed in between. Fields without a patch keep the
 * last-write-wins behaviour every other field has.
 *
 * @internal
 */
trait HasAutosaveMerge
{
    /** @var array<string, string|array{base: string, ours: string}> Patches the browser sent with this cycle, by path. */
    protected array $autosaveCycleMergePatches = [];

    /** @var array<string, string> Fields whose merged value differs from what the browser sent (stored, or computed against the latest value when contended). */
    protected array $autosaveMergedValues = [];

    /** @var array<string, list<array{ours: string, theirs: string, position: int, reason: string}>> */
    protected array $autosaveMergeConflicts = [];

    /** @var array<string, array{theirs: string, hash: string}> Current value of every column left unwritten. */
    protected array $autosaveContendedValues = [];

    protected bool $autosaveMergeWarned = false;

    /**
     * Top-level plain-text fields this page merges word by word; null uses
     * the plugin, then the config.
     *
     * @return array<int, string>|null
     *
     * @api
     */
    protected function autosaveMergeFields(): ?array
    {
        return null;
    }

    /**
     * Resolved list of mergeable fields (page, then plugin, then config).
     *
     * @return array<int, string>
     *
     * @api
     */
    public function getAutosaveMergeFields(): array
    {
        $fields = $this->autosaveMergeFields() ?? AutosavePlugin::resolve()->getMergeFields();

        return array_values(array_unique(array_map(strval(...), $fields)));
    }

    /**
     * Mergeable fields that really are plain text in this form. Anything
     * else listed is ignored, once per request, with a warning.
     *
     * @return array<string, true>
     */
    protected function autosaveMergeablePaths(): array
    {
        $fields = $this->getAutosaveFields();
        $paths = [];
        $ignored = [];

        foreach ($this->getAutosaveMergeFields() as $path) {
            $components = $fields[$path] ?? [];
            $text = $components !== [] && ! str_contains($path, '.') && ! str_contains($path, '*');

            foreach ($components as $component) {
                if (! $component instanceof TextInput && ! $component instanceof Textarea && ! $component instanceof MarkdownEditor) {
                    $text = false;
                }
            }

            $text ? $paths[$path] = true : $ignored[] = $path;
        }

        if ($ignored !== [] && ! $this->autosaveMergeWarned) {
            $this->autosaveMergeWarned = true;
            Log::warning('Autosave merge ignores '.implode(', ', $ignored).': not top-level plain text fields (TextInput, Textarea, MarkdownEditor); they stay last-write-wins.', [
                'component' => static::class,
            ]);
        }

        return $paths;
    }

    /**
     * Keep the patches a request sent along with its autosave call.
     *
     * @param  array<mixed>  $patches
     */
    protected function acceptAutosaveMergePatches(array $patches): void
    {
        $this->autosaveCycleMergePatches = [];

        foreach ($patches as $path => $patch) {
            if (is_string($patch)) {
                $this->autosaveCycleMergePatches[(string) $path] = $patch;
            } elseif (is_array($patch) && is_string($patch['base'] ?? null) && is_string($patch['ours'] ?? null)) {
                $this->autosaveCycleMergePatches[(string) $path] = ['base' => $patch['base'], 'ours' => $patch['ours']];
            }
        }
    }

    protected function resetAutosaveMergeReport(): void
    {
        $this->autosaveMergedValues = [];
        $this->autosaveMergeConflicts = [];
        $this->autosaveContendedValues = [];
    }

    /**
     * Pull the mergeable columns this cycle has a patch for out of the
     * payload Eloquent writes; they go through the conditional write.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> path => the browser's value
     */
    protected function extractAutosaveMergeColumns(array &$data): array
    {
        if ($this->autosaveCycleMergePatches === []) {
            return [];
        }

        $columns = [];

        foreach (array_keys($this->autosaveMergeablePaths()) as $path) {
            if (array_key_exists($path, $data) && array_key_exists($path, $this->autosaveCycleMergePatches)) {
                $columns[$path] = $data[$path];
                unset($data[$path]);
            }
        }

        return $columns;
    }

    /**
     * Merge and write each column with a compare-and-swap against the value
     * it was merged on, retrying on a new value when another editor got
     * there first. A column still contended after every retry is left as
     * it is and reported; the user's text stays in the form for the next
     * cycle.
     *
     * @param  array<string, mixed>  $columns  path => the browser's value
     * @return array{written: array<string, string>, previous: array<string, mixed>}
     */
    protected function writeAutosaveMergeColumns(Model $record, array $columns): array
    {
        $engine = new AutosaveTextMerge;
        $retries = max(0, (int) config('filament-autosave.merge_retries', 10));
        $written = [];
        $previous = [];

        foreach ($columns as $path => $ours) {
            $ours = is_scalar($ours) ? (string) $ours : '';
            $patch = $this->autosaveCycleMergePatches[$path];
            $theirs = $record->getAttributes()[$path] ?? null;
            $attempts = 0;

            while (true) {
                $attempts++;
                $theirsText = is_scalar($theirs) ? (string) $theirs : '';
                [$merged, $conflicts] = $this->mergeAutosaveColumn($engine, $patch, $ours, $theirsText);

                if ($merged === $theirsText || $this->autosaveCompareAndSwap($record, $path, $theirs, $merged)) {
                    $written[$path] = $merged;
                    $previous[$path] = $theirs;

                    if ($merged !== $ours) {
                        $this->autosaveMergedValues[$path] = $merged;
                    }

                    foreach ($conflicts as $conflict) {
                        $this->autosaveMergeConflicts[$path][] = [...$conflict, 'reason' => AutosaveSync::OVERLAP];
                    }

                    break;
                }

                $theirs = $record->newQueryWithoutScopes()->whereKey($record->getKey())->value($path);

                if ($attempts > $retries) {
                    // Hand the browser the merge against the latest value so
                    // it can adopt it and rebase; its next patch then starts
                    // from a current state with a fresh set of attempts.
                    $theirsText = is_scalar($theirs) ? (string) $theirs : '';
                    [$this->autosaveMergedValues[$path]] = $this->mergeAutosaveColumn($engine, $patch, $ours, $theirsText);
                    $this->autosaveMergeConflicts[$path][] = ['ours' => $ours, 'theirs' => $theirsText, 'position' => 0, 'reason' => AutosaveSync::CONTENDED];
                    $this->autosaveContendedValues[$path] = ['theirs' => $theirsText, 'hash' => AutosaveSync::hash($theirsText)];
                    Log::warning("Autosave left {$path} unwritten: still contended after {$attempts} attempts.", ['component' => static::class]);

                    break;
                }

                $this->autosaveMergeBackoff($attempts);
            }
        }

        return ['written' => $written, 'previous' => $previous];
    }

    /**
     * @param  string|array{base: string, ours: string}  $patch
     * @return array{0: string, 1: list<array{ours: string, theirs: string, position: int}>}
     */
    protected function mergeAutosaveColumn(AutosaveTextMerge $engine, string|array $patch, string $ours, string $theirs): array
    {
        try {
            $result = is_array($patch)
                ? $engine->merge($patch['base'], $ours, $theirs)
                : $engine->apply($theirs, $patch);
        } catch (InvalidArgumentException) {
            // A patch the engine cannot read is no patch: last write wins.
            return [$ours, []];
        }

        return [$result->value, $result->conflicts];
    }

    /**
     * Write one column only if it still holds the value it was merged on.
     * Comparing that column alone, not a row version, means a concurrent
     * write to another column never forces a retry.
     */
    protected function autosaveCompareAndSwap(Model $record, string $column, mixed $expected, string $value): bool
    {
        $query = $record->newQueryWithoutScopes()->whereKey($record->getKey());

        $expected === null ? $query->whereNull($column) : $query->where($column, '=', $expected);

        return $query->update([$column => $value]) > 0;
    }

    /**
     * Give the competing write time to finish before re-reading: 5 ms,
     * doubling up to 100 ms per wait (655 ms in total over ten retries).
     */
    protected function autosaveMergeBackoff(int $attempt): void
    {
        usleep($this->autosaveMergeBackoffMilliseconds($attempt) * 1000);
    }

    protected function autosaveMergeBackoffMilliseconds(int $attempt): int
    {
        return min(100, 5 * (2 ** max(0, $attempt - 1)));
    }

    /** @return list<string> */
    protected function autosaveContendedPaths(): array
    {
        return array_keys($this->autosaveContendedValues);
    }

    /**
     * Refill fields whose stored value differs from what the browser sent,
     * then acknowledge the stored value so the next cycle has nothing to
     * write for them. A contended field was not written: the user's text
     * stays in the form, dirty.
     */
    protected function refreshAutosaveMergedFields(object $record): void
    {
        $paths = array_keys(array_diff_key($this->autosaveMergedValues, $this->autosaveContendedValues));

        if ($paths === [] || ! $this->autosaveCanRefillFromRecord()) {
            return;
        }

        $this->refillAutosavePaths($record, $paths, false);
    }

    /** @return array<string, mixed> The extra keys a saved status carries. */
    protected function autosaveMergeReport(): array
    {
        return AutosaveSync::savedPayload($this->autosaveMergedValues, $this->autosaveMergeConflicts, $this->autosaveContendedValues);
    }

    /** Tell listeners which fields stayed contended through every retry. */
    protected function dispatchAutosaveContended(): void
    {
        $contended = array_intersect_key($this->autosaveMergeConflicts, $this->autosaveContendedValues);

        if ($contended === []) {
            return;
        }

        Event::dispatch(new AutosaveConflict($this, $this->autosaveEventRecord(), array_map(
            static fn (array $conflicts): array => array_values(array_filter($conflicts, static fn (array $conflict): bool => $conflict['reason'] === AutosaveSync::CONTENDED)),
            $contended,
        )));
    }
}
