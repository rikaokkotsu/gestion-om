<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { errorsFrom, http } from '@/api';
import { isOpen, priorityClasses, stateClasses } from '@/anomalies';
import AnomalyActions from '@/components/AnomalyActions.vue';
import ReportAnomalyForm from '@/components/ReportAnomalyForm.vue';
import { employeeName, formatTimestamp } from '@/format';
import type { Anomaly, ControlRun, MissionOrder } from '@/types';

const props = defineProps<{ order: MissionOrder }>();
const emit = defineEmits<{ changed: [] }>();

const anomalies = ref<Anomaly[]>([]);
const loading = ref(true);
const running = ref(false);
const reporting = ref(false);
const showTreated = ref(false);
const message = ref('');
const error = ref('');

const open = computed(() => anomalies.value.filter((anomaly) => isOpen(anomaly.state)));
const treated = computed(() => anomalies.value.filter((anomaly) => !isOpen(anomaly.state)));

async function load(): Promise<void> {
    const { data } = await http.get<{ data: Anomaly[] }>(`/api/mission-orders/${props.order.id}/anomalies`);
    anomalies.value = data.data;
    loading.value = false;
}

function plural(count: number, word: string): string {
    return `${count} ${word}${count > 1 ? 's' : ''}`;
}

async function runControl(): Promise<void> {
    running.value = true;
    message.value = '';
    error.value = '';
    try {
        const { data } = await http.post<{ data: ControlRun }>(`/api/mission-orders/${props.order.id}/control`);
        const run = data.data;
        const parts = [`${plural(run.anomalies_created, 'anomalie')} ${run.anomalies_created > 1 ? 'proposées' : 'proposée'}`];
        if (run.anomalies_closed) {
            parts.push(`${plural(run.anomalies_closed, 'anomalie')} ${run.anomalies_closed > 1 ? 'clôturées' : 'clôturée'} car plus détectée${run.anomalies_closed > 1 ? 's' : ''}`);
        }
        message.value = `Contrôle terminé : ${parts.join(', ')}.`;
        await load();
        emit('changed');
    } catch (failure) {
        error.value = errorsFrom(failure).message;
    } finally {
        running.value = false;
    }
}

function replace(updated: Anomaly): void {
    anomalies.value = anomalies.value.map((anomaly) => (anomaly.id === updated.id ? updated : anomaly));
    emit('changed');
}

async function reported(): Promise<void> {
    reporting.value = false;
    message.value = 'Signalement enregistré.';
    await load();
    emit('changed');
}

onMounted(load);
</script>

<template>
    <section class="card space-y-4 p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold">Contrôle et anomalies</h2>
                <p class="text-sm text-slate-600">
                    <template v-if="order.last_controlled_at">Dernier contrôle automatique le {{ formatTimestamp(order.last_controlled_at) }}.</template>
                    <template v-else>Aucun contrôle automatique n'a encore été lancé.</template>
                </p>
                <p v-if="order.changed_since_control" class="mt-1 text-sm text-amber-700">
                    L'OM a été modifié depuis : relancez le contrôle pour mettre les anomalies à jour.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="btn-primary" :disabled="running" @click="runControl">
                    {{ running ? 'Contrôle en cours…' : 'Lancer le contrôle' }}
                </button>
                <button type="button" class="btn-secondary" @click="reporting = !reporting">Signaler une anomalie</button>
            </div>
        </div>
        <p v-if="message" class="text-sm text-emerald-700" role="status">{{ message }}</p>
        <p v-if="error" class="text-sm text-red-700">{{ error }}</p>

        <ReportAnomalyForm v-if="reporting" :order="order" @reported="reported" @cancel="reporting = false" />

        <p v-if="loading" class="text-sm text-slate-500">Chargement…</p>
        <p v-else-if="anomalies.length === 0" class="text-sm text-slate-500">Aucune anomalie pour cet OM.</p>

        <ul v-if="open.length" class="divide-y divide-slate-100 rounded-md border border-slate-200">
            <li v-for="anomaly in open" :key="anomaly.id" class="space-y-2 p-4">
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="rounded px-2 py-0.5 font-medium" :class="stateClasses[anomaly.state]">{{ anomaly.state_label }}</span>
                    <span class="font-medium" :class="priorityClasses[anomaly.priority]">Priorité {{ anomaly.priority_label.toLowerCase() }}</span>
                    <span class="text-slate-500">{{ anomaly.category }}. {{ anomaly.category_label }}</span>
                    <span class="font-mono text-slate-500">{{ anomaly.rule_code ?? '' }}</span>
                    <span v-if="anomaly.source === 'MANUAL'" class="text-slate-500">Signalement de {{ anomaly.reported_by?.name ?? '—' }}</span>
                </div>
                <p class="text-sm">
                    <span v-if="anomaly.rule_label" class="font-medium">{{ anomaly.rule_label }} : </span>{{ anomaly.description }}
                </p>
                <p v-if="anomaly.no_longer_detected_at" class="text-xs text-emerald-700">
                    N'est plus détectée depuis le contrôle du {{ formatTimestamp(anomaly.no_longer_detected_at) }} : à clôturer ou à écarter.
                </p>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <AnomalyActions :anomaly="anomaly" @changed="replace" />
                    <RouterLink :to="`/anomalies/${anomaly.id}`" class="text-sm text-sky-700 hover:underline">Fiche et historique</RouterLink>
                </div>
            </li>
        </ul>

        <div v-if="treated.length">
            <button type="button" class="text-sm text-sky-700 hover:underline" @click="showTreated = !showTreated">
                {{ showTreated ? 'Masquer' : 'Afficher' }} les anomalies écartées ou clôturées ({{ treated.length }})
            </button>
            <ul v-if="showTreated" class="mt-2 divide-y divide-slate-100 rounded-md border border-slate-200">
                <li v-for="anomaly in treated" :key="anomaly.id" class="flex flex-wrap items-start justify-between gap-2 p-3 text-sm text-slate-600">
                    <div>
                        <span class="mr-2 rounded px-2 py-0.5 text-xs font-medium" :class="stateClasses[anomaly.state]">{{ anomaly.state_label }}</span>
                        <span class="mr-2 font-mono text-xs">{{ anomaly.rule_code ?? '' }}</span>
                        {{ anomaly.description }}
                        <span v-if="anomaly.employee" class="text-slate-500"> · {{ employeeName(anomaly.employee) }}</span>
                    </div>
                    <RouterLink :to="`/anomalies/${anomaly.id}`" class="text-sky-700 hover:underline">Fiche</RouterLink>
                </li>
            </ul>
        </div>
    </section>
</template>
