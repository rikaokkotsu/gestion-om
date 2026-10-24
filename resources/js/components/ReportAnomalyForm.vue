<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { errorsFrom, http } from '@/api';
import FieldError from '@/components/FieldError.vue';
import { employeeName } from '@/format';
import { useReferentials } from '@/referentials';
import type { Anomaly, MissionOrder, ValidationErrors } from '@/types';

const props = defineProps<{ order: MissionOrder }>();
const emit = defineEmits<{ reported: [anomaly: Anomaly]; cancel: [] }>();
const { referentials } = useReferentials();

const form = reactive({
    category: '',
    member_id: '' as number | '',
    nature: '',
    priority: '',
    rule_code: '',
    description: '',
    comment: '',
});
const errors = ref<ValidationErrors>({});
const message = ref('');
const saving = ref(false);

const rules = computed(() => (referentials.value?.rules ?? []).filter((rule) => !form.category || rule.category === form.category));

async function submit(): Promise<void> {
    saving.value = true;
    errors.value = {};
    message.value = '';
    try {
        const { data } = await http.post<{ data: Anomaly }>('/api/anomalies', {
            mission_order_id: props.order.id,
            category: form.category || null,
            member_id: form.member_id === '' ? null : form.member_id,
            nature: form.nature || null,
            priority: form.priority || null,
            rule_code: form.rule_code || null,
            description: form.description,
            comment: form.comment || null,
        });
        emit('reported', data.data);
    } catch (error) {
        ({ errors: errors.value, message: message.value } = errorsFrom(error));
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <form class="space-y-4 rounded-md border border-slate-200 bg-slate-50 p-4" @submit.prevent="submit">
        <p class="text-sm text-slate-600">
            Le signalement est enregistré comme confirmé, sans modifier l'OM. Il rejoint les anomalies de l'OM et la liste générale.
        </p>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="report-category" class="mb-1 block text-sm font-medium">Catégorie</label>
                <select id="report-category" v-model="form.category" class="field" required @change="form.rule_code = ''">
                    <option value="" disabled>Choisir</option>
                    <option v-for="category in referentials?.anomaly_categories" :key="category.value" :value="category.value">
                        {{ category.value }}. {{ category.label }}
                    </option>
                </select>
                <FieldError :messages="errors.category" />
            </div>
            <div>
                <label for="report-member" class="mb-1 block text-sm font-medium">Missionnaire concerné</label>
                <select id="report-member" v-model="form.member_id" class="field">
                    <option value="">Aucun en particulier</option>
                    <option v-for="member in order.employees" :key="member.id" :value="member.id">
                        {{ employeeName(member.employee) }} ({{ member.employee.matricule }})
                    </option>
                </select>
                <FieldError :messages="errors.member_id" />
            </div>
            <div>
                <label for="report-nature" class="mb-1 block text-sm font-medium">Nature</label>
                <select id="report-nature" v-model="form.nature" class="field">
                    <option value="">Non précisée</option>
                    <option v-for="nature in referentials?.anomaly_natures" :key="nature.value" :value="nature.value">{{ nature.label }}</option>
                </select>
            </div>
            <div>
                <label for="report-priority" class="mb-1 block text-sm font-medium">Priorité</label>
                <select id="report-priority" v-model="form.priority" class="field">
                    <option value="">Selon la nature (moyenne par défaut)</option>
                    <option v-for="priority in referentials?.anomaly_priorities" :key="priority.value" :value="priority.value">{{ priority.label }}</option>
                </select>
            </div>
            <div class="sm:col-span-2">
                <label for="report-rule" class="mb-1 block text-sm font-medium">Règle du catalogue concernée</label>
                <select id="report-rule" v-model="form.rule_code" class="field">
                    <option value="">Aucune</option>
                    <option v-for="rule in rules" :key="rule.code" :value="rule.code">{{ rule.code }} · {{ rule.label }}</option>
                </select>
            </div>
            <div class="sm:col-span-2">
                <label for="report-description" class="mb-1 block text-sm font-medium">Description du problème</label>
                <textarea id="report-description" v-model="form.description" rows="3" class="field" required></textarea>
                <FieldError :messages="errors.description" />
            </div>
            <div class="sm:col-span-2">
                <label for="report-comment" class="mb-1 block text-sm font-medium">Commentaire</label>
                <textarea id="report-comment" v-model="form.comment" rows="2" class="field"></textarea>
            </div>
        </div>
        <p v-if="message && Object.keys(errors).length === 0" class="text-sm text-red-700">{{ message }}</p>
        <div class="flex gap-2">
            <button type="submit" class="btn-primary" :disabled="saving">Enregistrer le signalement</button>
            <button type="button" class="btn-secondary" @click="emit('cancel')">Annuler</button>
        </div>
    </form>
</template>
