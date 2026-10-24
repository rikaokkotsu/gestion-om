<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { errorsFrom, http } from '@/api';
import { priorityClasses } from '@/anomalies';
import type { ControlRule } from '@/types';

const rules = ref<ControlRule[]>([]);
const loading = ref(true);
const error = ref('');

const groups = computed(() => {
    const byCategory = new Map<string, { label: string; rules: ControlRule[] }>();
    for (const rule of rules.value) {
        const group = byCategory.get(rule.category) ?? { label: `${rule.category}. ${rule.category_label}`, rules: [] };
        group.rules.push(rule);
        byCategory.set(rule.category, group);
    }
    return [...byCategory.values()];
});
const disabledCount = computed(() => rules.value.filter((rule) => !rule.is_enabled).length);

async function toggle(rule: ControlRule): Promise<void> {
    error.value = '';
    const enabled = !rule.is_enabled;
    try {
        await http.patch(`/api/control-rules/${rule.code}`, { is_enabled: enabled });
        rule.is_enabled = enabled;
    } catch (failure) {
        error.value = errorsFrom(failure).message;
    }
}

onMounted(async () => {
    const { data } = await http.get<{ data: ControlRule[] }>('/api/control-rules');
    rules.value = data.data;
    loading.value = false;
});
</script>

<template>
    <div class="space-y-4">
        <p class="text-sm text-slate-600">
            Le contrôle automatique applique les règles actives. Une règle désactivée n'est plus exécutée ; les anomalies qu'elle a déjà
            produites restent en place.
            <template v-if="disabledCount"> {{ disabledCount }} règle{{ disabledCount > 1 ? 's' : '' }} désactivée{{ disabledCount > 1 ? 's' : '' }}.</template>
        </p>
        <p v-if="error" class="text-sm text-red-700">{{ error }}</p>
        <p v-if="loading" class="text-sm text-slate-500">Chargement…</p>

        <section v-for="group in groups" :key="group.label" class="card overflow-x-auto">
            <h2 class="border-b border-slate-200 bg-slate-50 px-4 py-2 font-medium">{{ group.label }}</h2>
            <table class="min-w-full table-fixed divide-y divide-slate-100 text-sm">
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="rule in group.rules" :key="rule.code" :class="{ 'text-slate-400': !rule.is_enabled }">
                        <td class="w-24 px-4 py-2 align-top font-mono text-xs">{{ rule.code }}</td>
                        <td class="px-4 py-2 align-top">
                            <p class="font-medium">{{ rule.label }}</p>
                            <p class="text-slate-600" :class="{ 'text-slate-400': !rule.is_enabled }">{{ rule.explanation }}</p>
                        </td>
                        <td class="w-40 px-4 py-2 align-top text-xs">
                            <p>{{ rule.nature_label }}</p>
                            <p :class="rule.is_enabled ? priorityClasses[rule.priority] : ''">Priorité {{ rule.priority_label.toLowerCase() }}</p>
                        </td>
                        <td class="w-32 px-4 py-2 align-top text-xs">
                            <RouterLink
                                v-if="rule.open_anomalies_count"
                                :to="{ path: '/anomalies', query: { rule_code: rule.code } }"
                                class="text-sky-700 hover:underline"
                            >
                                {{ rule.open_anomalies_count }} à traiter
                            </RouterLink>
                        </td>
                        <td class="w-28 px-4 py-2 align-top">
                            <label class="inline-flex cursor-pointer items-center gap-2 text-xs">
                                <input type="checkbox" :checked="rule.is_enabled" class="h-4 w-4" @change="toggle(rule)" />
                                {{ rule.is_enabled ? 'Active' : 'Désactivée' }}
                            </label>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
