<?php

namespace App\Models\Contracts;

/**
 * Modèle désigné en clair dans le journal d'audit, plutôt que par son numéro.
 */
interface DescribedInAudit
{
    public function auditLabel(): string;
}
