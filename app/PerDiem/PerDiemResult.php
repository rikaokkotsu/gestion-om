<?php

namespace App\PerDiem;

use App\Models\RateVersion;

/**
 * Résultat d'un calcul de per diem. Un calcul incomplet garde le total des
 * lignes calculables, mais `complete` est faux et `issues` dit pourquoi :
 * un tarif manquant n'est jamais compté comme nul (§ 11.4).
 */
final readonly class PerDiemResult
{
    /**
     * @param  list<PerDiemLine>  $lines
     * @param  array<string, int>  $zoneTotals  code de zone => total
     * @param  list<string>  $issues
     */
    public function __construct(
        public ?RateVersion $version,
        public array $lines,
        public array $zoneTotals,
        public int $total,
        public bool $complete,
        public array $issues,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rate_version' => $this->version === null ? null : [
                'id' => $this->version->id,
                'label' => $this->version->label,
                'effective_from' => $this->version->effective_from?->toDateString(),
            ],
            'lines' => array_map(fn (PerDiemLine $line) => $line->toArray(), $this->lines),
            'zone_totals' => $this->zoneTotals,
            'total' => $this->total,
            'complete' => $this->complete,
            'issues' => $this->issues,
        ];
    }
}
