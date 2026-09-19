<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Browser;

use Filament\Forms\Components\RichEditor;
use Filament\Resources\Pages\EditRecord;
use Lenorix\FilamentAutosave\AutosaveRichMerge;
use Lenorix\FilamentAutosave\AutosaveTextMerge;
use Lenorix\FilamentAutosave\HasAutosave;

/**
 * Edit page whose rich `body` is merged structurally.
 *
 * Until the traits route RichEditor fields through {@see AutosaveRichMerge}
 * themselves (stream 2), this fixture stands in for that server side with
 * the same request contract: the browser sends `['base' => <document>]`
 * for the field, the form's own value is what it wrote, and the merge is
 * done in the column's format. Nothing here changes the payload the
 * browser sees.
 */
class BrowserRichMergeEditPost extends EditRecord
{
    use HasAutosave {
        acceptAutosaveMergePatches as acceptTextAutosaveMergePatches;
        mergeAutosaveColumn as mergeTextAutosaveColumn;
    }

    protected static string $resource = BrowserRichMergePostResource::class;

    /** @return array<int, string> */
    protected function autosaveMergeFields(): ?array
    {
        return ['body'];
    }

    /** @return array<string, true> */
    protected function autosaveMergeablePaths(): array
    {
        return ['body' => true];
    }

    /**
     * @param  array<mixed>  $patches
     */
    protected function acceptAutosaveMergePatches(array $patches): void
    {
        $body = $patches['body'] ?? null;

        if (is_array($body) && is_array($body['base'] ?? null)) {
            $patches['body'] = ['base' => (string) json_encode($body['base']), 'ours' => (string) json_encode($this->data['body'] ?? null)];
        }

        $this->acceptTextAutosaveMergePatches($patches);
    }

    /**
     * @param  string|array{base: string, ours: string}  $patch
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    protected function mergeAutosaveColumn(AutosaveTextMerge $engine, string|array $patch, string $ours, string $theirs): array
    {
        $component = $this->getAutosaveFields()['body'][0] ?? null;

        if (! is_array($patch) || ! $component instanceof RichEditor) {
            return $this->mergeTextAutosaveColumn($engine, $patch, $ours, $theirs);
        }

        $rich = new AutosaveRichMerge($component->getTipTapEditor(), $component->isJson());
        $result = $rich->merge($patch['base'], $patch['ours'], $theirs === '' ? null : $theirs);
        $value = is_array($result->value) ? (string) json_encode($result->value) : $result->value;

        return [$value, $result->conflicts];
    }
}
