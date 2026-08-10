<script setup lang="ts">
defineProps<{
    count: number
}>()
</script>

<template>
    <span
        v-if="count > 0"
        class="nc-badge"
        :aria-label="`${count} notifications non lues`"
    >
        {{ count > 99 ? '99+' : count }}
    </span>
</template>
