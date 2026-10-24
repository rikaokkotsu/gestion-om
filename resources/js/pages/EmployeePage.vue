<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { errorsFrom, http } from '@/api';
import FieldError from '@/components/FieldError.vue';
import { employeeName, formatDate, formatDateTime } from '@/format';
import { useReferentials } from '@/referentials';
import type { Employee, MissionOrderSummary, ValidationErrors } from '@/types';

const route = useRoute();
const router = useRouter();
const { referentials, load: loadReferentials } = useReferentials();

const isNew = computed(() => route.params.id === undefined);
const employee = ref<Employee | null>(null);
const errors = ref<ValidationErrors>({});
const message = ref('');
const saved = ref(false);
const submitting = ref(false);

const emptyForm = () => ({
    matricule: '',
    last_name: '',
    first_name: '',
    position: '',
    service_id: null as number | null,
    category_id: null as number | null,
    current_place_id: null as number | null,
    assignment_date: '',
    account_number: '',
    is_validator: false,
    is_active: true,
});
const form = reactive(emptyForm());

const placeChanged = computed(() => form.current_place_id !== (employee.value?.current_place_id ?? null));

function fill(data: Employee): void {
    employee.value = data;
    Object.assign(form, {
        ...emptyForm(),
        matricule: data.matricule,
        last_name: data.last_name,
        first_name: data.first_name ?? '',
        position: data.position ?? '',
        service_id: data.service_id,
        category_id: data.category_id,
        current_place_id: data.current_place_id,
        account_number: data.account_number ?? '',
        is_validator: data.is_validator,
        is_active: data.is_active,
    });
}

const orders = ref<MissionOrderSummary[]>([]);

function roles(order: MissionOrderSummary): string {
    const labels = [];
    if (order.employees.some((member) => member.id === employee.value?.id)) {
        labels.push('Missionnaire');
    }
    if (order.validator?.id === employee.value?.id) {
        labels.push('Validateur');
    }
    return labels.join(', ');
}

async function load(): Promise<void> {
    errors.value = {};
    saved.value = false;
    orders.value = [];
    if (isNew.value) {
        employee.value = null;
        Object.assign(form, emptyForm());
        return;
    }
    const id = String(route.params.id);
    const [{ data }, { data: missionOrders }] = await Promise.all([
        http.get<{ data: Employee }>(`/api/employees/${id}`),
        http.get<{ data: MissionOrderSummary[] }>(`/api/employees/${id}/mission-orders`),
    ]);
    fill(data.data);
    orders.value = missionOrders.data;
}

async function submit(): Promise<void> {
    submitting.value = true;
    errors.value = {};
    message.value = '';
    saved.value = false;

    const payload = {
        ...form,
        first_name: form.first_name || null,
        position: form.position || null,
        account_number: form.account_number || null,
        assignment_date: placeChanged.value && form.assignment_date ? form.assignment_date : null,
    };

    try {
        if (isNew.value) {
            const { data } = await http.post<{ data: Employee }>('/api/employees', payload);
            await router.replace(`/employes/${data.data.id}`);
        } else {
            const { data } = await http.patch<{ data: Employee }>(`/api/employees/${employee.value!.id}`, payload);
            fill(data.data);
        }
        saved.value = true;
    } catch (error) {
        const result = errorsFrom(error);
        errors.value = result.errors;
        message.value = Object.keys(result.errors).length ? 'Vérifiez les champs signalés.' : result.message;
    } finally {
        submitting.value = false;
    }
}

watch(() => route.params.id, load);
onMounted(async () => {
    await Promise.all([loadReferentials(), load()]);
});
</script>

<template>
    <div class="space-y-6">
        <div>
            <RouterLink to="/employes" class="text-sm text-slate-600 hover:text-slate-900">← Employés</RouterLink>
            <h1 class="mt-2 text-2xl font-semibold">
                {{ isNew ? 'Nouvel employé' : employee ? employeeName(employee) : '' }}
            </h1>
            <p v-if="employee" class="mt-1 font-mono text-sm text-slate-600">{{ employee.matricule }}</p>
        </div>

        <form class="card grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="submit">
            <div>
                <label for="matricule" class="mb-1 block text-sm font-medium">Matricule *</label>
                <input id="matricule" v-model="form.matricule" class="field font-mono" />
                <FieldError :messages="errors.matricule" />
            </div>
            <div>
                <label for="last_name" class="mb-1 block text-sm font-medium">Nom *</label>
                <input id="last_name" v-model="form.last_name" class="field" />
                <FieldError :messages="errors.last_name" />
            </div>
            <div>
                <label for="first_name" class="mb-1 block text-sm font-medium">Prénom</label>
                <input id="first_name" v-model="form.first_name" class="field" />
                <FieldError :messages="errors.first_name" />
            </div>
            <div>
                <label for="position" class="mb-1 block text-sm font-medium">Poste</label>
                <input id="position" v-model="form.position" class="field" />
            </div>
            <div>
                <label for="service" class="mb-1 block text-sm font-medium">Service rattaché</label>
                <select id="service" v-model="form.service_id" class="field">
                    <option :value="null">Non renseigné</option>
                    <option v-for="s in referentials?.services" :key="s.id" :value="s.id" :disabled="!s.is_active">{{ s.name }}</option>
                </select>
            </div>
            <div>
                <label for="category" class="mb-1 block text-sm font-medium">Catégorie</label>
                <select id="category" v-model="form.category_id" class="field">
                    <option :value="null">Non renseignée</option>
                    <option v-for="c in referentials?.categories" :key="c.id" :value="c.id" :disabled="!c.is_active">{{ c.label }}</option>
                </select>
                <p class="mt-1 text-xs text-slate-500">Détermine le barème de per diem.</p>
            </div>
            <div>
                <label for="place" class="mb-1 block text-sm font-medium">Affectation actuelle</label>
                <select id="place" v-model="form.current_place_id" class="field">
                    <option :value="null">Non renseignée</option>
                    <option v-for="p in referentials?.places" :key="p.id" :value="p.id" :disabled="!p.is_active">{{ p.name }}</option>
                </select>
            </div>
            <div v-if="placeChanged">
                <label for="assignment_date" class="mb-1 block text-sm font-medium">Date d'effet de l'affectation</label>
                <input id="assignment_date" v-model="form.assignment_date" type="date" class="field" />
                <p class="mt-1 text-xs text-slate-500">
                    {{ employee?.current_place_id ? "Aujourd'hui si vide." : 'Laisser vide si inconnue.' }} Le changement sera ajouté à
                    l'historique.
                </p>
                <FieldError :messages="errors.assignment_date" />
            </div>
            <div>
                <label for="account" class="mb-1 block text-sm font-medium">Compte missionné</label>
                <input id="account" v-model="form.account_number" class="field" />
            </div>
            <div class="flex flex-col justify-center gap-2 text-sm sm:col-span-2 lg:col-span-3">
                <label class="flex items-center gap-2">
                    <input v-model="form.is_validator" type="checkbox" class="size-4" />
                    Habilité à valider les OM
                </label>
                <label v-if="!isNew" class="flex items-center gap-2">
                    <input v-model="form.is_active" type="checkbox" class="size-4" />
                    Fiche active
                </label>
            </div>
            <div class="flex items-center gap-3 sm:col-span-2 lg:col-span-3">
                <button type="submit" class="btn-primary" :disabled="submitting">Enregistrer</button>
                <span v-if="saved" class="text-sm text-emerald-700">Fiche enregistrée.</span>
                <span v-if="message" class="text-sm text-red-700">{{ message }}</span>
            </div>
        </form>

        <section v-if="employee" class="space-y-3">
            <h2 class="text-lg font-semibold">Historique d'affectation</h2>
            <p class="text-sm text-slate-600">Du plus récent au plus ancien enregistrement.</p>
            <div class="card overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th class="px-4 py-2 font-medium">Date d'effet</th>
                            <th class="px-4 py-2 font-medium">Ancienne affectation</th>
                            <th class="px-4 py-2 font-medium">Nouvelle affectation</th>
                            <th class="px-4 py-2 font-medium">Origine</th>
                            <th class="px-4 py-2 font-medium">Enregistré par</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-if="!employee.assignment_histories?.length">
                            <td colspan="5" class="px-4 py-6 text-center text-slate-500">Aucun changement d'affectation enregistré.</td>
                        </tr>
                        <tr v-for="h in employee.assignment_histories" :key="h.id">
                            <td class="px-4 py-2">
                                <template v-if="h.effective_date">{{ formatDate(h.effective_date) }}</template>
                                <span v-else class="text-slate-500">Inconnue</span>
                            </td>
                            <td class="px-4 py-2">{{ h.previous_place?.name ?? '—' }}</td>
                            <td class="px-4 py-2">{{ h.new_place?.name ?? '—' }}</td>
                            <td class="px-4 py-2">{{ h.source_label }}</td>
                            <td class="px-4 py-2">{{ h.recorded_by?.name ?? '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="employee" class="space-y-3">
            <h2 class="text-lg font-semibold">Ordres de mission</h2>
            <p class="text-sm text-slate-600">OM dont l'employé est missionnaire ou validateur.</p>
            <div class="card overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th class="px-4 py-2 font-medium">Référence</th>
                            <th class="px-4 py-2 font-medium">Type</th>
                            <th class="px-4 py-2 font-medium">Départ</th>
                            <th class="px-4 py-2 font-medium">Destination</th>
                            <th class="px-4 py-2 font-medium">Rôle</th>
                            <th class="px-4 py-2 font-medium">Statut</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-if="orders.length === 0">
                            <td colspan="6" class="px-4 py-6 text-center text-slate-500">Aucun OM enregistré pour cet employé.</td>
                        </tr>
                        <tr v-for="order in orders" :key="order.id">
                            <td class="px-4 py-2 font-mono">
                                <RouterLink :to="`/om/${order.id}`" class="text-sky-700 hover:underline">{{ order.reference ?? 'sans référence' }}</RouterLink>
                            </td>
                            <td class="px-4 py-2">{{ order.type_label ?? '—' }}</td>
                            <td class="px-4 py-2 whitespace-nowrap">{{ formatDateTime(order.departure_at) || '—' }}</td>
                            <td class="px-4 py-2">{{ order.destination_place?.name ?? '—' }}</td>
                            <td class="px-4 py-2">{{ roles(order) }}</td>
                            <td class="px-4 py-2">{{ order.administrative_status_label }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
