<?php

namespace App\Policies;

use App\Models\User;

/**
 * Tous les comptes sont administrateurs ; chacun ne modifie, désactive ou
 * réactive que les comptes qu'il a lui-même créés.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, User $target): bool
    {
        return $target->isManagedBy($user);
    }

    public function deactivate(User $user, User $target): bool
    {
        return $target->isManagedBy($user) && $target->is_active;
    }

    public function reactivate(User $user, User $target): bool
    {
        return $target->isManagedBy($user) && ! $target->is_active;
    }
}
