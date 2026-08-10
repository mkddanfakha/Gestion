<script setup lang="ts">
import NotificationIcon from './NotificationIcon.vue'
import NotificationPriorityBadge from './NotificationPriorityBadge.vue'
import { useNotifications } from '../composables/useNotifications'
import { useNotificationUi } from '../composables/useNotificationUi'
import type { Notification } from '../types'
import type { NotificationProduct } from '../types/NotificationCounts'
import { formatNotificationTime, isNotificationResolved } from '../utils/dateGroups'
import { formatExpirationStatus } from '../utils/expirationStatus'
import { Archive, Check, Trash2 } from 'lucide-vue-next'
import { computed } from 'vue'

const props = defineProps<{
    notification: Notification
    read?: boolean
}>()

const emit = defineEmits<{
    read: [notification: Notification]
    archive: [notification: Notification]
    remove: [notification: Notification]
}>()

const { openNotificationItem, expirationClock } = useNotifications()
const { texts } = useNotificationUi()

const isInvoiceDue = computed(() => props.notification.type === 'invoice_due')

const isExpirationAlert = computed(() =>
    props.notification.type === 'product_expired' || props.notification.type === 'product_expiring',
)

const productPreview = computed(() => {
    const single = props.notification.metadata?.product as NotificationProduct | undefined
    return single ?? null
})

const productName = computed(() => {
    const fromMeta = productPreview.value?.name ?? props.notification.metadata?.product_name
    return typeof fromMeta === 'string' ? fromMeta : null
})

const imageUrl = computed(() => {
    const fromMeta = productPreview.value?.image_url ?? props.notification.metadata?.image_url
    return typeof fromMeta === 'string' && fromMeta.length > 0 ? fromMeta : null
})

const statusLabel = computed(() => {
    if (isExpirationAlert.value && expirationStatus.value) {
        return expirationStatus.value.statusBadge
    }

    if (productPreview.value?.status) {
        return productPreview.value.status
    }

    return props.notification.title
})

const expirationStatus = computed(() => {
    void expirationClock.value

    const expirationDate = productPreview.value?.expiration_date
    if (!expirationDate) {
        return null
    }

    return formatExpirationStatus(expirationDate)
})

const expirationDateLine = computed(() => {
    if (!isExpirationAlert.value || !expirationStatus.value) {
        return null
    }

    return `Date d'expiration : ${expirationStatus.value.formattedDate}`
})

const expirationCountdownLine = computed(() => expirationStatus.value?.label ?? null)

const expirationHintClass = computed(() => {
    if (!expirationStatus.value) {
        return ''
    }

    if (expirationStatus.value.status === 'expired') {
        return 'nc-item__hint--expired'
    }

    return 'nc-item__hint--warning'
})

const invoiceTitleLine = computed(() => {
    const preview = productPreview.value
    if (preview?.reference) {
        return `Facture ${preview.reference}`
    }

    if (preview?.name) {
        return preview.name
    }

    return null
})

function formatCurrency(amount: number): string {
    return `${new Intl.NumberFormat('fr-FR').format(amount)} Fcfa`
}

const invoiceDetails = computed(() => {
    if (!isInvoiceDue.value) {
        return []
    }

    const preview = productPreview.value
    if (!preview) {
        return []
    }

    const lines: string[] = []

    if (preview.customer) {
        lines.push(`Client : ${preview.customer}`)
    }

    if (preview.remaining_amount !== null && preview.remaining_amount !== undefined) {
        lines.push(`Montant : ${formatCurrency(preview.remaining_amount)}`)
    }

    if (preview.due_date) {
        lines.push(`Échéance : ${preview.due_date}`)
    }

    return lines
})

const invoiceHint = computed(() => {
    if (!isInvoiceDue.value) {
        return null
    }

    return productPreview.value?.status ?? props.notification.description
})

const stockLine = computed(() => {
    if (isInvoiceDue.value || isExpirationAlert.value) {
        return null
    }

    const product = productPreview.value
    if (!product) {
        return null
    }

    const stock = product.stock ?? product.stock_quantity
    if (stock === null || stock === undefined) {
        return null
    }

    const minimum = product.minimum_stock ?? product.min_stock_level
    if (minimum !== null && minimum !== undefined) {
        return `Stock : ${stock} / Seuil : ${minimum}`
    }

    return `Stock : ${stock}`
})

const referenceLine = computed(() => {
    if (isInvoiceDue.value) {
        return null
    }

    const product = productPreview.value
    const reference = product?.reference ?? product?.sku
    return reference ? `Réf : ${reference}` : null
})

const categoryLine = computed(() => {
    const category = productPreview.value?.category
    return category ? String(category) : null
})

const itemClass = computed(() => [
    'nc-item',
    `nc-item--${props.notification.priority}`,
    props.read ? 'nc-item--read' : 'nc-item--unread',
    isNotificationResolved(props.notification.status, props.notification.resolved_at) || props.read
        ? 'nc-item--resolved'
        : '',
    imageUrl.value ? 'nc-item--with-image' : '',
])

function openNotification() {
    openNotificationItem(props.notification)
}
</script>

<template>
    <article
        class="nc-item-wrap"
        :class="{ 'nc-item-wrap--unread': !read }"
    >
        <button
            type="button"
            class="nc-item__main"
            :class="itemClass"
            @click="openNotification"
        >
            <NotificationIcon
                :type="notification.type"
                :icon="notification.icon"
                :priority="notification.priority"
                :resolved="read || isNotificationResolved(notification.status, notification.resolved_at)"
                :image-url="imageUrl"
                :product-name="productName"
            />
            <div class="nc-item__body">
                <div class="nc-item__top">
                    <div class="nc-item__titles">
                        <h4 class="nc-item__title">
                            {{ isInvoiceDue ? notification.title : (productName ?? notification.title) }}
                        </h4>
                        <p v-if="isInvoiceDue && invoiceTitleLine" class="nc-item__product">{{ invoiceTitleLine }}</p>
                        <p v-else-if="productName" class="nc-item__product" :class="{ 'nc-item__product--expiration': isExpirationAlert }">{{ statusLabel }}</p>
                    </div>
                    <time class="nc-item__time" :datetime="notification.created_at">
                        {{ formatNotificationTime(notification.created_at) }}
                    </time>
                </div>

                <template v-if="isInvoiceDue">
                    <p v-for="line in invoiceDetails" :key="line" class="nc-item__desc">{{ line }}</p>
                    <p v-if="invoiceHint" class="nc-item__hint">{{ invoiceHint }}</p>
                </template>
                <template v-else-if="isExpirationAlert">
                    <p v-if="expirationDateLine" class="nc-item__desc">{{ expirationDateLine }}</p>
                    <p v-if="expirationCountdownLine" class="nc-item__hint" :class="expirationHintClass">{{ expirationCountdownLine }}</p>
                </template>
                <template v-else>
                    <p v-if="stockLine" class="nc-item__desc">{{ stockLine }}</p>
                    <p v-else class="nc-item__desc">{{ notification.description }}</p>
                    <p v-if="referenceLine" class="nc-item__ref">{{ referenceLine }}</p>
                </template>

                <div class="nc-item__meta">
                    <NotificationPriorityBadge :priority="notification.priority" />
                    <span v-if="categoryLine" class="nc-item__category">{{ categoryLine }}</span>
                    <span
                        class="nc-item__status"
                        :class="
                            read || isNotificationResolved(notification.status, notification.resolved_at)
                                ? 'nc-item__status--resolved'
                                : 'nc-item__status--active'
                        "
                    >
                        {{
                            read || isNotificationResolved(notification.status, notification.resolved_at)
                                ? texts.statusResolved
                                : texts.statusActive
                        }}
                    </span>
                </div>
            </div>
        </button>

        <div class="nc-item__actions">
            <button
                type="button"
                class="nc-item__action"
                title="Marquer comme lue"
                aria-label="Marquer comme lue"
                @click.stop="emit('read', notification)"
            >
                <Check :size="16" />
            </button>
            <button
                type="button"
                class="nc-item__action"
                title="Archiver"
                aria-label="Archiver"
                @click.stop="emit('archive', notification)"
            >
                <Archive :size="16" />
            </button>
            <button
                type="button"
                class="nc-item__action nc-item__action--danger"
                title="Supprimer"
                aria-label="Supprimer"
                @click.stop="emit('remove', notification)"
            >
                <Trash2 :size="16" />
            </button>
        </div>
    </article>
</template>

<style scoped>
.nc-item__ref,
.nc-item__hint {
    margin: 0.15rem 0 0;
    font-size: 0.72rem;
    color: #64748b;
}

.nc-item__hint {
    font-weight: 600;
}

.nc-item__hint--expired {
    color: #dc2626;
}

.nc-item__hint--warning {
    color: #d97706;
}

.nc-item__product--expiration {
    font-weight: 600;
}

.nc-item__category {
    font-size: 0.68rem;
    color: #94a3b8;
}
</style>
