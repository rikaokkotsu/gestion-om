<script setup lang="ts">
import { reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { errorsFrom } from '@/api';
import { login } from '@/auth';
import FieldError from '@/components/FieldError.vue';
import type { ValidationErrors } from '@/types';

const route = useRoute();
const router = useRouter();

const form = reactive({ email: '', password: '' });
const errors = ref<ValidationErrors>({});
const message = ref('');
const submitting = ref(false);

async function submit(): Promise<void> {
    submitting.value = true;
    errors.value = {};
    message.value = '';

    try {
        await login(form.email, form.password);
        const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/';
        await router.push(redirect);
    } catch (error) {
        const result = errorsFrom(error);
        errors.value = result.errors;
        message.value = Object.keys(result.errors).length ? '' : result.message;
        form.password = '';
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center px-4">
        <form class="card w-full max-w-sm space-y-5 p-6" @submit.prevent="submit">
            <div>
                <h1 class="text-xl font-semibold">Gestion OM</h1>
                <p class="mt-1 text-sm text-slate-600">Connexion réservée aux contrôleurs.</p>
            </div>

            <div>
                <label for="email" class="mb-1 block text-sm font-medium">Adresse e-mail</label>
                <input id="email" v-model="form.email" type="email" autocomplete="username" required class="field" />
                <FieldError :messages="errors.email" />
            </div>

            <div>
                <label for="password" class="mb-1 block text-sm font-medium">Mot de passe</label>
                <input id="password" v-model="form.password" type="password" autocomplete="current-password" required class="field" />
                <FieldError :messages="errors.password" />
            </div>

            <p v-if="message" class="text-sm text-red-700">{{ message }}</p>

            <button type="submit" class="btn-primary w-full" :disabled="submitting">Se connecter</button>
        </form>
    </div>
</template>
