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
