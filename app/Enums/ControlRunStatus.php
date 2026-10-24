<?php

namespace App\Enums;

enum ControlRunStatus: string
{
    case Queued = 'QUEUED';
    case Running = 'RUNNING';
    case Done = 'DONE';
    case Failed = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'En attente',
            self::Running => 'En cours',
            self::Done => 'Terminé',
            self::Failed => 'Échec',
        };
    }
}
