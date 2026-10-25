<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { errorsFrom, http } from '@/api';
import BarList, { type BarItem } from '@/components/BarList.vue';
import DownloadButton from '@/components/DownloadButton.vue';
import { formatAmount } from '@/format';
import type { Breakdown, DashboardFigures } from '@/types';

const route = useRoute();
const router = useRouter();

// La période porte sur la date de départ des OM ; elle vit dans l'URL.
const period = reactive({
    departure_from: String(route.query.departure_from ?? ''),
    departure_to: String(route.query.departure_to ?? ''),
});
const figures = ref<DashboardFigures | null>(null);
const criteria = ref<string[]>([]);
const loading = ref(true);
const invalid = ref('');

function iso(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

const today = new Date();
const presets = [
    { label: 'Tout', from: '', to: '' },
    { label: 'Ce mois-ci', from: iso(new Date(today.getFullYear(), today.getMonth(), 1)), to: iso(new Date(today.getFullYear(), today.getMonth() + 1, 0)) },
    { label: 'Mois précédent', from: iso(new Date(today.getFullYear(), today.getMonth() - 1, 1)), to: iso(new Date(today.getFullYear(), today.getMonth(), 0)) },
    { label: 'Cette année', from: iso(new Date(today.getFullYear(), 0, 1)), to: iso(new Date(today.getFullYear(), 11, 31)) },
];

function params(): Record<string, string> {
    return Object.fromEntries(Object.entries(period).filter(([, value]) => value !== ''));
}

/** Lien vers la liste des OM de la période, avec un critère de plus. */
function orders(extra: Record<string, string> = {}): { path: string; query: Record<string, string> } {
    return { path: '/om', query: { ...params(), ...extra } };
}

async function load(): Promise<void> {
    loading.value = true;
    invalid.value = '';
    void router.replace({ query: params() });
    try {
        const { data } = await http.get<{ data: DashboardFigures; criteria: string[] }>('/api/dashboard', { params: params() });
        figures.value = data.data;
        criteria.value = data.criteria;
    } catch (error) {
        const { errors, message } = errorsFrom(error);
        invalid.value = Object.values(errors)[0]?.[0] ?? message;
    } finally {
        loading.value = false;
    }
}

let timer: ReturnType<typeof setTimeout> | undefined;
watch(period, () => {
    clearTimeout(timer);
    timer = setTimeout(load, 250);
});
onMounted(load);

function bars(rows: Breakdown[], key: string): BarItem[] {
    return rows.map((row) => ({
        key: row.value ?? 'none',
        label: row.label,
        count: row.count,
        to: row.value ? orders({ [key]: row.value }) : undefined,
    }));
}

const statusCount = (value: string): number => figures.value?.orders.by_administrative_status.find((row) => row.value === value)?.count ?? 0;
const categories = computed<BarItem[]>(() =>
    (figures.value?.anomalies.by_category ?? []).map((row) => ({
        key: row.value,
        label: `${row.value}. ${row.label}`,
        count: row.count,
        note: row.count ? `${row.open} à traiter` : '',
    })),
);

/** « 1 missionnaire », « 2 missionnaires », « 3 calculs complets ». */
function plural(count: number, singular: string, pluralForm = `${singular}s`): string {
    return `${formatAmount(count)} ${count > 1 ? pluralForm : singular}`;
}
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Tableau de bord</h1>
                <p class="mt-1 text-sm text-slate-600">Indicateurs calculés sur les OM enregistrés, hors OM supprimés.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <DownloadButton url="/api/reports/summary" label="Récapitulatif PDF" :params="params" />
                <DownloadButton url="/api/exports/mission-orders" label="Exporter en Excel" :params="params" />
            </div>
        </div>

        <div class="card flex flex-wrap items-center gap-x-4 gap-y-3 p-4 text-sm">
            <div class="flex flex-wrap gap-1" role="group" aria-label="Période prédéfinie">
                <button
                    v-for="preset in presets"
                    :key="preset.label"
                    type="button"
                    class="rounded-md px-3 py-1.5"
                    :class="
                        period.departure_from === preset.from && period.departure_to === preset.to
                            ? 'bg-sky-100 font-medium text-sky-900'
                            : 'text-slate-600 hover:bg-slate-100'
                    "
                    @click="Object.assign(period, { departure_from: preset.from, departure_to: preset.to })"
                >
                    {{ preset.label }}
                </button>
            </div>
            <div class="flex min-w-0 flex-wrap items-center gap-2">
                <label for="period-from" class="text-slate-600">Départ du</label>
                <input id="period-from" v-model="period.departure_from" type="date" class="field w-auto min-w-0" />
                <label for="period-to" class="text-slate-600">au</label>
                <input id="period-to" v-model="period.departure_to" type="date" class="field w-auto min-w-0" />
            </div>
        </div>
        <p v-if="invalid" class="text-sm text-red-700" role="alert">{{ invalid }}</p>

        <template v-if="figures">
            <p class="text-sm text-slate-600" :class="{ 'opacity-60': loading }">
                {{ criteria.length ? criteria.join(' ; ') : 'Tous les OM enregistrés, quelle que soit leur date de départ.' }}
            </p>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" :class="{ 'opacity-60': loading }">
                <RouterLink :to="orders()" class="card block p-4 hover:border-sky-300">
                    <p class="text-sm text-slate-600">OM enregistrés</p>
                    <p class="mt-1 text-3xl font-semibold">{{ formatAmount(figures.orders.total) }}</p>
                </RouterLink>
                <RouterLink :to="orders({ administrative_status: 'PENDING' })" class="card block p-4 hover:border-sky-300">
                    <p class="text-sm text-slate-600">En attente de validation</p>
                    <p class="mt-1 text-3xl font-semibold">{{ formatAmount(statusCount('PENDING')) }}</p>
                </RouterLink>
                <RouterLink :to="orders({ administrative_status: 'VALIDATED' })" class="card block p-4 hover:border-sky-300">
                    <p class="text-sm text-slate-600">Validés</p>
                    <p class="mt-1 text-3xl font-semibold">{{ formatAmount(statusCount('VALIDATED')) }}</p>
                </RouterLink>
                <div class="card p-4">
                    <RouterLink :to="orders({ anomalies: 'any' })" class="block hover:text-sky-800">
                        <p class="text-sm text-slate-600">Présentant des anomalies</p>
                        <p class="mt-1 text-3xl font-semibold">{{ formatAmount(figures.orders.with_anomalies) }}</p>
                    </RouterLink>
                    <RouterLink :to="orders({ anomalies: 'open' })" class="mt-1 block text-xs text-sky-700 hover:underline">
                        dont {{ formatAmount(figures.orders.with_open_anomalies) }} avec anomalies à traiter
                    </RouterLink>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" :class="{ 'opacity-60': loading }">
                <div class="card p-4">
                    <p class="text-sm text-slate-600">Per diem calculés</p>
                    <p class="mt-1 text-2xl font-semibold">{{ figures.per_diem.complete ? `${formatAmount(figures.per_diem.calculated)} Ar` : '—' }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ plural(figures.per_diem.complete, 'calcul complet', 'calculs complets') }}</p>
                    <p v-if="figures.per_diem.incomplete" class="mt-1 text-xs text-amber-800">
                        {{ plural(figures.per_diem.incomplete, 'calcul incomplet', 'calculs incomplets') }} (tarif manquant ou quantité invalide), non compté{{ figures.per_diem.incomplete > 1 ? 's' : '' }}
                    </p>
                    <p v-if="figures.per_diem.not_calculated" class="mt-1 text-xs text-slate-500">{{ plural(figures.per_diem.not_calculated, 'missionnaire') }} sans calcul</p>
                </div>
                <div class="card p-4">
                    <p class="text-sm text-slate-600">Per diem déclarés</p>
                    <p class="mt-1 text-2xl font-semibold">{{ figures.per_diem.declared_count ? `${formatAmount(figures.per_diem.declared)} Ar` : '—' }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ plural(figures.per_diem.declared_count, 'missionnaire') }}</p>
                    <p v-if="figures.per_diem.mismatches" class="mt-1 text-xs text-amber-800">
                        {{ plural(figures.per_diem.mismatches, 'écart') }} avec le calcul, cumul déclaré − calculé : {{ formatAmount(figures.per_diem.gap) }} Ar
                    </p>
                </div>
                <div class="card p-4">
                    <p class="text-sm text-slate-600">Frais supplémentaires</p>
                    <p class="mt-1 text-2xl font-semibold">{{ figures.costs.orders_with_costs ? `${formatAmount(figures.costs.additional_costs)} Ar` : '—' }}</p>
                    <p class="mt-1 text-xs text-slate-500">Montants globaux déclarés sur {{ figures.costs.orders_with_costs }} OM</p>
                </div>
                <div class="card p-4">
                    <p class="text-sm text-slate-600">Indemnités d'affectation</p>
                    <p class="mt-1 text-2xl font-semibold">{{ figures.costs.orders_with_allowance ? `${formatAmount(figures.costs.assignment_allowance)} Ar` : '—' }}</p>
                    <p class="mt-1 text-xs text-slate-500">Déclarées sur {{ figures.costs.orders_with_allowance }} OM</p>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-3" :class="{ 'opacity-60': loading }">
                <section class="card space-y-3 p-4">
                    <h2 class="font-semibold">OM par type</h2>
                    <BarList :items="bars(figures.orders.by_type, 'type')" unit="OM" />
                </section>
                <section class="card space-y-3 p-4">
                    <h2 class="font-semibold">État de validation</h2>
                    <BarList :items="bars(figures.orders.by_administrative_status, 'administrative_status')" unit="OM" />
                </section>
                <section class="card space-y-3 p-4">
                    <h2 class="font-semibold">Contrôle</h2>
                    <BarList :items="bars(figures.orders.by_control_status, 'control_status')" unit="OM" />
                </section>
            </div>

            <section class="card space-y-3 p-4" :class="{ 'opacity-60': loading }">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="font-semibold">Répartition des anomalies par catégorie</h2>
                    <RouterLink to="/anomalies" class="text-sm text-sky-700 hover:underline">Anomalies à traiter</RouterLink>
                </div>
                <p class="text-xs text-slate-500">
                    {{ plural(figures.anomalies.total, 'anomalie') }} retenue{{ figures.anomalies.total > 1 ? 's' : '' }}, dont {{ figures.anomalies.open }} à traiter.
                    Une anomalie retenue est à traiter, ou clôturée après confirmation ; les anomalies écartées n'y figurent pas.
                </p>
                <BarList :items="categories" unit="anomalies retenues" />
            </section>
        </template>
        <p v-else-if="loading" class="text-sm text-slate-500">Chargement…</p>
    </div>
</template>
