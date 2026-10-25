<?php

namespace App\Console\Commands;

use App\Backup\DatabaseBackup;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class VerifyBackup extends Command
{
    protected $signature = 'om:verifier-sauvegarde {fichier? : Sauvegarde à vérifier ; la plus récente par défaut}';

    protected $description = 'Restaure une sauvegarde dans une base temporaire et vérifie qu\'elle est complète';

    public function handle(): int
    {
        $backup = DatabaseBackup::fromConfig();
        $file = $this->argument('fichier') ?? $backup->files()[0] ?? null;

        if (! is_string($file)) {
            return $this->reportFailure('Aucune sauvegarde à vérifier dans '.$backup->directory().'.');
        }

        try {
            $result = $backup->verify($file);
        } catch (Throwable $exception) {
            return $this->reportFailure('Vérification impossible de '.basename($file).' : '.$exception->getMessage(), $file);
        }

        if (! $result['ok']) {
            foreach ($result['problems'] as $problem) {
                $this->line("  - {$problem}");
            }

            return $this->reportFailure('La sauvegarde '.basename($file).' ne se restaure pas à l\'identique.', $file, $result['problems']);
        }

        Audit::record('backup.verified', null, [], ['file' => basename($file), 'tables' => $result['tables'], 'rows' => $result['rows']]);
        $this->info(sprintf('Sauvegarde %s restaurée et vérifiée : %d tables, %d lignes identiques.', basename($file), $result['tables'], $result['rows']));

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $problems
     */
    private function reportFailure(string $message, ?string $file = null, array $problems = []): int
    {
        Log::error($message, ['problems' => $problems]);
        Audit::record('backup.verification_failed', null, [], array_filter(['file' => $file === null ? null : basename($file), 'error' => $message]));
        $this->error($message);

        return self::FAILURE;
    }
}
