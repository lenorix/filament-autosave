<?php

namespace Lenorix\FilamentAutosave;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

/**
 * Journals Spatie Media Library files in the upload ledger the moment their
 * `media` row is created.
 *
 * Spatie saves the row first (the path derives from its id) and copies the
 * file afterwards, so a `created` listener puts the path in the ledger before
 * the file reaches disk: a process killed anywhere after that point still
 * leaves a durable trail for pruning, and one killed before it never wrote a
 * file. The listener is registered once at boot, ahead of anything the host
 * registers later, and only acts while a capture is open.
 *
 * @internal
 */
final class AutosaveMediaJournal
{
    /** @var array{model: string, key: string, collection: string, token: string|null}|null */
    private ?array $capture = null;

    private bool $listening = false;

    public function __construct(private readonly AutosaveUploadLedger $ledger) {}

    /** @return class-string<Model>|null */
    public static function mediaModel(): ?string
    {
        $configured = config('media-library.media_model');
        $model = is_string($configured) && class_exists($configured) ? $configured : SpatieMedia::class;

        return class_exists($model) && is_subclass_of($model, Model::class) ? $model : null;
    }

    /** Hook the media model's `created` event; safe to call more than once. */
    public function listen(): void
    {
        if ($this->listening || ($model = self::mediaModel()) === null) {
            return;
        }

        $model::created(fn (object $media) => $this->created($media));
        $this->listening = true;
    }

    /**
     * Run a Spatie save and journal every media row it creates for the given
     * record and collection. Returns the ledger token holding those files,
     * the one passed in when nothing new was created.
     */
    public function capture(Model $record, string $collection, ?string $token, callable $save): ?string
    {
        $this->capture = [
            'model' => $record->getMorphClass(),
            'key' => (string) $record->getKey(),
            'collection' => $collection,
            'token' => $token,
        ];

        try {
            $save();

            return $this->capture['token'];
        } finally {
            $this->capture = null;
        }
    }

    public function created(object $media): void
    {
        if ($this->capture === null || ! method_exists($media, 'getAttribute')) {
            return;
        }

        if ((string) $media->getAttribute('model_type') !== $this->capture['model']
            || (string) $media->getAttribute('model_id') !== $this->capture['key']
            || (string) $media->getAttribute('collection_name') !== $this->capture['collection']) {
            return;
        }

        $files = $this->files($media);

        if ($files === []) {
            return;
        }

        if ($this->capture['token'] === null) {
            $this->capture['token'] = $this->ledger->register($files);

            return;
        }

        $this->ledger->append($this->capture['token'], $files);
    }

    /**
     * Original file plus every conversion, in the ledger's flat shape.
     *
     * @return array<int, array{disk: string, path: string}>
     */
    private function files(object $media): array
    {
        if (! method_exists($media, 'getPathRelativeToRoot') || ! method_exists($media, 'getAttribute')) {
            return [];
        }

        $disk = $media->getAttribute('disk');
        $conversionsDisk = $media->getAttribute('conversions_disk') ?: $disk;
        $files = [];

        if (is_string($disk)) {
            $files[] = ['disk' => $disk, 'path' => $media->getPathRelativeToRoot()];
        }

        if (is_string($conversionsDisk) && method_exists($media, 'getMediaConversionNames')) {
            foreach ($media->getMediaConversionNames() as $conversion) {
                $files[] = ['disk' => $conversionsDisk, 'path' => $media->getPathRelativeToRoot($conversion)];
            }
        }

        return $files;
    }
}
