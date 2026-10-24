<?php

namespace App\Actions;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Désactive un compte au lieu de le supprimer, pour garder la trace des
 * constats qu'il a enregistrés.
 */
class DeactivateUser
{
    public function __invoke(User $actor, User $target): void
    {
        DB::transaction(function () use ($actor, $target) {
            // Les comptes créés par le compte désactivé passent à son créateur,
            // pour qu'aucun compte ne se retrouve sans gestionnaire.
            $reassigned = $target->createdUsers()->pluck('id')->all();
            $target->createdUsers()->update(['created_by_id' => $actor->id]);

            $target->forceFill([
                'is_active' => false,
                'deactivated_at' => now(),
                'remember_token' => null,
            ])->save();

            DB::table('sessions')->where('user_id', $target->id)->delete();
            $target->tokens()->delete();

            Audit::record('user.deactivated', $target, ['is_active' => true], [
                'is_active' => false,
                'reassigned_user_ids' => $reassigned,
            ], $actor);
        });
    }
}
