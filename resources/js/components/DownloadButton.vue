<script setup lang="ts">
import { ref } from 'vue';
import { download, errorsFrom } from '@/api';

// Bouton de téléchargement d'un export ou d'un rapport ; les critères sont lus au moment du clic.
const props = defineProps<{ url: string; label: string; params?: () => Record<string, string>; primary?: boolean }>();

const busy = ref(false);
const error = ref('');

async function run(): Promise<void> {
    busy.value = true;
    error.value = '';
    try {
        await download(props.url, props.params?.() ?? {});
    } catch (failure) {
        const { errors, message } = errorsFrom(failure);
        error.value = Object.values(errors)[0]?.[0] ?? message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <span class="inline-flex flex-col items-start gap-1">
        <button type="button" :class="primary ? 'btn-primary' : 'btn-secondary'" :disabled="busy" @click="run">
            {{ busy ? 'Préparation…' : label }}
        </button>
        <span v-if="error" class="text-xs text-red-700" role="alert">{{ error }}</span>
    </span>
</template>
