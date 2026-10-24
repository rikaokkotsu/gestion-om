<script setup lang="ts">
import { reactive, ref } from 'vue';
import { errorsFrom, http } from '@/api';
import { auth } from '@/auth';
import FieldError from '@/components/FieldError.vue';
import type { ValidationErrors } from '@/types';

const form = reactive({ current_password: '', password: '', password_confirmation: '' });
const errors = ref<ValidationErrors>({});
const message = ref('');
const done = ref(false);
const submitting = ref(false);

async function submit(): Promise<void> {
    submitting.value = true;
    errors.value = {};
    message.value = '';
    done.value = false;

    try {
        await http.put('/api/me/password', form);
        Object.assign(form, { current_password: '', password: '', password_confirmation: '' });
        done.value = true;
    } catch (error) {
        const result = errorsFrom(error);
        errors.value = result.errors;
        message.value = Object.keys(result.errors).length ? '' : result.message;
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <div class="max-w-lg space-y-6">
        <div>
            <h1 class="text-2xl font-semibold">Mon compte</h1>
            <p class="mt-1 text-slate-600">{{ auth.user?.name }} · {{ auth.user?.email }}</p>
            <p v-if="auth.user?.creator" class="mt-1 text-sm text-slate-500">Compte créé par {{ auth.user.creator.name }}</p>
        </div>

        <form class="card space-y-4 p-5" @submit.prevent="submit">
            <h2 class="font-medium">Changer mon mot de passe</h2>

            <div>
                <label for="current_password" class="mb-1 block text-sm font-medium">Mot de passe actuel</label>
                <input id="current_password" v-model="form.current_password" type="password" autocomplete="current-password" class="field" />
                <FieldError :messages="errors.current_password" />
            </div>
            <div>
                <label for="new_password" class="mb-1 block text-sm font-medium">Nouveau mot de passe</label>
                <input id="new_password" v-model="form.password" type="password" autocomplete="new-password" class="field" />
                <p class="mt-1 text-xs text-slate-500">10 caractères minimum, avec au moins une lettre et un chiffre.</p>
                <FieldError :messages="errors.password" />
            </div>
            <div>
                <label for="new_password_confirmation" class="mb-1 block text-sm font-medium">Confirmation</label>
                <input id="new_password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" class="field" />
            </div>

            <p v-if="message" class="text-sm text-red-700">{{ message }}</p>
            <p v-if="done" class="text-sm text-emerald-700">Mot de passe modifié.</p>

            <button type="submit" class="btn-primary" :disabled="submitting">Enregistrer</button>
        </form>
    </div>
</template>
