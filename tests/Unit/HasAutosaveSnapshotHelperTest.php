<?php

use Lenorix\FilamentAutosave\Tests\Fixtures\FakeEditPage;

test('the snapshot helper matches the hash seeded at mount time', function () {
    $page = new FakeEditPage(['name' => 'John']);
    $page->mountHasAutosave();

    $hash = (fn () => $this->currentAutosaveSnapshotHash())->call($page);

    expect($hash)->toBe($page->autosaveSnapshotHash)
        ->and($hash)->not->toBe('');
});

test('the snapshot helper changes when the form state changes', function () {
    $page = new FakeEditPage(['name' => 'John']);
    $page->mountHasAutosave();

    $before = (fn () => $this->currentAutosaveSnapshotHash())->call($page);
    $page->form->setState(['name' => 'Jane']);

    $after = (fn () => $this->currentAutosaveSnapshotHash())->call($page);

    expect($after)->not->toBe($before);
});

test('the snapshot helper pays no attention to field order', function () {
    $a = new FakeEditPage(['name' => 'John', 'email' => 'john@example.com']);
    $b = new FakeEditPage(['email' => 'john@example.com', 'name' => 'John']);

    $hashA = (fn () => $this->currentAutosaveSnapshotHash())->call($a);
    $hashB = (fn () => $this->currentAutosaveSnapshotHash())->call($b);

    expect($hashA)->toBe($hashB);
});

test('the snapshot helper stays stable when nothing in the form changes', function () {
    $page = new FakeEditPage(['name' => 'John']);
    $page->mountHasAutosave();

    $first = (fn () => $this->currentAutosaveSnapshotHash())->call($page);
    $second = (fn () => $this->currentAutosaveSnapshotHash())->call($page);

    expect($first)->toBe($second);
});
