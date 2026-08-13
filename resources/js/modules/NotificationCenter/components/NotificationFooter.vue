<script setup lang="ts">
import { useNotifications } from '../composables/useNotifications'
import { computed } from 'vue'

const { listTotal, visibleNotifications, hasMore, ui } = useNotifications()

const visibleCount = computed(() => visibleNotifications.value.length)
</script>

<template>
    <footer v-if="visibleCount > 0" class="nc-drawer__footer">
        <p class="nc-drawer__footer-text">
            {{ visibleCount }} / {{ listTotal }}
        </p>
        <p v-if="hasMore" class="nc-drawer__footer-hint">
            {{ ui.texts.loadMore }}
        </p>
    </footer>
</template>
