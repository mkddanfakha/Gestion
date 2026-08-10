export interface DashboardKpiChange {
  direction: 'up' | 'down'
  value: number
}

export interface DashboardKpi {
  key: string
  label: string
  value: number
  format: 'currency' | 'number'
  icon: string
  tone: string
  change: DashboardKpiChange | null
  href: string | null
  href_params?: Record<string, string | number | boolean>
}

export interface DashboardChartPoint {
  label: string
  bucket: string
  total: number
}

export interface DashboardPaymentMethod {
  method: string
  label: string
  count: number
  amount: number
  percentage: number
}

export interface DashboardTopProduct {
  id: number
  name: string
  price: number
  sales_count: number
  total_quantity: number
  image_url?: string | null
  category?: { name: string; color?: string } | null
}

export interface DashboardStockAlert {
  id: number
  name: string
  severity: 'critical' | 'warning' | 'info'
  type: 'stock' | 'expiration'
  message: string
  image_url?: string | null
  category?: string | null
  stock_quantity?: number
  min_stock_level?: number
  unit?: string
  expiration_date?: string
  days_until_expiration?: number | null
}

export interface DashboardInvoiceAlert {
  id: number
  sale_number: string
  customer: string
  total_amount: number
  remaining_amount: number
  due_date?: string | null
  payment_status: string
  severity: 'critical' | 'warning' | 'info'
  days_overdue: number
}

export interface DashboardActivityItem {
  id: number
  action: string
  description: string
  user_name: string
  created_at: string
}

export interface DashboardFilters {
  period: string
  date_from: string
  date_to: string
  label: string
}

export interface DashboardPageProps {
  filters: DashboardFilters
  refreshed_at: string
  kpis: DashboardKpi[]
  sales_chart: DashboardChartPoint[]
  payment_methods: DashboardPaymentMethod[]
  top_products: DashboardTopProduct[]
  stock_alerts: {
    low_stock: DashboardStockAlert[]
    expiring: DashboardStockAlert[]
    low_stock_total: number
    expiring_total: number
  }
  invoice_alerts: {
    due_today: DashboardInvoiceAlert[]
    overdue: DashboardInvoiceAlert[]
    due_today_total: number
    overdue_total: number
  }
  recent_sales: Array<{
    id: number
    sale_number: string
    total_amount: number
    payment_method?: string | null
    payment_status: string
    created_at: string
    customer?: string | null
  }>
  recent_expenses: Array<{
    id: number
    title: string
    amount: number
    category_label: string
    created_at: string
  }>
  recent_activity: DashboardActivityItem[]
  activity_stats: {
    actions_today: number
    logins_today: number
    deletions_today: number
  }
  can_view_financials: boolean
}
