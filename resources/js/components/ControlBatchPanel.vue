<script setup lang="ts">
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { errorsFrom, http } from '@/api';
import FieldError from '@/components/FieldError.vue';
import { formatDate, formatTimestamp } from '@/format';
import { useReferentials } from '@/referentials';
import type { ControlRun, Paginated, ValidationErrors } from '@/types';

const { referentials } = useReferentials();

const form = reactive({ departure_from: '', departure_to: '', type: '', administrative_status: '', scope: 'pending' });
const errors = ref<ValidationErrors>({});
const message = ref('');
const runs = ref<ControlRun[]>([]);
const launching = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

async function loadRuns(): Promise<void> {
    const { data } = await http.get<Paginated<ControlRun>>('/api/control-runs', { params: { trigger: 'BATCH' } });
    runs.value = data.data;
    // Tant qu'un lot attend ou tourne, la liste se rafraîchit.
    clearTimeout(timer);
    if (runs.value.some((run) => run.status === 'QUEUED' || run.status === 'RUNNING')) {
        timer = setTimeout(() => void loadRuns(), 2000);
    }
}

async function launch(): Promise<void> {
    launching.value = true;
    errors.value = {};
    message.value = '';
    try {
        await http.post('/api/control-runs', Object.fromEntries(Object.entries(form).filter(([, value]) => value !== '')));
        await loadRuns();
    } catch (error) {
        ({ errors: errors.value, message: message.value } = errorsFrom(error));
    } finally {
        launching.value = false;
    }
}

function criteria(run: ControlRun): string {
    const filters = run.filters ?? {};
    const parts: string[] = [];
    if (filters.departure_from || filters.departure_to) {
        parts.push(`départs du ${formatDate(filters.departure_from) || '…'} au ${formatDate(filters.departure_to) || '…'}`);
    }
    if (filters.type) {
        parts.push(referentials.value?.mission_order_types.find((type) => type.value === filters.type)?.label ?? filters.type);
    }
    if (filters.administrative_status) {
        parts.push(referentials.value?.administrative_statuses.find((status) => status.value === filters.administrative_status)?.label ?? '');
    }
    parts.push(filters.scope === 'pending' ? 'à contrôler seulement' : 'tous les OM');
    return parts.join(' · ');
}

onMounted(loadRuns);
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="space-y-6">
        <form class="card space-y-4 p-5" @submit.prevent="launch">
            <div>
                <h2 class="text-lg font-semibold">Contrôler un lot d'OM</h2>
                <p class="text-sm text-slate-600">
                    Le contrôle tourne en arrière-plan : vous pouvez quitter la page. Les anomalies déjà signalées ne sont pas dupliquées.
                </p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label for="batch-from" class="mb-1 block text-sm font-medium">Départ à partir du</label>
                    <input id="batch-from" v-model="form.departure_from" type="date" class="field" />
                    <FieldError :messages="errors.departure_from" />
                </div>
                <div>
                    <label for="batch-to" class="mb-1 block text-sm font-medium">Jusqu'au</label>
                    <input id="batch-to" v-model="form.departure_to" type="date" class="field" />
                    <FieldError :messages="errors.departure_to" />
                </div>
                <div>
                    <label for="batch-type" class="mb-1 block text-sm font-medium">Type</label>
                    <select id="batch-type" v-model="form.type" class="field">
                        <option value="">Tous</option>
                        <option v-for="type in referentials?.mission_order_types" :key="type.value" :value="type.value">{{ type.label }}</option>
                    </select>
                </div>
                <div>
                    <label for="batch-status" class="mb-1 block text-sm font-medium">Statut administratif</label>
                    <select id="batch-status" v-model="form.administrative_status" class="field">
                        <option value="">Tous</option>
                        <option v-for="status in referentials?.administrative_statuses" :key="status.value" :value="status.value">{{ status.label }}</option>
                    </select>
                </div>
            </div>
            <fieldset class="space-y-1 text-sm">
                <legend class="mb-1 font-medium">OM à contrôler</legend>
                <label class="flex items-center gap-2">
                    <input v-model="form.scope" type="radio" value="pending" />
                    Seulement ceux jamais contrôlés ou modifiés depuis leur dernier contrôle
                </label>
                <label class="flex items-center gap-2">
                    <input v-model="form.scope" type="radio" value="all" />
                    Tous les OM retenus par les critères
                </label>
            </fieldset>
            <p v-if="message && Object.keys(errors).length === 0" class="text-sm text-red-700">{{ message }}</p>
            <button type="submit" class="btn-primary" :disabled="launching">Lancer le contrôle par lot</button>
        </form>

        <div class="card overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th class="px-4 py-2 font-medium">Lancé le</th>
                        <th class="px-4 py-2 font-medium">Par</th>
                        <th class="px-4 py-2 font-medium">Critères</th>
                        <th class="px-4 py-2 font-medium">Avancement</th>
                        <th class="px-4 py-2 font-medium">Résultat</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-if="runs.length === 0">
                        <td colspan="5" class="px-4 py-6 text-center text-slate-500">Aucun contrôle par lot pour l'instant.</td>
                    </tr>
                    <tr v-for="run in runs" :key="run.id">
                        <td class="px-4 py-2 whitespace-nowrap">{{ formatTimestamp(run.created_at) }}</td>
                        <td class="px-4 py-2">{{ run.launched_by?.name ?? '—' }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ criteria(run) }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            {{ run.status_label }}
                            <span v-if="run.status !== 'QUEUED'" class="text-slate-500">· {{ run.processed_count }} / {{ run.orders_count }} OM</span>
                        </td>
                        <td class="px-4 py-2">
                            <template v-if="run.status === 'FAILED'"><span class="text-red-700">{{ run.error ?? 'Échec' }}</span></template>
                            <template v-else-if="run.status === 'DONE'">
                                {{ run.anomalies_created }} proposée{{ run.anomalies_created > 1 ? 's' : '' }},
                                {{ run.anomalies_closed }} clôturée{{ run.anomalies_closed > 1 ? 's' : '' }}
                                <RouterLink v-if="run.anomalies_created" :to="{ path: '/anomalies', query: { control_run_id: run.id, state: 'all' } }" class="ml-1 text-sky-700 hover:underline">
                                    Voir
                                </RouterLink>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
