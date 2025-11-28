<script setup lang="ts">
import BootstrapLayout from '@/layouts/BootstrapLayout.vue';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <BootstrapLayout :breadcrumbs="breadcrumbs">
        <slot />
    </BootstrapLayout>
</template>
