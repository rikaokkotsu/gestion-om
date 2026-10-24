<script setup lang="ts">
import { useRouter } from 'vue-router';
import { auth, logout } from '@/auth';

const router = useRouter();

const links = [
    { to: '/', label: 'Accueil' },
    { to: '/comptes', label: 'Comptes' },
    { to: '/journal', label: "Journal d'audit" },
];

async function signOut(): Promise<void> {
    await logout();
    await router.push({ name: 'login' });
}
</script>

<template>
    <div class="min-h-screen">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-8 gap-y-2 px-4 py-3">
                <RouterLink to="/" class="text-base font-semibold text-slate-900">Gestion OM</RouterLink>
                <nav class="flex flex-1 flex-wrap gap-1">
                    <RouterLink
                        v-for="link in links"
                        :key="link.to"
                        :to="link.to"
                        class="rounded-md px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100"
                        exact-active-class="bg-slate-100 font-medium text-slate-900"
                    >
                        {{ link.label }}
                    </RouterLink>
                </nav>
                <div class="flex items-center gap-3 text-sm">
                    <RouterLink to="/mon-compte" class="text-slate-600 hover:text-slate-900">{{ auth.user?.name }}</RouterLink>
                    <button type="button" class="btn-secondary" @click="signOut">Se déconnecter</button>
                </div>
            </div>
        </header>
        <main class="mx-auto max-w-6xl px-4 py-8">
            <slot />
        </main>
    </div>
</template>
