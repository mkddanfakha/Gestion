import type { NotificationPriority } from './NotificationPriority'

/** Compteurs de notifications non lues par priorité. */
export interface NotificationCounts {
    total: number
    critical: number
    warning: number
    info: number
}

export type NotificationSeverity = NotificationPriority

/** Produit ou entité affiché dans une notification groupée. */
export interface NotificationProduct {
    id: number
    name: string
    reference?: string | null
    sku?: string | null
    stock?: number | null
    stock_quantity?: number | null
    minimum_stock?: number | null
    min_stock_level?: number | null
    unit?: string | null
    image_url?: string | null
    category?: string | null
    expiration_date?: string | null
    days_until_expiration?: number | null
    status?: string | null
    url?: string | null
    customer?: string | null
    remaining_amount?: number | null
    due_date?: string | null
    due_date_iso?: string | null
    days_until_due?: number | null
}

/** @deprecated Utiliser NotificationProduct */
export type ProductPreview = NotificationProduct
