<script setup lang="ts">
import NotificationCenter from '@notification-center/NotificationCenter.vue'
import { useGestionNotificationCenter } from '@/integrations/notifications/useGestionNotificationCenter'

useGestionNotificationCenter()
</script>

<template>
    <NotificationCenter />
</template>
