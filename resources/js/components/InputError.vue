<script setup lang="ts">
defineProps<{
    message?: string;
    id?: string;
}>();
</script>

<template>
    <div v-show="message" :id="id" class="input-error-wrap">
        <p class="input-error" role="alert">
            {{ message }}
        </p>
    </div>
</template>
