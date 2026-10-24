<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { http } from '@/api';
import { formatDate, formatDateTime } from '@/format';
import { useReferentials } from '@/referentials';
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
    'mission_order.deleted': 'OM supprimé',
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
    mission_order: 'OM',
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
    { value: 'mission_order.', label: 'OM' },
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
    reference: 'référence',
    departure_at: 'départ',
    return_at: 'retour',
    departure_place_id: 'lieu de départ',
    destination_place_id: 'destination',
    mission_object_id: 'objet',
    declared_duration: 'durée déclarée',
    additional_costs: 'frais supplémentaires',
    assignment_allowance: "indemnité d'affectation",
    validator_id: 'validateur',
    validated_at: 'validé le',
    administrative_status: 'statut',
    notes: 'observations',
    costs: 'ventilation des frais',
    legs: 'étapes de transport',
    employees: 'missionnaires',
    current_place_id: 'affectation',
};

// Références vers les listes de référence, affichées par leur nom plutôt que par leur numéro.
const { referentials, load: loadReferentials } = useReferentials();
const referenceFields: Record<string, () => { id: number; name?: string; label?: string }[]> = {
    current_place_id: () => referentials.value?.places ?? [],
    departure_place_id: () => referentials.value?.places ?? [],
    destination_place_id: () => referentials.value?.places ?? [],
    zone_id: () => referentials.value?.zones ?? [],
    category_id: () => referentials.value?.categories ?? [],
    service_id: () => referentials.value?.services ?? [],
    mission_object_id: () => referentials.value?.mission_objects ?? [],
};

function formatField(key: string, value: unknown): string {
    const list = referenceFields[key];
    if (list && typeof value === 'number') {
        const item = list().find((entry) => entry.id === value);
        if (item) {
            return item.name ?? item.label ?? String(value);
        }
    }
    return formatValue(value);
}

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
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(value)) {
        return formatDateTime(value.replace(' ', 'T'));
    }
    // Les listes (étapes, missionnaires…) sont résumées ; le détail reste dans le journal.
    if (Array.isArray(value)) {
        return `${value.length} élément${value.length > 1 ? 's' : ''}`;
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
            parts.push(before === undefined ? `${label} : ${formatField(key, after)}` : `${label} : ${formatField(key, before)} → ${formatField(key, after)}`);
        } else {
            parts.push(`${label} : ${formatField(key, before)}`);
        }
    }
    return parts.join(' · ');
}

watch(action, () => {
    page.value = 1;
    void load();
});
watch(page, load);
onMounted(async () => {
    await Promise.all([loadReferentials(), load()]);
});
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
