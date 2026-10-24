<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { http } from '@/api';
import { employeeName, formatDateTime } from '@/format';
import { useReferentials } from '@/referentials';
import type { MissionOrderSummary, Paginated } from '@/types';

const route = useRoute();
const router = useRouter();
const { referentials, load: loadReferentials } = useReferentials();

// Les filtres vivent dans l'URL, comme pour les employés.
const filters = reactive({
    search: String(route.query.search ?? ''),
    type: String(route.query.type ?? ''),
    administrative_status: String(route.query.administrative_status ?? ''),
});
const page = ref(Number(route.query.page ?? 1));
const orders = ref<MissionOrderSummary[]>([]);
const total = ref(0);
const lastPage = ref(1);
const loading = ref(true);

async function load(): Promise<void> {
    loading.value = true;
    const params = Object.fromEntries(Object.entries({ ...filters, page: String(page.value) }).filter(([, value]) => value !== ''));
    void router.replace({ query: params });
    const { data } = await http.get<Paginated<MissionOrderSummary>>('/api/mission-orders', { params });
    orders.value = data.data;
    total.value = data.meta.total;
    lastPage.value = data.meta.last_page;
    loading.value = false;
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
            <RouterLink to="/om/nouveau" class="btn-primary">Nouvel OM</RouterLink>
        </div>

        <div class="card grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <input v-model="filters.search" type="search" placeholder="Référence, matricule ou nom d'un missionnaire" class="field sm:col-span-2" />
            <select v-model="filters.type" class="field">
                <option value="">Tous les types</option>
                <option v-for="t in referentials?.mission_order_types" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
            <select v-model="filters.administrative_status" class="field">
                <option value="">Tous les statuts</option>
                <option v-for="s in referentials?.administrative_statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
        </div>

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
                        <td class="px-4 py-2 text-slate-600">{{ order.control_status_label }}</td>
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
