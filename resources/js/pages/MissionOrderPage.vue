<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { errorsFrom, http } from '@/api';
import DownloadButton from '@/components/DownloadButton.vue';
import MissionOrderAnomalies from '@/components/MissionOrderAnomalies.vue';
import { employeeName, formatAmount, formatDate, formatDateTime } from '@/format';
import { useReferentials } from '@/referentials';
import type { ConsumptionTypeCode, MissionOrder, MissionOrderEmployee, TravelDirectionCode } from '@/types';

const route = useRoute();
const router = useRouter();
const { referentials, load: loadReferentials } = useReferentials();

const order = ref<MissionOrder | null>(null);
const confirmDelete = ref(false);
const message = ref('');
const openDetails = ref<Record<number, boolean>>({});

const zones = computed(() => referentials.value?.zones ?? []);
const types = computed(() => referentials.value?.consumption_types ?? []);

const durationDiffers = computed(
    () =>
        order.value !== null &&
        order.value.declared_duration !== null &&
        order.value.calculated_duration !== null &&
        order.value.declared_duration !== order.value.calculated_duration,
);

const validatorIsMember = computed(
    () => order.value?.validator !== null && order.value?.employees.some((member) => member.employee_id === order.value?.validator_id),
);

const costsSum = computed(() => order.value?.costs.reduce((sum, cost) => sum + (cost.amount ?? 0), 0) ?? 0);

function legsOf(direction: TravelDirectionCode) {
    return order.value?.legs.filter((leg) => leg.direction === direction) ?? [];
}

function quantity(member: MissionOrderEmployee, type: ConsumptionTypeCode, zoneId: number): number | null {
    return member.quantities.find((q) => q.consumption_type === type && q.zone_id === zoneId)?.quantity ?? null;
}

function total(member: MissionOrderEmployee, type: ConsumptionTypeCode): number | null {
    return member.totals.find((t) => t.consumption_type === type)?.total ?? null;
}

function zoneSum(member: MissionOrderEmployee, type: ConsumptionTypeCode): number | null {
    const values = member.quantities.filter((q) => q.consumption_type === type).map((q) => q.quantity);
    return values.length ? values.reduce((a, b) => a + b, 0) : null;
}

function gap(member: MissionOrderEmployee): number | null {
    return member.declared_per_diem === null || member.calculated_per_diem === null || !member.calculation_complete
        ? null
        : member.declared_per_diem - member.calculated_per_diem;
}

function zoneLabel(code: string): string {
    return zones.value.find((zone) => zone.code === code)?.label ?? code;
}

const statusLabels: Record<string, string> = {
    missing_rate: 'Tarif non renseigné',
    not_applicable: 'Sans objet',
    invalid_quantity: 'Quantité invalide',
};

async function remove(): Promise<void> {
    try {
        await http.delete(`/api/mission-orders/${order.value!.id}`);
        await router.push('/om');
    } catch (error) {
        message.value = errorsFrom(error).message;
    }
}

async function loadOrder(): Promise<void> {
    const { data } = await http.get<{ data: MissionOrder }>(`/api/mission-orders/${String(route.params.id)}`);
    order.value = data.data;
}

onMounted(async () => {
    await Promise.all([loadReferentials(), loadOrder()]);
});
</script>

<template>
    <div v-if="order" class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <RouterLink to="/om" class="text-sm text-slate-600 hover:text-slate-900">← Ordres de mission</RouterLink>
                <h1 class="mt-2 text-2xl font-semibold">
                    <template v-if="order.reference">OM <span class="font-mono">{{ order.reference }}</span></template>
                    <template v-else>OM sans référence</template>
                </h1>
                <div class="mt-2 flex flex-wrap gap-2 text-sm">
                    <span class="rounded bg-slate-100 px-2 py-0.5">{{ order.type_label ?? 'Type non renseigné' }}</span>
                    <span class="rounded bg-slate-100 px-2 py-0.5">{{ order.administrative_status_label }}</span>
                    <span class="rounded bg-sky-50 px-2 py-0.5 text-sky-800">
                        {{ order.control_status_label }}<template v-if="order.open_anomalies_count"> · {{ order.open_anomalies_count }} à traiter</template>
                    </span>
                </div>
            </div>
            <div class="flex flex-wrap items-start gap-2">
                <DownloadButton :url="`/api/reports/mission-orders/${order.id}`" label="Rapport de contrôle PDF" />
                <RouterLink :to="`/om/${order.id}/modifier`" class="btn-primary">Modifier</RouterLink>
                <button v-if="!confirmDelete" type="button" class="btn-danger" @click="confirmDelete = true">Supprimer</button>
                <button v-else type="button" class="btn-danger" @click="remove">Confirmer la suppression</button>
            </div>
        </div>
        <p v-if="message" class="text-sm text-red-700">{{ message }}</p>

        <MissionOrderAnomalies :order="order" @changed="loadOrder" />

        <section class="card p-5">
            <h2 class="mb-4 text-lg font-semibold">Informations générales</h2>
            <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-slate-600">Objet</dt>
                    <dd>{{ order.mission_object?.label ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-600">Départ</dt>
                    <dd>
                        {{ formatDateTime(order.departure_at) || '—' }}
                        <template v-if="order.departure_place">
                            · {{ order.departure_place.name }}
                            <span class="text-slate-500">({{ order.departure_place.zone?.label ?? 'sans zone' }})</span>
                        </template>
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-600">Retour</dt>
                    <dd>{{ formatDateTime(order.return_at) || '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-600">Destination</dt>
                    <dd>
                        <template v-if="order.destination_place">
                            {{ order.destination_place.name }}
                            <span class="text-slate-500">({{ order.destination_place.zone?.label ?? 'sans zone' }})</span>
                        </template>
                        <template v-else>—</template>
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-600">Durée</dt>
                    <dd>
                        Déclarée : {{ order.declared_duration ?? '—' }} · selon les dates :
                        <span :class="{ 'rounded bg-amber-50 px-1 text-amber-800': durationDiffers }">{{ order.calculated_duration ?? '—' }}</span>
                        <p class="text-xs text-slate-500">Convention : {{ order.duration_convention }}.</p>
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-600">Validateur</dt>
                    <dd>
                        <template v-if="order.validator">
                            <RouterLink :to="`/employes/${order.validator.id}`" class="text-sky-700 hover:underline">
                                {{ employeeName(order.validator) }}
                            </RouterLink>
                            <span v-if="order.validated_at"> · le {{ formatDateTime(order.validated_at) }}</span>
                            <p v-if="!order.validator.is_validator" class="text-xs text-amber-700">Non habilité à valider les OM.</p>
                            <p v-if="validatorIsMember" class="text-xs text-amber-700">Missionnaire de cet OM.</p>
                        </template>
                        <template v-else>—</template>
                    </dd>
                </div>
                <div v-if="order.notes" class="sm:col-span-2 lg:col-span-3">
                    <dt class="text-slate-600">Observations</dt>
                    <dd class="whitespace-pre-line">{{ order.notes }}</dd>
                </div>
            </dl>
            <p class="mt-4 text-xs text-slate-500">
                Saisi par {{ order.created_by?.name ?? '—' }}<template v-if="order.updated_by">, dernière modification par {{ order.updated_by.name }}</template>.
            </p>
        </section>

        <section class="card space-y-4 p-5">
            <h2 class="text-lg font-semibold">Transport</h2>
            <p v-if="order.legs.length === 0" class="text-sm text-slate-500">Aucun moyen de transport.</p>
            <template v-for="direction in ['OUTBOUND', 'RETURN'] as const" :key="direction">
                <div v-if="legsOf(direction).length" class="overflow-x-auto">
                    <h3 class="mb-1 font-medium">{{ direction === 'OUTBOUND' ? 'Aller' : 'Retour' }}</h3>
                    <table class="min-w-full table-fixed divide-y divide-slate-200 text-sm">
                        <thead class="text-left text-slate-600">
                            <tr>
                                <th class="w-12 py-1 pr-4 font-medium">N°</th>
                                <th class="w-1/3 py-1 pr-4 font-medium">Moyen</th>
                                <th class="py-1 pr-4 font-medium">De</th>
                                <th class="py-1 pr-4 font-medium">À</th>
                                <th class="w-32 py-1 text-right font-medium">Frais</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="leg in legsOf(direction)" :key="leg.position">
                                <td class="py-1.5 pr-4 text-slate-500">{{ leg.position }}</td>
                                <td class="py-1.5 pr-4">
                                    {{ leg.transport_mode?.label ?? '—' }}
                                    <span v-if="leg.transport_mode" class="text-xs text-slate-500">
                                        {{ leg.transport_mode.generates_costs ? 'payant' : 'sans frais' }}
                                    </span>
                                </td>
                                <td class="py-1.5 pr-4">{{ leg.from_place?.name ?? '—' }}</td>
                                <td class="py-1.5 pr-4">{{ leg.to_place?.name ?? '—' }}</td>
                                <td class="py-1.5 text-right">{{ leg.cost === null ? '' : `${formatAmount(leg.cost)} Ar` }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </section>

        <section class="card space-y-4 p-5">
            <h2 class="text-lg font-semibold">Frais et indemnité</h2>
            <dl class="grid gap-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-slate-600">Frais supplémentaires (montant global)</dt>
                    <dd>{{ order.additional_costs === null ? '—' : `${formatAmount(order.additional_costs)} Ar` }}</dd>
                </div>
                <div>
                    <dt class="text-slate-600">Indemnité d'affectation</dt>
                    <dd>{{ order.assignment_allowance === null ? '—' : `${formatAmount(order.assignment_allowance)} Ar` }}</dd>
                </div>
            </dl>
            <div v-if="order.costs.length" class="overflow-x-auto">
                <h3 class="mb-1 font-medium">Ventilation</h3>
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="(cost, index) in order.costs" :key="index">
                            <td class="py-1.5 pr-4">{{ cost.nature_label }}</td>
                            <td class="py-1.5 pr-4 text-slate-600">{{ cost.label }}</td>
                            <td class="py-1.5 text-right">{{ cost.amount === null ? '—' : `${formatAmount(cost.amount)} Ar` }}</td>
                        </tr>
                        <tr class="font-medium">
                            <td class="py-1.5 pr-4" colspan="2">Somme de la ventilation</td>
                            <td class="py-1.5 text-right">{{ formatAmount(costsSum) }} Ar</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-4">
            <h2 class="text-lg font-semibold">Missionnaires ({{ order.employees.length }})</h2>
            <p v-if="order.employees.length === 0" class="text-sm text-slate-500">Aucun missionnaire.</p>
            <article v-for="member in order.employees" :key="member.id" class="card space-y-4 p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="text-sm">
                        <RouterLink :to="`/employes/${member.employee_id}`" class="text-base font-medium text-sky-700 hover:underline">
                            {{ employeeName(member.employee) }}
                        </RouterLink>
                        <span class="ml-2 font-mono text-xs text-slate-500">{{ member.employee.matricule }}</span>
                        <p class="text-slate-600">
                            Catégorie sur l'OM : {{ member.category?.label ?? 'non renseignée' }}
                            <span v-if="member.category_id !== (member.employee.category?.id ?? null)" class="text-amber-700">
                                (fiche : {{ member.employee.category?.label ?? 'non renseignée' }})
                            </span>
                        </p>
                        <p v-for="(change, index) in member.assignment_changes" :key="index" class="text-slate-600">
                            Affectation mise à jour par cet OM : {{ change.previous_place?.name ?? '—' }} → {{ change.new_place?.name ?? '—' }}
                            {{ change.effective_date ? `au ${formatDate(change.effective_date)}` : '(date inconnue)' }}
                        </p>
                    </div>
                    <dl class="grid grid-cols-3 gap-4 text-sm">
                        <div>
                            <dt class="text-slate-600">Per diem déclaré</dt>
                            <dd class="font-medium">{{ member.declared_per_diem === null ? '—' : `${formatAmount(member.declared_per_diem)} Ar` }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-600">Per diem calculé</dt>
                            <dd class="font-medium">
                                {{ member.calculated_per_diem === null ? '—' : `${formatAmount(member.calculated_per_diem)} Ar` }}
                                <span v-if="member.calculation_complete === false" class="block text-xs font-normal text-amber-700">incomplet</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-slate-600">Écart</dt>
                            <dd class="font-medium" :class="{ 'text-amber-700': gap(member) }">
                                {{ gap(member) === null ? '—' : `${gap(member)! > 0 ? '+' : ''}${formatAmount(gap(member))} Ar` }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="text-slate-600">
                            <tr>
                                <th class="py-1 pr-4 text-left font-medium">Consommation</th>
                                <th v-for="zone in zones" :key="zone.id" class="px-2 py-1 text-right font-medium">{{ zone.label }}</th>
                                <th class="px-2 py-1 text-right font-medium">Total déclaré</th>
                                <th class="px-2 py-1 text-right font-medium">Somme des zones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="type in types" :key="type.value">
                                <td class="py-1 pr-4">{{ type.label }}</td>
                                <td v-for="zone in zones" :key="zone.id" class="px-2 py-1 text-right">{{ quantity(member, type.value, zone.id) ?? '' }}</td>
                                <td class="px-2 py-1 text-right">{{ total(member, type.value) ?? '' }}</td>
                                <td
                                    class="px-2 py-1 text-right"
                                    :class="{ 'text-amber-700': total(member, type.value) !== null && total(member, type.value) !== zoneSum(member, type.value) }"
                                >
                                    {{ zoneSum(member, type.value) ?? '' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <template v-if="member.calculation">
                    <ul v-if="member.calculation.issues.length" class="space-y-1 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        <li v-for="issue in member.calculation.issues" :key="issue">{{ issue }}</li>
                    </ul>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-600">
                        <span>Barème : {{ member.rate_version?.label ?? 'aucun' }}</span>
                        <span v-for="(amount, code) in member.calculation.zone_totals" :key="code">
                            {{ zoneLabel(String(code)) }} : {{ formatAmount(amount) }} Ar
                        </span>
                        <button
                            v-if="member.calculation.lines.length"
                            type="button"
                            class="text-sky-700 hover:underline"
                            @click="openDetails[member.id] = !openDetails[member.id]"
                        >
                            {{ openDetails[member.id] ? 'Masquer le détail' : 'Détail du calcul' }}
                        </button>
                    </div>
                    <table v-if="openDetails[member.id]" class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="text-left text-slate-600">
                            <tr>
                                <th class="py-1 pr-4 font-medium">Consommation</th>
                                <th class="py-1 pr-4 font-medium">Zone</th>
                                <th class="py-1 pr-4 text-right font-medium">Quantité</th>
                                <th class="py-1 pr-4 text-right font-medium">Tarif</th>
                                <th class="py-1 pr-4 text-right font-medium">Montant</th>
                                <th class="py-1"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="(line, index) in member.calculation.lines" :key="index">
                                <td class="py-1 pr-4">{{ line.consumption_label }}</td>
                                <td class="py-1 pr-4">{{ zoneLabel(line.zone_code) }}</td>
                                <td class="py-1 pr-4 text-right">{{ line.quantity }}</td>
                                <td class="py-1 pr-4 text-right">{{ formatAmount(line.rate) }}</td>
                                <td class="py-1 pr-4 text-right">{{ formatAmount(line.amount) }}</td>
                                <td class="py-1">
                                    <span v-if="line.status !== 'ok'" class="rounded bg-amber-50 px-2 py-0.5 text-xs text-amber-800">
                                        {{ statusLabels[line.status] }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </article>
        </section>
    </div>
</template>
