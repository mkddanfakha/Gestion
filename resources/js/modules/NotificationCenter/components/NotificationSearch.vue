<script setup lang="ts">
import { useNotificationUi } from '../composables/useNotificationUi'
import { Search } from 'lucide-vue-next'

defineProps<{
    modelValue: string
}>()

const emit = defineEmits<{
    'update:modelValue': [value: string]
}>()

const { texts } = useNotificationUi()
</script>

<template>
    <div class="nc-search">
        <Search class="nc-search__icon" :size="16" aria-hidden="true" />
        <input
            :value="modelValue"
            type="search"
            class="nc-search__input"
            :placeholder="texts.searchPlaceholder"
            aria-label="Rechercher dans les notifications"
            @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
        />
    </div>
</template>
