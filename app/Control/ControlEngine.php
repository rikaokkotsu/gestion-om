<?php

namespace App\Control;

use App\Enums\AdministrativeStatus;
use App\Enums\AnomalyEventType;
use App\Enums\AnomalySource;
use App\Enums\AnomalyState;
use App\Enums\ControlRunStatus;
use App\Enums\ControlTrigger;
use App\Enums\MissionOrderType;
use App\Models\Anomaly;
use App\Models\ControlRun;
use App\Models\MissionOrder;
use App\Models\User;
use App\Models\Zone;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Exécute les règles actives sur un OM et tient ses anomalies à jour, sans
 * jamais modifier l'OM. Une situation déjà signalée n'est pas signalée deux
 * fois : elle est reconnue à sa règle, à l'employé concerné et à sa cible.
 * - Une anomalie encore à traiter est conservée (et sa description mise à
 *   jour si la situation a évolué).
 * - Une anomalie écartée ou clôturée n'est pas reproposée, sauf si la
 *   situation a changé ou avait disparu entre-temps.
 * - Une anomalie proposée que le contrôle ne retrouve plus est clôturée ;
 *   une anomalie confirmée ou transmise est seulement marquée comme n'étant
 *   plus détectée : le contrôleur décide.
 */
class ControlEngine
{
    /**
     * @var Collection<int, Zone>|null
     */
    private ?Collection $zones = null;

    public function __construct(
        private readonly RuleRegistry $registry,
        private readonly ControlStatusResolver $statuses,
    ) {}

    /**
     * Contrôle d'un OM à la demande d'un contrôleur.
     */
    public function controlOrder(MissionOrder $order, ?User $actor): ControlRun
    {
        $run = ControlRun::create([
            'trigger' => ControlTrigger::Request,
            'status' => ControlRunStatus::Running,
            'mission_order_id' => $order->id,
            'launched_by_id' => $actor?->id,
            'orders_count' => 1,
            'started_at' => now(),
        ]);

        $outcome = $this->run($order, $run);

        $run->update([
            'status' => ControlRunStatus::Done,
            'processed_count' => 1,
            'anomalies_created' => $outcome->created,
            'anomalies_closed' => $outcome->closed,
            'finished_at' => now(),
        ]);
        Audit::record('control.run', $order, [], [
            'control_run_id' => $run->id,
            'anomalies_created' => $outcome->created,
            'anomalies_closed' => $outcome->closed,
        ], $actor);

        return $run;
    }

    /**
     * Contrôle par lot, exécuté dans la file d'attente.
     */
    public function runBatch(ControlRun $run): void
    {
        $orders = self::selection($run->filters ?? []);
        $run->update([
            'status' => ControlRunStatus::Running,
            'started_at' => now(),
            'orders_count' => (clone $orders)->count(),
        ]);

        $orders->chunkById(50, function (Collection $chunk) use ($run) {
            foreach ($chunk as $order) {
                $outcome = $this->run($order, $run);
                $run->processed_count++;
                $run->anomalies_created += $outcome->created;
                $run->anomalies_closed += $outcome->closed;
            }
            $run->save();
        });

        $run->update(['status' => ControlRunStatus::Done, 'finished_at' => now()]);
    }

    /**
     * OM retenus par les critères d'un contrôle par lot.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<MissionOrder>
     */
    public static function selection(array $filters): Builder
    {
        return MissionOrder::query()
            ->when($filters['departure_from'] ?? null, fn (Builder $query, string $date) => $query->where('departure_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($filters['departure_to'] ?? null, fn (Builder $query, string $date) => $query->where('departure_at', '<=', Carbon::parse($date)->endOfDay()))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', MissionOrderType::from($type)))
            ->when($filters['administrative_status'] ?? null, fn (Builder $query, string $status) => $query->where('administrative_status', AdministrativeStatus::from($status)))
            ->when(($filters['scope'] ?? 'all') === 'pending', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereNull('last_controlled_at')
                ->orWhereColumn('updated_at', '>', 'last_controlled_at')));
    }

    /**
     * Exécute les règles actives sur l'OM et met à jour ses anomalies.
     */
    public function run(MissionOrder $order, ControlRun $run): ControlOutcome
    {
        return DB::transaction(function () use ($order, $run) {
            // Deux contrôles simultanés du même OM ne doivent pas proposer deux fois la même anomalie.
            MissionOrder::query()->whereKey($order->id)->lockForUpdate()->value('id');
            $now = now();
            $rules = $this->registry->enabled();
            $context = new ControlContext($order, $this->zones());

            /** @var array<string, array{RuleDefinition, Finding}> $findings */
            $findings = [];
            foreach ($rules as $rule) {
                $definition = $rule->definition();
                foreach ($rule->check($context) as $finding) {
                    $findings[self::key($definition->code, $finding->employeeId(), $finding->target)] ??= [$definition, $finding];
                }
            }

            // Pour chaque situation, la dernière anomalie automatique ; les règles désactivées ne sont pas concernées.
            $existing = Anomaly::query()
                ->where('mission_order_id', $order->id)
                ->where('source', AnomalySource::Automatic)
                ->whereIn('rule_code', array_keys($rules))
                ->orderBy('id')
                ->get()
                ->keyBy(fn (Anomaly $anomaly) => self::key((string) $anomaly->rule_code, $anomaly->employee_id, $anomaly->target));

            $created = 0;
            foreach ($findings as $key => [$definition, $finding]) {
                $anomaly = $existing->get($key);

                if ($anomaly === null || $this->isNewOccurrence($anomaly, $finding)) {
                    $this->propose($order, $definition, $finding, $run, $now);
                    $created++;
                } else {
                    $this->redetect($anomaly, $finding, $now);
                }
            }

            $closed = 0;
            foreach ($existing as $key => $anomaly) {
                if (! isset($findings[$key]) && $anomaly->no_longer_detected_at === null && $this->vanish($anomaly, $now)) {
                    $closed++;
                }
            }

            $order->last_controlled_at = $now;
            $this->statuses->refresh($order);

            return new ControlOutcome($created, $closed);
        });
    }

    private static function key(string $code, ?int $employeeId, string $target): string
    {
        return $code.'|'.($employeeId ?? '').'|'.$target;
    }

    /**
     * @return Collection<int, Zone>
     */
    private function zones(): Collection
    {
        return $this->zones ??= Zone::query()->get()->keyBy('id');
    }

    private function isNewOccurrence(Anomaly $anomaly, Finding $finding): bool
    {
        return ! $anomaly->state->isOpen()
            && ($anomaly->no_longer_detected_at !== null || $anomaly->description !== $finding->description);
    }

    private function propose(MissionOrder $order, RuleDefinition $definition, Finding $finding, ControlRun $run, Carbon $now): void
    {
        $anomaly = Anomaly::create([
            'mission_order_id' => $order->id,
            'mission_order_employee_id' => $finding->member?->id,
            'employee_id' => $finding->employeeId(),
            'category' => $definition->category,
            'rule_code' => $definition->code,
            'target' => $finding->target,
            'source' => AnomalySource::Automatic,
            'nature' => $definition->nature,
            'priority' => $definition->nature->defaultPriority(),
            'state' => AnomalyState::Proposed,
            'description' => $finding->description,
            'control_run_id' => $run->id,
            'detected_at' => $now,
            'last_detected_at' => $now,
        ]);

        $anomaly->events()->create(['type' => AnomalyEventType::Created, 'to_state' => AnomalyState::Proposed]);
    }

    private function redetect(Anomaly $anomaly, Finding $finding, Carbon $now): void
    {
        $changes = $anomaly->description === $finding->description
            ? []
            : ['description' => ['old' => $anomaly->description, 'new' => $finding->description]];
        $reappeared = $anomaly->no_longer_detected_at !== null;

        $anomaly->fill([
            'description' => $finding->description,
            'mission_order_employee_id' => $finding->member?->id,
            'last_detected_at' => $now,
            'no_longer_detected_at' => null,
        ])->save();

        if ($changes !== [] || $reappeared) {
            $anomaly->events()->create(['type' => AnomalyEventType::Redetected, 'changes' => $changes === [] ? null : $changes]);
        }
    }

    /**
     * @return bool vrai si l'anomalie a été clôturée
     */
    private function vanish(Anomaly $anomaly, Carbon $now): bool
    {
        $anomaly->no_longer_detected_at = $now;

        if ($anomaly->state === AnomalyState::Proposed) {
            $anomaly->state = AnomalyState::Closed;
            $anomaly->save();
            $anomaly->events()->create([
                'type' => AnomalyEventType::StateChanged,
                'from_state' => AnomalyState::Proposed,
                'to_state' => AnomalyState::Closed,
                'comment' => 'Clôturée par le contrôle : la situation n\'est plus détectée.',
            ]);

            return true;
        }

        $anomaly->save();
        if ($anomaly->state->isOpen()) {
            $anomaly->events()->create(['type' => AnomalyEventType::NoLongerDetected]);
        }

        return false;
    }
}
