<?php

namespace App\Control;

use App\Enums\AnomalyCategory;
use App\Enums\AnomalyNature;

/**
 * Fiche d'une règle du catalogue : code, catégorie du § 13.2, nature de
 * l'anomalie produite, libellé et ce que la règle vérifie.
 */
final readonly class RuleDefinition
{
    public function __construct(
        public string $code,
        public AnomalyCategory $category,
        public AnomalyNature $nature,
        public string $label,
        public string $explanation,
    ) {}
}
