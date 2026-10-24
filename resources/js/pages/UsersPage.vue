<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { errorsFrom, http } from '@/api';
import FieldError from '@/components/FieldError.vue';
import type { User, ValidationErrors } from '@/types';

const users = ref<User[]>([]);
const loading = ref(true);
const message = ref('');

const emptyForm = () => ({ name: '', email: '', password: '', password_confirmation: '' });
const form = reactive(emptyForm());
const editing = ref<User | null>(null);
const showForm = ref(false);
const errors = ref<ValidationErrors>({});
const submitting = ref(false);

async function load(): Promise<void> {
    loading.value = true;
    const { data } = await http.get<{ data: User[] }>('/api/users');
    users.value = data.data;
    loading.value = false;
}

function openCreate(): void {
    editing.value = null;
    Object.assign(form, emptyForm());
    errors.value = {};
    showForm.value = true;
}

function openEdit(user: User): void {
    editing.value = user;
    Object.assign(form, { ...emptyForm(), name: user.name, email: user.email });
    errors.value = {};
    showForm.value = true;
}

async function submit(): Promise<void> {
    submitting.value = true;
    errors.value = {};
    message.value = '';

    // En modification, un mot de passe vide signifie « ne pas le changer ».
    const payload: Record<string, string> = { name: form.name, email: form.email };
    if (!editing.value || form.password !== '') {
        payload.password = form.password;
        payload.password_confirmation = form.password_confirmation;
    }

    try {
        if (editing.value) {
            await http.patch(`/api/users/${editing.value.id}`, payload);
        } else {
            await http.post('/api/users', payload);
        }
        showForm.value = false;
        await load();
    } catch (error) {
        const result = errorsFrom(error);
        errors.value = result.errors;
        message.value = Object.keys(result.errors).length ? '' : result.message;
    } finally {
        submitting.value = false;
    }
}

async function toggleActive(user: User): Promise<void> {
    if (user.is_active && !window.confirm(`Désactiver le compte de ${user.name} ? Ses comptes créés vous seront confiés.`)) {
        return;
    }

    message.value = '';
    try {
        await http.post(`/api/users/${user.id}/${user.is_active ? 'deactivate' : 'reactivate'}`);
        await load();
    } catch (error) {
        message.value = errorsFrom(error).message;
    }
}

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleDateString('fr-FR') : '';
}

onMounted(load);
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Comptes contrôleurs</h1>
                <p class="mt-1 text-sm text-slate-600">Vous pouvez modifier ou désactiver uniquement les comptes que vous avez créés.</p>
            </div>
            <button type="button" class="btn-primary" @click="openCreate">Nouveau compte</button>
        </div>

        <p v-if="message" class="text-sm text-red-700">{{ message }}</p>

        <form v-if="showForm" class="card grid gap-4 p-5 sm:grid-cols-2" @submit.prevent="submit">
            <h2 class="font-medium sm:col-span-2">{{ editing ? `Modifier ${editing.name}` : 'Nouveau compte' }}</h2>
            <div>
                <label for="name" class="mb-1 block text-sm font-medium">Nom</label>
                <input id="name" v-model="form.name" class="field" />
                <FieldError :messages="errors.name" />
            </div>
            <div>
                <label for="email" class="mb-1 block text-sm font-medium">Adresse e-mail</label>
                <input id="email" v-model="form.email" type="email" class="field" />
                <FieldError :messages="errors.email" />
            </div>
            <div>
                <label for="password" class="mb-1 block text-sm font-medium">
                    {{ editing ? 'Nouveau mot de passe (laisser vide pour ne pas changer)' : 'Mot de passe' }}
                </label>
                <input id="password" v-model="form.password" type="password" autocomplete="new-password" class="field" />
                <FieldError :messages="errors.password" />
            </div>
            <div>
                <label for="password_confirmation" class="mb-1 block text-sm font-medium">Confirmation</label>
                <input id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" class="field" />
            </div>
            <div class="flex gap-2 sm:col-span-2">
                <button type="submit" class="btn-primary" :disabled="submitting">Enregistrer</button>
                <button type="button" class="btn-secondary" @click="showForm = false">Annuler</button>
            </div>
        </form>

        <div class="card overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th class="px-4 py-2 font-medium">Nom</th>
                        <th class="px-4 py-2 font-medium">E-mail</th>
                        <th class="px-4 py-2 font-medium">Créé par</th>
                        <th class="px-4 py-2 font-medium">Créé le</th>
                        <th class="px-4 py-2 font-medium">État</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-if="loading">
                        <td colspan="6" class="px-4 py-6 text-center text-slate-500">Chargement…</td>
                    </tr>
                    <tr v-for="user in users" v-else :key="user.id" :class="{ 'text-slate-400': !user.is_active }">
                        <td class="px-4 py-2 font-medium">{{ user.name }}</td>
                        <td class="px-4 py-2">{{ user.email }}</td>
                        <td class="px-4 py-2">{{ user.creator?.name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ formatDate(user.created_at) }}</td>
                        <td class="px-4 py-2">
                            <span v-if="user.is_active" class="rounded bg-emerald-50 px-2 py-0.5 text-emerald-700">Actif</span>
                            <span v-else class="rounded bg-slate-100 px-2 py-0.5 whitespace-nowrap text-slate-600">Désactivé le {{ formatDate(user.deactivated_at) }}</span>
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <template v-if="user.can_manage">
                                <button type="button" class="btn-secondary mr-2" @click="openEdit(user)">Modifier</button>
                                <button type="button" :class="user.is_active ? 'btn-danger' : 'btn-secondary'" @click="toggleActive(user)">
                                    {{ user.is_active ? 'Désactiver' : 'Réactiver' }}
                                </button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
