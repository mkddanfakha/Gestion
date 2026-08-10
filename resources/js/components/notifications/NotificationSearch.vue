<script setup lang="ts">
import { notificationConfig } from './notification.config'
import { Search } from 'lucide-vue-next'

defineProps<{
    modelValue: string
}>()

const emit = defineEmits<{
    'update:modelValue': [value: string]
}>()
</script>

<template>
    <div class="nc-search">
        <Search class="nc-search__icon" :size="16" aria-hidden="true" />
        <input
            :value="modelValue"
            type="search"
            class="nc-search__input"
            :placeholder="notificationConfig.texts.searchPlaceholder"
            aria-label="Rechercher dans les notifications"
            @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
        />
    </div>
</template>
