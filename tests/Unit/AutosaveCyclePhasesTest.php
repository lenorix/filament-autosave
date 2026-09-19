<?php

use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Log;
use Lenorix\FilamentAutosave\Tests\Fixtures\FakeEditPage;

/**
 * The cycle handles a failing phase in exactly one place. A second handler
 * (as the old inner catch would have been, had it ever been reachable)
 * would discard staged uploads twice and report two statuses.
 */
function makeCountingPage(?Throwable $throwFromHook = null): FakeEditPage
{
    return new class(['title' => 'Original'], ['title' => 'Original'], $throwFromHook) extends FakeEditPage
    {
        public int $discards = 0;

        public function __construct(array $formState, array $dbState, private readonly ?Throwable $throwFromHook)
        {
            parent::__construct($formState, $dbState);
        }

        protected function beforeAutosave(array $data): array
        {
            if ($this->throwFromHook !== null) {
                throw $this->throwFromHook;
            }

            return $data;
        }

        protected function discardAutosaveStoredUploads(): void
        {
            $this->discards++;
            parent::discardAutosaveStoredUploads();
        }

        public function statuses(): array
        {
            return array_map(fn (array $event): string => $event['params']['status'], $this->dispatched);
        }
    };
}

test('a phase throwing is handled once: one discard, one error status', function () {
    Log::shouldReceive('warning')->once();
    $page = makeCountingPage(new RuntimeException('hook failed'));
    $page->mountHasAutosave();
    $page->form->setState(['title' => 'Changed']);

    $page->autosave();

    expect($page->discards)->toBe(1)
        ->and($page->statuses())->toBe(['error'])
        ->and($page->updates)->toBe([]);
});

test('a Halt from a phase is handled once and ends idle', function () {
    $page = makeCountingPage(new Halt);
    $page->mountHasAutosave();
    $page->form->setState(['title' => 'Changed']);

    $page->autosave();

    expect($page->discards)->toBe(1)
        ->and($page->statuses())->toBe(['idle']);
});

test('the phases run in order and a successful cycle reports saved exactly once', function () {
    $page = makeCountingPage();
    $page->mountHasAutosave();
    $page->form->setState(['title' => 'Changed']);

    $page->autosave();

    expect($page->discards)->toBe(0)
        ->and($page->statuses())->toBe(['saved'])
        ->and($page->updates)->toBe([['title' => 'Changed']]);
});
