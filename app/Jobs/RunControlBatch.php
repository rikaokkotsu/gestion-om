<?php

namespace App\Jobs;

use App\Control\ControlEngine;
use App\Enums\ControlRunStatus;
use App\Models\ControlRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Contrôle d'un lot d'OM, hors de la requête du contrôleur. Les OM déjà
 * contrôlés lors d'une tentative interrompue gardent leurs anomalies : un
 * nouveau contrôle les retrouve sans les dupliquer.
 */
class RunControlBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly int $runId) {}

    public function handle(ControlEngine $engine): void
    {
        $engine->runBatch(ControlRun::query()->findOrFail($this->runId));
    }

    public function failed(?Throwable $exception): void
    {
        ControlRun::query()->whereKey($this->runId)->update([
            'status' => ControlRunStatus::Failed,
            'error' => $exception?->getMessage(),
            'finished_at' => now(),
        ]);
    }
}
