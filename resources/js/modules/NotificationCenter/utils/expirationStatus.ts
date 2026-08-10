import { formatDate } from '@/utils/dateFormatter'

const TIMEZONE = 'Africa/Dakar'

export type ExpirationVisualStatus = 'warning' | 'expired' | 'today'

export interface ExpirationStatusResult {
    label: string
    status: ExpirationVisualStatus
    statusBadge: string
    daysDiff: number
    formattedDate: string
}

interface CalendarDate {
    year: number
    month: number
    day: number
}

function parseIsoDate(value: string): CalendarDate | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value.trim())
    if (!match) {
        return null
    }

    const year = Number(match[1])
    const month = Number(match[2])
    const day = Number(match[3])

    if (!Number.isFinite(year) || !Number.isFinite(month) || !Number.isFinite(day)) {
        return null
    }

    return { year, month, day }
}

function getCalendarDate(referenceDate: Date = new Date()): CalendarDate {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: TIMEZONE,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(referenceDate)

    const year = Number(parts.find((part) => part.type === 'year')?.value ?? 0)
    const month = Number(parts.find((part) => part.type === 'month')?.value ?? 0)
    const day = Number(parts.find((part) => part.type === 'day')?.value ?? 0)

    return { year, month, day }
}

function calendarDateToUtcMs(date: CalendarDate): number {
    return Date.UTC(date.year, date.month - 1, date.day)
}

/**
 * Différence en jours calendaires (expiration - aujourd'hui).
 * Positif = futur, 0 = aujourd'hui, négatif = passé.
 */
export function diffCalendarDays(
    expirationDate: string,
    referenceDate: Date = new Date(),
): number | null {
    const expiration = parseIsoDate(expirationDate)
    if (!expiration) {
        return null
    }

    const today = getCalendarDate(referenceDate)
    const diffMs = calendarDateToUtcMs(expiration) - calendarDateToUtcMs(today)

    return Math.round(diffMs / (24 * 60 * 60 * 1000))
}

function buildLabel(daysDiff: number): string {
    if (daysDiff > 1) {
        return `Expire dans ${daysDiff} jours`
    }

    if (daysDiff === 1) {
        return 'Expire dans 1 jour'
    }

    if (daysDiff === 0) {
        return 'Expire aujourd\'hui'
    }

    const overdueDays = Math.abs(daysDiff)

    if (overdueDays === 1) {
        return 'Expiré depuis 1 jour'
    }

    return `Expiré depuis ${overdueDays} jours`
}

function resolveVisualStatus(daysDiff: number): ExpirationVisualStatus {
    if (daysDiff < 0) {
        return 'expired'
    }

    if (daysDiff === 0) {
        return 'today'
    }

    return 'warning'
}

function resolveStatusBadge(status: ExpirationVisualStatus): string {
    if (status === 'expired') {
        return '🔴 Produit expiré'
    }

    return '🟠 Bientôt expiré'
}

/**
 * Calcule le libellé et le statut visuel d'une date d'expiration produit.
 * Comparaison au jour près dans le fuseau horaire de l'application.
 */
export function formatExpirationStatus(
    expirationDate: string | null | undefined,
    referenceDate: Date = new Date(),
): ExpirationStatusResult | null {
    if (!expirationDate) {
        return null
    }

    const daysDiff = diffCalendarDays(expirationDate, referenceDate)
    if (daysDiff === null) {
        return null
    }

    const status = resolveVisualStatus(daysDiff)

    return {
        label: buildLabel(daysDiff),
        status,
        statusBadge: resolveStatusBadge(status),
        daysDiff,
        formattedDate: formatDate(expirationDate),
    }
}
