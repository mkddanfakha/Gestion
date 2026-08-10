import { formatCurrency, formatCurrencyNumber } from '@/utils/currencyFormatter'

export function formatDashboardCurrency(amount: number): string {
  return formatCurrency(amount)
}

export function formatDashboardNumber(value: number): string {
  return formatCurrencyNumber(value)
}

export function formatKpiValue(value: number, format: 'currency' | 'number'): string {
  return format === 'currency' ? formatDashboardCurrency(value) : formatDashboardNumber(value)
}

export const dashboardPeriodOptions = [
  { value: 'today', label: "Aujourd'hui" },
  { value: 'week', label: 'Cette semaine' },
  { value: 'month', label: 'Ce mois' },
  { value: 'quarter', label: 'Ce trimestre' },
  { value: 'year', label: 'Cette année' },
] as const

export function getActionIcon(action: string): string {
  const icons: Record<string, string> = {
    create: 'bi-plus-circle',
    update: 'bi-pencil-square',
    delete: 'bi-trash',
    validate: 'bi-check-circle',
    cancel: 'bi-x-circle',
    payment: 'bi-cash-coin',
    login: 'bi-box-arrow-in-right',
    logout: 'bi-box-arrow-right',
  }

  return icons[action] || 'bi-dot'
}

export function getPaymentMethodLabel(method?: string | null): string {
  const labels: Record<string, string> = {
    cash: 'Espèces',
    card: 'Carte',
    bank_transfer: 'Virement',
    check: 'Chèque',
    orange_money: 'Orange Money',
    wave: 'Wave',
  }

  if (!method) return 'Non renseigné'
  return labels[method] ?? method
}
