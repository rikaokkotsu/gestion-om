<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Point d'entrée unique pour écrire dans le journal d'audit.
 */
class Audit
{
    /**
     * Attributs jamais recopiés dans le journal.
     *
     * @var list<string>
     */
    private const SECRET_KEYS = ['password', 'remember_token', 'current_password', 'password_confirmation'];

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public static function record(string $action, ?Model $subject = null, array $old = [], array $new = [], ?User $actor = null): AuditLog
    {
        $request = request();
        $actor ??= $request->user();

        return AuditLog::create([
            'user_id' => $actor?->getKey(),
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'old_values' => self::clean($old),
            'new_values' => self::clean($new),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>|null
     */
    private static function clean(array $values): ?array
    {
        $values = Arr::except($values, self::SECRET_KEYS);

        return $values === [] ? null : $values;
    }
}
