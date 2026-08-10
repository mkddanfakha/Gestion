<script setup lang="ts">
import { useNotifications } from '../composables/useNotifications'
import type { NotificationProduct } from '../types/NotificationCounts'
import { formatExpirationStatus } from '../utils/expirationStatus'
import { Package } from 'lucide-vue-next'
import { computed, ref } from 'vue'

const props = defineProps<{
    products: NotificationProduct[]
    notificationType: string
}>()

const { navigate, closeDrawer, expirationClock } = useNotifications()
const failedImages = ref<Record<number, boolean>>({})

const showExpirationDetails = computed(() =>
    ['product_expired', 'product_expiring'].includes(props.notificationType),
)

function productImage(product: NotificationProduct): string | null {
    if (failedImages.value[product.id]) {
        return null
    }

    return product.image_url ?? null
}

function onImageError(productId: number) {
    failedImages.value = { ...failedImages.value, [productId]: true }
}

function openProduct(product: NotificationProduct) {
    const url = product.url ?? `/products/${product.id}`
    closeDrawer()
    navigate(url)
}

function stockLine(product: NotificationProduct): string | null {
    const stock = product.stock ?? product.stock_quantity
    if (stock === null || stock === undefined) {
        return null
    }

    const unit = product.unit ? ` ${product.unit}` : ''
    return `Stock : ${stock}${unit}`
}

function minimumStockLine(product: NotificationProduct): string | null {
    const minimum = product.minimum_stock ?? product.min_stock_level
    if (minimum === null || minimum === undefined) {
        return null
    }

    return `Seuil minimum : ${minimum}`
}

function expirationDateLine(product: NotificationProduct): string | null {
    void expirationClock.value

    const status = formatExpirationStatus(product.expiration_date)
    if (!status) {
        return null
    }

    return `Date d'expiration : ${status.formattedDate}`
}

function expirationCountdownLine(product: NotificationProduct): string | null {
    void expirationClock.value

    return formatExpirationStatus(product.expiration_date)?.label ?? null
}

function expirationStatusBadge(product: NotificationProduct): string | null {
    void expirationClock.value

    return formatExpirationStatus(product.expiration_date)?.statusBadge ?? null
}

function referenceLine(product: NotificationProduct): string | null {
    const reference = product.reference ?? product.sku
    if (!reference) {
        return null
    }

    return `Réf. : ${reference}`
}

function showStockDetails(): boolean {
    return ['stock_out', 'low_stock'].includes(props.notificationType)
}
</script>

<template>
    <div class="nc-group-products" role="list" :aria-label="`${products.length} élément(s) concerné(s)`">
        <article
            v-for="product in products"
            :key="product.id"
            class="nc-group-product"
            role="listitem"
        >
            <button
                type="button"
                class="nc-group-product__btn"
                @click="openProduct(product)"
            >
                <div class="nc-group-product__media" aria-hidden="true">
                    <img
                        v-if="productImage(product)"
                        :src="productImage(product)!"
                        :alt="`Image de ${product.name}`"
                        class="nc-group-product__image"
                        loading="lazy"
                        decoding="async"
                        @error="onImageError(product.id)"
                    />
                    <div v-else class="nc-group-product__placeholder">
                        <Package :size="18" stroke-width="1.75" />
                    </div>
                </div>

                <div class="nc-group-product__body">
                    <h5 class="nc-group-product__name">{{ product.name }}</h5>
                    <p v-if="showExpirationDetails && expirationStatusBadge(product)" class="nc-group-product__status">
                        {{ expirationStatusBadge(product) }}
                    </p>
                    <p v-if="referenceLine(product)" class="nc-group-product__line">{{ referenceLine(product) }}</p>
                    <p v-if="showStockDetails() && stockLine(product)" class="nc-group-product__line">{{ stockLine(product) }}</p>
                    <p v-if="showStockDetails() && minimumStockLine(product)" class="nc-group-product__line">{{ minimumStockLine(product) }}</p>
                    <p v-if="showExpirationDetails && expirationDateLine(product)" class="nc-group-product__line">{{ expirationDateLine(product) }}</p>
                    <p v-if="showExpirationDetails && expirationCountdownLine(product)" class="nc-group-product__line nc-group-product__line--strong">
                        {{ expirationCountdownLine(product) }}
                    </p>
                    <p v-if="product.category" class="nc-group-product__line nc-group-product__line--muted">{{ product.category }}</p>
                    <p v-if="!showExpirationDetails && product.status" class="nc-group-product__status">{{ product.status }}</p>
                </div>
            </button>
        </article>
    </div>
</template>

<style scoped>
.nc-group-product__line--strong {
    font-weight: 600;
}
</style>
