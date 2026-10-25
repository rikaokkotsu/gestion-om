<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { http } from '@/api';
import { priorityClasses, stateClasses } from '@/anomalies';
import ControlBatchPanel from '@/components/ControlBatchPanel.vue';
import DownloadButton from '@/components/DownloadButton.vue';
import ControlRulesPanel from '@/components/ControlRulesPanel.vue';
import { employeeName, formatTimestamp } from '@/format';
import { useReferentials } from '@/referentials';
import type { Anomaly, Paginated } from '@/types';

const route = useRoute();
const router = useRouter();
const { referentials, load: loadReferentials } = useReferentials();

const tabs = [
    { value: 'liste', label: 'Anomalies' },
    { value: 'regles', label: 'Règles de contrôle' },
    { value: 'lots', label: 'Contrôle par lot' },
] as const;
type Tab = (typeof tabs)[number]['value'];
const tab = ref<Tab>(tabs.some((entry) => entry.value === route.query.onglet) ? (route.query.onglet as Tab) : 'liste');

// Les filtres vivent dans l'URL ; sans état choisi, la liste montre les anomalies à traiter.
const query = (key: string): string => String(route.query[key] ?? '');
const filters = reactive({
    search: query('search'),
    state: query('state') || 'open',
    category: query('category'),
    priority: query('priority'),
    source: query('source'),
    rule_code: query('rule_code'),
    from: query('from'),
    to: query('to'),
    employee_id: query('employee_id'),
    mission_order_id: query('mission_order_id'),
    control_run_id: query('control_run_id'),
});
const page = ref(Number(route.query.page ?? 1));
const anomalies = ref<Anomaly[]>([]);
const total = ref(0);
const lastPage = ref(1);
const loading = ref(true);
const invalid = ref('');

const rules = computed(() => (referentials.value?.rules ?? []).filter((rule) => !filters.category || rule.category === filters.category));
const scoped = computed(() =>
    [
        filters.employee_id && 'un employé',
        filters.mission_order_id && 'un OM',
        filters.control_run_id && `le contrôle par lot n° ${filters.control_run_id}`,
    ].filter(Boolean),
);

function criteria(): Record<string, string> {
    return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));
}

function params(): Record<string, string> {
    return { ...criteria(), page: String(page.value) };
}

async function load(): Promise<void> {
    loading.value = true;
    invalid.value = '';
    void router.replace({ query: tab.value === 'liste' ? params() : { onglet: tab.value } });
    try {
        const { data } = await http.get<Paginated<Anomaly>>('/api/anomalies', { params: params() });
        anomalies.value = data.data;
        total.value = data.meta.total;
        lastPage.value = data.meta.last_page;
    } catch {
        invalid.value = 'Vérifiez les filtres : la période est peut-être inversée.';
    } finally {
        loading.value = false;
    }
}

function clearScope(): void {
    filters.employee_id = '';
    filters.mission_order_id = '';
    filters.control_run_id = '';
}

let timer: ReturnType<typeof setTimeout> | undefined;
watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        page.value = 1;
        void load();
    }, 250);
});
watch(page, load);
watch(tab, (value) => {
    if (value === 'liste') {
        void load();
    } else {
        void router.replace({ query: { onglet: value } });
    }
});

onMounted(async () => {
    await Promise.all([loadReferentials(), tab.value === 'liste' ? load() : Promise.resolve()]);
});
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold">Anomalies</h1>
            <p class="mt-1 text-sm text-slate-600">
                Situations à vérifier, proposées par le contrôle automatique ou signalées par un contrôleur. Une anomalie ne modifie jamais l'OM.
            </p>
        </div>

        <div class="flex gap-1 border-b border-slate-200" role="tablist">
            <button
                v-for="entry in tabs"
                :key="entry.value"
                type="button"
                role="tab"
                :aria-selected="tab === entry.value"
                class="-mb-px border-b-2 px-4 py-2 text-sm font-medium"
                :class="tab === entry.value ? 'border-sky-600 text-sky-800' : 'border-transparent text-slate-600 hover:text-slate-900'"
                @click="tab = entry.value"
            >
                {{ entry.label }}
            </button>
        </div>

        <template v-if="tab === 'liste'">
            <div class="card grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                <input v-model="filters.search" type="search" placeholder="Référence d'OM, missionnaire ou texte" class="field sm:col-span-2" />
                <select v-model="filters.state" class="field" aria-label="État">
                    <option value="open">À traiter</option>
                    <option value="all">Tous les états</option>
                    <option v-for="state in referentials?.anomaly_states" :key="state.value" :value="state.value">{{ state.label }}</option>
                </select>
                <select v-model="filters.priority" class="field" aria-label="Priorité">
                    <option value="">Toutes les priorités</option>
                    <option v-for="priority in referentials?.anomaly_priorities" :key="priority.value" :value="priority.value">{{ priority.label }}</option>
                </select>
                <select v-model="filters.category" class="field" aria-label="Catégorie" @change="filters.rule_code = ''">
                    <option value="">Toutes les catégories</option>
                    <option v-for="category in referentials?.anomaly_categories" :key="category.value" :value="category.value">
                        {{ category.value }}. {{ category.label }}
                    </option>
                </select>
                <select v-model="filters.rule_code" class="field sm:col-span-2" aria-label="Règle">
                    <option value="">Toutes les règles</option>
                    <option v-for="rule in rules" :key="rule.code" :value="rule.code">{{ rule.code }} · {{ rule.label }}</option>
                </select>
                <select v-model="filters.source" class="field" aria-label="Origine">
                    <option value="">Contrôle et signalements</option>
                    <option v-for="source in referentials?.anomaly_sources" :key="source.value" :value="source.value">{{ source.label }}</option>
                </select>
                <div class="flex min-w-0 items-center gap-2 text-sm sm:col-span-2">
                    <label for="filter-from" class="shrink-0 text-slate-600">Détectée du</label>
                    <input id="filter-from" v-model="filters.from" type="date" class="field min-w-0" />
                    <label for="filter-to" class="shrink-0 text-slate-600">au</label>
                    <input id="filter-to" v-model="filters.to" type="date" class="field min-w-0" />
                </div>
            </div>
            <p v-if="scoped.length" class="text-sm text-slate-600">
                Limité à {{ scoped.join(', ') }}.
                <button type="button" class="text-sky-700 hover:underline" @click="clearScope">Retirer</button>
            </p>
            <p v-if="invalid" class="text-sm text-red-700">{{ invalid }}</p>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-slate-600">{{ total }} anomalie{{ total > 1 ? 's' : '' }}, de la plus récente à la plus ancienne</p>
                <DownloadButton url="/api/exports/anomalies" label="Exporter en Excel" :params="criteria" />
            </div>
            <div class="card overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th class="px-4 py-2 font-medium">N°</th>
                            <th class="px-4 py-2 font-medium">Détectée le</th>
                            <th class="px-4 py-2 font-medium">OM</th>
                            <th class="px-4 py-2 font-medium">Concerne</th>
                            <th class="px-4 py-2 font-medium">Anomalie</th>
                            <th class="px-4 py-2 font-medium">Priorité</th>
                            <th class="px-4 py-2 font-medium">État</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-if="loading">
                            <td colspan="7" class="px-4 py-6 text-center text-slate-500">Chargement…</td>
                        </tr>
                        <tr v-else-if="anomalies.length === 0">
                            <td colspan="7" class="px-4 py-6 text-center text-slate-500">Aucune anomalie ne correspond.</td>
                        </tr>
                        <tr
                            v-for="anomaly in anomalies"
                            v-else
                            :key="anomaly.id"
                            class="cursor-pointer align-top hover:bg-slate-50"
                            @click="router.push(`/anomalies/${anomaly.id}`)"
                        >
                            <td class="px-4 py-2 text-slate-500">
                                <RouterLink :to="`/anomalies/${anomaly.id}`" @click.stop>{{ anomaly.id }}</RouterLink>
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap">{{ formatTimestamp(anomaly.detected_at) }}</td>
                            <td class="px-4 py-2 font-mono whitespace-nowrap">
                                <RouterLink :to="`/om/${anomaly.mission_order_id}`" class="text-sky-700 hover:underline" @click.stop>
                                    {{ anomaly.mission_order.reference ?? 'sans référence' }}
                                </RouterLink>
                            </td>
                            <td class="px-4 py-2">{{ anomaly.employee ? employeeName(anomaly.employee) : 'L\'OM' }}</td>
                            <td class="max-w-md px-4 py-2">
                                <p class="text-xs text-slate-500">
                                    {{ anomaly.category }}. {{ anomaly.category_label }}
                                    <span class="font-mono">{{ anomaly.rule_code ?? '' }}</span>
                                    <template v-if="anomaly.source === 'MANUAL'"> · signalement</template>
                                </p>
                                <p class="line-clamp-2">{{ anomaly.description }}</p>
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap" :class="priorityClasses[anomaly.priority]">{{ anomaly.priority_label }}</td>
                            <td class="px-4 py-2 whitespace-nowrap">
                                <span class="rounded px-2 py-0.5 text-xs font-medium" :class="stateClasses[anomaly.state]">{{ anomaly.state_label }}</span>
                                <p v-if="anomaly.no_longer_detected_at" class="mt-1 text-xs text-emerald-700">plus détectée</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-end gap-2 text-sm">
                <button type="button" class="btn-secondary" :disabled="page <= 1" @click="page--">Précédent</button>
                <span>Page {{ page }} sur {{ lastPage }}</span>
                <button type="button" class="btn-secondary" :disabled="page >= lastPage" @click="page++">Suivant</button>
            </div>
        </template>

        <ControlRulesPanel v-else-if="tab === 'regles'" />
        <ControlBatchPanel v-else />
    </div>
</template>
