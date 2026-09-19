<?php

use Illuminate\Support\Facades\Log;
use Lenorix\FilamentAutosave\HasAutosaveBase;

test('a failed autosave operation logs the exception class and dispatches an error', function (string $context, string $expectedMessage) {
    Log::spy();

    $page = new class
    {
        use HasAutosaveBase;

        public ?array $data = [];

        public array $dispatched = [];

        public function dispatch(string $event, ...$params): void
        {
            $this->dispatched[] = ['event' => $event, 'params' => $params];
        }
    };

    (fn ($e, $c) => $this->handleAutosaveFailure($e, $c))
        ->call($page, new RuntimeException('db down'), $context);

    expect($page->dispatched[0]['params'])->toHaveKey('status', 'error');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => $message === $expectedMessage)
        ->once();
})->with([
    ['save', 'Autosave save failed'],
    ['undo', 'Autosave undo failed'],
    ['restore', 'Autosave restore failed'],
]);

test('the error status is scoped to the component like every other status', function () {
    Log::spy();

    $page = new class
    {
        use HasAutosaveBase;

        public ?array $data = [];

        public array $scoped = [];

        public function dispatch(string $event, ...$params): object
        {
            $scoped = &$this->scoped;

            return new class($event, $scoped)
            {
                public function __construct(private string $event, private array &$scoped) {}

                public function self(): void
                {
                    $this->scoped[] = $this->event;
                }
            };
        }
    };

    (fn ($e) => $this->handleAutosaveFailure($e, 'save'))
        ->call($page, new RuntimeException('db down'));

    // A plain dispatch from a component nested in a page never reaches the
    // indicator's $wire.$on(), which then sticks at "saving".
    expect($page->scoped)->toBe(['autosave-status']);
});

test('a disabled page answers an autosave call with idle instead of silence', function () {
    $page = new class
    {
        use HasAutosaveBase;

        public ?array $data = [];

        public array $dispatched = [];

        public function dispatch(string $event, ...$params): void
        {
            $this->dispatched[] = ['event' => $event, 'params' => $params];
        }

        protected function shouldAutosave(): bool
        {
            return false;
        }
    };

    (fn () => $this->performAutosave(fn () => null))->call($page);

    expect($page->dispatched)->toHaveCount(1)
        ->and($page->dispatched[0]['params'])->toHaveKey('status', 'idle');
});

test('failed operations never pass the exception message to the log', function () {
    Log::spy();

    $page = new class
    {
        use HasAutosaveBase;

        public ?array $data = [];

        public function dispatch(string $event, ...$params): void {}
    };

    (fn ($e) => $this->handleAutosaveFailure($e, 'undo'))
        ->call($page, new RuntimeException('SELECT 1=1 secret-data'));

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context = []) => ! str_contains($message.json_encode($context), 'secret-data'));
});
