<?php

namespace App\Imports;

/**
 * Bilan d'un import : rien n'est rejeté en silence, chaque ligne écartée ou
 * douteuse est rapportée avec son numéro de ligne.
 */
class ImportReport
{
    public int $created = 0;

    public int $updated = 0;

    public int $unchanged = 0;

    /** @var list<array{line: int, errors: list<string>}> */
    public array $rejected = [];

    /** @var list<array{line: int, message: string}> */
    public array $warnings = [];

    /** @var list<string> */
    public array $missingColumns = [];

    public function __construct(public bool $dryRun) {}

    /**
     * @param  list<string>  $errors
     */
    public function reject(int $line, array $errors): void
    {
        $this->rejected[] = ['line' => $line, 'errors' => $errors];
    }

    public function warn(int $line, string $message): void
    {
        $this->warnings[] = ['line' => $line, 'message' => $message];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dry_run' => $this->dryRun,
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'rejected' => $this->rejected,
            'warnings' => $this->warnings,
            'missing_columns' => $this->missingColumns,
        ];
    }
}
