<?php

namespace App\Control;

use App\Models\ControlRuleSetting;

/**
 * Catalogue des règles de contrôle, dans l'ordre des catégories du § 13.2.
 * Une règle se désactive depuis l'application, sans toucher au code.
 */
class RuleRegistry
{
    /**
     * @var list<class-string<Rule>>
     */
    public const RULES = [
        Rules\InactiveEmployee::class,
        Rules\MissingCategory::class,
        Rules\IncompleteEmployeeRecord::class,
        Rules\ValidatorIsMissionary::class,
        Rules\UnauthorizedValidator::class,
        Rules\IncompleteValidation::class,
        Rules\DuplicateReference::class,
        Rules\MissingIdentification::class,
        Rules\NoMissionary::class,
        Rules\MissingPlace::class,
        Rules\TypeObjectMismatch::class,
        Rules\ReturnBeforeDeparture::class,
        Rules\DurationMismatch::class,
        Rules\MissingDates::class,
        Rules\QuantitiesExceedDuration::class,
        Rules\CostWithFreeTransport::class,
        Rules\IncompleteLeg::class,
        Rules\BrokenItinerary::class,
        Rules\TransportCostToVerify::class,
        Rules\AllowanceOnMission::class,
        Rules\UnknownNewAssignment::class,
        Rules\UnlinkedAssignmentChange::class,
        Rules\AssignmentHistoryMismatch::class,
        Rules\PerDiemMismatch::class,
        Rules\MissingRate::class,
        Rules\NotApplicableConsumption::class,
        Rules\CategoryMismatch::class,
        Rules\InconsistentQuantities::class,
        Rules\MissingCostAmount::class,
        Rules\CostBreakdownMismatch::class,
    ];

    /**
     * @var array<string, Rule>|null
     */
    private ?array $rules = null;

    /**
     * Toutes les règles, par code.
     *
     * @return array<string, Rule>
     */
    public function all(): array
    {
        if ($this->rules === null) {
            $this->rules = [];
            foreach (self::RULES as $class) {
                $rule = new $class;
                $this->rules[$rule->definition()->code] = $rule;
            }
        }

        return $this->rules;
    }

    public function find(string $code): ?Rule
    {
        return $this->all()[$code] ?? null;
    }

    /**
     * Codes des règles désactivées.
     *
     * @return list<string>
     */
    public function disabledCodes(): array
    {
        return ControlRuleSetting::query()->where('is_enabled', false)->pluck('code')->all();
    }

    /**
     * Règles actives, par code.
     *
     * @return array<string, Rule>
     */
    public function enabled(): array
    {
        return array_diff_key($this->all(), array_flip($this->disabledCodes()));
    }
}
