<script setup lang="ts">
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

interface Props {
    title?: string;
    message?: string;
    dismissible?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    title: 'Attention',
    message: '',
    dismissible: true,
});
</script>

<template>
    <Alert variant="warning" :dismissible="dismissible">
        <AlertTitle>{{ title }}</AlertTitle>
        <AlertDescription v-if="message">
            {{ message }}
        </AlertDescription>
        <AlertDescription v-else>
            <slot />
        </AlertDescription>
    </Alert>
</template>
