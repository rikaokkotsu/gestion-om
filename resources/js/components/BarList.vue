<script setup lang="ts">
import { computed } from 'vue';
import type { RouteLocationRaw } from 'vue-router';

// Barres horizontales d'une seule série : la valeur est écrite au bout de la barre,
// la ligne entière mène à la liste correspondante quand `to` est donné.
export interface BarItem {
    key: string;
    label: string;
    count: number;
    note?: string;
    to?: RouteLocationRaw;
}

const props = defineProps<{ items: BarItem[]; unit: string }>();

const max = computed(() => Math.max(1, ...props.items.map((item) => item.count)));
const withNotes = computed(() => props.items.some((item) => item.note));
const format = new Intl.NumberFormat('fr-FR');

function width(count: number): string {
    // Place gardée au bout de la barre la plus longue pour écrire sa valeur.
    return `calc((100% - 3.5rem) * ${count / max.value})`;
}
</script>

<template>
    <ul class="space-y-1">
        <li v-for="item in items" :key="item.key">
            <component
                :is="item.to ? 'RouterLink' : 'div'"
                :to="item.to"
                class="grid items-center gap-3 rounded px-1 py-1 text-sm"
                :class="[
                    withNotes ? 'grid-cols-[minmax(0,8rem)_1fr] sm:grid-cols-[minmax(0,11rem)_1fr_6rem]' : 'grid-cols-[minmax(0,9rem)_1fr] sm:grid-cols-[minmax(0,11rem)_1fr]',
                    { 'hover:bg-slate-50': item.to },
                ]"
                :title="`${item.label} : ${format.format(item.count)} ${unit}${item.note ? `, ${item.note}` : ''}`"
            >
                <span class="min-w-0 text-slate-700">
                    <span class="block truncate">{{ item.label }}</span>
                    <!-- Sur petit écran, la note passe sous le libellé pour laisser la place à la barre. -->
                    <span v-if="item.note" class="block text-xs text-slate-500 sm:hidden">{{ item.note }}</span>
                </span>
                <span class="flex min-w-0 items-center gap-2">
                    <span v-if="item.count > 0" class="h-3 shrink-0 rounded-r bg-[#2a78d6]" :style="{ width: width(item.count) }"></span>
                    <span class="shrink-0 font-medium text-slate-900 tabular-nums">{{ format.format(item.count) }}</span>
                </span>
                <span v-if="withNotes" class="hidden text-right text-xs text-slate-500 sm:block">{{ item.note }}</span>
            </component>
        </li>
    </ul>
</template>
