import { ref } from 'vue';
import { http } from '@/api';
import type { Referentials } from '@/types';

// Listes partagées par les formulaires ; rechargées après une modification.
const referentials = ref<Referentials | null>(null);
let pending: Promise<Referentials> | null = null;

export function useReferentials() {
    async function load(force = false): Promise<Referentials> {
        if (referentials.value && !force) {
            return referentials.value;
        }

        pending ??= http
            .get<{ data: Referentials }>('/api/referentials')
            .then(({ data }) => (referentials.value = data.data))
            .finally(() => (pending = null));

        return pending;
    }

    return { referentials, load };
}
