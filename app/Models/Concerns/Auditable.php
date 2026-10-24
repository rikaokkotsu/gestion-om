<?php

namespace App\Models\Concerns;

use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Journalise automatiquement la création et la modification d'un modèle
 * (actions `<type>.created` et `<type>.updated`, type = alias du morph map).
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            Audit::record($model->getMorphClass().'.created', $model, [], self::auditableValues($model->getAttributes()));
        });

        static::updated(function (Model $model) {
            $changes = self::auditableValues($model->getChanges());

            if ($changes !== []) {
                Audit::record($model->getMorphClass().'.updated', $model, Arr::only($model->getOriginal(), array_keys($changes)), $changes);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function auditableValues(array $values): array
    {
        return Arr::except($values, ['id', 'created_at', 'updated_at']);
    }
}
