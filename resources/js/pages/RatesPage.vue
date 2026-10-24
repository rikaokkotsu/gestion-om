<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { errorsFrom, http } from '@/api';
import FieldError from '@/components/FieldError.vue';
import { formatAmount, formatDate } from '@/format';
import { useReferentials } from '@/referentials';
import type { ConsumptionTypeCode, PerDiemResult, Rate, RateVersion, ValidationErrors } from '@/types';

interface Cell {
    amount: string;
    is_applicable: boolean;
}

const { referentials, load: loadReferentials } = useReferentials();
const zones = computed(() => referentials.value?.zones ?? []);
const categories = computed(() => referentials.value?.categories ?? []);
const types = computed(() => referentials.value?.consumption_types ?? []);

const activeTab = ref<'grids' | 'simulator'>('grids');
const versions = ref<RateVersion[]>([]);
const selected = ref<RateVersion | null>(null);
const message = ref('');

function today(): string {
    const now = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

function key(categoryId: number, zoneId: number, type: ConsumptionTypeCode): string {
    return `${categoryId}|${zoneId}|${type}`;
}

function isCurrent(version: RateVersion): boolean {
    const date = today();
    return (version.effective_from === null || version.effective_from <= date) && (version.effective_to === null || version.effective_to >= date);
}

function period(version: RateVersion): string {
    if (version.effective_from === null) {
        return version.effective_to ? `jusqu'au ${formatDate(version.effective_to)}` : 'toutes dates';
    }
    return version.effective_to
        ? `du ${formatDate(version.effective_from)} au ${formatDate(version.effective_to)}`
        : `depuis le ${formatDate(version.effective_from)}`;
}

const rates = computed(() => {
    const map = new Map<string, Rate>();
    for (const rate of selected.value?.rates ?? []) {
        map.set(key(rate.category_id, rate.zone_id, rate.consumption_type), rate);
    }
    return map;
});

async function loadVersions(): Promise<void> {
    const { data } = await http.get<{ data: RateVersion[] }>('/api/rate-versions');
    versions.value = data.data;
}

async function select(id: number): Promise<void> {
    editing.value = false;
    confirmDelete.value = false;
    message.value = '';
    const { data } = await http.get<{ data: RateVersion }>(`/api/rate-versions/${id}`);
    selected.value = data.data;
}

// --- Modification d'une version ---

const editing = ref(false);
const confirmDelete = ref(false);
const draft = reactive({ label: '', effective_from: '' });
const cells = reactive<Record<string, Cell>>({});
const errors = ref<ValidationErrors>({});
const cellErrors = ref<Record<string, string>>({});

function startEdit(): void {
    if (!selected.value) {
        return;
    }
    errors.value = {};
    cellErrors.value = {};
    draft.label = selected.value.label;
    draft.effective_from = selected.value.effective_from ?? '';
    for (const category of categories.value) {
        for (const zone of zones.value) {
            for (const type of types.value) {
                const rate = rates.value.get(key(category.id, zone.id, type.value));
                cells[key(category.id, zone.id, type.value)] = {
                    amount: rate?.amount === null || rate?.amount === undefined ? '' : String(rate.amount),
                    is_applicable: rate?.is_applicable ?? true,
                };
            }
        }
    }
    editing.value = true;
}

/** Tarifs modifiés par rapport à la version enregistrée ; un montant illisible est envoyé tel quel pour être refusé. */
function changedRates(): { key: string; payload: Record<string, unknown> }[] {
    const changes = [];
    for (const [cellKey, cell] of Object.entries(cells)) {
        const [categoryId, zoneId, type] = cellKey.split('|') as [string, string, ConsumptionTypeCode];
        const raw = cell.amount.replace(/\s/g, '');
        const amount = raw === '' ? null : /^\d+$/.test(raw) ? Number(raw) : raw;
        const rate = rates.value.get(cellKey);
        const before = { amount: rate?.amount ?? null, is_applicable: rate?.is_applicable ?? true };
        const after = { amount: cell.is_applicable ? amount : null, is_applicable: cell.is_applicable };
        if (!rate || before.amount !== after.amount || before.is_applicable !== after.is_applicable) {
            changes.push({
                key: cellKey,
                payload: { category_id: Number(categoryId), zone_id: Number(zoneId), consumption_type: type, ...after },
            });
        }
    }
    return changes;
}

async function save(): Promise<void> {
    if (!selected.value) {
        return;
    }
    errors.value = {};
    cellErrors.value = {};
    message.value = '';

    const payload: Record<string, unknown> = { label: draft.label };
    const changes = selected.value.is_locked ? [] : changedRates();
    if (!selected.value.is_locked) {
        payload.rates = changes.map((change) => change.payload);
        if (selected.value.effective_from !== null) {
            payload.effective_from = draft.effective_from;
        }
    }

    try {
        const { data } = await http.patch<{ data: RateVersion }>(`/api/rate-versions/${selected.value.id}`, payload);
        await loadVersions();
        selected.value = data.data;
        editing.value = false;
    } catch (error) {
        const result = errorsFrom(error);
        errors.value = result.errors;
        for (const [field, messages] of Object.entries(result.errors)) {
            const match = /^rates\.(\d+)\./.exec(field);
            const change = match ? changes[Number(match[1])] : undefined;
            if (change) {
                cellErrors.value[change.key] = messages[0] ?? '';
            }
        }
        message.value = Object.keys(result.errors).length ? 'Certains tarifs ne sont pas valides : un montant est un nombre entier d\'ariary.' : result.message;
    }
}

async function remove(): Promise<void> {
    if (!selected.value) {
        return;
    }
    try {
        await http.delete(`/api/rate-versions/${selected.value.id}`);
        selected.value = null;
        await loadVersions();
        const current = versions.value.find(isCurrent);
        if (current) {
            await select(current.id);
        }
    } catch (error) {
        message.value = errorsFrom(error).message;
    }
}

// --- Nouvelle version ---

const creating = ref(false);
const newVersion = reactive<{ label: string; effective_from: string; copy_from_id: number | null }>({
    label: '',
    effective_from: '',
    copy_from_id: null,
});
const createErrors = ref<ValidationErrors>({});

function startCreate(): void {
    createErrors.value = {};
    Object.assign(newVersion, { label: '', effective_from: '', copy_from_id: null });
    creating.value = true;
}

async function create(): Promise<void> {
    createErrors.value = {};
    try {
        const { data } = await http.post<{ data: RateVersion }>('/api/rate-versions', newVersion);
        creating.value = false;
        await loadVersions();
        await select(data.data.id);
    } catch (error) {
        createErrors.value = errorsFrom(error).errors;
    }
}

// --- Simulateur ---

const simulation = reactive<{ category_id: number | null; date: string; rate_version_id: number | null }>({
    category_id: null,
    date: today(),
    rate_version_id: null,
});
const quantities = reactive<Record<string, number>>({});
const result = ref<PerDiemResult | null>(null);
const simulationErrors = ref<ValidationErrors>({});

function quantityKey(zoneId: number, type: ConsumptionTypeCode): string {
    return `${zoneId}|${type}`;
}

function clearQuantities(): void {
    for (const zone of zones.value) {
        for (const type of types.value) {
            quantities[quantityKey(zone.id, type.value)] = 0;
        }
    }
    result.value = null;
}

/** Exemple du cahier des charges : R HC3, 3 jours en zone Nord puis 2 jours ailleurs, 503 000 Ar. */
function fillExample(): void {
    clearQuantities();
    simulation.category_id = categories.value.find((c) => c.code === 'R_HC3')?.id ?? null;
    simulation.rate_version_id = null;
    const example: Record<string, Partial<Record<ConsumptionTypeCode, number>>> = {
        NORD: { PETIT_DEJ: 3, DEJEUNER: 2, DINER: 2, NUITEE: 2 },
        AUTRES: { PETIT_DEJ: 2, DEJEUNER: 2, DINER: 2, NUITEE: 2 },
    };
    for (const zone of zones.value) {
        for (const [type, quantity] of Object.entries(example[zone.code] ?? {})) {
            quantities[quantityKey(zone.id, type as ConsumptionTypeCode)] = quantity;
        }
    }
}

async function simulate(): Promise<void> {
    simulationErrors.value = {};
    const lines = Object.entries(quantities)
        .filter(([, quantity]) => Number(quantity) !== 0)
        .map(([quantityKey, quantity]) => {
            const [zoneId, type] = quantityKey.split('|');
            return { zone_id: Number(zoneId), consumption_type: type, quantity: Number(quantity) };
        });

    try {
        const { data } = await http.post<{ data: PerDiemResult }>('/api/per-diem/simulate', {
            ...simulation,
            date: simulation.date || null,
            quantities: lines,
        });
        result.value = data.data;
    } catch (error) {
        const { errors } = errorsFrom(error);
        simulationErrors.value = errors;
        if (Object.keys(errors).some((field) => field.startsWith('quantities.'))) {
            simulationErrors.value.quantities = ['Les quantités sont des nombres entiers positifs.'];
        }
        result.value = null;
    }
}

const statusLabels: Record<string, string> = {
    ok: '',
    missing_rate: 'Tarif non renseigné',
    not_applicable: 'Sans objet',
    invalid_quantity: 'Quantité invalide',
};

function zoneLabel(code: string): string {
    return zones.value.find((zone) => zone.code === code)?.label ?? code;
}

onMounted(async () => {
    await Promise.all([loadReferentials(true), loadVersions()]);
    clearQuantities();
    const current = versions.value.find(isCurrent) ?? versions.value[0];
    if (current) {
        await select(current.id);
    }
});
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold">Barèmes de per diem</h1>
            <p class="mt-1 text-sm text-slate-600">
                Tarifs par catégorie et par zone, en ariary. Chaque version s'applique à partir de sa date d'effet ; le calcul retient
                celle en vigueur à la date de départ de la mission.
            </p>
        </div>

        <div class="flex flex-wrap gap-1 border-b border-slate-200">
            <button
                v-for="tab in [
                    { key: 'grids', label: 'Versions et tarifs' },
                    { key: 'simulator', label: 'Simulateur de calcul' },
                ] as const"
                :key="tab.key"
                type="button"
                class="-mb-px border-b-2 px-3 py-2 text-sm"
                :class="tab.key === activeTab ? 'border-sky-700 font-medium text-sky-800' : 'border-transparent text-slate-600 hover:text-slate-900'"
                @click="activeTab = tab.key"
            >
                {{ tab.label }}
            </button>
        </div>

        <template v-if="activeTab === 'grids'">
            <div class="card overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th class="px-4 py-2 font-medium">Version</th>
                            <th class="px-4 py-2 font-medium">Période d'application</th>
                            <th class="px-4 py-2 font-medium">Tarifs manquants</th>
                            <th class="px-4 py-2 font-medium">État</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr
                            v-for="version in versions"
                            :key="version.id"
                            class="cursor-pointer hover:bg-slate-50"
                            :class="{ 'bg-sky-50/60': selected?.id === version.id }"
                            @click="select(version.id)"
                        >
                            <td class="px-4 py-2 font-medium">
                                {{ version.label }}
                                <span v-if="isCurrent(version)" class="ml-2 rounded bg-sky-100 px-2 py-0.5 text-xs font-normal text-sky-800">
                                    en vigueur
                                </span>
                            </td>
                            <td class="px-4 py-2">{{ period(version) }}</td>
                            <td class="px-4 py-2">
                                <span v-if="version.missing_count" class="rounded bg-amber-50 px-2 py-0.5 text-amber-800">
                                    {{ version.missing_count }}
                                </span>
                                <span v-else class="text-slate-500">Aucun</span>
                            </td>
                            <td class="px-4 py-2">{{ version.is_locked ? 'Figée' : 'Modifiable' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="!creating" class="flex justify-end">
                <button type="button" class="btn-primary" @click="startCreate">Nouvelle version</button>
            </div>
            <form v-else class="card space-y-4 p-4" @submit.prevent="create">
                <h2 class="font-semibold">Nouvelle version du barème</h2>
                <div class="grid gap-4 md:grid-cols-3">
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">Libellé</span>
                        <input v-model="newVersion.label" class="field" placeholder="Barème 2027" />
                        <FieldError :messages="createErrors.label" />
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">Date d'effet</span>
                        <input v-model="newVersion.effective_from" type="date" class="field" />
                        <FieldError :messages="createErrors.effective_from" />
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">Tarifs de départ</span>
                        <select v-model="newVersion.copy_from_id" class="field">
                            <option :value="null">Ceux en vigueur à la date d'effet</option>
                            <option v-for="version in versions" :key="version.id" :value="version.id">Copie de : {{ version.label }}</option>
                        </select>
                    </label>
                </div>
                <p class="text-sm text-slate-600">Les tarifs sont copiés puis modifiables tant que la version n'a servi à aucun calcul enregistré.</p>
                <div class="flex gap-2">
                    <button type="submit" class="btn-primary">Créer la version</button>
                    <button type="button" class="btn-secondary" @click="creating = false">Annuler</button>
                </div>
            </form>

            <section v-if="selected" class="space-y-4">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div v-if="!editing">
                        <h2 class="text-lg font-semibold">{{ selected.label }}</h2>
                        <p class="text-sm text-slate-600">Applicable {{ period(selected) }}.</p>
                    </div>
                    <div v-else class="grid flex-1 gap-4 md:grid-cols-3">
                        <label class="block text-sm">
                            <span class="mb-1 block font-medium">Libellé</span>
                            <input v-model="draft.label" class="field" />
                            <FieldError :messages="errors.label" />
                        </label>
                        <label v-if="selected.effective_from !== null && !selected.is_locked" class="block text-sm">
                            <span class="mb-1 block font-medium">Date d'effet</span>
                            <input v-model="draft.effective_from" type="date" class="field" />
                            <FieldError :messages="errors.effective_from" />
                        </label>
                    </div>
                    <div class="flex gap-2">
                        <template v-if="editing">
                            <button type="button" class="btn-primary" @click="save">Enregistrer</button>
                            <button type="button" class="btn-secondary" @click="editing = false">Annuler</button>
                        </template>
                        <template v-else>
                            <button type="button" class="btn-secondary" @click="startEdit">
                                {{ selected.is_locked ? 'Renommer' : 'Modifier' }}
                            </button>
                            <template v-if="!selected.is_locked && selected.effective_from !== null">
                                <button v-if="!confirmDelete" type="button" class="btn-danger" @click="confirmDelete = true">Supprimer</button>
                                <button v-else type="button" class="btn-danger" @click="remove">Confirmer la suppression</button>
                            </template>
                        </template>
                    </div>
                </div>

                <p v-if="selected.is_locked" class="rounded-md bg-slate-100 px-3 py-2 text-sm text-slate-700">
                    Version figée : elle a servi à un calcul enregistré. Pour changer des tarifs, créez une nouvelle version.
                </p>
                <p v-else-if="editing" class="text-sm text-slate-600">
                    Laissez vide un tarif encore inconnu : il sera signalé au calcul, jamais compté à 0. Le bouton ⊘ marque une consommation
                    sans objet pour la catégorie.
                </p>
                <p v-if="message" class="text-sm text-red-700">{{ message }}</p>

                <div v-for="zone in zones" :key="zone.id" class="card overflow-x-auto">
                    <h3 class="border-b border-slate-200 px-4 py-2 font-medium">{{ zone.label }}</h3>
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium">Catégorie</th>
                                <th v-for="type in types" :key="type.value" class="px-4 py-2 text-right font-medium">{{ type.label }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="category in categories" :key="category.id">
                                <td class="px-4 py-2 whitespace-nowrap" :class="{ 'text-slate-400': !category.is_active }">
                                    {{ category.label }}
                                    <span v-if="!category.is_active" class="text-xs">(désactivée)</span>
                                </td>
                                <td v-for="type in types" :key="type.value" class="px-4 py-1.5 text-right whitespace-nowrap">
                                    <template v-if="editing && !selected.is_locked">
                                        <div class="flex items-center justify-end gap-1">
                                            <input
                                                v-if="cells[key(category.id, zone.id, type.value)]!.is_applicable"
                                                v-model="cells[key(category.id, zone.id, type.value)]!.amount"
                                                inputmode="numeric"
                                                :aria-label="`${type.label}, ${category.label}, ${zone.label}`"
                                                class="field w-24 px-2 py-1 text-right"
                                                :class="{
                                                    'border-amber-400 bg-amber-50': cells[key(category.id, zone.id, type.value)]!.amount === '',
                                                    'border-red-500': cellErrors[key(category.id, zone.id, type.value)],
                                                }"
                                                :title="cellErrors[key(category.id, zone.id, type.value)]"
                                            />
                                            <span v-else class="w-24 text-center text-xs text-slate-400">sans objet</span>
                                            <button
                                                type="button"
                                                class="rounded px-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                                                :title="cells[key(category.id, zone.id, type.value)]!.is_applicable ? 'Marquer sans objet' : 'Rétablir un tarif'"
                                                :aria-label="`${cells[key(category.id, zone.id, type.value)]!.is_applicable ? 'Marquer sans objet' : 'Rétablir un tarif'} : ${type.label}, ${category.label}, ${zone.label}`"
                                                @click="cells[key(category.id, zone.id, type.value)]!.is_applicable = !cells[key(category.id, zone.id, type.value)]!.is_applicable"
                                            >
                                                ⊘
                                            </button>
                                        </div>
                                    </template>
                                    <template v-else>
                                        <span v-if="rates.get(key(category.id, zone.id, type.value))?.is_applicable === false" class="text-xs text-slate-400">
                                            sans objet
                                        </span>
                                        <span
                                            v-else-if="(rates.get(key(category.id, zone.id, type.value))?.amount ?? null) === null"
                                            class="rounded bg-amber-50 px-2 py-0.5 text-xs text-amber-800"
                                        >
                                            non renseigné
                                        </span>
                                        <template v-else>{{ formatAmount(rates.get(key(category.id, zone.id, type.value))?.amount) }}</template>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>

        <template v-else>
            <form class="card space-y-4 p-4" @submit.prevent="simulate">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <p class="max-w-2xl text-sm text-slate-600">
                        Calcul à blanc, sans rien enregistrer : quantités attribuées par type et par zone, multipliées par le tarif de la
                        catégorie.
                    </p>
                    <button type="button" class="btn-secondary" @click="fillExample">Exemple du cahier des charges</button>
                </div>
                <div class="grid gap-4 md:grid-cols-3">
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">Catégorie du missionnaire</span>
                        <select v-model="simulation.category_id" class="field">
                            <option :value="null" disabled>Choisir…</option>
                            <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.label }}</option>
                        </select>
                        <FieldError :messages="simulationErrors.category_id" />
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">Date de départ</span>
                        <input v-model="simulation.date" type="date" class="field" :disabled="simulation.rate_version_id !== null" />
                        <FieldError :messages="simulationErrors.date" />
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">Barème</span>
                        <select v-model="simulation.rate_version_id" class="field">
                            <option :value="null">Celui en vigueur à la date de départ</option>
                            <option v-for="version in versions" :key="version.id" :value="version.id">{{ version.label }}</option>
                        </select>
                    </label>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-slate-600">
                            <tr>
                                <th class="py-2 pr-4 text-left font-medium">Quantités</th>
                                <th v-for="type in types" :key="type.value" class="px-2 py-2 text-right font-medium">{{ type.label }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="zone in zones" :key="zone.id">
                                <td class="py-1.5 pr-4 whitespace-nowrap">{{ zone.label }}</td>
                                <td v-for="type in types" :key="type.value" class="px-2 py-1.5">
                                    <input
                                        v-model.number="quantities[quantityKey(zone.id, type.value)]"
                                        type="number"
                                        min="0"
                                        :aria-label="`${type.label}, ${zone.label}`"
                                        class="field ml-auto w-20 px-2 py-1 text-right"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <FieldError :messages="simulationErrors.quantities" />
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="btn-primary">Calculer</button>
                    <button type="button" class="btn-secondary" @click="clearQuantities">Effacer</button>
                </div>
            </form>

            <section v-if="result" class="card space-y-4 p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-4">
                    <div>
                        <p class="text-sm text-slate-600">Per diem calculé</p>
                        <p class="text-2xl font-semibold">{{ formatAmount(result.total) }} Ar</p>
                        <p v-if="!result.complete" class="mt-1 text-sm text-amber-800">
                            Calcul incomplet : ce total ne compte que les lignes calculables.
                        </p>
                    </div>
                    <dl class="flex gap-6 text-sm">
                        <div v-for="(total, code) in result.zone_totals" :key="code">
                            <dt class="text-slate-600">{{ zoneLabel(String(code)) }}</dt>
                            <dd class="font-medium">{{ formatAmount(total) }} Ar</dd>
                        </div>
                    </dl>
                </div>
                <p class="text-sm text-slate-600">Barème utilisé : {{ result.rate_version?.label ?? 'aucun' }}</p>

                <ul v-if="result.issues.length" class="space-y-1 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <li v-for="issue in result.issues" :key="issue">{{ issue }}</li>
                </ul>

                <table v-if="result.lines.length" class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="text-left text-slate-600">
                        <tr>
                            <th class="py-2 pr-4 font-medium">Consommation</th>
                            <th class="py-2 pr-4 font-medium">Zone</th>
                            <th class="py-2 pr-4 text-right font-medium">Quantité</th>
                            <th class="py-2 pr-4 text-right font-medium">Tarif</th>
                            <th class="py-2 pr-4 text-right font-medium">Montant</th>
                            <th class="py-2 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="(line, index) in result.lines" :key="index">
                            <td class="py-1.5 pr-4">{{ line.consumption_label }}</td>
                            <td class="py-1.5 pr-4">{{ zoneLabel(line.zone_code) }}</td>
                            <td class="py-1.5 pr-4 text-right">{{ line.quantity }}</td>
                            <td class="py-1.5 pr-4 text-right">{{ formatAmount(line.rate) }}</td>
                            <td class="py-1.5 pr-4 text-right">{{ formatAmount(line.amount) }}</td>
                            <td class="py-1.5">
                                <span v-if="line.status !== 'ok'" class="rounded bg-amber-50 px-2 py-0.5 text-xs text-amber-800">
                                    {{ statusLabels[line.status] }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </template>
    </div>
</template>
