<?php

namespace App\Models;

use App\Enums\ConsumptionType;
use App\Models\Concerns\Auditable;
use App\Models\Contracts\DescribedInAudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Version d'un barème de per diem. Une version s'applique à partir de sa
 * date d'effet jusqu'à la date d'effet de la suivante ; elle ne se modifie
 * plus une fois utilisée par un calcul enregistré.
 *
 * @property int $id
 * @property string $label
 * @property Carbon|null $effective_from
 * @property Carbon|null $locked_at
 * @property int|null $created_by_id
 */
#[Fillable(['label', 'effective_from', 'created_by_id'])]
class RateVersion extends Model implements DescribedInAudit
{
    use Auditable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'locked_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Rate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(Rate::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * Fige la version : appelé lorsqu'un calcul l'utilise et est enregistré.
     */
    public function lock(): void
    {
        if (! $this->isLocked()) {
            $this->forceFill(['locked_at' => now()])->save();
        }
    }

    /**
     * Version suivante dans le temps : sa date d'effet clôt la période de celle-ci.
     */
    public function next(): ?self
    {
        return self::query()
            ->whereNotNull('effective_from')
            ->when($this->effective_from !== null, fn (Builder $query) => $query->whereDate('effective_from', '>', $this->effective_from))
            ->orderBy('effective_from')
            ->first();
    }

    /**
     * Nombre de tarifs d'une grille complète : catégories × zones × types.
     */
    public static function expectedRateCount(): int
    {
        return Category::query()->count() * Zone::query()->count() * count(ConsumptionType::cases());
    }

    /**
     * Compte, sous `filled_count`, les tarifs renseignés ou sans objet.
     *
     * @param  Builder<RateVersion>  $query
     */
    public function scopeWithFilledCount(Builder $query): void
    {
        $query->withCount(['rates as filled_count' => fn (Builder $rates) => $rates
            ->where(fn (Builder $rate) => $rate->where('is_applicable', false)->orWhereNotNull('amount'))]);
    }

    /**
     * Version applicable à une date : la plus récente déjà en vigueur.
     */
    public static function applicableOn(Carbon $date): ?self
    {
        return self::query()
            ->where(fn (Builder $query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
            ->orderByRaw('effective_from desc nulls last')
            ->first();
    }

    /**
     * Ordre chronologique : la version d'origine (sans date) d'abord.
     *
     * @param  Builder<RateVersion>  $query
     */
    public function scopeChronological(Builder $query): void
    {
        $query->orderByRaw('effective_from asc nulls first');
    }

    public function auditLabel(): string
    {
        return $this->label;
    }
}
