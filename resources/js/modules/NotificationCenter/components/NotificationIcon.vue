<script setup lang="ts">
import { useNotificationUi } from '../composables/useNotificationUi'
import type { NotificationPriority } from '../types'
import { Package } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'

const props = defineProps<{
    type: string
    icon?: string
    priority: NotificationPriority
    resolved?: boolean
    imageUrl?: string | null
    productName?: string | null
    size?: number
}>()

const { iconMap, defaultIcon } = useNotificationUi()

const imageFailed = ref(false)

watch(
    () => props.imageUrl,
    () => {
        imageFailed.value = false
    },
)

const showImage = computed(() => Boolean(props.imageUrl) && !imageFailed.value)

const iconComponent = computed(() => {
    if (props.icon && iconMap[props.icon]) {
        return iconMap[props.icon]
    }
    if (iconMap[props.type]) {
        return iconMap[props.type]
    }
    return defaultIcon
})

const priorityClass = computed(
    () =>
        `nc-icon nc-icon--${props.priority} ${props.resolved ? 'nc-icon--resolved' : ''} ${showImage.value ? 'nc-icon--image' : ''}`,
)

const imageAlt = computed(() =>
    props.productName ? `Image du produit ${props.productName}` : 'Image du produit',
)

function onImageError() {
    imageFailed.value = true
}
</script>

<template>
    <div class="nc-icon-wrap" :class="priorityClass" aria-hidden="true">
        <img
            v-if="showImage"
            :src="imageUrl!"
            :alt="imageAlt"
            class="nc-icon__image"
            loading="lazy"
            decoding="async"
            @error="onImageError"
        />
        <div v-else class="nc-icon__fallback">
            <Package v-if="productName" :size="size ?? 18" stroke-width="2" />
            <component v-else :is="iconComponent" :size="size ?? 18" stroke-width="2" />
        </div>
    </div>
</template>
