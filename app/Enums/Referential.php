<?php

namespace App\Enums;

use App\Models\Category;
use App\Models\MissionObject;
use App\Models\Place;
use App\Models\Service;
use App\Models\TransportMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Listes de référence administrables depuis l'application. Les zones n'en
 * font pas partie : elles sont fixées par le cahier des charges.
 */
enum Referential: string
{
    case Categories = 'categories';
    case Places = 'places';
    case Services = 'services';
    case MissionObjects = 'mission-objects';
    case TransportModes = 'transport-modes';

    /**
     * @return class-string<Model>
     */
    public function model(): string
    {
        return match ($this) {
            self::Categories => Category::class,
            self::Places => Place::class,
            self::Services => Service::class,
            self::MissionObjects => MissionObject::class,
            self::TransportModes => TransportMode::class,
        };
    }

    public function orderColumn(): string
    {
        return match ($this) {
            self::Categories => 'id',
            self::Places, self::Services => 'name',
            self::MissionObjects, self::TransportModes => 'label',
        };
    }

    /**
     * @return list<string>
     */
    public function relations(): array
    {
        return $this === self::Places ? ['zone'] : [];
    }

    /**
     * Règles de validation ; `$existing` est l'élément modifié, null en création.
     *
     * @return array<string, mixed>
     */
    public function rules(?Model $existing): array
    {
        $table = (new ($this->model()))->getTable();
        $required = $existing === null ? 'required' : 'sometimes';
        $unique = fn (string $column) => Rule::unique($table, $column)->ignore($existing?->getKey());

        return match ($this) {
            self::Categories => [
                // Le code sert de clé aux barèmes : il ne change plus après création.
                'code' => $existing === null
                    ? ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_]+$/', $unique('code')]
                    : ['prohibited'],
                'label' => [$required, 'string', 'max:255'],
                'is_active' => ['sometimes', 'boolean'],
            ],
            self::Places => [
                'name' => [$required, 'string', 'max:255', $unique('name')],
                'zone_id' => ['nullable', 'integer', 'exists:zones,id'],
                'is_active' => ['sometimes', 'boolean'],
            ],
            self::Services => [
                'name' => [$required, 'string', 'max:255', $unique('name')],
                'is_active' => ['sometimes', 'boolean'],
            ],
            self::MissionObjects => [
                'label' => [$required, 'string', 'max:255', $unique('label')],
                'is_active' => ['sometimes', 'boolean'],
            ],
            self::TransportModes => [
                'label' => [$required, 'string', 'max:255', $unique('label')],
                'generates_costs' => [$required, 'boolean'],
                'is_active' => ['sometimes', 'boolean'],
            ],
        };
    }
}
