<script setup lang="ts">
defineProps<{
    loading?: boolean
}>()
</script>

<template>
    <div v-if="loading" class="nc-loading" role="status" aria-live="polite">
        <div v-for="n in 4" :key="n" class="nc-loading__row">
            <div class="nc-loading__avatar shimmer"></div>
            <div class="nc-loading__content">
                <div class="nc-loading__line shimmer"></div>
                <div class="nc-loading__line nc-loading__line--short shimmer"></div>
            </div>
        </div>
    </div>
</template>
