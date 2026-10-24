<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { errorsFrom, http } from '@/api';
import DownloadButton from '@/components/DownloadButton.vue';
import { employeeName, formatDateTime } from '@/format';
import { useReferentials } from '@/referentials';
import type { MissionOrderSummary, Paginated } from '@/types';

const route = useRoute();
const router = useRouter();
const { referentials, load: loadReferentials } = useReferentials();

// Critères du § 14.1, combinables ; ils vivent dans l'URL, comme pour les employés.
const query = (key: string): string => String(route.query[key] ?? '');
const filters = reactive({
    search: query('search'),
    type: query('type'),
    administrative_status: query('administrative_status'),
    anomalies: query('anomalies'),
    missionary: query('missionary'),
    category_id: query('category_id'),
    service_id: query('service_id'),
    validator: query('validator'),
    mission_object_id: query('mission_object_id'),
    destination_place_id: query('destination_place_id'),
    control_status: query('control_status'),
    departure_from: query('departure_from'),
    departure_to: query('departure_to'),
    return_from: query('return_from'),
    return_to: query('return_to'),
    employee_id: query('employee_id'),
});
const advancedKeys = [
    'missionary', 'category_id', 'service_id', 'validator', 'mission_object_id', 'destination_place_id',
    'control_status', 'departure_from', 'departure_to', 'return_from', 'return_to',
] as const;
const advancedCount = computed(() => advancedKeys.filter((key) => filters[key] !== '').length);
const showAdvanced = ref(advancedCount.value > 0);
const page = ref(Number(route.query.page ?? 1));
const orders = ref<MissionOrderSummary[]>([]);
const total = ref(0);
const lastPage = ref(1);
const loading = ref(true);
const invalid = ref('');

const anomalyOptions = [
    { value: 'open', label: 'Avec anomalies à traiter' },
    { value: 'any', label: 'Avec anomalies, à traiter ou traitées' },
    { value: 'none', label: 'Sans anomalie' },
];

/** Critères non vides, pour la liste, les exports et l'URL. */
function criteria(): Record<string, string> {
    return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));
}

async function load(): Promise<void> {
    loading.value = true;
    invalid.value = '';
    const params = { ...criteria(), ...(page.value > 1 ? { page: String(page.value) } : {}) };
    void router.replace({ query: params });
    try {
        const { data } = await http.get<Paginated<MissionOrderSummary>>('/api/mission-orders', { params });
        orders.value = data.data;
        total.value = data.meta.total;
        lastPage.value = data.meta.last_page;
    } catch (error) {
        const { errors, message } = errorsFrom(error);
        invalid.value = Object.values(errors)[0]?.[0] ?? message;
    } finally {
        loading.value = false;
    }
}

function clear(): void {
    for (const key of Object.keys(filters) as (keyof typeof filters)[]) {
        filters[key] = '';
    }
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

onMounted(async () => {
    await Promise.all([loadReferentials(), load()]);
});
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Ordres de mission</h1>
                <p class="mt-1 text-sm text-slate-600">{{ total }} OM, du départ le plus récent au plus ancien</p>
            </div>
            <div class="flex flex-wrap items-start gap-2">
                <DownloadButton url="/api/exports/mission-orders" label="Exporter en Excel" :params="criteria" />
                <DownloadButton url="/api/reports/summary" label="Récapitulatif PDF" :params="criteria" />
                <RouterLink to="/om/nouveau" class="btn-primary">Nouvel OM</RouterLink>
            </div>
        </div>

        <div class="card space-y-3 p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1.4fr)_minmax(0,1.4fr)]">
                <input
                    v-model="filters.search"
                    type="search"
                    placeholder="Référence, matricule ou nom d'un missionnaire"
                    aria-label="Référence ou missionnaire"
                    class="field sm:col-span-2 lg:col-span-1"
                />
                <select v-model="filters.type" class="field" aria-label="Type">
                    <option value="">Tous les types</option>
                    <option v-for="t in referentials?.mission_order_types" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
                <select v-model="filters.administrative_status" class="field" aria-label="État de validation">
                    <option value="">Tous les états de validation</option>
                    <option v-for="s in referentials?.administrative_statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
                <select v-model="filters.anomalies" class="field" aria-label="Anomalies">
                    <option value="">Avec ou sans anomalie</option>
                    <option v-for="option in anomalyOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
            </div>

            <div v-if="showAdvanced" class="grid gap-3 border-t border-slate-100 pt-3 sm:grid-cols-2 lg:grid-cols-4">
                <input v-model="filters.missionary" type="search" placeholder="Missionnaire : nom ou matricule" aria-label="Missionnaire" class="field" />
                <select v-model="filters.category_id" class="field" aria-label="Catégorie du missionnaire">
                    <option value="">Toutes les catégories</option>
                    <option v-for="category in referentials?.categories" :key="category.id" :value="String(category.id)">{{ category.label }}</option>
                </select>
                <select v-model="filters.service_id" class="field" aria-label="Service du missionnaire">
                    <option value="">Tous les services</option>
                    <option v-for="service in referentials?.services" :key="service.id" :value="String(service.id)">{{ service.name }}</option>
                </select>
                <input v-model="filters.validator" type="search" placeholder="Validateur : nom ou matricule" aria-label="Validateur" class="field" />
                <select v-model="filters.mission_object_id" class="field" aria-label="Objet">
                    <option value="">Tous les objets</option>
                    <option v-for="object in referentials?.mission_objects" :key="object.id" :value="String(object.id)">{{ object.label }}</option>
                </select>
                <select v-model="filters.destination_place_id" class="field" aria-label="Destination">
                    <option value="">Toutes les destinations</option>
                    <option v-for="place in referentials?.places" :key="place.id" :value="String(place.id)">{{ place.name }}</option>
                </select>
                <select v-model="filters.control_status" class="field sm:col-span-2" aria-label="Contrôle">
                    <option value="">Tous les états de contrôle</option>
                    <option v-for="s in referentials?.control_statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
                <div class="flex min-w-0 items-center gap-2 text-sm sm:col-span-2">
                    <label for="departure-from" class="w-14 shrink-0 text-slate-600">Départ</label>
                    <input id="departure-from" v-model="filters.departure_from" type="date" class="field min-w-0" aria-label="Départ à partir du" />
                    <span class="shrink-0 text-slate-600">au</span>
                    <input v-model="filters.departure_to" type="date" class="field min-w-0" aria-label="Départ jusqu'au" />
                </div>
                <div class="flex min-w-0 items-center gap-2 text-sm sm:col-span-2">
                    <label for="return-from" class="w-14 shrink-0 text-slate-600">Retour</label>
                    <input id="return-from" v-model="filters.return_from" type="date" class="field min-w-0" aria-label="Retour à partir du" />
                    <span class="shrink-0 text-slate-600">au</span>
                    <input v-model="filters.return_to" type="date" class="field min-w-0" aria-label="Retour jusqu'au" />
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                <button type="button" class="text-sky-700 hover:underline" :aria-expanded="showAdvanced" @click="showAdvanced = !showAdvanced">
                    {{ showAdvanced ? 'Moins de critères' : 'Plus de critères' }}<template v-if="!showAdvanced && advancedCount"> ({{ advancedCount }} actif{{ advancedCount > 1 ? 's' : '' }})</template>
                </button>
                <button v-if="Object.keys(criteria()).length" type="button" class="text-slate-600 hover:text-slate-900 hover:underline" @click="clear">
                    Effacer les critères
                </button>
                <span v-if="filters.employee_id" class="text-slate-600">Limité aux OM d'un employé (missionnaire ou validateur).</span>
            </div>
        </div>
        <p v-if="invalid" class="text-sm text-red-700" role="alert">{{ invalid }}</p>

        <div class="card overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th class="px-4 py-2 font-medium">Référence</th>
                        <th class="px-4 py-2 font-medium">Type</th>
                        <th class="px-4 py-2 font-medium">Départ</th>
                        <th class="px-4 py-2 font-medium">Destination</th>
                        <th class="px-4 py-2 font-medium">Missionnaires</th>
                        <th class="px-4 py-2 font-medium">Statut</th>
                        <th class="px-4 py-2 font-medium">Contrôle</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-if="loading">
                        <td colspan="7" class="px-4 py-6 text-center text-slate-500">Chargement…</td>
                    </tr>
                    <tr v-else-if="orders.length === 0">
                        <td colspan="7" class="px-4 py-6 text-center text-slate-500">Aucun OM ne correspond.</td>
                    </tr>
                    <tr v-for="order in orders" v-else :key="order.id" class="cursor-pointer hover:bg-slate-50" @click="router.push(`/om/${order.id}`)">
                        <td class="px-4 py-2 font-mono">
                            <RouterLink :to="`/om/${order.id}`" @click.stop>{{ order.reference ?? 'sans référence' }}</RouterLink>
                        </td>
                        <td class="px-4 py-2">{{ order.type_label ?? '—' }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ formatDateTime(order.departure_at) || '—' }}</td>
                        <td class="px-4 py-2">{{ order.destination_place?.name ?? '—' }}</td>
                        <td class="px-4 py-2">
                            {{ order.employees.map(employeeName).join(', ') || '—' }}
                        </td>
                        <td class="px-4 py-2">{{ order.administrative_status_label }}</td>
                        <td class="px-4 py-2 text-slate-600">
                            {{ order.control_status_label }}
                            <span v-if="order.open_anomalies_count" class="ml-1 rounded bg-amber-100 px-1.5 text-xs font-medium text-amber-900">
                                {{ order.open_anomalies_count }} à traiter
                            </span>
                            <span v-if="order.changed_since_control" class="block text-xs text-amber-700">modifié depuis le contrôle</span>
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
    </div>
</template>
