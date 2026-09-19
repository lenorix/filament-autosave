<?php

use Lenorix\FilamentAutosave\Tests\Fixtures\FakeEditPage;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\AutosavePostForm;

test('every trait resolves dirty_only from the same default when the config key is missing', function () {
    // offsetUnset() would leave the key present with null; drop it for real.
    config()->set('filament-autosave', array_diff_key(config('filament-autosave'), ['dirty_only' => true]));
    $expected = (require dirname(__DIR__, 2).'/config/filament-autosave.php')['dirty_only'];

    $edit = (fn (): bool => $this->autosaveDirtyOnly())->call(new FakeEditPage);
    $form = (fn (): bool => $this->autosaveDirtyOnly())->call(new AutosavePostForm);

    expect($edit)->toBe($expected)->and($form)->toBe($expected);
});
