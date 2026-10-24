<script setup lang="ts">
defineProps<{ messages?: string[] }>();
</script>

<template>
    <p v-if="messages?.length" class="mt-1 text-sm text-red-700">{{ messages[0] }}</p>
</template>
