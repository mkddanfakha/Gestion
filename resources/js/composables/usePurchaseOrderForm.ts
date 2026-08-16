import { formatCurrency } from '@/utils/currencyFormatter'
import { useForm } from '@inertiajs/vue3'
import { computed, onMounted, ref, watch, type Ref } from 'vue'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { useFormDraft } from '@/composables/useFormDraft'
import { restoreInertiaFormData } from '@/drafts/restoreInertiaForm'
import { formatDateForInput, getTodayForInput, normalizeFormDateFields } from '@/utils/dateFormatter'
import type { AttachmentRecord } from '@/types/attachment'

export type PurchaseOrderFormMode = 'create' | 'edit'

export const PURCHASE_ORDER_USER_STATUSES = ['draft', 'sent', 'confirmed', 'cancelled'] as const
export const PURCHASE_ORDER_SYSTEM_STATUSES = ['partially_received', 'received'] as const

export type PurchaseOrderUserStatus = (typeof PURCHASE_ORDER_USER_STATUSES)[number]
export type PurchaseOrderSystemStatus = (typeof PURCHASE_ORDER_SYSTEM_STATUSES)[number]
export type PurchaseOrderStatus = PurchaseOrderUserStatus | PurchaseOrderSystemStatus

export interface PurchaseOrderFormSupplier {
  id: number
  name: string
  email?: string | null
  phone?: string | null
}

export interface PurchaseOrderFormCategory {
  id: number
  name: string
  color: string
}

export interface PurchaseOrderFormProduct {
  id: number
  name: string
  sku?: string | null
  barcode?: string | null
  price: number
  cost_price?: number | null
  stock_quantity?: number
  unit: string
  category?: PurchaseOrderFormCategory
  image_url?: string | null
}

export interface PurchaseOrderFormItem {
  product_id: number
  quantity: number
  unit_price: number
  total_price: number
}

export interface PurchaseOrderFormPurchase {
  id: number
  po_number: string
  supplier_id?: number | null
  order_date?: string | null
  expected_delivery_date?: string | null
  status?: PurchaseOrderStatus | string | null
  notes?: string | null
  tax_amount?: number | string | null
  discount_amount?: number | string | null
  subtotal?: number | string | null
  total_amount?: number | string | null
  items?: PurchaseOrderFormItem[]
  attachments?: AttachmentRecord[]
}

interface PurchaseOrderPayload {
  supplier_id: number | null
  order_date: string
  expected_delivery_date: string | null
  status: PurchaseOrderStatus
  notes: string | null
  subtotal: number
  tax_amount: number
  discount_amount: number
  total_amount: number
  items: Array<{
    product_id: number
    quantity: number
    unit_price: number
    total_price: number
  }>
}

interface UsePurchaseOrderFormOptions {
  mode: PurchaseOrderFormMode
  purchaseOrder?: PurchaseOrderFormPurchase
  products: Ref<PurchaseOrderFormProduct[]> | PurchaseOrderFormProduct[]
  pendingFiles?: Ref<File[]>
}

const STATUS_LABELS: Record<PurchaseOrderStatus, string> = {
  draft: 'Brouillon',
  sent: 'Envoyé',
  confirmed: 'Confirmé',
  partially_received: 'Partiellement reçu',
  received: 'Reçu',
  cancelled: 'Annulé',
}

function toNumber(value: unknown, fallback = 0): number {
  const parsed = parseFloat(String(value ?? fallback))
  return Number.isNaN(parsed) ? fallback : parsed
}

function normalizePercent(value: unknown): number {
  const parsed = toNumber(value, 0)
  if (!Number.isFinite(parsed)) {
    return 0
  }

  return Math.round(Math.min(100, Math.max(0, parsed)) * 100) / 100
}

function isSystemStatus(status: unknown): status is PurchaseOrderSystemStatus {
  return PURCHASE_ORDER_SYSTEM_STATUSES.includes(status as PurchaseOrderSystemStatus)
}

function isUserSelectableStatus(status: unknown): status is PurchaseOrderUserStatus {
  return PURCHASE_ORDER_USER_STATUSES.includes(status as PurchaseOrderUserStatus)
}

function getProductUnitPrice(product: PurchaseOrderFormProduct): number {
  const costPrice = toNumber(product.cost_price)
  if (costPrice > 0) {
    return costPrice
  }

  return toNumber(product.price)
}

function getItemLineTotal(item: PurchaseOrderFormItem): number {
  const storedTotal = toNumber(item.total_price)
  if (storedTotal > 0) {
    return storedTotal
  }

  return toNumber(item.quantity) * toNumber(item.unit_price)
}

function mapPurchaseOrderItems(items?: PurchaseOrderFormItem[]): PurchaseOrderFormItem[] {
  if (!items?.length) {
    return []
  }

  return items.map((item) => ({
    product_id: item.product_id || 0,
    quantity: parseInt(String(item.quantity), 10) || 1,
    unit_price: toNumber(item.unit_price),
    total_price: toNumber(item.total_price),
  }))
}

function buildInitialForm(mode: PurchaseOrderFormMode, purchaseOrder?: PurchaseOrderFormPurchase) {
  if (mode === 'edit' && purchaseOrder) {
    return {
      supplier_id: purchaseOrder.supplier_id ?? null,
      order_date: formatDateForInput(purchaseOrder.order_date),
      expected_delivery_date: formatDateForInput(purchaseOrder.expected_delivery_date),
      status: (purchaseOrder.status as PurchaseOrderStatus) || 'draft',
      notes: purchaseOrder.notes || '',
      tax_amount: toNumber(purchaseOrder.tax_amount),
      discount_amount: toNumber(purchaseOrder.discount_amount),
      subtotal: toNumber(purchaseOrder.subtotal),
      total_amount: toNumber(purchaseOrder.total_amount),
      items: mapPurchaseOrderItems(purchaseOrder.items),
    }
  }

  return {
    supplier_id: null,
    order_date: getTodayForInput(),
    expected_delivery_date: '',
    status: 'draft' as PurchaseOrderUserStatus,
    notes: '',
    tax_amount: 0,
    discount_amount: 0,
    subtotal: 0,
    total_amount: 0,
    items: [] as PurchaseOrderFormItem[],
  }
}

export function usePurchaseOrderForm({ mode, purchaseOrder, products, pendingFiles }: UsePurchaseOrderFormOptions) {
  const { success, error } = useSweetAlert()
  const clientErrors = ref<Record<string, string>>({})

  const taxMode = ref<'amount' | 'percent'>('amount')
  const discountMode = ref<'amount' | 'percent'>('amount')
  const taxPercent = ref(0)
  const discountPercent = ref(0)
  const taxPercentUserEdited = ref(false)
  const discountPercentUserEdited = ref(false)
  const taxEnabled = ref(mode === 'edit' ? toNumber(purchaseOrder?.tax_amount) > 0 : true)
  const discountEnabled = ref(mode === 'edit' ? toNumber(purchaseOrder?.discount_amount) > 0 : true)

  const form = useForm(buildInitialForm(mode, purchaseOrder))

  const purchaseOrderDraftBaseline = {
    ...buildInitialForm(mode, purchaseOrder),
    ui: {
      taxMode: 'amount' as const,
      discountMode: 'amount' as const,
      taxPercent: 0,
      discountPercent: 0,
      taxEnabled: mode === 'edit' ? toNumber(purchaseOrder?.tax_amount) > 0 : true,
      discountEnabled: mode === 'edit' ? toNumber(purchaseOrder?.discount_amount) > 0 : true,
    },
  }

  const getPurchaseOrderDraftData = () => ({
    ...(form.data() as Record<string, unknown>),
    ui: {
      taxMode: taxMode.value,
      discountMode: discountMode.value,
      taxPercent: taxPercent.value,
      discountPercent: discountPercent.value,
      taxEnabled: taxEnabled.value,
      discountEnabled: discountEnabled.value,
    },
  })

  const restorePurchaseOrderDraftData = (data: Record<string, unknown>) => {
    const { ui, ...formData } = data
    restoreInertiaFormData(form as unknown as Record<string, unknown>, formData)
    normalizeFormDateFields(form as unknown as Record<string, unknown>, [
      'order_date',
      'expected_delivery_date',
    ])

    if (ui && typeof ui === 'object') {
      const uiState = ui as Record<string, unknown>
      if (uiState.taxMode === 'amount' || uiState.taxMode === 'percent') {
        taxMode.value = uiState.taxMode
      }
      if (uiState.discountMode === 'amount' || uiState.discountMode === 'percent') {
        discountMode.value = uiState.discountMode
      }
      taxPercent.value = toNumber(uiState.taxPercent)
      discountPercent.value = toNumber(uiState.discountPercent)
      taxEnabled.value = Boolean(uiState.taxEnabled)
      discountEnabled.value = Boolean(uiState.discountEnabled)
    }
  }

  const purchaseOrderDraftSnapshot = computed(() => getPurchaseOrderDraftData())

  const draft = useFormDraft({
    formType: 'purchase_order',
    mode,
    entityId: purchaseOrder?.id ?? null,
    watchSource: purchaseOrderDraftSnapshot,
    getData: () => getPurchaseOrderDraftData(),
    restoreData: (data) => restorePurchaseOrderDraftData(data as Record<string, unknown>),
    getBaseline: () => purchaseOrderDraftBaseline,
  })

  const productsList = computed(() => (Array.isArray(products) ? products : products.value))

  const pageTitle = computed(() =>
    mode === 'edit' && purchaseOrder
      ? `Modifier le bon de commande ${purchaseOrder.po_number}`
      : 'Nouveau bon de commande',
  )

  const pageSubtitle = computed(() =>
    mode === 'edit'
      ? 'Modifiez les informations du bon de commande'
      : 'Créez un bon de commande pour votre fournisseur',
  )

  const submitLabel = computed(() =>
    mode === 'edit' ? 'Enregistrer les modifications' : 'Créer le bon de commande',
  )

  const submitLoadingLabel = computed(() => (mode === 'edit' ? 'Modification...' : 'Enregistrement...'))

  const submitIcon = computed(() => (mode === 'edit' ? 'bi-check-circle' : 'bi-save'))

  const today = getTodayForInput()

  const statusOptions = computed(() => {
    const options = PURCHASE_ORDER_USER_STATUSES.map((value) => ({
      value,
      label: STATUS_LABELS[value],
      disabled: false,
    }))

    if (mode === 'edit' && purchaseOrder && isSystemStatus(purchaseOrder.status)) {
      const currentStatus = purchaseOrder.status as PurchaseOrderSystemStatus
      if (!options.some((option) => option.value === currentStatus)) {
        options.unshift({
          value: currentStatus,
          label: STATUS_LABELS[currentStatus],
          disabled: true,
        })
      }
    }

    return options
  })

  const getEffectiveStatus = (): PurchaseOrderStatus => {
    const currentStatus = form.status as PurchaseOrderStatus

    if (isUserSelectableStatus(currentStatus)) {
      return currentStatus
    }

    if (mode === 'edit' && purchaseOrder?.status && isSystemStatus(purchaseOrder.status)) {
      return purchaseOrder.status as PurchaseOrderSystemStatus
    }

    return 'draft'
  }

  const statusLabel = computed(() => {
    const status = getEffectiveStatus()
    return STATUS_LABELS[status] ?? (typeof status === 'string' ? status : 'Inconnu')
  })

  function getStatusBadgeClass(status: unknown): string {
    const value = typeof status === 'string' ? status : String(status ?? '')
    const classes: Record<string, string> = {
      draft: 'bg-secondary',
      sent: 'bg-info',
      confirmed: 'bg-warning',
      partially_received: 'bg-primary',
      received: 'bg-success',
      cancelled: 'bg-danger',
    }

    return classes[value] || 'bg-secondary'
  }

  const isSystemStatusDisplay = computed(() => isSystemStatus(getEffectiveStatus()))

  watch(
    () => form.status,
    (newStatus, oldStatus) => {
      if (isSystemStatus(newStatus)) {
        const isPreservedSystemStatus =
          mode === 'edit' && purchaseOrder && newStatus === purchaseOrder.status

        if (!isPreservedSystemStatus) {
          form.status = oldStatus
        }
      }
    },
  )

  watch(taxEnabled, (enabled) => {
    if (!enabled) {
      form.tax_amount = 0
      taxPercent.value = 0
    }
  })

  watch(discountEnabled, (enabled) => {
    if (!enabled) {
      form.discount_amount = 0
      discountPercent.value = 0
    }
  })

  const getProduct = (productId: number): PurchaseOrderFormProduct | undefined => {
    if (!productId) return undefined
    return productsList.value.find((product) => product.id === productId)
  }

  const addItem = () => {
    form.items.push({
      product_id: 0,
      quantity: 1,
      unit_price: 0,
      total_price: 0,
    })
  }

  const removeItem = (index: number) => {
    form.items.splice(index, 1)
  }

  const handleProductSelected = (product: PurchaseOrderFormProduct, index: number) => {
    const item = form.items[index]
    item.product_id = product.id
    item.unit_price = getProductUnitPrice(product)
    updateItemTotal(index)
  }

  const getExcludedProductIds = (currentIndex: number): number[] =>
    form.items
      .map((item, index) => (index !== currentIndex ? item.product_id : null))
      .filter((id): id is number => id !== null && id > 0)

  const updateItemTotal = (index: number) => {
    const item = form.items[index]
    item.total_price = item.quantity * item.unit_price
  }

  const canIncrementQuantity = (_index: number): boolean => true

  const decrementQuantity = (index: number) => {
    const item = form.items[index]
    if (item.quantity <= 1) return
    item.quantity -= 1
    updateItemTotal(index)
  }

  const incrementQuantity = (index: number) => {
    const item = form.items[index]
    item.quantity += 1
    updateItemTotal(index)
  }

  const isProductAlreadySelected = (productId: number, currentIndex: number): boolean => {
    if (!productId) return false
    return form.items.some((item, index) => index !== currentIndex && item.product_id === productId)
  }

  const isProductDuplicate = (index: number): boolean => {
    const currentItem = form.items[index]
    if (!currentItem.product_id) return false
    return isProductAlreadySelected(currentItem.product_id, index)
  }

  const hasDuplicateProducts = computed(() => {
    const productIds = form.items.map((item) => item.product_id).filter((id) => id > 0)
    return productIds.length !== new Set(productIds).size
  })

  const mergeDuplicateProducts = () => {
    const productMap = new Map<number, PurchaseOrderFormItem>()

    form.items.forEach((item) => {
      if (item.product_id > 0) {
        if (productMap.has(item.product_id)) {
          const existingItem = productMap.get(item.product_id)!
          existingItem.quantity += item.quantity
          existingItem.total_price = existingItem.quantity * existingItem.unit_price
        } else {
          productMap.set(item.product_id, { ...item })
        }
      }
    })

    form.items = Array.from(productMap.values())
  }

  const getProductUnit = (index: number): string => {
    const item = form.items[index]
    if (!item.product_id) return ''
    const product = productsList.value.find((p) => p.id === item.product_id)
    return product ? product.unit : ''
  }

  const itemsCount = computed(() => form.items.length)

  const subtotal = computed(() => form.items.reduce((total, item) => total + getItemLineTotal(item), 0))

  const syncTaxPercentFromAmount = () => {
    const amount = toNumber(form.tax_amount)
    if (subtotal.value > 0 && amount > 0) {
      taxPercent.value = normalizePercent((amount / subtotal.value) * 100)
    } else if (amount <= 0) {
      taxPercent.value = 0
    }
  }

  const syncDiscountPercentFromAmount = () => {
    const amount = toNumber(form.discount_amount)
    if (subtotal.value > 0 && amount > 0) {
      discountPercent.value = normalizePercent((amount / subtotal.value) * 100)
    } else if (amount <= 0) {
      discountPercent.value = 0
    }
  }

  watch(
    () => form.tax_amount,
    () => {
      if (taxMode.value === 'amount') {
        syncTaxPercentFromAmount()
      }
    },
  )

  watch(
    () => form.discount_amount,
    () => {
      if (discountMode.value === 'amount') {
        syncDiscountPercentFromAmount()
      }
    },
  )

  const calculatedTaxAmount = computed(() => {
    if (!taxEnabled.value) return 0
    const amount = toNumber(form.tax_amount)
    if (taxMode.value === 'percent' && taxPercentUserEdited.value && taxPercent.value > 0) {
      return (subtotal.value * taxPercent.value) / 100
    }
    return amount
  })

  const calculatedDiscountAmount = computed(() => {
    if (!discountEnabled.value) return 0
    const amount = toNumber(form.discount_amount)
    if (discountMode.value === 'percent' && discountPercentUserEdited.value && discountPercent.value > 0) {
      return (subtotal.value * discountPercent.value) / 100
    }
    return amount
  })

  const updateTaxFromPercent = () => {
    if (!taxEnabled.value || taxMode.value !== 'percent') return
    if (subtotal.value <= 0) return

    taxPercent.value = normalizePercent(taxPercent.value)

    if (taxPercent.value > 0) {
      form.tax_amount = (subtotal.value * taxPercent.value) / 100
    }
  }

  const updateDiscountFromPercent = () => {
    if (!discountEnabled.value || discountMode.value !== 'percent') return
    if (subtotal.value <= 0) return

    discountPercent.value = normalizePercent(discountPercent.value)

    if (discountPercent.value > 0) {
      form.discount_amount = (subtotal.value * discountPercent.value) / 100
    }
  }

  const onTaxPercentInput = () => {
    if (!taxEnabled.value) return

    taxPercentUserEdited.value = true

    if (toNumber(taxPercent.value) <= 0) {
      taxPercent.value = 0
      form.tax_amount = 0
    } else {
      updateTaxFromPercent()
    }

    validateField('tax_percent', taxPercent.value)
  }

  const onDiscountPercentInput = () => {
    if (!discountEnabled.value) return

    discountPercentUserEdited.value = true

    if (toNumber(discountPercent.value) <= 0) {
      discountPercent.value = 0
      form.discount_amount = 0
    } else {
      updateDiscountFromPercent()
    }

    validateField('discount_percent', discountPercent.value)
  }

  watch(taxMode, () => {
    taxPercentUserEdited.value = false
    syncTaxPercentFromAmount()
  })

  watch(discountMode, () => {
    discountPercentUserEdited.value = false
    syncDiscountPercentFromAmount()
  })

  watch(subtotal, () => {
    if (taxMode.value === 'percent') {
      if (taxPercentUserEdited.value) {
        updateTaxFromPercent()
      } else {
        syncTaxPercentFromAmount()
      }
    } else {
      syncTaxPercentFromAmount()
    }

    if (discountMode.value === 'percent') {
      if (discountPercentUserEdited.value) {
        updateDiscountFromPercent()
      } else {
        syncDiscountPercentFromAmount()
      }
    } else {
      syncDiscountPercentFromAmount()
    }
  })

  onMounted(() => {
    syncTaxPercentFromAmount()
    syncDiscountPercentFromAmount()
  })

  const taxAmount = computed(() => {
    if (!taxEnabled.value) return 0
    if (taxMode.value === 'percent') return calculatedTaxAmount.value
    return form.tax_amount || 0
  })

  const discountAmount = computed(() => {
    if (!discountEnabled.value) return 0
    if (discountMode.value === 'percent') return calculatedDiscountAmount.value
    return form.discount_amount || 0
  })

  const totalAmount = computed(() => subtotal.value + taxAmount.value - discountAmount.value)

  const validateForm = () => {
    const errors: Record<string, string> = {}

    if (!form.supplier_id) {
      errors.supplier_id = 'Le fournisseur est requis.'
    }

    if (!form.order_date) {
      errors.order_date = 'La date de commande est requise.'
    }

    if (
      form.expected_delivery_date &&
      form.order_date &&
      new Date(form.expected_delivery_date) < new Date(form.order_date)
    ) {
      errors.expected_delivery_date =
        'La date de livraison prévue ne peut pas être antérieure à la date de commande.'
    }

    if (form.items.length === 0) {
      errors.items = 'Au moins un article est requis.'
    }

    if (hasDuplicateProducts.value) {
      errors.items = 'Chaque produit ne peut être sélectionné qu\'une seule fois.'
    }

    if (taxEnabled.value && taxMode.value === 'percent') {
      if (!Number.isFinite(taxPercent.value) || taxPercent.value < 0 || taxPercent.value > 100) {
        errors.tax_percent = 'Le pourcentage de taxe doit être compris entre 0 et 100.'
      }
    }

    if (discountEnabled.value && discountMode.value === 'percent') {
      if (!Number.isFinite(discountPercent.value) || discountPercent.value < 0 || discountPercent.value > 100) {
        errors.discount_percent = 'Le pourcentage de remise doit être compris entre 0 et 100.'
      }
    }

    if (taxEnabled.value && form.tax_amount < 0) {
      errors.tax_amount = 'Le montant de la taxe ne peut pas être négatif'
    }

    if (discountEnabled.value && form.discount_amount < 0) {
      errors.discount_amount = 'Le montant de la remise ne peut pas être négatif'
    }

    form.items.forEach((item, index) => {
      if (!item.product_id) {
        errors[`items.${index}.product_id`] = 'Le produit est requis'
      } else if (isProductAlreadySelected(item.product_id, index)) {
        errors[`items.${index}.product_id`] = 'Ce produit est déjà sélectionné.'
      }

      if (!item.quantity || item.quantity <= 0) {
        errors[`items.${index}.quantity`] = 'La quantité doit être supérieure à 0'
      }

      if (item.unit_price < 0) {
        errors[`items.${index}.unit_price`] = 'Le prix unitaire ne peut pas être négatif'
      }
    })

    return Object.keys(errors).length === 0 ? null : errors
  }

  const validateField = (fieldName: string, value: unknown) => {
    if (clientErrors.value[fieldName]) {
      delete clientErrors.value[fieldName]
    }

    let errorMessage = ''

    switch (fieldName) {
      case 'supplier_id':
        if (!value) {
          errorMessage = 'Le fournisseur est requis.'
        }
        break
      case 'order_date':
        if (!value) {
          errorMessage = 'La date de commande est requise.'
        }
        break
      case 'expected_delivery_date':
        if (value && form.order_date && new Date(String(value)) < new Date(form.order_date)) {
          errorMessage =
            'La date de livraison prévue ne peut pas être antérieure à la date de commande.'
        }
        break
      case 'status':
        if (!value) {
          errorMessage = 'Le statut est requis.'
        }
        break
      case 'tax_amount':
        if (typeof value === 'number' && value < 0) {
          errorMessage = 'Le montant de la taxe ne peut pas être négatif'
        }
        break
      case 'discount_amount':
        if (typeof value === 'number' && value < 0) {
          errorMessage = 'Le montant de la remise ne peut pas être négatif'
        }
        break
      case 'tax_percent':
        if (!Number.isFinite(value) || Number(value) < 0 || Number(value) > 100) {
          errorMessage = 'Le pourcentage de taxe doit être compris entre 0 et 100.'
        }
        break
      case 'discount_percent':
        if (!Number.isFinite(value) || Number(value) < 0 || Number(value) > 100) {
          errorMessage = 'Le pourcentage de remise doit être compris entre 0 et 100.'
        }
        break
    }

    if (errorMessage) {
      clientErrors.value[fieldName] = errorMessage
    }
  }

  const buildPayload = (overrides: Record<string, unknown> = {}): PurchaseOrderPayload => {
    const computedSubtotal = subtotal.value
    const computedTotal = totalAmount.value

    form.subtotal = computedSubtotal
    form.total_amount = computedTotal

    return {
      supplier_id: form.supplier_id || null,
      order_date: form.order_date,
      expected_delivery_date: form.expected_delivery_date || null,
      status: getEffectiveStatus(),
      notes: form.notes || null,
      subtotal: computedSubtotal,
      tax_amount: taxEnabled.value ? form.tax_amount || 0 : 0,
      discount_amount: discountEnabled.value ? form.discount_amount || 0 : 0,
      total_amount: computedTotal,
      items: form.items.map((item) => ({
        product_id: item.product_id,
        quantity: item.quantity,
        unit_price: item.unit_price,
        total_price: getItemLineTotal(item),
      })),
      ...overrides,
    }
  }

  const appendPayloadToFormData = (formData: FormData, payload: PurchaseOrderPayload) => {
    if (payload.supplier_id != null) {
      formData.append('supplier_id', String(payload.supplier_id))
    }

    formData.append('order_date', payload.order_date)
    formData.append('expected_delivery_date', payload.expected_delivery_date ?? '')
    formData.append('status', payload.status)
    formData.append('notes', payload.notes ?? '')
    formData.append('subtotal', String(payload.subtotal))
    formData.append('tax_amount', String(payload.tax_amount))
    formData.append('discount_amount', String(payload.discount_amount))
    formData.append('total_amount', String(payload.total_amount))

    payload.items.forEach((item, index) => {
      formData.append(`items[${index}][product_id]`, String(item.product_id))
      formData.append(`items[${index}][quantity]`, String(item.quantity))
      formData.append(`items[${index}][unit_price]`, String(item.unit_price))
      formData.append(`items[${index}][total_price]`, String(item.total_price))
    })
  }

  const hasPendingAttachments = () => (pendingFiles?.value.length ?? 0) > 0

  const scrollToFirstError = () => {
    requestAnimationFrame(() => {
      const firstInvalid = document.querySelector(
        '.purchase-order-form-root .is-invalid, .purchase-order-form-root .invalid-feedback.d-block',
      )
      firstInvalid?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    })
  }

  const prepareSubmit = () => {
    clientErrors.value = {}

    if (taxEnabled.value && taxMode.value === 'percent' && taxPercentUserEdited.value) {
      updateTaxFromPercent()
    } else if (!taxEnabled.value) {
      form.tax_amount = 0
    }

    if (discountEnabled.value && discountMode.value === 'percent' && discountPercentUserEdited.value) {
      updateDiscountFromPercent()
    } else if (!discountEnabled.value) {
      form.discount_amount = 0
    }

    const validationErrors = validateForm()
    if (validationErrors) {
      clientErrors.value = validationErrors
      scrollToFirstError()
      return false
    }

    if (form.items.length === 0) {
      error('Veuillez ajouter au moins un article.')
      return false
    }

    return true
  }

  const preparePreview = (): Record<string, unknown> | false => {
    if (!prepareSubmit()) {
      return false
    }

    const payload = buildPayload()

    if (mode === 'edit' && purchaseOrder?.id) {
      payload.purchase_order_id = purchaseOrder.id
    }

    return payload
  }

  const submit = () => {
    if (!prepareSubmit()) return

    const payload = buildPayload()
    const files = pendingFiles?.value ?? []

    if (hasPendingAttachments()) {
      const submitOptions = {
        forceFormData: true,
        onSuccess: async () => {
          await draft.markSubmitted()
          if (pendingFiles) {
            pendingFiles.value = []
          }
          success(
            mode === 'edit'
              ? 'Bon de commande modifié avec succès !'
              : 'Bon de commande créé avec succès !',
          )
          if (mode === 'create') {
            form.reset()
          }
          clientErrors.value = {}
        },
        onError: () => {
          error(
            mode === 'edit'
              ? 'Erreur lors de la modification du bon de commande.'
              : 'Erreur lors de la création du bon de commande.',
          )
        },
      }

      if (mode === 'create') {
        form.transform(() => {
          const formData = new FormData()
          appendPayloadToFormData(formData, payload)
          files.forEach((file) => {
            formData.append('attachments[]', file)
          })
          return formData
        }).post(route('purchase-orders.store'), submitOptions)
        return
      }

      if (!purchaseOrder) return

      form.transform(() => {
        const formData = new FormData()
        appendPayloadToFormData(formData, payload)
        files.forEach((file) => {
          formData.append('attachments[]', file)
        })
        formData.append('_method', 'PUT')
        return formData
      }).post(route('purchase-orders.update', { id: purchaseOrder.id }), submitOptions)
      return
    }

    if (mode === 'create') {
      form.transform(() => payload).post(route('purchase-orders.store'), {
        onSuccess: async () => {
          await draft.markSubmitted()
          success('Bon de commande créé avec succès !')
          form.reset()
          clientErrors.value = {}
        },
        onError: () => {
          error('Erreur lors de la création du bon de commande.')
        },
      })
      return
    }

    if (!purchaseOrder) return

    form.transform(() => payload).put(route('purchase-orders.update', { id: purchaseOrder.id }), {
      onSuccess: async () => {
        await draft.markSubmitted()
        success('Bon de commande modifié avec succès !')
        clientErrors.value = {}
      },
      onError: () => {
        error('Erreur lors de la modification du bon de commande.')
      },
    })
  }

  return {
    mode,
    purchaseOrder,
    form,
    errors: computed(() => form.errors),
    processing: computed(() => form.processing),
    clientErrors,
    taxMode,
    discountMode,
    taxPercent,
    discountPercent,
    taxEnabled,
    discountEnabled,
    pageTitle,
    pageSubtitle,
    submitLabel,
    submitLoadingLabel,
    submitIcon,
    today,
    itemsCount,
    subtotal,
    taxAmount,
    discountAmount,
    totalAmount,
    hasDuplicateProducts,
    statusOptions,
    userSelectableStatuses: PURCHASE_ORDER_USER_STATUSES,
    systemStatuses: PURCHASE_ORDER_SYSTEM_STATUSES,
    isSystemStatus,
    isSystemStatusDisplay,
    statusLabel,
    getStatusBadgeClass,
    getEffectiveStatus,
    getProduct,
    addItem,
    removeItem,
    handleProductSelected,
    getExcludedProductIds,
    updateItemTotal,
    canIncrementQuantity,
    decrementQuantity,
    incrementQuantity,
    isProductDuplicate,
    mergeDuplicateProducts,
    getProductUnit,
    formatCurrency,
    onTaxPercentInput,
    onDiscountPercentInput,
    validateField,
    preparePreview,
    submit,
    draft,
  }
}
