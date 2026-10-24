<script setup lang="ts">
import { ref, watch } from 'vue';
import { http } from '@/api';
import { employeeName } from '@/format';
import type { Employee, Paginated } from '@/types';

const props = defineProps<{ inputId?: string; placeholder?: string; exclude?: number[] }>();
const emit = defineEmits<{ select: [employee: Employee] }>();

const query = ref('');
const results = ref<Employee[]>([]);
const open = ref(false);
const searched = ref(false);
const active = ref(0);

let timer: ReturnType<typeof setTimeout> | undefined;
let sequence = 0;

watch(query, (value) => {
    clearTimeout(timer);
    if (value.trim() === '') {
        results.value = [];
        open.value = false;
        return;
    }
    timer = setTimeout(async () => {
        const current = ++sequence;
        // Les fiches désactivées restent proposées : le document peut les citer, le contrôle le signalera.
        const { data } = await http.get<Paginated<Employee>>('/api/employees', { params: { search: value, status: 'all' } });
        if (current === sequence) {
            results.value = data.data.slice(0, 10);
            searched.value = true;
            active.value = 0;
            open.value = true;
        }
    }, 200);
});

function excluded(employee: Employee): boolean {
    return props.exclude?.includes(employee.id) ?? false;
}

function choose(employee: Employee | undefined): void {
    if (!employee || excluded(employee)) {
        return;
    }
    emit('select', employee);
    query.value = '';
    results.value = [];
    open.value = false;
}

function move(step: number): void {
    if (results.value.length) {
        active.value = (active.value + step + results.value.length) % results.value.length;
    }
}
</script>

<template>
    <div class="relative">
        <input
            :id="inputId"
            v-model="query"
            type="search"
            autocomplete="off"
            role="combobox"
            aria-autocomplete="list"
            :aria-expanded="open"
            class="field"
            :placeholder="placeholder ?? 'Rechercher par matricule ou nom'"
            @focus="open = searched && query !== ''"
            @blur="open = false"
            @keydown.down.prevent="move(1)"
            @keydown.up.prevent="move(-1)"
            @keydown.enter.prevent="choose(results[active])"
            @keydown.esc="open = false"
        />
        <ul
            v-if="open"
            role="listbox"
            class="absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-md border border-slate-200 bg-white py-1 text-sm shadow-lg"
        >
            <li
                v-for="(employee, index) in results"
                :key="employee.id"
                role="option"
                :aria-selected="index === active"
                :aria-disabled="excluded(employee)"
                class="flex cursor-pointer flex-wrap items-baseline gap-x-2 px-3 py-1.5"
                :class="[index === active ? 'bg-sky-50' : '', excluded(employee) ? 'cursor-not-allowed text-slate-400' : '']"
                @mousedown.prevent="choose(employee)"
                @mouseenter="active = index"
            >
                <span class="font-mono text-xs">{{ employee.matricule }}</span>
                <span class="font-medium">{{ employeeName(employee) }}</span>
                <span class="text-xs text-slate-500">{{ employee.category?.label ?? 'catégorie manquante' }}</span>
                <span v-if="!employee.is_active" class="text-xs text-amber-700">fiche désactivée</span>
                <span v-if="excluded(employee)" class="text-xs">déjà choisi</span>
            </li>
            <li v-if="results.length === 0" class="px-3 py-2 text-slate-500">
                Aucun employé ne correspond.
                <RouterLink to="/employes/nouveau" target="_blank" class="text-sky-700 underline" @mousedown.prevent>Créer la fiche</RouterLink>
            </li>
        </ul>
    </div>
</template>
