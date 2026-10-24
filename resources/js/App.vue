<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import AppLayout from '@/layouts/AppLayout.vue';

const route = useRoute();
const isGuestPage = computed(() => route.meta.guest === true);
</script>

<template>
    <RouterView v-if="isGuestPage" />
    <AppLayout v-else>
        <RouterView />
    </AppLayout>
</template>
