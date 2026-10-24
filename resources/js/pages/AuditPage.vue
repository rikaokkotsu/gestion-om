<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { http } from '@/api';
import { formatDate } from '@/format';
import type { AuditLog, Paginated } from '@/types';

const actionLabels: Record<string, string> = {
    'auth.login': 'Connexion',
    'auth.login_failed': 'Échec de connexion',
    'auth.logout': 'Déconnexion',
    'user.created': 'Compte créé',
    'user.updated': 'Compte modifié',
    'user.deactivated': 'Compte désactivé',
    'user.reactivated': 'Compte réactivé',
    'user.password_changed': 'Mot de passe changé',
    'import.places': 'Import de lieux',
    'import.employees': 'Import d\'employés',
    'rate_version.copied': 'Barème : tarifs copiés',
    'rate_version.deleted': 'Version de barème supprimée',
};

const subjectLabels: Record<string, string> = {
    user: 'Compte',
    employee: 'Employé',
    place: 'Lieu',
    category: 'Catégorie',
    service: 'Service',
    mission_object: 'Objet de mission',
    transport_mode: 'Moyen de transport',
    rate_version: 'Version de barème',
    rate: 'Tarif',
};

const verbLabels: Record<string, string> = { created: 'création', updated: 'modification' };

function actionLabel(action: string): string {
    if (actionLabels[action]) {
        return actionLabels[action];
    }
    const [subject = '', verb = ''] = action.split('.');
    return `${subjectLabels[subject] ?? subject} : ${verbLabels[verb] ?? verb}`;
}

const filters = [
    { value: '', label: 'Toutes les actions' },
    { value: 'auth.', label: 'Connexions' },
    { value: 'user.', label: 'Comptes' },
    { value: 'employee.', label: 'Employés' },
    { value: 'place.', label: 'Lieux' },
    { value: 'import.', label: 'Imports' },
    { value: 'rate', label: 'Barèmes' },
];

const logs = ref<AuditLog[]>([]);
const page = ref(1);
const lastPage = ref(1);
const action = ref('');
const loading = ref(true);

async function load(): Promise<void> {
    loading.value = true;
    const { data } = await http.get<Paginated<AuditLog>>('/api/audit-logs', {
        params: { page: page.value, action: action.value || undefined },
    });
    logs.value = data.data;
    lastPage.value = data.meta.last_page;
    loading.value = false;
}

function subject(log: AuditLog): string {
    if (!log.auditable_type) {
        return '';
    }
    const type = subjectLabels[log.auditable_type] ?? log.auditable_type;
    return log.subject_label ? `${type} : ${log.subject_label}` : `${type} n° ${log.auditable_id}`;
}

const fieldLabels: Record<string, string> = {
    amount: 'montant',
    is_applicable: 'applicable',
    effective_from: "date d'effet",
    source_version_id: 'version source',
    rates: 'tarifs',
    label: 'libellé',
};

function formatValue(value: unknown): string {
    if (value === null) {
        return 'vide';
    }
    if (typeof value === 'boolean') {
        return value ? 'oui' : 'non';
    }
    // Une date sans heure est enregistrée avec minuit : on n'affiche que la date.
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}( 00:00:00)?$/.test(value)) {
        return formatDate(value.slice(0, 10));
    }
    return JSON.stringify(value);
}

function details(log: AuditLog): string {
    const parts: string[] = [];
    const values = { ...(log.old_values ?? {}), ...(log.new_values ?? {}) };
    for (const key of Object.keys(values)) {
        const label = fieldLabels[key] ?? key;
        const before = log.old_values?.[key];
        const after = log.new_values?.[key];
        if (log.new_values && key in log.new_values) {
            parts.push(before === undefined ? `${label} : ${formatValue(after)}` : `${label} : ${formatValue(before)} → ${formatValue(after)}`);
        } else {
            parts.push(`${label} : ${formatValue(before)}`);
        }
    }
    return parts.join(' · ');
}

watch(action, () => {
    page.value = 1;
    void load();
});
watch(page, load);
onMounted(load);
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Journal d'audit</h1>
                <p class="mt-1 text-sm text-slate-600">Connexions et opérations sensibles, de la plus récente à la plus ancienne.</p>
            </div>
            <select v-model="action" class="field w-auto">
                <option v-for="filter in filters" :key="filter.value" :value="filter.value">{{ filter.label }}</option>
            </select>
        </div>

        <div class="card overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th class="px-4 py-2 font-medium">Date</th>
                        <th class="px-4 py-2 font-medium">Contrôleur</th>
                        <th class="px-4 py-2 font-medium">Action</th>
                        <th class="px-4 py-2 font-medium">Objet</th>
                        <th class="px-4 py-2 font-medium">Détails</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-if="loading">
                        <td colspan="5" class="px-4 py-6 text-center text-slate-500">Chargement…</td>
                    </tr>
                    <tr v-else-if="logs.length === 0">
                        <td colspan="5" class="px-4 py-6 text-center text-slate-500">Aucune entrée.</td>
                    </tr>
                    <tr v-for="log in logs" v-else :key="log.id">
                        <td class="px-4 py-2 whitespace-nowrap">{{ new Date(log.created_at).toLocaleString('fr-FR') }}</td>
                        <td class="px-4 py-2">{{ log.user?.name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ actionLabel(log.action) }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ subject(log) }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ details(log) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-end gap-2 text-sm">
            <button type="button" class="btn-secondary" :disabled="page <= 1" @click="page--">Précédent</button>
            <span>Page {{ page }} sur {{ lastPage }}</span>
            <button type="button" class="btn-secondary" :disabled="page >= lastPage" @click="page++">Suivant</button>
        </div>
    </div>
</template>
