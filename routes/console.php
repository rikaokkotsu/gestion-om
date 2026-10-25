<?php

use Illuminate\Support\Facades\Schedule;

// Sauvegarde quotidienne de la base, puis restauration d'essai de cette sauvegarde (docs/exploitation.md).
Schedule::command('om:sauvegarde')->dailyAt(config('backup.schedule.backup'))->withoutOverlapping();
Schedule::command('om:verifier-sauvegarde')->dailyAt(config('backup.schedule.verify'))->withoutOverlapping();
