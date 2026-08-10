<script setup lang="ts">
/**
 * Point d'entrée du module NotificationCenter.
 * Monte la cloche, le drawer et les toasts.
 * L'intégration projet (Inertia, API, temps réel) se fait en dehors de ce composant.
 */
import NotificationBell from './NotificationBell.vue'
import NotificationDrawer from './NotificationDrawer.vue'
import NotificationToastContainer from './NotificationToastContainer.vue'
</script>

<template>
    <div class="nc-root">
        <NotificationBell />
        <NotificationDrawer />
        <NotificationToastContainer />
    </div>
</template>

<style scoped>
.nc-root {
    display: inline-flex;
    align-items: center;
}
</style>
