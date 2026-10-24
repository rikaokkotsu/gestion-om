<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { errorsFrom, http } from '@/api';
import { priorityClasses, stateClasses } from '@/anomalies';
import AnomalyActions from '@/components/AnomalyActions.vue';
import FieldError from '@/components/FieldError.vue';
import { employeeName, formatDateTime, formatTimestamp } from '@/format';
import { useReferentials } from '@/referentials';
import type { Anomaly, AnomalyEvent, MissionOrder, ValidationErrors } from '@/types';

const route = useRoute();
const { referentials, load: loadReferentials } = useReferentials();

const anomaly = ref<Anomaly | null>(null);
const members = ref<MissionOrder['employees']>([]);
const editing = ref(false);
const edit = reactive({ priority: '', category: '', description: '', member_id: '' as number | '' });
const errors = ref<ValidationErrors>({});
const message = ref('');
const comment = ref('');
const commentError = ref('');

async function load(): Promise<void> {
    const { data } = await http.get<{ data: Anomaly }>(`/api/anomalies/${String(route.params.id)}`);
    anomaly.value = data.data;
}

async function startEdit(): Promise<void> {
    const current = anomaly.value!;
    Object.assign(edit, { priority: current.priority, category: current.category, description: current.description, member_id: current.member_id ?? '' });
    if (current.editable_fields.includes('member_id') && !current.mission_order.deleted) {
        const { data } = await http.get<{ data: MissionOrder }>(`/api/mission-orders/${current.mission_order_id}`);
        members.value = data.data.employees;
    }
    errors.value = {};
    editing.value = true;
}

async function save(): Promise<void> {
    const current = anomaly.value!;
    const payload: Record<string, unknown> = {};
    for (const field of current.editable_fields) {
        payload[field] = field === 'member_id' ? (edit.member_id === '' ? null : edit.member_id) : edit[field];
    }
    try {
        const { data } = await http.patch<{ data: Anomaly }>(`/api/anomalies/${current.id}`, payload);
        anomaly.value = data.data;
        editing.value = false;
        message.value = 'Modifications enregistrées.';
    } catch (error) {
        ({ errors: errors.value, message: message.value } = errorsFrom(error));
    }
}

async function addComment(): Promise<void> {
    commentError.value = '';
    try {
        const { data } = await http.post<{ data: Anomaly }>(`/api/anomalies/${anomaly.value!.id}/comments`, { comment: comment.value });
        anomaly.value = data.data;
        comment.value = '';
    } catch (error) {
        const { errors: fieldErrors, message: text } = errorsFrom(error);
        commentError.value = fieldErrors.comment?.[0] ?? text;
    }
}

const fieldLabels: Record<string, string> = { priority: 'priorité', category: 'catégorie', description: 'description', employee_id: 'missionnaire' };

function changeValue(field: string, value: string | number | null): string {
    if (value === null || value === '') {
        return 'aucun';
    }
    if (field === 'priority') {
        return referentials.value?.anomaly_priorities.find((option) => option.value === value)?.label ?? String(value);
    }
    if (field === 'category') {
        return referentials.value?.anomaly_categories.find((option) => option.value === value)?.label ?? String(value);
    }
    return String(value);
}

function eventTitle(event: AnomalyEvent): string {
    if (event.type === 'STATE_CHANGED') {
        return `${event.from_state_label} → ${event.to_state_label}`;
    }
    if (event.type === 'CREATED') {
        return event.user ? 'Signalée' : 'Proposée par le contrôle automatique';
    }
    if (event.type === 'REDETECTED') {
        return event.changes ? 'Détectée à nouveau, la situation a évolué' : 'Détectée à nouveau';
    }
    if (event.type === 'NO_LONGER_DETECTED') {
        return 'Le contrôle ne détecte plus cette situation';
    }
    return event.type_label;
}

onMounted(async () => {
    await Promise.all([loadReferentials(), load()]);
});
</script>

<template>
    <div v-if="anomaly" class="space-y-6">
        <div>
            <RouterLink to="/anomalies" class="text-sm text-slate-600 hover:text-slate-900">← Anomalies</RouterLink>
            <h1 class="mt-2 text-2xl font-semibold">Anomalie n° {{ anomaly.id }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                <span class="rounded px-2 py-0.5 font-medium" :class="stateClasses[anomaly.state]">{{ anomaly.state_label }}</span>
                <span class="font-medium" :class="priorityClasses[anomaly.priority]">Priorité {{ anomaly.priority_label.toLowerCase() }}</span>
                <span class="rounded bg-slate-100 px-2 py-0.5">{{ anomaly.source_label }}</span>
            </div>
        </div>

        <section class="card space-y-4 p-5">
            <p class="text-base">
                <span v-if="anomaly.rule_label" class="font-medium">{{ anomaly.rule_label }} : </span>{{ anomaly.description }}
            </p>
            <p v-if="anomaly.no_longer_detected_at" class="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                Le contrôle du {{ formatTimestamp(anomaly.no_longer_detected_at) }} ne détecte plus cette situation.
            </p>
            <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-slate-600">OM concerné</dt>
                    <dd>
                        <RouterLink v-if="!anomaly.mission_order.deleted" :to="`/om/${anomaly.mission_order_id}`" class="font-mono text-sky-700 hover:underline">
                            {{ anomaly.mission_order.reference ?? 'sans référence' }}
                        </RouterLink>
                        <span v-else class="font-mono">{{ anomaly.mission_order.reference ?? 'sans référence' }} (supprimé)</span>
                        <span class="text-slate-500">
                            · {{ anomaly.mission_order.type_label ?? 'type non renseigné' }}
                            <template v-if="anomaly.mission_order.departure_at"> · départ le {{ formatDateTime(anomaly.mission_order.departure_at) }}</template>
                        </span>
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-600">Concerne</dt>
                    <dd>
                        <RouterLink v-if="anomaly.employee" :to="`/employes/${anomaly.employee.id}`" class="text-sky-700 hover:underline">
                            {{ employeeName(anomaly.employee) }} ({{ anomaly.employee.matricule }})
                        </RouterLink>
                        <template v-else>L'OM dans son ensemble</template>
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-600">Catégorie</dt>
                    <dd>{{ anomaly.category }}. {{ anomaly.category_label }}</dd>
                </div>
                <div>
                    <dt class="text-slate-600">Règle de contrôle</dt>
                    <dd>
                        <template v-if="anomaly.rule_code"><span class="font-mono">{{ anomaly.rule_code }}</span> · {{ anomaly.rule_label }}</template>
                        <template v-else>—</template>
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-600">Nature</dt>
                    <dd>{{ anomaly.nature_label ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-600">{{ anomaly.source === 'MANUAL' ? 'Signalée' : 'Détectée' }}</dt>
                    <dd>
                        le {{ formatTimestamp(anomaly.detected_at) }}
                        <template v-if="anomaly.reported_by"> par {{ anomaly.reported_by.name }}</template>
                        <p v-if="anomaly.source === 'AUTO' && anomaly.last_detected_at" class="text-xs text-slate-500">
                            Dernière détection le {{ formatTimestamp(anomaly.last_detected_at) }}
                        </p>
                    </dd>
                </div>
            </dl>
        </section>

        <section class="card space-y-3 p-5">
            <h2 class="text-lg font-semibold">Traitement</h2>
            <p v-if="anomaly.transitions.length === 0" class="text-sm text-slate-500">Aucune action possible.</p>
            <AnomalyActions :anomaly="anomaly" @changed="(updated) => (anomaly = updated)" />
            <p v-if="message" class="text-sm text-emerald-700">{{ message }}</p>

            <div v-if="!editing">
                <button type="button" class="text-sm text-sky-700 hover:underline" @click="startEdit">Modifier les détails</button>
            </div>
            <form v-else class="space-y-4 rounded-md border border-slate-200 bg-slate-50 p-4" @submit.prevent="save">
                <p v-if="!anomaly.editable_fields.includes('description')" class="text-sm text-slate-600">
                    La description d'une anomalie détectée par le contrôle vient de sa règle : seule la priorité se modifie. Ajoutez un commentaire pour la nuancer.
                </p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="edit-priority" class="mb-1 block text-sm font-medium">Priorité</label>
                        <select id="edit-priority" v-model="edit.priority" class="field">
                            <option v-for="priority in referentials?.anomaly_priorities" :key="priority.value" :value="priority.value">{{ priority.label }}</option>
                        </select>
                        <FieldError :messages="errors.priority" />
                    </div>
                    <div v-if="anomaly.editable_fields.includes('category')">
                        <label for="edit-category" class="mb-1 block text-sm font-medium">Catégorie</label>
                        <select id="edit-category" v-model="edit.category" class="field">
                            <option v-for="category in referentials?.anomaly_categories" :key="category.value" :value="category.value">
                                {{ category.value }}. {{ category.label }}
                            </option>
                        </select>
                        <FieldError :messages="errors.category" />
                    </div>
                    <div v-if="anomaly.editable_fields.includes('member_id')">
                        <label for="edit-member" class="mb-1 block text-sm font-medium">Missionnaire concerné</label>
                        <select id="edit-member" v-model="edit.member_id" class="field">
                            <option value="">Aucun en particulier</option>
                            <option v-for="member in members" :key="member.id" :value="member.id">
                                {{ employeeName(member.employee) }} ({{ member.employee.matricule }})
                            </option>
                        </select>
                        <FieldError :messages="errors.member_id" />
                    </div>
                    <div v-if="anomaly.editable_fields.includes('description')" class="sm:col-span-2">
                        <label for="edit-description" class="mb-1 block text-sm font-medium">Description</label>
                        <textarea id="edit-description" v-model="edit.description" rows="3" class="field"></textarea>
                        <FieldError :messages="errors.description" />
                    </div>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn-primary">Enregistrer</button>
                    <button type="button" class="btn-secondary" @click="editing = false">Annuler</button>
                </div>
            </form>
        </section>

        <section class="card space-y-4 p-5">
            <h2 class="text-lg font-semibold">Historique et commentaires</h2>
            <ol class="space-y-3 border-l border-slate-200 pl-4">
                <li v-for="event in anomaly.events" :key="event.id" class="text-sm">
                    <p class="text-xs text-slate-500">
                        {{ formatTimestamp(event.created_at) }} · {{ event.user?.name ?? 'Contrôle automatique' }}
                    </p>
                    <p class="font-medium">{{ eventTitle(event) }}</p>
                    <p v-if="event.comment" class="mt-1 whitespace-pre-line rounded-md bg-slate-50 px-3 py-2">{{ event.comment }}</p>
                    <ul v-if="event.changes" class="mt-1 space-y-1 text-slate-600">
                        <li v-for="(change, field) in event.changes" :key="field">
                            <template v-if="field === 'description'">
                                Ancienne description : <span class="italic">{{ change.old }}</span>
                            </template>
                            <template v-else>
                                {{ fieldLabels[field] ?? field }} : {{ changeValue(String(field), change.old) }} → {{ changeValue(String(field), change.new) }}
                            </template>
                        </li>
                    </ul>
                </li>
            </ol>
            <form class="space-y-2" @submit.prevent="addComment">
                <label for="new-comment" class="block text-sm font-medium">Ajouter un commentaire</label>
                <textarea id="new-comment" v-model="comment" rows="2" class="field" required></textarea>
                <p v-if="commentError" class="text-sm text-red-700">{{ commentError }}</p>
                <button type="submit" class="btn-secondary">Ajouter</button>
            </form>
        </section>
    </div>
</template>
