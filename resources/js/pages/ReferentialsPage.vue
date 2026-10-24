<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { errorsFrom, http } from '@/api';
import FieldError from '@/components/FieldError.vue';
import { useReferentials } from '@/referentials';
import type { ValidationErrors, Zone } from '@/types';

type Item = Record<string, unknown> & { id: number; is_active?: boolean };

interface Column {
    key: string;
    label: string;
    type: 'text' | 'zone' | 'bool';
    createOnly?: boolean;
    hint?: string;
}

interface Tab {
    key: string;
    label: string;
    columns: Column[];
    readOnly?: boolean;
    help?: string;
}

const tabs: Tab[] = [
    {
        key: 'places',
        label: 'Lieux',
        columns: [
            { key: 'name', label: 'Nom', type: 'text' },
            { key: 'zone_id', label: 'Zone', type: 'zone' },
        ],
        help: 'La zone détermine le barème de per diem. Un lieu sans zone reste utilisable mais sera signalé.',
    },
    {
        key: 'categories',
        label: 'Catégories',
        columns: [
            { key: 'code', label: 'Code', type: 'text', createOnly: true, hint: 'Majuscules, chiffres et _ ; ne change plus ensuite.' },
            { key: 'label', label: 'Libellé', type: 'text' },
        ],
        help: 'Le code relie la catégorie à ses barèmes de per diem.',
    },
    { key: 'services', label: 'Services', columns: [{ key: 'name', label: 'Nom', type: 'text' }] },
    { key: 'mission-objects', label: 'Objets de mission', columns: [{ key: 'label', label: 'Libellé', type: 'text' }] },
    {
        key: 'transport-modes',
        label: 'Moyens de transport',
        columns: [
            { key: 'label', label: 'Libellé', type: 'text' },
            { key: 'generates_costs', label: 'Engendre des frais', type: 'bool' },
        ],
        help: 'Des frais de transport déclarés avec un moyen « sans frais » seront signalés comme anomalie.',
    },
    {
        key: 'zones',
        label: 'Zones',
        readOnly: true,
        columns: [
            { key: 'code', label: 'Code', type: 'text' },
            { key: 'label', label: 'Libellé', type: 'text' },
        ],
        help: 'Les deux zones sont fixées par le cahier des charges.',
    },
];

const { referentials, load: loadReferentials } = useReferentials();
const activeKey = ref(tabs[0]!.key);
const tab = computed(() => tabs.find((t) => t.key === activeKey.value)!);
const items = ref<Item[]>([]);
const loading = ref(false);
const message = ref('');

const editingId = ref<number | 'new' | null>(null);
const draft = reactive<Record<string, unknown>>({});
const errors = ref<ValidationErrors>({});

const zones = computed<Zone[]>(() => referentials.value?.zones ?? []);

async function load(): Promise<void> {
    loading.value = true;
    message.value = '';
    editingId.value = null;
    if (tab.value.key === 'zones') {
        items.value = (await loadReferentials(true)).zones as unknown as Item[];
    } else {
        const { data } = await http.get<{ data: Item[] }>(`/api/referentials/${tab.value.key}`);
        items.value = data.data;
    }
    loading.value = false;
}

function startEdit(item: Item | null): void {
    errors.value = {};
    for (const key of Object.keys(draft)) {
        delete draft[key];
    }
    for (const column of tab.value.columns) {
        draft[column.key] = item ? item[column.key] : column.type === 'bool' ? false : column.type === 'zone' ? null : '';
    }
    editingId.value = item ? item.id : 'new';
}

async function save(): Promise<void> {
    errors.value = {};
    const payload = { ...draft };
    if (editingId.value !== 'new') {
        for (const column of tab.value.columns.filter((c) => c.createOnly)) {
            delete payload[column.key];
        }
    }

    try {
        if (editingId.value === 'new') {
            await http.post(`/api/referentials/${tab.value.key}`, payload);
        } else {
            await http.patch(`/api/referentials/${tab.value.key}/${editingId.value}`, payload);
        }
        await Promise.all([load(), loadReferentials(true)]);
    } catch (error) {
        const result = errorsFrom(error);
        errors.value = result.errors;
        message.value = Object.keys(result.errors).length ? '' : result.message;
    }
}

async function toggleActive(item: Item): Promise<void> {
    try {
        await http.patch(`/api/referentials/${tab.value.key}/${item.id}`, { is_active: !item.is_active });
        await Promise.all([load(), loadReferentials(true)]);
    } catch (error) {
        message.value = errorsFrom(error).message;
    }
}

function display(item: Item, column: Column): string {
    const value = item[column.key];
    if (column.type === 'bool') {
        return value ? 'Oui' : 'Non';
    }
    if (column.type === 'zone') {
        return zones.value.find((z) => z.id === value)?.label ?? '';
    }
    return String(value ?? '');
}

watch(activeKey, load);
onMounted(async () => {
    await loadReferentials();
    await load();
});
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold">Référentiels</h1>
            <p class="mt-1 text-sm text-slate-600">
                Listes de référence utilisées par les fiches employés et les OM. Un élément ne se supprime pas : il se désactive.
            </p>
        </div>

        <div class="flex flex-wrap gap-1 border-b border-slate-200">
            <button
                v-for="t in tabs"
                :key="t.key"
                type="button"
                class="-mb-px border-b-2 px-3 py-2 text-sm"
                :class="t.key === activeKey ? 'border-sky-700 font-medium text-sky-800' : 'border-transparent text-slate-600 hover:text-slate-900'"
                @click="activeKey = t.key"
            >
                {{ t.label }}
            </button>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-4">
            <p class="text-sm text-slate-600">{{ tab.help }}</p>
            <button v-if="!tab.readOnly" type="button" class="btn-primary" @click="startEdit(null)">Ajouter</button>
        </div>

        <p v-if="message" class="text-sm text-red-700">{{ message }}</p>

        <div class="card overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th v-for="column in tab.columns" :key="column.key" class="px-4 py-2 font-medium">{{ column.label }}</th>
                        <th v-if="!tab.readOnly" class="px-4 py-2 font-medium">État</th>
                        <th v-if="!tab.readOnly" class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template v-for="item in editingId === 'new' ? [null, ...items] : items" :key="item?.id ?? 'new'">
                        <tr v-if="item === null || editingId === item.id" class="bg-sky-50/50">
                            <td v-for="column in tab.columns" :key="column.key" class="px-4 py-2 align-top">
                                <template v-if="column.createOnly && item !== null">{{ display(item, column) }}</template>
                                <select v-else-if="column.type === 'zone'" v-model="draft[column.key]" class="field">
                                    <option :value="null">Sans zone</option>
                                    <option v-for="zone in zones" :key="zone.id" :value="zone.id">{{ zone.label }}</option>
                                </select>
                                <input v-else-if="column.type === 'bool'" v-model="draft[column.key]" type="checkbox" class="size-4" />
                                <template v-else>
                                    <input v-model="draft[column.key]" class="field" />
                                    <p v-if="column.hint" class="mt-1 text-xs text-slate-500">{{ column.hint }}</p>
                                </template>
                                <FieldError :messages="errors[column.key]" />
                            </td>
                            <td class="px-4 py-2"></td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                <button type="button" class="btn-primary mr-2" @click="save">Enregistrer</button>
                                <button type="button" class="btn-secondary" @click="editingId = null">Annuler</button>
                            </td>
                        </tr>
                        <tr v-else :class="{ 'text-slate-400': item.is_active === false }">
                            <td v-for="column in tab.columns" :key="column.key" class="px-4 py-2">
                                <span
                                    v-if="column.type === 'zone' && item[column.key] === null"
                                    class="rounded bg-amber-50 px-2 py-0.5 text-amber-800"
                                >
                                    Sans zone
                                </span>
                                <template v-else>{{ display(item, column) }}</template>
                            </td>
                            <td v-if="!tab.readOnly" class="px-4 py-2">{{ item.is_active ? 'Actif' : 'Désactivé' }}</td>
                            <td v-if="!tab.readOnly" class="px-4 py-2 text-right whitespace-nowrap">
                                <button type="button" class="btn-secondary mr-2" @click="startEdit(item)">Modifier</button>
                                <button type="button" class="btn-secondary" @click="toggleActive(item)">
                                    {{ item.is_active ? 'Désactiver' : 'Réactiver' }}
                                </button>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="!loading && items.length === 0 && editingId !== 'new'">
                        <td :colspan="tab.columns.length + 2" class="px-4 py-6 text-center text-slate-500">Aucun élément.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
