<?php

namespace Lenorix\FilamentAutosave;

use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Lenorix\FilamentAutosave\Events\AutosaveConflict;
use Throwable;

/**
 * Merging of fields two editors change at once: plain text word by word,
 * RichEditor content block by block.
 *
 * The server keeps no base. The browser sends, per dirty mergeable field, a
 * diff-match-patch patch of its own change (plain text) or the value it
 * started from (rich content, `['base' => …]`); the server merges it with
 * the column's current value and writes the result with a compare-and-swap
 * on that column alone, re-reading and re-merging when someone else
 * committed in between. Fields without a patch keep the last-write-wins
 * behaviour every other field has.
 *
 * A rich field is merged before the form dehydrates, so the RichEditor's
 * own attachment cleanup — which deletes every file the submitted document
 * no longer references — sees the merged document and keeps the images the
 * other editor still uses. The conditional write then merges again against
 * whatever the column holds at that moment.
 *
 * @internal
 */
trait HasAutosaveMerge
{
    /** @var array<string, string|array{base: string|array<string, mixed>, ours: string|array<string, mixed>|null}> Patches the browser sent with this cycle, by path. */
    protected array $autosaveCycleMergePatches = [];

    /** @var array<string, mixed> Fields whose merged value differs from what the browser sent (stored, or computed against the latest value when contended). */
    protected array $autosaveMergedValues = [];

    /** @var array<string, list<array<string, mixed>>> */
    protected array $autosaveMergeConflicts = [];

    /** @var array<string, array{theirs: mixed, hash: string}> Current value of every column left unwritten. */
    protected array $autosaveContendedValues = [];

    /** @var array<string, mixed> Raw column value each rich field was pre-merged on, by path. */
    protected array $autosaveRichMergeTheirs = [];

    /** @var array<string, mixed> What the browser sent for each pre-merged rich field, by path. */
    protected array $autosaveRichMergeBrowserValues = [];

    /** @var array<string, list<array<string, mixed>>> Overlaps the pre-merge resolved, by path. */
    protected array $autosaveRichMergePremergeConflicts = [];

    protected bool $autosaveMergeWarned = false;

    /**
     * Top-level text fields this page merges (plain text word by word,
     * RichEditor block by block); null uses the plugin, then the config.
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
     * Mergeable fields that really are plain text or rich content in this
     * form. Anything else listed is ignored, once per request, with a warning.
     *
     * @return array<string, true>
     */
    protected function autosaveMergeablePaths(): array
    {
        $paths = [];
        $ignored = [];

        foreach ($this->getAutosaveMergeFields() as $path) {
            $this->autosaveMergeComponent($path) !== null ? $paths[$path] = true : $ignored[] = $path;
        }

        if ($ignored !== [] && ! $this->autosaveMergeWarned) {
            $this->autosaveMergeWarned = true;
            Log::warning('Autosave merge ignores '.implode(', ', $ignored).': not top-level text fields (TextInput, Textarea, MarkdownEditor, RichEditor); they stay last-write-wins.', [
                'component' => static::class,
            ]);
        }

        return $paths;
    }

    /**
     * The mergeable component at a top-level path, or null when the path is
     * nested, unknown, or holds a component that cannot be merged.
     */
    protected function autosaveMergeComponent(string $path): TextInput|Textarea|MarkdownEditor|RichEditor|null
    {
        if (str_contains($path, '.') || str_contains($path, '*')) {
            return null;
        }

        $components = $this->getAutosaveFields()[$path] ?? [];
        $found = null;

        foreach ($components as $component) {
            if (! $component instanceof TextInput && ! $component instanceof Textarea
                && ! $component instanceof MarkdownEditor && ! $component instanceof RichEditor) {
                return null;
            }

            $found ??= $component;
        }

        return $found;
    }

    /**
     * Mergeable RichEditor paths this cycle has a base for, by path.
     *
     * @return array<string, RichEditor>
     */
    protected function autosaveRichMergeComponents(): array
    {
        $rich = [];

        foreach ($this->autosaveMergeablePaths() as $path => $_) {
            $component = $this->autosaveMergeComponent($path);

            if ($component instanceof RichEditor && is_array($this->autosaveCycleMergePatches[$path] ?? null)) {
                $rich[$path] = $component;
            }
        }

        return $rich;
    }

    /**
     * Keep the patches a request sent along with its autosave call: a patch
     * string, or the base (and optionally the browser's value) as either a
     * string or a rich document.
     *
     * @param  array<mixed>  $patches
     */
    protected function acceptAutosaveMergePatches(array $patches): void
    {
        $this->autosaveCycleMergePatches = [];
        $this->autosaveRichMergeTheirs = [];
        $this->autosaveRichMergeBrowserValues = [];
        $this->autosaveRichMergePremergeConflicts = [];

        foreach ($patches as $path => $patch) {
            if (is_string($patch)) {
                $this->autosaveCycleMergePatches[(string) $path] = $patch;
            } elseif (is_array($patch) && (is_string($patch['base'] ?? null) || is_array($patch['base'] ?? null))) {
                $ours = $patch['ours'] ?? null;
                $this->autosaveCycleMergePatches[(string) $path] = [
                    'base' => $patch['base'],
                    'ours' => is_string($ours) || is_array($ours) ? $ours : null,
                ];
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
     * Merge every rich field the browser sent a base for with the column's
     * current value, into the form state, before the form dehydrates. Reads
     * those columns in one query so an image the other editor added since
     * this tab loaded is in the document the attachment cleanup keeps.
     */
    protected function premergeAutosaveRichFields(): void
    {
        $components = $this->autosaveRichMergeComponents();
        $record = $this->autosaveSyncRecord();

        if ($components === [] || ! $record instanceof Model || ! $record->exists) {
            return;
        }

        $row = $record->newQueryWithoutScopes()->whereKey($record->getKey())->toBase()->first(array_keys($components));
        $raw = $row === null ? [] : (array) $row;

        foreach ($components as $path => $component) {
            $theirs = $raw[$path] ?? null;
            $ours = $component->getRawState();
            $result = $this->autosaveRichMergeEngine($component)->merge(
                $this->autosaveCycleMergePatches[$path]['base'],
                $this->autosaveRichMergeValue($ours),
                $this->autosaveMergeCastValue($record, $path, $theirs),
            );

            $this->autosaveRichMergeTheirs[$path] = $theirs;
            $this->autosaveRichMergeBrowserValues[$path] = $ours;
            $this->autosaveRichMergePremergeConflicts[$path] = $this->autosaveRichConflictNodes($component, $result->conflicts);
            // The form holds the document; the column format only matters
            // when the form dehydrates.
            $component->rawState($this->autosaveRichDocument($component, $result->value));
        }
    }

    protected function autosaveRichMergeEngine(RichEditor $component): AutosaveRichMerge
    {
        return new AutosaveRichMerge($component->getTipTapEditor(), $component->isJson());
    }

    /**
     * The document the form (and the browser) holds for a column value,
     * whichever format the column stores.
     *
     * @param  string|array<string, mixed>|null  $value
     * @return array<string, mixed>
     */
    protected function autosaveRichDocument(RichEditor $component, string|array|null $value): array
    {
        $document = $component->getTipTapEditor()->setContent($value ?? ['type' => 'doc', 'content' => []])->getDocument();

        return json_decode((string) json_encode($document), true) ?: ['type' => 'doc', 'content' => []];
    }

    /**
     * Conflict fragments as lists of document nodes, the shape the browser
     * can insert, whichever format the column stores.
     *
     * @param  list<array<string, mixed>>  $conflicts
     * @return list<array<string, mixed>>
     */
    protected function autosaveRichConflictNodes(RichEditor $component, array $conflicts): array
    {
        foreach ($conflicts as &$conflict) {
            foreach (['ours', 'theirs'] as $side) {
                $fragment = $conflict[$side] ?? null;

                if (is_string($fragment)) {
                    $conflict[$side] = $fragment === '' ? [] : ($this->autosaveRichDocument($component, $fragment)['content'] ?? []);
                }
            }
        }

        return $conflicts;
    }

    /**
     * @return string|array<string, mixed>|null
     */
    protected function autosaveRichMergeValue(mixed $value): string|array|null
    {
        return is_array($value) || is_string($value) ? $value : null;
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
            if (! array_key_exists($path, $data) || ! array_key_exists($path, $this->autosaveCycleMergePatches)) {
                continue;
            }

            // A rich field merges from a base, never from a text patch.
            if (is_string($this->autosaveCycleMergePatches[$path]) && $this->autosaveMergeComponent($path) instanceof RichEditor) {
                continue;
            }

            $columns[$path] = $data[$path];
            unset($data[$path]);
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
     * @return array{written: array<string, mixed>, previous: array<string, mixed>}
     */
    protected function writeAutosaveMergeColumns(Model $record, array $columns): array
    {
        $retries = max(0, (int) config('filament-autosave.merge_retries', 10));
        $written = [];
        $previous = [];

        foreach ($columns as $path => $ours) {
            $rich = $this->autosaveMergeComponent($path);
            $rich = $rich instanceof RichEditor && is_array($this->autosaveCycleMergePatches[$path]) ? $rich : null;
            $ours = $rich === null ? (is_scalar($ours) ? (string) $ours : '') : $this->autosaveRichMergeValue($ours);
            $sent = $rich === null ? $ours : ($this->autosaveRichMergeBrowserValues[$path] ?? $ours);
            $patch = $this->autosaveCycleMergePatches[$path];
            $premerged = $rich !== null && array_key_exists($path, $this->autosaveRichMergeTheirs);
            $theirs = $premerged ? $this->autosaveRichMergeTheirs[$path] : ($record->getAttributes()[$path] ?? null);
            $attempts = 0;

            // A pre-merged rich document already holds the other editor's
            // changes up to the value it was merged on; from here on that
            // value is the base, and only what landed after it is merged in.
            if ($premerged) {
                $patch = ['base' => $this->autosaveRichMergeValue($this->autosaveMergeCastValue($record, $path, $theirs)), 'ours' => null];
                $stored = $this->autosaveMergeRawValue($record, $path, $ours);
            }

            if ($rich !== null) {
                $sent = $this->autosaveRichMergeEngine($rich)->canonical($this->autosaveRichMergeValue($sent));
            }

            while (true) {
                $attempts++;
                [$merged, $raw, $conflicts] = $premerged && $attempts === 1
                    ? [$this->autosaveMergeCastValue($record, $path, $stored), $stored, []]
                    : $this->mergeAutosaveColumn($record, $path, $rich, $patch, $ours, $theirs);

                if ($raw === $theirs || $this->autosaveCompareAndSwap($record, $path, $theirs, $raw)) {
                    $written[$path] = $merged;
                    $previous[$path] = $rich === null ? $theirs : $this->autosaveMergeCastValue($record, $path, $theirs);

                    if ($merged !== $sent) {
                        $this->autosaveMergedValues[$path] = $rich === null ? $merged : $this->autosaveRichDocument($rich, $merged);
                    }

                    foreach ([...($this->autosaveRichMergePremergeConflicts[$path] ?? []), ...$conflicts] as $conflict) {
                        $this->autosaveMergeConflicts[$path][] = [...$conflict, 'reason' => AutosaveSync::OVERLAP];
                    }

                    break;
                }

                $theirs = $this->autosaveMergeCurrentValue($record, $path);

                if ($attempts > $retries) {
                    // Hand the browser the merge against the latest value so
                    // it can adopt it and rebase; its next patch then starts
                    // from a current state with a fresh set of attempts.
                    [$adopt] = $this->mergeAutosaveColumn($record, $path, $rich, $patch, $ours, $theirs);
                    $this->autosaveMergedValues[$path] = $rich === null ? $adopt : $this->autosaveRichDocument($rich, $adopt);
                    $latest = $rich === null
                        ? (is_scalar($theirs) ? (string) $theirs : '')
                        : $this->autosaveRichDocument($rich, $this->autosaveRichMergeValue($this->autosaveMergeCastValue($record, $path, $theirs)));
                    $this->autosaveMergeConflicts[$path][] = ['ours' => $sent, 'theirs' => $latest, 'position' => 0, 'reason' => AutosaveSync::CONTENDED];
                    $this->autosaveContendedValues[$path] = ['theirs' => $latest, 'hash' => AutosaveSync::hash(is_scalar($theirs) ? (string) $theirs : '')];
                    Log::warning("Autosave left {$path} unwritten: still contended after {$attempts} attempts.", ['component' => static::class]);

                    break;
                }

                $this->autosaveMergeBackoff($attempts);
            }
        }

        return ['written' => $written, 'previous' => $previous];
    }

    /**
     * Merge one column's browser value with the raw value the column holds.
     *
     * @param  string|array{base: string|array<string, mixed>, ours: string|array<string, mixed>|null}  $patch
     * @param  string|array<string, mixed>|null  $ours
     * @return array{0: mixed, 1: string, 2: list<array<string, mixed>>} the merged value, its raw column form, and the conflicts
     */
    protected function mergeAutosaveColumn(Model $record, string $path, ?RichEditor $rich, string|array $patch, string|array|null $ours, mixed $theirs): array
    {
        if ($rich !== null) {
            $result = $this->autosaveRichMergeEngine($rich)->merge(
                is_array($patch) ? $patch['base'] : null,
                $ours,
                $this->autosaveMergeCastValue($record, $path, $theirs),
            );

            return [$result->value, $this->autosaveMergeRawValue($record, $path, $result->value), $this->autosaveRichConflictNodes($rich, $result->conflicts)];
        }

        $ours = is_string($ours) ? $ours : '';
        $theirs = is_scalar($theirs) ? (string) $theirs : '';

        try {
            $result = is_array($patch)
                ? (new AutosaveTextMerge)->merge(is_string($patch['base']) ? $patch['base'] : '', $ours, $theirs)
                : (new AutosaveTextMerge)->apply($theirs, $patch);
        } catch (Throwable $e) {
            // A patch or value the engine cannot read is no patch: last
            // write wins, as for a field that sent none. Never let the
            // engine take the whole cycle down.
            Log::warning("Autosave could not merge {$path}; last write wins.", ['component' => static::class, 'exception' => $e::class, 'message' => $e->getMessage()]);

            return [$ours, $ours, []];
        }

        return [$result->value, $result->value, $result->conflicts];
    }

    /**
     * The value a raw column holds once the model's casts have run — a
     * decoded document for a JSON column, the string itself otherwise.
     */
    protected function autosaveMergeCastValue(Model $record, string $path, mixed $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        return $record->newInstance()->setRawAttributes([$path => $raw])->getAttribute($path);
    }

    /**
     * The raw form a value takes in its column, through the model's own
     * casts, so the conditional write stores exactly what Eloquent would.
     */
    protected function autosaveMergeRawValue(Model $record, string $path, mixed $value): string
    {
        $model = $record->newInstance();
        $model->setAttribute($path, $value);
        $raw = $model->getAttributes()[$path] ?? $value;

        return is_string($raw) ? $raw : (string) json_encode($raw);
    }

    /**
     * Write one column only if it still holds the value it was merged on.
     * Comparing that column alone, not a row version, means a concurrent
     * write to another column never forces a retry.
     */
    /**
     * The value a column holds right now, raw as stored (the model's casts
     * would decode it), for the merge to retry on.
     *
     * A locking read: the cycle runs inside a transaction, and under MySQL's
     * REPEATABLE READ a plain SELECT would return the snapshot of the first
     * read, so every retry would merge the same stale value and end
     * contended. `FOR UPDATE` reads the committed value (and holds the row
     * only until the conditional write that follows).
     */
    protected function autosaveMergeCurrentValue(Model $record, string $column): mixed
    {
        return $record->newQueryWithoutScopes()->whereKey($record->getKey())->toBase()->lockForUpdate()->value($column);
    }

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

    /**
     * The value a poll hands the browser for a stale mergeable column: the
     * cast value for rich content, the string for plain text.
     */
    protected function autosaveMergeRemoteValue(object $record, string $path, mixed $raw): mixed
    {
        if ($record instanceof Model && ($component = $this->autosaveMergeComponent($path)) instanceof RichEditor) {
            return $this->autosaveRichDocument($component, $this->autosaveRichMergeValue($this->autosaveMergeCastValue($record, $path, $raw)));
        }

        return is_scalar($raw) ? (string) $raw : '';
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
