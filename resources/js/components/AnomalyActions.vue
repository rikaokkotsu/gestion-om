<script setup lang="ts">
import { computed, ref } from 'vue';
import { errorsFrom, http } from '@/api';
import { actionLabel, needsComment } from '@/anomalies';
import type { Anomaly, AnomalyStateCode } from '@/types';

const props = defineProps<{ anomaly: Anomaly }>();
const emit = defineEmits<{ changed: [anomaly: Anomaly] }>();

const pending = ref<AnomalyStateCode | null>(null);
const comment = ref('');
const error = ref('');
const busy = ref(false);

const commentMode = computed(() => (pending.value ? needsComment(props.anomaly.state, pending.value) : 'none'));

function start(state: AnomalyStateCode): void {
    error.value = '';
    comment.value = '';
    if (needsComment(props.anomaly.state, state) === 'none') {
        void submit(state);
    } else {
        pending.value = state;
    }
}

async function submit(state: AnomalyStateCode): Promise<void> {
    busy.value = true;
    try {
        const { data } = await http.post<{ data: Anomaly }>(`/api/anomalies/${props.anomaly.id}/transition`, {
            state,
            comment: comment.value || null,
        });
        pending.value = null;
        emit('changed', data.data);
    } catch (failure) {
        const { errors, message } = errorsFrom(failure);
        error.value = errors.comment?.[0] ?? errors.state?.[0] ?? message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="space-y-2">
        <div v-if="!pending" class="flex flex-wrap gap-2">
            <button
                v-for="transition in anomaly.transitions"
                :key="transition.value"
                type="button"
                class="btn-secondary px-3 py-1 text-xs"
                :disabled="busy"
                @click="start(transition.value)"
            >
                {{ actionLabel(anomaly.state, transition.value) }}
            </button>
        </div>
        <form v-else class="space-y-2" @submit.prevent="submit(pending)">
            <label :for="`anomaly-comment-${anomaly.id}`" class="block text-xs font-medium text-slate-700">
                {{ commentMode === 'required' ? 'Motif (obligatoire)' : 'Commentaire (facultatif)' }}
            </label>
            <textarea :id="`anomaly-comment-${anomaly.id}`" v-model="comment" rows="2" class="field" :required="commentMode === 'required'"></textarea>
            <div class="flex flex-wrap gap-2">
                <button type="submit" class="btn-primary px-3 py-1 text-xs" :disabled="busy">{{ actionLabel(anomaly.state, pending) }}</button>
                <button type="button" class="btn-secondary px-3 py-1 text-xs" @click="pending = null">Annuler</button>
            </div>
        </form>
        <p v-if="error" class="text-xs text-red-700">{{ error }}</p>
    </div>
</template>
