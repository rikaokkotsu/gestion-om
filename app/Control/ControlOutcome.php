<?php

namespace App\Control;

/**
 * Bilan du contrôle d'un OM.
 */
final readonly class ControlOutcome
{
    public function __construct(
        public int $created,
        public int $closed,
    ) {}
}
