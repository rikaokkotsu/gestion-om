<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { http } from '@/api';
import { employeeName } from '@/format';
import { useReferentials } from '@/referentials';
import type { Employee, Paginated } from '@/types';

const route = useRoute();
const router = useRouter();
const { referentials, load: loadReferentials } = useReferentials();

// Les filtres vivent dans l'URL : une recherche se partage et survit au retour arrière.
const filters = reactive({
    search: String(route.query.search ?? ''),
    service_id: String(route.query.service_id ?? ''),
    category_id: String(route.query.category_id ?? ''),
    current_place_id: String(route.query.current_place_id ?? ''),
    is_validator: String(route.query.is_validator ?? ''),
    status: String(route.query.status ?? 'active'),
});
const page = ref(Number(route.query.page ?? 1));
const employees = ref<Employee[]>([]);
const total = ref(0);
const lastPage = ref(1);
const loading = ref(true);

async function load(): Promise<void> {
    loading.value = true;
    const params = Object.fromEntries(Object.entries({ ...filters, page: String(page.value) }).filter(([, value]) => value !== ''));
    void router.replace({ query: params });
    const { data } = await http.get<Paginated<Employee>>('/api/employees', { params });
    employees.value = data.data;
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
                <h1 class="text-2xl font-semibold">Employés</h1>
                <p class="mt-1 text-sm text-slate-600">{{ total }} fiche{{ total > 1 ? 's' : '' }}</p>
            </div>
            <div class="flex gap-2">
                <RouterLink to="/import" class="btn-secondary">Importer</RouterLink>
                <RouterLink to="/employes/nouveau" class="btn-primary">Nouvel employé</RouterLink>
            </div>
        </div>

        <div class="card grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
            <input v-model="filters.search" type="search" placeholder="Matricule, nom ou prénom" class="field sm:col-span-2" />
            <select v-model="filters.service_id" class="field">
                <option value="">Tous les services</option>
                <option v-for="s in referentials?.services" :key="s.id" :value="String(s.id)">{{ s.name }}</option>
            </select>
            <select v-model="filters.category_id" class="field">
                <option value="">Toutes catégories</option>
                <option v-for="c in referentials?.categories" :key="c.id" :value="String(c.id)">{{ c.label }}</option>
            </select>
            <select v-model="filters.current_place_id" class="field">
                <option value="">Toutes affectations</option>
                <option v-for="p in referentials?.places" :key="p.id" :value="String(p.id)">{{ p.name }}</option>
            </select>
            <select v-model="filters.is_validator" class="field">
                <option value="">Validateurs ou non</option>
                <option value="1">Validateurs</option>
                <option value="0">Non validateurs</option>
            </select>
            <select v-model="filters.status" class="field">
                <option value="active">Fiches actives</option>
                <option value="inactive">Fiches désactivées</option>
                <option value="all">Toutes les fiches</option>
            </select>
        </div>

        <div class="card overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th class="px-4 py-2 font-medium">Matricule</th>
                        <th class="px-4 py-2 font-medium">Nom</th>
                        <th class="px-4 py-2 font-medium">Poste</th>
                        <th class="px-4 py-2 font-medium">Service</th>
                        <th class="px-4 py-2 font-medium">Catégorie</th>
                        <th class="px-4 py-2 font-medium">Affectation</th>
                        <th class="px-4 py-2 font-medium">Validateur</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-if="loading">
                        <td colspan="7" class="px-4 py-6 text-center text-slate-500">Chargement…</td>
                    </tr>
                    <tr v-else-if="employees.length === 0">
                        <td colspan="7" class="px-4 py-6 text-center text-slate-500">Aucun employé ne correspond.</td>
                    </tr>
                    <tr
                        v-for="employee in employees"
                        v-else
                        :key="employee.id"
                        class="cursor-pointer hover:bg-slate-50"
                        :class="{ 'text-slate-400': !employee.is_active }"
                        @click="router.push(`/employes/${employee.id}`)"
                    >
                        <td class="px-4 py-2 font-mono">{{ employee.matricule }}</td>
                        <td class="px-4 py-2 font-medium">
                            <RouterLink :to="`/employes/${employee.id}`" @click.stop>{{ employeeName(employee) }}</RouterLink>
                        </td>
                        <td class="px-4 py-2">{{ employee.position }}</td>
                        <td class="px-4 py-2">{{ employee.service?.name }}</td>
                        <td class="px-4 py-2">
                            <span v-if="employee.category">{{ employee.category.label }}</span>
                            <span v-else class="rounded bg-amber-50 px-2 py-0.5 text-amber-800">Manquante</span>
                        </td>
                        <td class="px-4 py-2">{{ employee.current_place?.name }}</td>
                        <td class="px-4 py-2">{{ employee.is_validator ? 'Oui' : '' }}</td>
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
