<script setup lang="ts">
import { auth } from '@/auth';
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold">Bonjour {{ auth.user?.name }}</h1>
            <p class="mt-1 text-slate-600">Outil de contrôle des ordres de mission.</p>
        </div>

        <div class="card p-5 text-sm text-slate-700">
            <p>
                Cette première version couvre les comptes contrôleurs et le journal d'audit. Les référentiels, les
                barèmes, la saisie des OM et le contrôle des anomalies arrivent dans les étapes suivantes.
            </p>
        </div>
    </div>
</template>
