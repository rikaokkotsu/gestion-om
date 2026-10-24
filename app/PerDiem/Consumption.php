<?php

namespace App\PerDiem;

use App\Enums\ConsumptionType;

/**
 * Quantité attribuée pour un type de consommation dans une zone.
 */
final readonly class Consumption
{
    public function __construct(
        public ConsumptionType $type,
        public int $zoneId,
        public int $quantity,
    ) {}
}
