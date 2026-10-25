<?php

namespace App\Console\Commands;

use App\Backup\DatabaseBackup;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class BackupDatabase extends Command
{
    protected $signature = 'om:sauvegarde';

    protected $description = 'Sauvegarde la base PostgreSQL et supprime les sauvegardes trop anciennes';

    public function handle(): int
    {
        $backup = DatabaseBackup::fromConfig();

        try {
            $manifest = $backup->create();
        } catch (Throwable $exception) {
            Log::error('Sauvegarde de la base impossible.', ['exception' => $exception->getMessage()]);
            Audit::record('backup.failed', null, [], ['error' => $exception->getMessage()]);
            $this->error('Sauvegarde impossible : '.$exception->getMessage());

            return self::FAILURE;
        }

        $deleted = $backup->prune((int) config('backup.keep_days'));
        $rows = array_sum($manifest['tables']);
        Audit::record('backup.created', null, [], [
            'file' => $manifest['file'],
            'size' => $manifest['size'],
            'rows' => $rows,
            'deleted' => count($deleted),
        ]);

        $this->info(sprintf('Sauvegarde créée : %s (%s Ko, %d tables, %d lignes).', $manifest['path'], number_format($manifest['size'] / 1024, 0, ',', ' '), count($manifest['tables']), $rows));
        if ($deleted !== []) {
            $this->line(count($deleted).' sauvegarde(s) de plus de '.config('backup.keep_days').' jours supprimée(s).');
        }

        return self::SUCCESS;
    }
}
