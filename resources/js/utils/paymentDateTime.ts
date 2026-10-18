/**
 * Dates/heures des règlements clients et trésorerie (UTC+0).
 * Les valeurs datetime-local et les chaînes sans offset sont interprétées en UTC côté backend.
 */

const LOCALE = 'fr-FR'
export const PAYMENT_TIMEZONE = 'UTC'

/**
 * Affichage DD/MM/YYYY HH:mm en UTC (aligné trésorerie + historique vente).
 */
export function formatPaymentDateTime(date: string | Date): string {
  const dateObj = typeof date === 'string' ? new Date(date) : date

  return new Intl.DateTimeFormat(LOCALE, {
    timeZone: PAYMENT_TIMEZONE,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(dateObj)
}

/**
 * Valeur pour input[type=datetime-local] : YYYY-MM-DDTHH:mm (horloge UTC).
 */
export function formatPaymentDateTimeForInput(date: Date = new Date()): string {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: PAYMENT_TIMEZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).formatToParts(date)

  const pick = (type: Intl.DateTimeFormatPartTypes): string =>
    parts.find((part) => part.type === type)?.value ?? '00'

  return `${pick('year')}-${pick('month')}-${pick('day')}T${pick('hour')}:${pick('minute')}`
}
