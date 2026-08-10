/**
 * Mapper métier → modèle générique Notification.
 * Ce fichier est spécifique au projet Gestion et ne fait PAS partie du module réutilisable.
 */
import { route } from '@/lib/routes'
import type { Notification, NotificationPriority } from '@/modules/NotificationCenter/types'
import type { NotificationProduct } from '@/modules/NotificationCenter/types/NotificationCounts'
import { diffCalendarDays } from '@/modules/NotificationCenter/utils/expirationStatus'
import { formatCurrency } from '@/utils/currencyFormatter'

/** Payload legacy partagé via Inertia (HandleInertiaRequests). */
export interface LegacyNotificationsPayload {
    salesDueToday?: Array<{
        id: number
        sale_number: string
        customer: string
        remaining_amount: number
    }>
    lowStockProducts?: Array<{
        id: number
        name: string
        stock_quantity: number
        unit: string
        category?: { name: string } | null
        image_url?: string | null
        sku?: string | null
        min_stock_level?: number
    }>
    lowStockProductsTotal?: number
    expiringProducts?: Array<{
        id: number
        name: string
        expiration_date: string
        days_until_expiration: number | null
        image_url?: string | null
    }>
    expiringProductsTotal?: number
}

export interface ApiNotificationPayload {
    id: string
    title: string
    description: string
    type: string
    priority: NotificationPriority
    status: Notification['status']
    created_at: string
    read_at?: string | null
    resolved_at?: string | null
    url?: string | null
    icon?: string | null
    metadata?: Record<string, unknown>
}

export interface ApiAlertItemPayload {
    id: string
    type: string
    priority: NotificationPriority
    severity?: NotificationPriority
    title: string
    description: string
    message?: string
    entity_id: number
    legacy_type: string
    product?: NotificationProduct | null
    url?: string | null
    created_at: string
}

export interface RealtimeNotificationPayload {
    type: string
    id?: number
    grouped?: boolean
    count?: number
    entity_ids?: number[]
    products?: NotificationProduct[]
    message?: string
    title?: string
    description?: string
    name?: string
    sale_number?: string
    customer?: string
    stock_quantity?: number
    priority?: NotificationPriority
    status?: string
    image_url?: string | null
    expiration_date?: string
}

const nowIso = () => new Date().toISOString()

function itemId(legacyType: string, entityId: number): string {
    return `${legacyType}:${entityId}`
}

function normalizeProducts(value: unknown): NotificationProduct[] {
    if (!Array.isArray(value)) {
        return []
    }

    return value.filter((item): item is NotificationProduct => {
        return typeof item === 'object' && item !== null && typeof (item as NotificationProduct).id === 'number'
    })
}

function mapLegacyProductPreview(
    product: NonNullable<LegacyNotificationsPayload['lowStockProducts']>[number],
    notificationType: string,
): NotificationProduct {
    const isOut = product.stock_quantity <= 0

    return {
        id: product.id,
        name: product.name,
        reference: product.sku ?? null,
        sku: product.sku ?? null,
        stock: product.stock_quantity,
        stock_quantity: product.stock_quantity,
        minimum_stock: product.min_stock_level ?? null,
        min_stock_level: product.min_stock_level ?? null,
        unit: product.unit,
        image_url: product.image_url ?? null,
        category: product.category?.name ?? null,
        status: isOut ? 'Rupture de stock' : 'Stock faible',
        url: route('products.show', { id: product.id }),
    }
}

function mapLegacyExpiringPreview(
    product: NonNullable<LegacyNotificationsPayload['expiringProducts']>[number],
): NotificationProduct {
    return {
        id: product.id,
        name: product.name,
        image_url: product.image_url ?? null,
        expiration_date: product.expiration_date,
        url: route('products.show', { id: product.id }),
    }
}

function mapGroupedSummary(
    type: string,
    icon: string,
    count: number,
    title: string,
    description: string,
    priority: NotificationPriority,
    url: string,
    products: NotificationProduct[] = [],
    options: {
        id?: string
        legacyType?: string
        entityIds?: number[]
        skipApi?: boolean
    } = {},
): Notification {
    const entityIds = options.entityIds ?? products.map((product) => product.id)

    return {
        id: options.id ?? `${type}:grouped`,
        type,
        icon,
        title,
        description,
        priority,
        status: 'active',
        created_at: nowIso(),
        url,
        metadata: {
            grouped: true,
            count,
            products,
            entity_ids: entityIds,
            group_key: `${type}:grouped`,
            legacy_type: options.legacyType ?? type,
            entity_id: 0,
            skip_api: options.skipApi ?? !/^\d+$/.test(options.id ?? ''),
        },
    }
}

function mapSale(sale: NonNullable<LegacyNotificationsPayload['salesDueToday']>[number]): Notification {
    const preview = {
        id: sale.id,
        name: `Facture ${sale.sale_number}`,
        reference: sale.sale_number,
        customer: sale.customer,
        remaining_amount: sale.remaining_amount,
        status: 'Échéance aujourd\'hui',
        url: route('sales.show', { id: sale.id }),
    }

    return {
        id: itemId('sale_due_today', sale.id),
        type: 'invoice_due',
        icon: 'receipt',
        title: 'Échéance de facture',
        description: `${sale.customer} · ${formatCurrency(sale.remaining_amount)} restant`,
        priority: 'info',
        status: 'active',
        created_at: nowIso(),
        url: route('sales.show', { id: sale.id }),
        metadata: {
            legacy_type: 'sale_due_today',
            entity_id: sale.id,
            product: preview,
        },
    }
}

function mapLowStock(product: NonNullable<LegacyNotificationsPayload['lowStockProducts']>[number]): Notification {
    const isOut = product.stock_quantity <= 0
    const preview = mapLegacyProductPreview(product, isOut ? 'stock_out' : 'low_stock')

    return {
        id: itemId('low_stock', product.id),
        type: isOut ? 'stock_out' : 'low_stock',
        icon: isOut ? 'package' : 'inventory',
        title: isOut ? 'Rupture de stock' : 'Stock faible',
        description: isOut
            ? 'Le produit est actuellement en rupture de stock.'
            : `Stock restant : ${product.stock_quantity} ${product.unit}.`,
        priority: isOut ? 'critical' : 'warning',
        status: 'active',
        created_at: nowIso(),
        url: route('products.show', { id: product.id }),
        metadata: {
            legacy_type: 'low_stock',
            entity_id: product.id,
            product_name: product.name,
            image_url: product.image_url ?? null,
            product: preview,
        },
    }
}

function mapExpiring(product: NonNullable<LegacyNotificationsPayload['expiringProducts']>[number]): Notification {
    const expired = (product.days_until_expiration ?? 0) < 0
    const preview = mapLegacyExpiringPreview(product)

    return {
        id: itemId('expiring_product', product.id),
        type: expired ? 'product_expired' : 'product_expiring',
        icon: 'alert',
        title: expired ? 'Produit périmé' : 'Produit bientôt périmé',
        description: expired
            ? 'Ce produit est périmé.'
            : 'Ce produit expire bientôt.',
        priority: expired ? 'critical' : 'warning',
        status: 'active',
        created_at: nowIso(),
        url: route('products.show', { id: product.id }),
        metadata: {
            legacy_type: 'expiring_product',
            entity_id: product.id,
            product_name: product.name,
            image_url: product.image_url ?? null,
            product: preview,
        },
    }
}

export function mapAlertItemApiPayload(raw: Record<string, unknown>): Notification {
    const payload = raw as unknown as ApiAlertItemPayload
    const product = payload.product ?? null

    return {
        id: String(payload.id),
        type: payload.type as Notification['type'],
        title: payload.title,
        description: payload.description ?? payload.message ?? '',
        priority: payload.priority ?? payload.severity ?? 'info',
        status: 'active',
        created_at: payload.created_at,
        url: payload.url ?? product?.url ?? undefined,
        icon: iconForType(payload.type),
        metadata: {
            legacy_type: payload.legacy_type,
            entity_id: payload.entity_id,
            product,
            product_name: product?.name,
            image_url: product?.image_url ?? null,
            grouped: false,
        },
    }
}

export function mapApiNotification(raw: ApiNotificationPayload): Notification {
    const metadata = raw.metadata ?? {}
    const products = normalizeProducts(metadata.products)

    return {
        id: String(raw.id),
        title: raw.title,
        description: raw.description,
        type: raw.type as Notification['type'],
        priority: raw.priority,
        status: raw.status,
        created_at: raw.created_at,
        read_at: raw.read_at ?? null,
        resolved_at: raw.resolved_at ?? null,
        url: raw.url ?? undefined,
        icon: raw.icon ?? (typeof metadata.icon === 'string' ? metadata.icon : undefined),
        metadata: {
            ...metadata,
            grouped: Boolean(metadata.grouped),
            products,
            count: typeof metadata.count === 'number' ? metadata.count : products.length,
            entity_ids: Array.isArray(metadata.entity_ids) ? metadata.entity_ids : products.map((p) => p.id),
            group_key: typeof metadata.group_key === 'string' ? metadata.group_key : `${raw.type}:grouped`,
            skip_api: false,
        },
    }
}

export function mapLegacyPayload(payload: LegacyNotificationsPayload): Notification[] {
    const items: Notification[] = []

    ;(payload.salesDueToday ?? []).forEach((sale) => items.push(mapSale(sale)))

    const lowStock = payload.lowStockProducts ?? []
    const lowStockTotal = payload.lowStockProductsTotal ?? lowStock.length
    const outOfStock = lowStock.filter((product) => product.stock_quantity <= 0)
    const lowOnly = lowStock.filter((product) => product.stock_quantity > 0)

    if (outOfStock.length > 0) {
        items.unshift(
            mapGroupedSummary(
                'stock_out',
                'package',
                outOfStock.length,
                'Rupture de stock',
                `${outOfStock.length} produit(s) sont actuellement en rupture de stock.`,
                'critical',
                route('products.index'),
                outOfStock.map((product) => mapLegacyProductPreview(product, 'stock_out')),
                { legacyType: 'stock_out', skipApi: true },
            ),
        )
    }

    if (lowOnly.length > 0 && lowStockTotal > lowOnly.length) {
        items.unshift(
            mapGroupedSummary(
                'low_stock',
                'inventory',
                lowStockTotal,
                'Stock faible',
                `${lowStockTotal} produit(s) ont un stock faible.`,
                'warning',
                route('products.index'),
                lowOnly.map((product) => mapLegacyProductPreview(product, 'low_stock')),
                { legacyType: 'low_stock', skipApi: true },
            ),
        )
    }

    lowStock.forEach((product) => items.push(mapLowStock(product)))

    const expiring = payload.expiringProducts ?? []
    const expiringTotal = payload.expiringProductsTotal ?? expiring.length
    if (expiringTotal > 0) {
        items.unshift(
            mapGroupedSummary(
                'product_expiring',
                'alert',
                expiringTotal,
                'Produit bientôt périmé',
                `${expiringTotal} produit(s) expirent bientôt.`,
                'warning',
                route('products.index', { expiration_alert: true }),
                expiring.map(mapLegacyExpiringPreview),
                { legacyType: 'expiring_product', skipApi: true },
            ),
        )
    }
    expiring.forEach((product) => items.push(mapExpiring(product)))

    return dedupeById(items)
}

export function mergeNotificationLists(api: Notification[], legacy: Notification[]): Notification[] {
    const merged = [...api]
    const apiGroupedTypes = new Set(api.filter((item) => item.metadata?.grouped).map((item) => String(item.type)))
    const hiddenEntityKeys = new Set<string>()

    for (const grouped of api) {
        if (!grouped.metadata?.grouped) {
            continue
        }

        const entityIds = Array.isArray(grouped.metadata.entity_ids)
            ? grouped.metadata.entity_ids.map((id) => Number(id))
            : []

        for (const entityId of entityIds) {
            hiddenEntityKeys.add(`${grouped.type}:${entityId}`)
        }
    }

    for (const item of legacy) {
        if (item.metadata?.grouped && apiGroupedTypes.has(String(item.type))) {
            continue
        }

        if (!item.metadata?.grouped) {
            const entityId = Number(item.metadata?.entity_id ?? 0)
            if (entityId > 0 && hiddenEntityKeys.has(`${item.type}:${entityId}`)) {
                continue
            }
        }

        merged.push(item)
    }

    return dedupeById(merged)
}

export function mapRealtimePayload(payload: RealtimeNotificationPayload): Notification | null {
    const type = payload.type
    const id = payload.id ?? 0
    const notificationType = resolveNotificationType(type)

    if (payload.grouped) {
        const products = normalizeProducts(payload.products)
        const count = payload.count ?? products.length

        return mapGroupedSummary(
            notificationType,
            iconForType(notificationType),
            count,
            payload.title ?? groupedTitle(notificationType),
            payload.description ?? payload.message ?? groupedMessage(notificationType, count),
            priorityFromPayload(payload, notificationType),
            hrefForType(notificationType),
            products,
            {
                id: `${notificationType}:grouped`,
                legacyType: type,
                entityIds: payload.entity_ids ?? products.map((product) => product.id),
                skipApi: true,
            },
        )
    }

    if (type === 'sale_due_today' && id) {
        return {
            id: itemId(type, id),
            type: 'invoice_due',
            icon: 'receipt',
            title: 'Échéance de facture',
            description: payload.message ?? (payload.customer ?? 'Nouvelle alerte de paiement'),
            priority: 'info',
            status: 'active',
            created_at: nowIso(),
            url: route('sales.show', { id }),
            metadata: {
                legacy_type: type,
                entity_id: id,
                product: {
                    id,
                    name: payload.sale_number ? `Facture ${payload.sale_number}` : 'Facture',
                    reference: payload.sale_number ?? null,
                    customer: payload.customer ?? null,
                    status: 'Échéance de facture',
                    url: route('sales.show', { id }),
                },
            },
        }
    }

    if ((type === 'low_stock' || type === 'stock_out') && id) {
        const isOut = type === 'stock_out' || (payload.stock_quantity ?? 1) <= 0
        const productName = payload.name ?? 'Produit'

        return {
            id: itemId(type, id),
            type: isOut ? 'stock_out' : 'low_stock',
            icon: isOut ? 'package' : 'inventory',
            title: isOut ? 'Rupture de stock' : 'Stock faible',
            description: payload.message ?? (isOut
                ? 'Le produit est actuellement en rupture de stock.'
                : `Stock restant : ${payload.stock_quantity ?? 0} unité(s).`),
            priority: isOut ? 'critical' : 'warning',
            status: 'active',
            created_at: nowIso(),
            url: route('products.show', { id }),
            metadata: {
                legacy_type: type,
                entity_id: id,
                product_name: productName,
                image_url: payload.image_url ?? null,
                product: {
                    id,
                    name: productName,
                    image_url: payload.image_url ?? null,
                    stock_quantity: payload.stock_quantity,
                    status: isOut ? 'Rupture de stock' : 'Stock faible',
                    url: route('products.show', { id }),
                },
            },
        }
    }

    if (type === 'expiring_product' && id) {
        const productName = payload.name ?? 'Produit'
        const daysUntilExpiration = payload.expiration_date
            ? diffCalendarDays(payload.expiration_date)
            : null
        const isExpired = daysUntilExpiration !== null && daysUntilExpiration < 0

        return {
            id: itemId(type, id),
            type: isExpired ? 'product_expired' : 'product_expiring',
            icon: 'alert',
            title: isExpired ? 'Produit périmé' : 'Produit bientôt périmé',
            description: isExpired
                ? 'Ce produit est périmé.'
                : 'Ce produit expire bientôt.',
            priority: isExpired ? 'critical' : 'warning',
            status: 'active',
            created_at: nowIso(),
            url: route('products.show', { id }),
            metadata: {
                legacy_type: type,
                entity_id: id,
                product_name: productName,
                image_url: payload.image_url ?? null,
                product: {
                    id,
                    name: productName,
                    image_url: payload.image_url ?? null,
                    expiration_date: payload.expiration_date ?? null,
                    url: route('products.show', { id }),
                },
            },
        }
    }

    if (type === 'test') {
        return {
            id: `test:${Date.now()}`,
            type: 'system_info',
            icon: 'default',
            title: 'Notification de test',
            description: payload.message ?? 'Test temps réel',
            priority: 'info',
            status: 'active',
            created_at: nowIso(),
            url: route('dashboard'),
            metadata: { legacy_type: 'test', entity_id: id, skip_api: true },
        }
    }

    return {
        id: `${type}:${id}:${Date.now()}`,
        type: notificationType,
        icon: iconForType(notificationType),
        title: payload.title ?? payload.message ?? 'Nouvelle notification',
        description: payload.description ?? 'Mise à jour en temps réel',
        priority: priorityFromPayload(payload, notificationType),
        status: 'active',
        created_at: nowIso(),
        url: hrefForType(notificationType),
        metadata: { legacy_type: type, entity_id: id },
    }
}

function dedupeById(items: Notification[]): Notification[] {
    const singles = new Map<string, Notification>()
    const grouped = new Map<string, Notification>()

    for (const item of items) {
        if (item.metadata?.grouped) {
            const groupKey = String(item.metadata.group_key ?? `${item.type}:grouped`)
            const existing = grouped.get(groupKey)

            if (!existing || /^\d+$/.test(String(item.id))) {
                grouped.set(groupKey, item)
            }

            continue
        }

        singles.set(item.id, item)
    }

    return [...grouped.values(), ...singles.values()]
}

function resolveNotificationType(type: string): string {
    const map: Record<string, string> = {
        sale_due_today: 'invoice_due',
        low_stock: 'low_stock',
        expiring_product: 'product_expiring',
    }

    return map[type] ?? type
}

function groupedTitle(type: string): string {
    const titles: Record<string, string> = {
        stock_out: 'Rupture de stock',
        low_stock: 'Stock faible',
        product_expired: 'Produit périmé',
        product_expiring: 'Produit bientôt périmé',
        invoice_due: 'Échéance de facture',
    }

    return titles[type] ?? 'Alerte groupée'
}

function groupedMessage(type: string, count: number): string {
    const messages: Record<string, string> = {
        stock_out: `${count} produit(s) sont actuellement en rupture de stock.`,
        low_stock: `${count} produit(s) ont un stock faible.`,
        product_expired: `${count} produit(s) sont périmés.`,
        product_expiring: `${count} produit(s) expirent bientôt.`,
        invoice_due: `${count} facture(s) arrivent à échéance.`,
    }

    return messages[type] ?? `${count} élément(s) concerné(s).`
}

function iconForType(type: string): string {
    const map: Record<string, string> = {
        sale_due_today: 'receipt',
        invoice_due: 'receipt',
        stock_out: 'package',
        low_stock: 'inventory',
        product_expired: 'alert',
        product_expiring: 'alert',
        expiring_product: 'alert',
    }

    return map[type] ?? 'default'
}

function priorityFromPayload(payload: RealtimeNotificationPayload, type: string): NotificationPriority {
    if (payload.priority) {
        return payload.priority
    }

    if (type === 'stock_out' || type === 'product_expired') {
        return 'critical'
    }

    if (type === 'low_stock' || type === 'product_expiring') {
        return 'warning'
    }

    if (type === 'invoice_due' || type === 'sale_due_today') {
        return 'info'
    }

    return 'info'
}

function hrefForType(type: string): string {
    switch (type) {
        case 'invoice_due':
        case 'sale_due_today':
            return route('sales.index')
        case 'stock_out':
        case 'low_stock':
            return route('products.index')
        case 'product_expired':
        case 'product_expiring':
        case 'expiring_product':
            return route('products.index', { expiration_alert: true })
        default:
            return route('dashboard')
    }
}

export { dedupeById }
