<?php

namespace Lenorix\FilamentAutosave;

/**
 * Statuses pushed to the browser over AutosaveStatus::EVENT.
 *
 * Each case value doubles as the Lang key (resources/lang/)
 * and the Alpine status string consumed by the controller and indicator.
 */
enum AutosaveStatus: string
{
    /** Event sent with every status update. */
    public const EVENT = 'autosave-status';

    case Idle = 'idle';
    case DraftAvailable = 'draft_available';
    case Unsaved = 'unsaved';
    case Saving = 'saving';
    case Saved = 'saved';
    case Error = 'error';
    case Validation = 'validation';
    case Restored = 'restored';
    case Undone = 'undone';
    case Conflict = 'conflict';

    /**
     * Statuses that leave the form aligned with the server state.
     *
     * @return array<int, string>
     */
    public static function settledStatuses(): array
    {
        return [
            self::Saved->value,
            self::Idle->value,
            self::Restored->value,
            self::Undone->value,
        ];
    }

    /**
     * Statuses a save() request can finish with, where the sent payload wins.
     *
     * @return array<int, string>
     */
    public static function saveResultStatuses(): array
    {
        return [
            self::Saved->value,
            self::Idle->value,
        ];
    }

    /**
     * Settled statuses that vanish by themselves once the user has seen them.
     *
     * @return array<string, int>
     */
    public static function fadeMsByStatus(): array
    {
        return [
            self::Saved->value => 5000,
            self::Restored->value => 3000,
            self::Undone->value => 3000,
        ];
    }

    /**
     * Everything the Alpine controller needs to keep its status logic in PHP.
     *
     * @return array{event: string, idle: string, unsaved: string, saving: string, draftAvailable: string, error: string, conflict: string, validation: string, settled: array<int, string>, saveResults: array<int, string>, fadeMs: array<string, int>}
     */
    public static function statusMeta(): array
    {
        return [
            'event' => self::EVENT,
            'idle' => self::Idle->value,
            'unsaved' => self::Unsaved->value,
            'saving' => self::Saving->value,
            'draftAvailable' => self::DraftAvailable->value,
            'error' => self::Error->value,
            'conflict' => self::Conflict->value,
            'validation' => self::Validation->value,
            'settled' => self::settledStatuses(),
            'saveResults' => self::saveResultStatuses(),
            'fadeMs' => self::fadeMsByStatus(),
        ];
    }
}
