<script setup lang="ts">
import { reactive } from 'vue';
import { errorsFrom, http } from '@/api';
import { useReferentials } from '@/referentials';
import type { ImportReport } from '@/types';

type ImportType = 'places' | 'employees';

interface ImportState {
    file: File | null;
    report: ImportReport | null;
    busy: boolean;
    message: string;
}

const cards: { type: ImportType; title: string; description: string }[] = [
    {
        type: 'places',
        title: '1. Lieux',
        description: 'Colonnes : Nom, Zone (Nord ou Autres). Un lieu existant voit sa zone mise à jour.',
    },
    {
        type: 'employees',
        title: '2. Employés',
        description:
            'Colonnes : Matricule, Nom, Prénom, Poste, Service, Catégorie, Affectation, Compte missionné, Validateur. Importez les lieux avant les employés.',
    },
];

const state = reactive<Record<ImportType, ImportState>>({
    places: { file: null, report: null, busy: false, message: '' },
    employees: { file: null, report: null, busy: false, message: '' },
});

const { load: loadReferentials } = useReferentials();

const columnLabels: Record<string, string> = { matricule: 'Matricule', nom: 'Nom' };

function pick(type: ImportType, event: Event): void {
    const input = event.target as HTMLInputElement;
    state[type].file = input.files?.[0] ?? null;
    state[type].report = null;
    state[type].message = '';
}

async function run(type: ImportType, dryRun: boolean): Promise<void> {
    const current = state[type];
    if (!current.file) {
        return;
    }

    const body = new FormData();
    body.append('file', current.file);
    body.append('dry_run', dryRun ? '1' : '0');

    current.busy = true;
    current.message = '';
    try {
        const { data } = await http.post<{ data: ImportReport }>(`/api/imports/${type}`, body);
        current.report = data.data;
        if (!dryRun) {
            await loadReferentials(true);
        }
    } catch (error) {
        const result = errorsFrom(error);
        current.message = result.errors.file?.[0] ?? result.message;
    } finally {
        current.busy = false;
    }
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold">Import Excel</h1>
            <p class="mt-1 text-sm text-slate-600">
                Fichiers .xlsx ou .csv, première ligne = en-têtes. Lancez d'abord une simulation : elle montre le bilan sans rien
                enregistrer.
            </p>
        </div>

        <section v-for="card in cards" :key="card.type" class="card space-y-4 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="font-semibold">{{ card.title }}</h2>
                    <p class="mt-1 max-w-2xl text-sm text-slate-600">{{ card.description }}</p>
                </div>
                <a :href="`/api/imports/${card.type}/template`" class="btn-secondary">Télécharger le modèle</a>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <input type="file" accept=".xlsx,.csv" class="text-sm" @change="pick(card.type, $event)" />
                <button
                    type="button"
                    class="btn-secondary"
                    :disabled="!state[card.type].file || state[card.type].busy"
                    @click="run(card.type, true)"
                >
                    Simuler
                </button>
                <button
                    type="button"
                    class="btn-primary"
                    :disabled="!state[card.type].file || state[card.type].busy || !state[card.type].report?.dry_run"
                    :title="state[card.type].report?.dry_run ? '' : 'Lancez d\'abord une simulation'"
                    @click="run(card.type, false)"
                >
                    Importer
                </button>
            </div>

            <p v-if="state[card.type].message" class="text-sm text-red-700">{{ state[card.type].message }}</p>

            <div v-if="state[card.type].report" class="space-y-3 text-sm">
                <template v-for="report in [state[card.type].report!]" :key="card.type">
                    <p v-if="report.missing_columns.length" class="text-red-700">
                        Colonnes obligatoires absentes :
                        {{ report.missing_columns.map((c) => columnLabels[c] ?? c).join(', ') }}. Rien n'a été importé.
                    </p>
                    <template v-else>
                        <p class="font-medium" :class="report.dry_run ? 'text-slate-700' : 'text-emerald-700'">
                            {{ report.dry_run ? 'Simulation' : 'Import terminé' }} : {{ report.created }} création(s),
                            {{ report.updated }} mise(s) à jour, {{ report.unchanged }} inchangée(s),
                            {{ report.rejected.length }} ligne(s) rejetée(s).
                        </p>
                        <div v-if="report.rejected.length" class="rounded-md border border-red-200 bg-red-50 p-3">
                            <p class="mb-1 font-medium text-red-800">Lignes rejetées</p>
                            <ul class="space-y-1 text-red-800">
                                <li v-for="r in report.rejected" :key="r.line">Ligne {{ r.line }} : {{ r.errors.join(' ') }}</li>
                            </ul>
                        </div>
                        <div v-if="report.warnings.length" class="rounded-md border border-amber-200 bg-amber-50 p-3">
                            <p class="mb-1 font-medium text-amber-900">À vérifier</p>
                            <ul class="space-y-1 text-amber-900">
                                <li v-for="w in report.warnings" :key="`${w.line}-${w.message}`">Ligne {{ w.line }} : {{ w.message }}</li>
                            </ul>
                        </div>
                    </template>
                </template>
            </div>
        </section>
    </div>
</template>
