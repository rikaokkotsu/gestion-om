<?php

namespace App\PerDiem;

use App\Enums\ConsumptionType;

/**
 * Une ligne du calcul : quantité × tarif pour un type et une zone.
 */
final readonly class PerDiemLine
{
    public const OK = 'ok';

    public const MISSING_RATE = 'missing_rate';

    public const NOT_APPLICABLE = 'not_applicable';

    public const INVALID_QUANTITY = 'invalid_quantity';

    public function __construct(
        public ConsumptionType $type,
        public int $zoneId,
        public string $zoneCode,
        public int $quantity,
        public ?int $rate,
        public ?int $amount,
        public string $status,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'consumption_type' => $this->type->value,
            'consumption_label' => $this->type->label(),
            'zone_id' => $this->zoneId,
            'zone_code' => $this->zoneCode,
            'quantity' => $this->quantity,
            'rate' => $this->rate,
            'amount' => $this->amount,
            'status' => $this->status,
        ];
    }
}
