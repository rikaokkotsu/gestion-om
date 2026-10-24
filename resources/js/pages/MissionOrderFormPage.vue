<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { errorsFrom, http } from '@/api';
import EmployeePicker from '@/components/EmployeePicker.vue';
import FieldError from '@/components/FieldError.vue';
import { employeeName, formatAmount, parseInteger } from '@/format';
import { useReferentials } from '@/referentials';
import type {
    AdministrativeStatusCode,
    ConsumptionTypeCode,
    CostNatureCode,
    Employee,
    MissionOrder,
    MissionOrderEmployee,
    MissionOrderTypeCode,
    TravelDirectionCode,
    ValidationErrors,
} from '@/types';

interface PersonInfo {
    id: number;
    matricule: string;
    last_name: string;
    first_name: string | null;
    is_validator: boolean;
    is_active: boolean;
    category: { id: number; code: string; label: string } | null;
    current_place: { id: number; name: string } | null;
}

interface LegDraft {
    key: number;
    transport_mode_id: number | null;
    from_place_id: number | null;
    to_place_id: number | null;
    cost: string;
}

interface CostDraft {
    key: number;
    nature: CostNatureCode;
    label: string;
    amount: string;
}

interface MemberDraft {
    key: number;
    employee: PersonInfo;
    category_id: number | null;
    declared_per_diem: string;
    quantities: Record<string, string>;
    totals: Record<string, string>;
    saved: MissionOrderEmployee | null;
}

const route = useRoute();
const router = useRouter();
const { referentials, load: loadReferentials } = useReferentials();

const orderId = computed(() => (route.params.id === undefined ? null : Number(route.params.id)));
const loaded = ref(false);
const submitting = ref(false);
const errors = ref<ValidationErrors>({});
const message = ref('');

let nextKey = 1;
const key = () => nextKey++;

const form = reactive({
    reference: '',
    type: null as MissionOrderTypeCode | null,
    mission_object_id: null as number | null,
    administrative_status: 'PENDING' as AdministrativeStatusCode,
    departure_at: '',
    return_at: '',
    departure_place_id: null as number | null,
    destination_place_id: null as number | null,
    declared_duration: '',
    additional_costs: '',
    assignment_allowance: '',
    validator: null as PersonInfo | null,
    validated_at: '',
    notes: '',
});
const legs = reactive<Record<TravelDirectionCode, LegDraft[]>>({ OUTBOUND: [], RETURN: [] });
const costs = ref<CostDraft[]>([]);
const members = ref<MemberDraft[]>([]);
const activeMember = ref(0);

const zones = computed(() => referentials.value?.zones ?? []);
const places = computed(() => referentials.value?.places ?? []);
const types = computed(() => referentials.value?.consumption_types ?? []);

function person(employee: Employee | MissionOrderEmployee['employee'] | MissionOrder['validator']): PersonInfo | null {
    if (!employee) {
        return null;
    }
    return {
        id: employee.id,
        matricule: employee.matricule,
        last_name: employee.last_name,
        first_name: employee.first_name,
        is_validator: employee.is_validator,
        is_active: employee.is_active,
        category: 'category' in employee ? (employee.category ?? null) : null,
        current_place: 'current_place' in employee ? (employee.current_place ?? null) : null,
    };
}

function cellKey(type: ConsumptionTypeCode, zoneId: number): string {
    return `${type}|${zoneId}`;
}

function newMember(employee: PersonInfo): MemberDraft {
    return {
        key: key(),
        employee,
        category_id: employee.category?.id ?? null,
        declared_per_diem: '',
        quantities: {},
        totals: {},
        saved: null,
    };
}

function fill(order: MissionOrder): void {
    Object.assign(form, {
        reference: order.reference ?? '',
        type: order.type,
        mission_object_id: order.mission_object_id,
        administrative_status: order.administrative_status,
        departure_at: order.departure_at ?? '',
        return_at: order.return_at ?? '',
        departure_place_id: order.departure_place_id,
        destination_place_id: order.destination_place_id,
        declared_duration: order.declared_duration === null ? '' : String(order.declared_duration),
        additional_costs: order.additional_costs === null ? '' : String(order.additional_costs),
        assignment_allowance: order.assignment_allowance === null ? '' : String(order.assignment_allowance),
        validator: person(order.validator),
        validated_at: order.validated_at ?? '',
        notes: order.notes ?? '',
    });
    for (const direction of ['OUTBOUND', 'RETURN'] as const) {
        legs[direction] = order.legs
            .filter((leg) => leg.direction === direction)
            .map((leg) => ({
                key: key(),
                transport_mode_id: leg.transport_mode_id,
                from_place_id: leg.from_place_id,
                to_place_id: leg.to_place_id,
                cost: leg.cost === null ? '' : String(leg.cost),
            }));
    }
    costs.value = order.costs.map((cost) => ({
        key: key(),
        nature: cost.nature,
        label: cost.label ?? '',
        amount: cost.amount === null ? '' : String(cost.amount),
    }));
    members.value = order.employees.map((member) => ({
        key: key(),
        employee: person(member.employee)!,
        category_id: member.category_id,
        declared_per_diem: member.declared_per_diem === null ? '' : String(member.declared_per_diem),
        quantities: Object.fromEntries(member.quantities.map((q) => [cellKey(q.consumption_type, q.zone_id), String(q.quantity)])),
        totals: Object.fromEntries(member.totals.map((t) => [t.consumption_type, String(t.total)])),
        saved: member,
    }));
}

// --- Étapes de transport ---

function addLeg(direction: TravelDirectionCode): void {
    const list = legs[direction];
    const previous = list[list.length - 1];
    // Une nouvelle étape part par défaut de l'arrivée de la précédente.
    const from = previous?.to_place_id ?? (direction === 'OUTBOUND' ? form.departure_place_id : form.destination_place_id);
    list.push({ key: key(), transport_mode_id: null, from_place_id: from, to_place_id: null, cost: '' });
}

function moveLeg(direction: TravelDirectionCode, index: number, step: number): void {
    const list = legs[direction];
    const target = index + step;
    if (target < 0 || target >= list.length) {
        return;
    }
    [list[index], list[target]] = [list[target]!, list[index]!];
}

function modeGeneratesCosts(id: number | null): boolean | null {
    const mode = referentials.value?.transport_modes.find((m) => m.id === id);
    return mode ? mode.generates_costs : null;
}

// --- Frais ---

const costsSum = computed(() =>
    costs.value.reduce((sum, cost) => {
        const value = parseInteger(cost.amount);
        return typeof value === 'number' ? sum + value : sum;
    }, 0),
);

// --- Missionnaires ---

const memberIds = computed(() => members.value.map((member) => member.employee.id));

function addMember(employee: Employee): void {
    members.value.push(newMember(person(employee)!));
    activeMember.value = members.value.length - 1;
}

function removeMember(index: number): void {
    members.value.splice(index, 1);
    activeMember.value = Math.max(0, Math.min(activeMember.value, members.value.length - 1));
}

function zoneSum(member: MemberDraft, type: ConsumptionTypeCode): number | null {
    let sum: number | null = null;
    for (const zone of zones.value) {
        const value = parseInteger(member.quantities[cellKey(type, zone.id)]);
        if (typeof value === 'number') {
            sum = (sum ?? 0) + value;
        }
    }
    return sum;
}

function categoryLabel(id: number | null): string {
    return referentials.value?.categories.find((category) => category.id === id)?.label ?? '';
}

function memberHasErrors(index: number): boolean {
    return Object.keys(errors.value).some((field) => field.startsWith(`employees.${index}.`));
}

function memberErrors(index: number): string[] {
    return Object.entries(errors.value)
        .filter(([field]) => field.startsWith(`employees.${index}.`))
        .map(([, messages]) => messages[0] ?? '');
}

function legErrors(direction: TravelDirectionCode, index: number): string[] {
    const offset = direction === 'OUTBOUND' ? 0 : legs.OUTBOUND.length;
    return Object.entries(errors.value)
        .filter(([field]) => field.startsWith(`legs.${offset + index}.`))
        .map(([, messages]) => messages[0] ?? '');
}

// --- Avertissements (le contrôle les signalera, rien n'est bloqué) ---

const validatorIsMember = computed(() => form.validator !== null && memberIds.value.includes(form.validator.id));

// --- Enregistrement ---

function payload(): Record<string, unknown> {
    return {
        reference: form.reference.trim() || null,
        type: form.type,
        mission_object_id: form.mission_object_id,
        administrative_status: form.administrative_status,
        departure_at: form.departure_at || null,
        return_at: form.return_at || null,
        departure_place_id: form.departure_place_id,
        destination_place_id: form.destination_place_id,
        declared_duration: parseInteger(form.declared_duration),
        additional_costs: parseInteger(form.additional_costs),
        assignment_allowance: parseInteger(form.assignment_allowance),
        validator_id: form.validator?.id ?? null,
        validated_at: form.validated_at || null,
        notes: form.notes.trim() || null,
        legs: (['OUTBOUND', 'RETURN'] as const).flatMap((direction) =>
            legs[direction].map((leg) => ({
                direction,
                transport_mode_id: leg.transport_mode_id,
                from_place_id: leg.from_place_id,
                to_place_id: leg.to_place_id,
                cost: parseInteger(leg.cost),
            })),
        ),
        costs: costs.value.map((cost) => ({ nature: cost.nature, label: cost.label.trim() || null, amount: parseInteger(cost.amount) })),
        employees: members.value.map((member) => ({
            employee_id: member.employee.id,
            category_id: member.category_id,
            declared_per_diem: parseInteger(member.declared_per_diem),
            quantities: Object.entries(member.quantities)
                .filter(([, value]) => String(value).trim() !== '')
                .map(([cell, value]) => {
                    const [type, zoneId] = cell.split('|');
                    return { consumption_type: type, zone_id: Number(zoneId), quantity: parseInteger(value) };
                }),
            totals: Object.entries(member.totals)
                .filter(([, value]) => String(value).trim() !== '')
                .map(([type, value]) => ({ consumption_type: type, total: parseInteger(value) })),
        })),
    };
}

async function submit(): Promise<void> {
    submitting.value = true;
    errors.value = {};
    message.value = '';
    try {
        const { data } = orderId.value
            ? await http.patch<{ data: MissionOrder }>(`/api/mission-orders/${orderId.value}`, payload())
            : await http.post<{ data: MissionOrder }>('/api/mission-orders', payload());
        await router.push(`/om/${data.data.id}`);
    } catch (error) {
        const result = errorsFrom(error);
        errors.value = result.errors;
        message.value = Object.keys(result.errors).length
            ? 'Certaines valeurs ne peuvent pas être enregistrées : vérifiez les champs signalés.'
            : result.message;
        const failing = members.value.findIndex((_, index) => memberHasErrors(index));
        if (failing >= 0) {
            activeMember.value = failing;
        }
    } finally {
        submitting.value = false;
    }
}

onMounted(async () => {
    await loadReferentials();
    if (orderId.value) {
        const { data } = await http.get<{ data: MissionOrder }>(`/api/mission-orders/${orderId.value}`);
        fill(data.data);
    }
    loaded.value = true;
});
</script>

<template>
    <div v-if="loaded" class="space-y-6">
        <div>
            <RouterLink :to="orderId ? `/om/${orderId}` : '/om'" class="text-sm text-slate-600 hover:text-slate-900">
                ← {{ orderId ? 'Fiche de l\'OM' : 'Ordres de mission' }}
            </RouterLink>
            <h1 class="mt-2 text-2xl font-semibold">{{ orderId ? 'Modifier l\'OM' : 'Nouvel OM' }}</h1>
            <p class="mt-1 text-sm text-slate-600">
                Saisissez l'OM tel qu'il figure sur le document, même incomplet ou incohérent : l'application calcule et signale, elle ne
                corrige pas.
            </p>
        </div>

        <form class="space-y-6" @submit.prevent="submit">
            <section class="card space-y-4 p-5">
                <h2 class="text-lg font-semibold">Informations générales</h2>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label for="reference" class="mb-1 block text-sm font-medium">Référence</label>
                        <input id="reference" v-model="form.reference" class="field font-mono" />
                        <FieldError :messages="errors.reference" />
                    </div>
                    <div>
                        <label for="type" class="mb-1 block text-sm font-medium">Type</label>
                        <select id="type" v-model="form.type" class="field">
                            <option :value="null">Non renseigné</option>
                            <option v-for="t in referentials?.mission_order_types" :key="t.value" :value="t.value">{{ t.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="object" class="mb-1 block text-sm font-medium">Objet</label>
                        <select id="object" v-model="form.mission_object_id" class="field">
                            <option :value="null">Non renseigné</option>
                            <option
                                v-for="o in referentials?.mission_objects"
                                :key="o.id"
                                :value="o.id"
                                :disabled="!o.is_active && o.id !== form.mission_object_id"
                            >
                                {{ o.label }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label for="status" class="mb-1 block text-sm font-medium">Statut administratif</label>
                        <select id="status" v-model="form.administrative_status" class="field">
                            <option v-for="s in referentials?.administrative_statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="departure_at" class="mb-1 block text-sm font-medium">Départ</label>
                        <input id="departure_at" v-model="form.departure_at" type="datetime-local" class="field" />
                        <FieldError :messages="errors.departure_at" />
                    </div>
                    <div>
                        <label for="departure_place" class="mb-1 block text-sm font-medium">Lieu de départ</label>
                        <select id="departure_place" v-model="form.departure_place_id" class="field">
                            <option :value="null">Non renseigné</option>
                            <option v-for="p in places" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="return_at" class="mb-1 block text-sm font-medium">Retour</label>
                        <input id="return_at" v-model="form.return_at" type="datetime-local" class="field" />
                        <FieldError :messages="errors.return_at" />
                    </div>
                    <div>
                        <label for="destination" class="mb-1 block text-sm font-medium">Destination</label>
                        <select id="destination" v-model="form.destination_place_id" class="field">
                            <option :value="null">Non renseignée</option>
                            <option v-for="p in places" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="duration" class="mb-1 block text-sm font-medium">Durée déclarée (jours)</label>
                        <input id="duration" v-model="form.declared_duration" inputmode="numeric" class="field" />
                        <FieldError :messages="errors.declared_duration" />
                    </div>
                    <div class="sm:col-span-2">
                        <label for="validator" class="mb-1 block text-sm font-medium">Validateur</label>
                        <div v-if="form.validator" class="flex flex-wrap items-center gap-2">
                            <span class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                                <span class="font-mono text-xs">{{ form.validator.matricule }}</span>
                                {{ employeeName(form.validator) }}
                            </span>
                            <button type="button" class="btn-secondary" @click="form.validator = null">Changer</button>
                        </div>
                        <EmployeePicker v-else input-id="validator" @select="(e) => (form.validator = person(e))" />
                        <p v-if="form.validator && !form.validator.is_validator" class="mt-1 text-xs text-amber-700">
                            Cet employé n'est pas habilité à valider : le contrôle le signalera.
                        </p>
                        <p v-if="validatorIsMember" class="mt-1 text-xs text-amber-700">
                            Le validateur est aussi missionnaire de cet OM : le contrôle le signalera.
                        </p>
                    </div>
                    <div>
                        <label for="validated_at" class="mb-1 block text-sm font-medium">Date de validation</label>
                        <input id="validated_at" v-model="form.validated_at" type="datetime-local" class="field" />
                    </div>
                    <div class="sm:col-span-2 lg:col-span-4">
                        <label for="notes" class="mb-1 block text-sm font-medium">Observations</label>
                        <textarea id="notes" v-model="form.notes" rows="2" class="field" />
                    </div>
                </div>
                <p v-if="form.type === 'AFFECTATION'" class="rounded-md bg-sky-50 px-3 py-2 text-sm text-sky-900">
                    OM d'affectation : à l'enregistrement, la destination devient l'affectation de chaque missionnaire, à la date de départ.
                </p>
            </section>

            <section class="card space-y-4 p-5">
                <div>
                    <h2 class="text-lg font-semibold">Transport</h2>
                    <p class="text-sm text-slate-600">Étapes dans l'ordre du trajet. Sans étape, l'OM n'a aucun moyen de transport.</p>
                </div>
                <div v-for="direction in ['OUTBOUND', 'RETURN'] as const" :key="direction" class="space-y-2">
                    <h3 class="font-medium">{{ direction === 'OUTBOUND' ? 'Aller' : 'Retour' }}</h3>
                    <div v-if="legs[direction].length" class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-slate-600">
                                <tr>
                                    <th class="py-1 pr-2 font-medium">N°</th>
                                    <th class="py-1 pr-2 font-medium">Moyen</th>
                                    <th class="py-1 pr-2 font-medium">De</th>
                                    <th class="py-1 pr-2 font-medium">À</th>
                                    <th class="py-1 pr-2 font-medium">Frais (Ar)</th>
                                    <th class="py-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="(leg, index) in legs[direction]" :key="leg.key">
                                    <tr>
                                        <td class="py-1 pr-2 text-slate-500">{{ index + 1 }}</td>
                                        <td class="py-1 pr-2">
                                            <select
                                                v-model="leg.transport_mode_id"
                                                class="field min-w-40"
                                                :aria-label="`Moyen de l'étape ${index + 1}`"
                                            >
                                                <option :value="null">Non renseigné</option>
                                                <option v-for="m in referentials?.transport_modes" :key="m.id" :value="m.id">{{ m.label }}</option>
                                            </select>
                                        </td>
                                        <td class="py-1 pr-2">
                                            <select v-model="leg.from_place_id" class="field min-w-36" :aria-label="`Départ de l'étape ${index + 1}`">
                                                <option :value="null">—</option>
                                                <option v-for="p in places" :key="p.id" :value="p.id">{{ p.name }}</option>
                                            </select>
                                        </td>
                                        <td class="py-1 pr-2">
                                            <select v-model="leg.to_place_id" class="field min-w-36" :aria-label="`Arrivée de l'étape ${index + 1}`">
                                                <option :value="null">—</option>
                                                <option v-for="p in places" :key="p.id" :value="p.id">{{ p.name }}</option>
                                            </select>
                                        </td>
                                        <td class="py-1 pr-2">
                                            <input
                                                v-model="leg.cost"
                                                inputmode="numeric"
                                                class="field w-28 text-right"
                                                :aria-label="`Frais de l'étape ${index + 1}`"
                                            />
                                        </td>
                                        <td class="py-1 whitespace-nowrap">
                                            <button
                                                type="button"
                                                class="rounded px-2 py-1 text-slate-500 hover:bg-slate-100"
                                                :disabled="index === 0"
                                                aria-label="Monter l'étape"
                                                @click="moveLeg(direction, index, -1)"
                                            >
                                                ↑
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded px-2 py-1 text-slate-500 hover:bg-slate-100"
                                                :disabled="index === legs[direction].length - 1"
                                                aria-label="Descendre l'étape"
                                                @click="moveLeg(direction, index, 1)"
                                            >
                                                ↓
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded px-2 py-1 text-red-700 hover:bg-red-50"
                                                aria-label="Retirer l'étape"
                                                @click="legs[direction].splice(index, 1)"
                                            >
                                                ✕
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="modeGeneratesCosts(leg.transport_mode_id) === false && leg.cost.trim() !== ''">
                                        <td></td>
                                        <td colspan="5" class="pb-2 text-xs text-amber-700">
                                            Ce moyen n'engendre pas de frais de transport : le contrôle signalera ce montant.
                                        </td>
                                    </tr>
                                    <tr v-for="error in legErrors(direction, index)" :key="error">
                                        <td></td>
                                        <td colspan="5" class="pb-2 text-sm text-red-700">{{ error }}</td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="text-sm text-slate-500">Aucune étape.</p>
                    <button type="button" class="btn-secondary" @click="addLeg(direction)">
                        Ajouter une étape {{ direction === 'OUTBOUND' ? "à l'aller" : 'au retour' }}
                    </button>
                </div>
            </section>

            <section class="card space-y-4 p-5">
                <h2 class="text-lg font-semibold">Frais et indemnité</h2>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label for="additional_costs" class="mb-1 block text-sm font-medium">Frais supplémentaires (Ar)</label>
                        <input id="additional_costs" v-model="form.additional_costs" inputmode="numeric" class="field" />
                        <p class="mt-1 text-xs text-slate-500">Montant global de l'OM, quel que soit le nombre de missionnaires.</p>
                        <FieldError :messages="errors.additional_costs" />
                    </div>
                    <div>
                        <label for="allowance" class="mb-1 block text-sm font-medium">Indemnité d'affectation (Ar)</label>
                        <input id="allowance" v-model="form.assignment_allowance" inputmode="numeric" class="field" />
                        <p v-if="form.type !== 'AFFECTATION' && form.assignment_allowance.trim() !== ''" class="mt-1 text-xs text-amber-700">
                            L'indemnité ne concerne que les OM d'affectation : le contrôle signalera ce montant.
                        </p>
                        <FieldError :messages="errors.assignment_allowance" />
                    </div>
                </div>

                <div class="space-y-2">
                    <h3 class="font-medium">Ventilation des frais supplémentaires (facultative)</h3>
                    <div v-for="(cost, index) in costs" :key="cost.key" class="flex flex-wrap items-start gap-2">
                        <select v-model="cost.nature" class="field w-52" :aria-label="`Nature du frais ${index + 1}`">
                            <option v-for="n in referentials?.cost_natures" :key="n.value" :value="n.value">{{ n.label }}</option>
                        </select>
                        <input v-model="cost.label" class="field w-60" placeholder="Précision (facultative)" :aria-label="`Précision du frais ${index + 1}`" />
                        <input v-model="cost.amount" inputmode="numeric" class="field w-36 text-right" placeholder="Montant" :aria-label="`Montant du frais ${index + 1}`" />
                        <button type="button" class="rounded px-2 py-2 text-red-700 hover:bg-red-50" aria-label="Retirer le frais" @click="costs.splice(index, 1)">
                            ✕
                        </button>
                        <FieldError class="w-full" :messages="errors[`costs.${index}.amount`]" />
                    </div>
                    <p v-if="costs.length" class="text-sm text-slate-600">
                        Somme de la ventilation : {{ formatAmount(costsSum) }} Ar
                        <span v-if="parseInteger(form.additional_costs) !== costsSum" class="text-amber-700">
                            (montant global : {{ form.additional_costs.trim() === '' ? 'non renseigné' : `${formatAmount(Number(parseInteger(form.additional_costs)) || 0)} Ar` }})
                        </span>
                    </p>
                    <button type="button" class="btn-secondary" @click="costs.push({ key: key(), nature: 'TRANSPORT', label: '', amount: '' })">
                        Ajouter une ligne
                    </button>
                </div>
            </section>

            <section class="card space-y-4 p-5">
                <div>
                    <h2 class="text-lg font-semibold">Missionnaires</h2>
                    <p class="text-sm text-slate-600">Quantités de repas et de nuitées attribuées par le responsable, réparties par zone.</p>
                </div>

                <div class="flex flex-wrap items-center gap-1 border-b border-slate-200">
                    <button
                        v-for="(member, index) in members"
                        :key="member.key"
                        type="button"
                        class="-mb-px border-b-2 px-3 py-2 text-sm"
                        :class="[
                            index === activeMember ? 'border-sky-700 font-medium text-sky-800' : 'border-transparent text-slate-600 hover:text-slate-900',
                            memberHasErrors(index) ? 'text-red-700' : '',
                        ]"
                        @click="activeMember = index"
                    >
                        {{ employeeName(member.employee) }}
                    </button>
                </div>

                <div class="max-w-md">
                    <label for="add-member" class="mb-1 block text-sm font-medium">Ajouter un missionnaire</label>
                    <EmployeePicker input-id="add-member" :exclude="memberIds" @select="addMember" />
                </div>

                <p v-if="members.length === 0" class="text-sm text-slate-500">Aucun missionnaire pour l'instant.</p>

                <template v-for="(member, index) in members" :key="member.key">
                    <div v-if="index === activeMember" class="space-y-4 rounded-md border border-slate-200 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="text-sm">
                                <p class="font-medium">
                                    {{ employeeName(member.employee) }} <span class="font-mono text-xs text-slate-500">{{ member.employee.matricule }}</span>
                                </p>
                                <p class="text-slate-600">
                                    Fiche : {{ member.employee.category?.label ?? 'catégorie manquante' }} ·
                                    {{ member.employee.current_place?.name ?? 'affectation non renseignée' }}
                                </p>
                                <p v-if="!member.employee.is_active" class="text-amber-700">Fiche employé désactivée.</p>
                            </div>
                            <button type="button" class="btn-danger" @click="removeMember(index)">Retirer de l'OM</button>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <label :for="`category-${member.key}`" class="mb-1 block text-sm font-medium">Catégorie retenue sur l'OM</label>
                                <select :id="`category-${member.key}`" v-model="member.category_id" class="field">
                                    <option :value="null">Non renseignée</option>
                                    <option v-for="c in referentials?.categories" :key="c.id" :value="c.id">{{ c.label }}</option>
                                </select>
                                <p v-if="member.category_id !== (member.employee.category?.id ?? null)" class="mt-1 text-xs text-amber-700">
                                    Différente de la fiche employé ({{ member.employee.category?.label ?? 'non renseignée' }}).
                                </p>
                            </div>
                            <div>
                                <label :for="`declared-${member.key}`" class="mb-1 block text-sm font-medium">Per diem déclaré (Ar)</label>
                                <input :id="`declared-${member.key}`" v-model="member.declared_per_diem" inputmode="numeric" class="field" />
                            </div>
                            <div v-if="member.saved" class="text-sm lg:col-span-2">
                                <p class="mb-1 font-medium">Per diem calculé au dernier enregistrement</p>
                                <p>
                                    {{ formatAmount(member.saved.calculated_per_diem) }} Ar
                                    <span v-if="member.saved.calculation_complete === false" class="text-amber-700">(calcul incomplet)</span>
                                </p>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="text-slate-600">
                                    <tr>
                                        <th class="py-1 pr-4 text-left font-medium">Consommation</th>
                                        <th v-for="zone in zones" :key="zone.id" class="px-2 py-1 text-right font-medium">{{ zone.label }}</th>
                                        <th class="px-2 py-1 text-right font-medium">Total déclaré</th>
                                        <th class="px-2 py-1 text-right font-medium">Somme des zones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="type in types" :key="type.value">
                                        <td class="py-1 pr-4 whitespace-nowrap">{{ type.label }}</td>
                                        <td v-for="zone in zones" :key="zone.id" class="px-2 py-1">
                                            <input
                                                v-model="member.quantities[cellKey(type.value, zone.id)]"
                                                inputmode="numeric"
                                                class="field ml-auto w-20 text-right"
                                                :aria-label="`${type.label}, ${zone.label}`"
                                            />
                                        </td>
                                        <td class="px-2 py-1">
                                            <input
                                                v-model="member.totals[type.value]"
                                                inputmode="numeric"
                                                class="field ml-auto w-20 text-right"
                                                :aria-label="`${type.label}, total déclaré`"
                                            />
                                        </td>
                                        <td
                                            class="px-2 py-1 text-right"
                                            :class="{
                                                'text-amber-700':
                                                    (member.totals[type.value] ?? '').trim() !== '' &&
                                                    parseInteger(member.totals[type.value]) !== zoneSum(member, type.value),
                                            }"
                                        >
                                            {{ zoneSum(member, type.value) ?? '' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p class="mt-1 text-xs text-slate-500">
                                Laissez vide une quantité non renseignée sur le document. Catégorie {{ categoryLabel(member.category_id) || 'non renseignée' }}.
                            </p>
                        </div>

                        <ul v-if="memberErrors(index).length" class="space-y-1 text-sm text-red-700">
                            <li v-for="error in memberErrors(index)" :key="error">{{ error }}</li>
                        </ul>
                    </div>
                </template>
            </section>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="btn-primary" :disabled="submitting">Enregistrer l'OM</button>
                <RouterLink :to="orderId ? `/om/${orderId}` : '/om'" class="btn-secondary">Annuler</RouterLink>
                <span v-if="message" class="text-sm text-red-700">{{ message }}</span>
            </div>
        </form>
    </div>
</template>
