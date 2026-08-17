import { formatCurrency } from '@/utils/currencyFormatter'
import { useForm } from '@inertiajs/vue3'
import { computed, onMounted, onUnmounted, ref, watch, type Ref } from 'vue'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { useFormDraft } from '@/composables/useFormDraft'
import { restoreInertiaFormData } from '@/drafts/restoreInertiaForm'
import { formatDateForInput, getTodayForInput, normalizeFormDateFields } from '@/utils/dateFormatter'
import type { AttachmentRecord } from '@/types/attachment'
import {
  getReceiptLineForProduct,
  validateDeliveryQuantityAgainstReceipt,
  type PurchaseOrderReceiptSummary,
} from '@/composables/usePurchaseOrderReceipt'

export type DeliveryNoteFormMode = 'create' | 'edit'

export type DeliveryNoteStatus = 'pending' | 'validated' | 'cancelled'

export interface DeliveryNoteFormSupplier {
  id: number
  name: string
  email?: string | null
  phone?: string | null
}

export interface DeliveryNoteFormCategory {
  id: number
  name: string
  color: string
}

export interface DeliveryNoteFormProduct {
  id: number
  name: string
  sku?: string | null
  barcode?: string | null
  price: number
  cost_price?: number | null
  stock_quantity?: number
  unit: string
  category?: DeliveryNoteFormCategory
  image_url?: string | null
}

export interface DeliveryNoteFormItem {
  id?: number
  product_id: number
  quantity: number
  unit_price: number
  total_price: number
  ordered_quantity?: number
  delivered_quantity?: number
  remaining_quantity?: number
  available_quantity?: number
}

export interface DeliveryNoteFormDeliveryNote {
  id: number
  delivery_number: string
  supplier_id?: number | null
  purchase_order_id?: number | null
  delivery_date?: string | null
  status?: DeliveryNoteStatus | string | null
  notes?: string | null
  invoice_number?: string | null
  tax_amount?: number | string | null
  discount_amount?: number | string | null
  subtotal?: number | string | null
  total_amount?: number | string | null
  items?: DeliveryNoteFormItem[]
  attachments?: AttachmentRecord[]
}

export interface DeliveryNoteFormPurchaseOrder {
  id: number
  po_number: string
  supplier_id: number
}

interface DeliveryNotePayload {
  supplier_id: number | null
  purchase_order_id: number | null
  delivery_date: string
  status: DeliveryNoteStatus | string
  notes: string | null
  invoice_number: string | null
  subtotal: number
  tax_amount: number
  discount_amount: number
  total_amount: number
  items: Array<{
    id?: number
    product_id: number
    quantity: number
    unit_price: number
    total_price: number
  }>
}

interface UseDeliveryNoteFormOptions {
  mode: DeliveryNoteFormMode
  deliveryNote?: DeliveryNoteFormDeliveryNote
  standalone?: boolean
  suppliers: Ref<DeliveryNoteFormSupplier[]> | DeliveryNoteFormSupplier[]
  products: Ref<DeliveryNoteFormProduct[]> | DeliveryNoteFormProduct[]
  purchaseOrders: Ref<DeliveryNoteFormPurchaseOrder[]> | DeliveryNoteFormPurchaseOrder[]
  purchaseOrder?: DeliveryNoteFormPurchaseOrder
  purchaseOrderReceipt?: PurchaseOrderReceiptSummary | null
  initialPurchaseOrderId?: number | null
  pendingFiles?: Ref<File[]>
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

function getItemLineTotal(item: DeliveryNoteFormItem): number {
  const storedTotal = toNumber(item.total_price)
  if (storedTotal > 0) {
    return storedTotal
  }

  return toNumber(item.quantity) * toNumber(item.unit_price)
}

function getProductUnitPrice(product: DeliveryNoteFormProduct): number {
  const costPrice = toNumber(product.cost_price)
  if (costPrice > 0) {
    return costPrice
  }

  return toNumber(product.price)
}

function mapDeliveryNoteItems(items?: DeliveryNoteFormItem[]): DeliveryNoteFormItem[] {
  if (!items?.length) {
    return []
  }

  return items.map((item) => ({
    id: item.id,
    product_id: item.product_id || 0,
    quantity: parseInt(String(item.quantity), 10) || 1,
    unit_price: toNumber(item.unit_price),
    total_price: toNumber(item.total_price) || toNumber(item.quantity) * toNumber(item.unit_price),
    ordered_quantity: item.ordered_quantity,
    delivered_quantity: item.delivered_quantity,
    remaining_quantity: item.remaining_quantity,
    available_quantity: item.available_quantity,
  }))
}

function buildItemsFromReceipt(summary: PurchaseOrderReceiptSummary | null): DeliveryNoteFormItem[] {
  if (!summary) {
    return []
  }

  return summary.items
    .filter((line) => line.available_quantity > 0)
    .map((line) => ({
      product_id: line.product_id,
      quantity: line.available_quantity,
      unit_price: toNumber(line.unit_price),
      total_price: line.available_quantity * toNumber(line.unit_price),
      ordered_quantity: line.ordered_quantity,
      delivered_quantity: line.delivered_quantity,
      remaining_quantity: line.remaining_quantity,
      available_quantity: line.available_quantity,
    }))
}

async function fetchReceiptSummary(
  purchaseOrderId: number,
  excludeDeliveryNoteId?: number,
): Promise<PurchaseOrderReceiptSummary> {
  let url = route('purchase-orders.receipt-summary', { purchaseOrder: purchaseOrderId })

  if (excludeDeliveryNoteId) {
    url += `?exclude_delivery_note_id=${excludeDeliveryNoteId}`
  }

  const response = await fetch(url, {
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
  })

  if (!response.ok) {
    throw new Error('Impossible de charger les quantités restantes du bon de commande.')
  }

  return response.json()
}

function buildInitialForm(
  mode: DeliveryNoteFormMode,
  deliveryNote?: DeliveryNoteFormDeliveryNote,
  purchaseOrder?: DeliveryNoteFormPurchaseOrder,
  purchaseOrderReceipt?: PurchaseOrderReceiptSummary | null,
  initialPurchaseOrderId?: number | null,
) {
  if (mode === 'edit' && deliveryNote) {
    return {
      supplier_id: deliveryNote.supplier_id ?? null,
      purchase_order_id: deliveryNote.purchase_order_id ?? null,
      delivery_date: formatDateForInput(deliveryNote.delivery_date),
      status: (deliveryNote.status as DeliveryNoteStatus) || 'pending',
      notes: deliveryNote.notes || '',
      invoice_number: deliveryNote.invoice_number || '',
      tax_amount: toNumber(deliveryNote.tax_amount),
      discount_amount: toNumber(deliveryNote.discount_amount),
      subtotal: toNumber(deliveryNote.subtotal),
      total_amount: toNumber(deliveryNote.total_amount),
      items: mapDeliveryNoteItems(deliveryNote.items),
    }
  }

  const resolvedPurchaseOrderId = purchaseOrder?.id ?? initialPurchaseOrderId ?? null

  return {
    supplier_id: purchaseOrder?.supplier_id ?? null,
    purchase_order_id: resolvedPurchaseOrderId,
    delivery_date: getTodayForInput(),
    status: 'pending' as DeliveryNoteStatus,
    notes: '',
    invoice_number: '',
    tax_amount: 0,
    discount_amount: 0,
    subtotal: 0,
    total_amount: 0,
    items: buildItemsFromReceipt(purchaseOrderReceipt ?? null),
  }
}

export function useDeliveryNoteForm({
  mode,
  deliveryNote,
  standalone = false,
  suppliers: _suppliers,
  products,
  purchaseOrders,
  purchaseOrder,
  purchaseOrderReceipt,
  initialPurchaseOrderId,
  pendingFiles,
}: UseDeliveryNoteFormOptions) {
  const { success, error } = useSweetAlert()
  const clientErrors = ref<Record<string, string>>({})

  const taxMode = ref<'amount' | 'percent'>('amount')
  const discountMode = ref<'amount' | 'percent'>('amount')
  const taxPercent = ref(0)
  const discountPercent = ref(0)
  const taxPercentUserEdited = ref(false)
  const discountPercentUserEdited = ref(false)
  const taxEnabled = ref(mode === 'edit' ? toNumber(deliveryNote?.tax_amount) > 0 : true)
  const discountEnabled = ref(mode === 'edit' ? toNumber(deliveryNote?.discount_amount) > 0 : true)

  const poSearchQuery = ref('')
  const showPOSuggestions = ref(false)
  const selectedPurchaseOrder = ref<DeliveryNoteFormPurchaseOrder | null>(purchaseOrder ?? null)
  const receiptSummary = ref<PurchaseOrderReceiptSummary | null>(purchaseOrderReceipt ?? null)
  const isRestoringDraft = ref(false)

  const form = useForm(
    buildInitialForm(mode, deliveryNote, purchaseOrder, purchaseOrderReceipt, initialPurchaseOrderId),
  )

  const isFormDisabled = computed(
    () => mode === 'edit' && deliveryNote?.status === 'validated',
  )

  const deliveryNoteDraftBaseline = {
    ...buildInitialForm(mode, deliveryNote, purchaseOrder, purchaseOrderReceipt, initialPurchaseOrderId),
    ui: {
      taxMode: 'amount' as const,
      discountMode: 'amount' as const,
      taxPercent: 0,
      discountPercent: 0,
      taxEnabled: mode === 'edit' ? toNumber(deliveryNote?.tax_amount) > 0 : true,
      discountEnabled: mode === 'edit' ? toNumber(deliveryNote?.discount_amount) > 0 : true,
      poSearchQuery: purchaseOrder?.po_number ?? '',
    },
  }

  const getDeliveryNoteDraftData = () => ({
    ...(form.data() as Record<string, unknown>),
    ui: {
      taxMode: taxMode.value,
      discountMode: discountMode.value,
      taxPercent: taxPercent.value,
      discountPercent: discountPercent.value,
      taxEnabled: taxEnabled.value,
      discountEnabled: discountEnabled.value,
      poSearchQuery: poSearchQuery.value,
    },
  })

  const restoreDeliveryNoteDraftData = (data: Record<string, unknown>) => {
    isRestoringDraft.value = true

    try {
      const { ui, ...formData } = data
      restoreInertiaFormData(form as unknown as Record<string, unknown>, formData)
      normalizeFormDateFields(form as unknown as Record<string, unknown>, ['delivery_date'])

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
        if (typeof uiState.poSearchQuery === 'string') {
          poSearchQuery.value = uiState.poSearchQuery
        }
      }

      syncSelectedPurchaseOrderFromForm()
    } finally {
      isRestoringDraft.value = false
    }
  }

  const deliveryNoteDraftSnapshot = computed(() => getDeliveryNoteDraftData())

  const draftScopeContext = computed(() => {
    if (mode !== 'create') {
      return null
    }

    if (standalone) {
      return 'standalone'
    }

    if (form.purchase_order_id) {
      return `from-po:${form.purchase_order_id}`
    }

    return 'from-po:select'
  })

  const draft = useFormDraft({
    formType: 'delivery_note',
    mode,
    entityId: deliveryNote?.id ?? null,
    scopeContext: draftScopeContext,
    watchSource: deliveryNoteDraftSnapshot,
    getData: () => getDeliveryNoteDraftData(),
    restoreData: (data) => restoreDeliveryNoteDraftData(data as Record<string, unknown>),
    getBaseline: () => deliveryNoteDraftBaseline,
    enabled: !(mode === 'edit' && deliveryNote?.status === 'validated'),
  })

  const productsList = computed(() => (Array.isArray(products) ? products : products.value))
  const purchaseOrdersList = computed(() =>
    Array.isArray(purchaseOrders) ? purchaseOrders : purchaseOrders.value,
  )

  const pageTitle = computed(() =>
    mode === 'edit' && deliveryNote
      ? `Modifier le bon de livraison ${deliveryNote.delivery_number}`
      : 'Nouveau bon de livraison',
  )

  const pageSubtitle = computed(() => {
    if (mode === 'edit') {
      return deliveryNote?.delivery_number ?? 'Modifiez les informations du bon de livraison'
    }

    if (standalone) {
      return 'Bon de livraison autonome sans bon de commande'
    }

    if (form.purchase_order_id) {
      return 'Bon de livraison lié à un bon de commande — quantités restantes préremplies'
    }

    return 'Enregistrez une nouvelle livraison fournisseur'
  })

  const submitLabel = computed(() =>
    mode === 'edit' ? 'Enregistrer les modifications' : 'Créer le bon de livraison',
  )

  const submitLoadingLabel = computed(() => (mode === 'edit' ? 'Modification...' : 'Création...'))

  const submitIcon = computed(() => (mode === 'edit' ? 'bi-check-circle' : 'bi-save'))

  const today = getTodayForInput()

  const filteredPurchaseOrders = computed(() => {
    if (!form.supplier_id) {
      return []
    }

    let filtered = purchaseOrdersList.value.filter(
      (po) => po.supplier_id === Number(form.supplier_id),
    )

    if (poSearchQuery.value) {
      const query = poSearchQuery.value.toLowerCase()
      filtered = filtered.filter((po) => po.po_number.toLowerCase().includes(query))
    }

    return filtered
  })

  const productsForSelection = computed(() => {
    if (!form.purchase_order_id || !receiptSummary.value) {
      return productsList.value
    }

    const receiptProductIds = new Set(
      receiptSummary.value.items
        .filter((line) => line.available_quantity > 0)
        .map((line) => line.product_id),
    )

    return productsList.value.filter((product) => receiptProductIds.has(product.id))
  })

  const syncSelectedPurchaseOrderFromForm = () => {
    if (!form.purchase_order_id) {
      selectedPurchaseOrder.value = null
      return
    }

    const po = purchaseOrdersList.value.find((entry) => entry.id === Number(form.purchase_order_id))
    if (po) {
      selectedPurchaseOrder.value = po
      if (!poSearchQuery.value) {
        poSearchQuery.value = po.po_number
      }
    }
  }

  const syncItemReceiptMetadata = (productId: number, index?: number) => {
    const line = getReceiptLineForProduct(receiptSummary.value, productId)
    if (!line) {
      return
    }

    const applyToItem = (item: DeliveryNoteFormItem, itemIndex: number) => {
      item.ordered_quantity = line.ordered_quantity
      item.delivered_quantity = line.delivered_quantity
      item.remaining_quantity = line.remaining_quantity
      item.available_quantity = line.available_quantity

      if (item.available_quantity !== undefined && item.quantity > item.available_quantity) {
        item.quantity = item.available_quantity
        updateItemTotal(itemIndex)
      }
    }

    if (index !== undefined) {
      applyToItem(form.items[index], index)
      return
    }

    form.items.forEach((item, itemIndex) => {
      if (item.product_id === productId) {
        applyToItem(item, itemIndex)
      }
    })
  }

  const getReceiptLineForItem = (index: number) => {
    const item = form.items[index]
    if (!item?.product_id) {
      return null
    }

    return getReceiptLineForProduct(receiptSummary.value, item.product_id)
  }

  const handlePOSearch = () => {
    showPOSuggestions.value = true
  }

  const selectPurchaseOrder = async (po: DeliveryNoteFormPurchaseOrder) => {
    selectedPurchaseOrder.value = po
    form.purchase_order_id = po.id
    poSearchQuery.value = po.po_number
    showPOSuggestions.value = false

    try {
      const excludeId = mode === 'edit' ? deliveryNote?.id : undefined
      receiptSummary.value = await fetchReceiptSummary(po.id, excludeId)
      form.items = buildItemsFromReceipt(receiptSummary.value)
      syncFormTotals()
    } catch {
      error('Impossible de charger les quantités restantes du bon de commande.')
    }
  }

  /**
   * Réinitialise la sélection BC/lignes lorsque le fournisseur change
   * dans le workflow « BL depuis BC » (sélection manuelle du BC).
   * BL autonome : ne touche ni aux lignes ni au purchase_order_id.
   * BL déjà rattaché à un BC : aucune action (fournisseur verrouillé côté UI).
   */
  const handleSupplierChange = () => {
    if (standalone || form.purchase_order_id) {
      return
    }

    form.purchase_order_id = null
    form.items = []
    poSearchQuery.value = ''
    selectedPurchaseOrder.value = null
    receiptSummary.value = null
    showPOSuggestions.value = false
  }

  watch(
    () => form.supplier_id,
    (newSupplierId, oldSupplierId) => {
      if (isRestoringDraft.value) {
        return
      }

      if (newSupplierId && newSupplierId !== oldSupplierId) {
        handleSupplierChange()
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

  const getProduct = (productId: number): DeliveryNoteFormProduct | undefined => {
    if (!productId) return undefined
    return productsList.value.find((product) => product.id === productId)
  }

  const addItem = () => {
    if (form.purchase_order_id && receiptSummary.value) {
      const availableLines = receiptSummary.value.items.filter((line) => line.available_quantity > 0)
      const alreadySelectedIds = new Set(
        form.items.map((item) => item.product_id).filter((id) => id > 0),
      )
      const nextLine = availableLines.find((line) => !alreadySelectedIds.has(line.product_id))

      if (nextLine) {
        form.items.push({
          product_id: nextLine.product_id,
          quantity: nextLine.available_quantity,
          unit_price: toNumber(nextLine.unit_price),
          total_price: nextLine.available_quantity * toNumber(nextLine.unit_price),
          ordered_quantity: nextLine.ordered_quantity,
          delivered_quantity: nextLine.delivered_quantity,
          remaining_quantity: nextLine.remaining_quantity,
          available_quantity: nextLine.available_quantity,
        })
        syncFormTotals()
        return
      }
    }

    form.items.push({
      product_id: 0,
      quantity: 1,
      unit_price: 0,
      total_price: 0,
    })
  }

  const removeItem = (index: number) => {
    form.items.splice(index, 1)
    syncFormTotals()
  }

  const handleProductSelected = (product: DeliveryNoteFormProduct, index: number) => {
    const item = form.items[index]
    item.product_id = product.id

    const fullProduct = getProduct(product.id)
    if (fullProduct) {
      item.unit_price = getProductUnitPrice(fullProduct)
    } else {
      item.unit_price = getProductUnitPrice(product)
    }

    const receiptLine = getReceiptLineForProduct(receiptSummary.value, product.id)
    if (receiptLine) {
      item.unit_price = toNumber(receiptLine.unit_price) || item.unit_price
      syncItemReceiptMetadata(product.id, index)
    }

    updateItemTotal(index)
  }

  const getExcludedProductIds = (currentIndex: number): number[] =>
    form.items
      .map((item, index) => (index !== currentIndex ? item.product_id : null))
      .filter((id): id is number => id !== null && id > 0)

  const updateItemTotal = (index: number) => {
    const item = form.items[index]
    if (!item) return

    item.total_price = toNumber(item.quantity) * toNumber(item.unit_price)
    syncFormTotals()
  }

  const canIncrementQuantity = (index: number): boolean => {
    const item = form.items[index]
    if (!item) return false

    if (item.available_quantity !== undefined && item.available_quantity > 0) {
      return item.quantity < item.available_quantity
    }

    return true
  }

  const decrementQuantity = (index: number) => {
    const item = form.items[index]
    if (!item || item.quantity <= 1) return
    item.quantity -= 1
    updateItemTotal(index)
  }

  const incrementQuantity = (index: number) => {
    if (!canIncrementQuantity(index)) return

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
    const productMap = new Map<number, DeliveryNoteFormItem>()

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
    syncFormTotals()
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

  watch(
    () =>
      form.items.map((item) => ({
        quantity: item.quantity,
        unit_price: item.unit_price,
        total_price: item.total_price,
      })),
    () => {
      form.items.forEach((item, index) => {
        item.total_price = toNumber(item.quantity) * toNumber(item.unit_price)
      })
      syncFormTotals()
    },
    { deep: true },
  )

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

  const syncFormTotals = () => {
    form.subtotal = subtotal.value
    form.total_amount = totalAmount.value
  }

  const validateForm = () => {
    const errors: Record<string, string> = {}

    if (!form.supplier_id) {
      errors.supplier_id = 'Le fournisseur est requis.'
    }

    if (!standalone && !form.purchase_order_id) {
      errors.purchase_order_id = 'Le bon de commande est requis.'
    }

    if (!form.delivery_date) {
      errors.delivery_date = 'La date de livraison est requise.'
    }

    if (form.items.length === 0) {
      errors.items = 'Au moins un article est requis.'
    }

    if (hasDuplicateProducts.value) {
      errors.items = "Chaque produit ne peut être sélectionné qu'une seule fois."
    }

    if (taxEnabled.value && taxMode.value === 'percent') {
      if (!Number.isFinite(taxPercent.value) || taxPercent.value < 0 || taxPercent.value > 100) {
        errors.tax_percent = 'Le pourcentage de taxe doit être compris entre 0 et 100.'
      }
    }

    if (discountEnabled.value && discountMode.value === 'percent') {
      if (
        !Number.isFinite(discountPercent.value) ||
        discountPercent.value < 0 ||
        discountPercent.value > 100
      ) {
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
      } else if (isProductDuplicate(index)) {
        errors[`items.${index}.product_id`] = 'Ce produit est déjà sélectionné.'
      }

      if (!item.quantity || item.quantity <= 0) {
        errors[`items.${index}.quantity`] = 'La quantité doit être supérieure à 0'
      } else {
        const receiptError = validateDeliveryQuantityAgainstReceipt(
          receiptSummary.value,
          item.product_id,
          item.quantity,
        )
        if (receiptError) {
          errors[`items.${index}.quantity`] = receiptError
        }
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
      case 'purchase_order_id':
        if (!value) {
          errorMessage = 'Le bon de commande est requis.'
        }
        break
      case 'delivery_date':
        if (!value) {
          errorMessage = 'La date de livraison est requise.'
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

  const validateItemField = (index: number, fieldName: string, value: unknown) => {
    const key = `items.${index}.${fieldName}`
    if (clientErrors.value[key]) {
      delete clientErrors.value[key]
    }

    let errorMessage = ''

    switch (fieldName) {
      case 'product_id':
        if (!value || Number(value) === 0) {
          errorMessage = 'Le produit est requis'
        } else if (isProductDuplicate(index)) {
          errorMessage = 'Ce produit est déjà sélectionné'
        }
        break
      case 'quantity':
        if (!value || Number(value) < 1) {
          errorMessage = 'La quantité doit être supérieure à 0'
        } else {
          const item = form.items[index]
          const receiptError = validateDeliveryQuantityAgainstReceipt(
            receiptSummary.value,
            item.product_id,
            Number(value),
          )
          if (receiptError) {
            errorMessage = receiptError
          }
        }
        break
      case 'unit_price':
        if (value === null || value === undefined || Number(value) < 0) {
          errorMessage = 'Le prix unitaire est requis et doit être positif'
        }
        break
    }

    if (errorMessage) {
      clientErrors.value[key] = errorMessage
    }
  }

  const buildPayload = (overrides: Record<string, unknown> = {}): DeliveryNotePayload => {
    const computedSubtotal = subtotal.value
    const computedTotal = totalAmount.value

    form.subtotal = computedSubtotal
    form.total_amount = computedTotal

    return {
      supplier_id: form.supplier_id || null,
      purchase_order_id: form.purchase_order_id || null,
      delivery_date: form.delivery_date,
      status: mode === 'create' ? 'pending' : form.status,
      notes: form.notes || null,
      invoice_number: form.invoice_number || null,
      subtotal: computedSubtotal,
      tax_amount: taxEnabled.value ? form.tax_amount || 0 : 0,
      discount_amount: discountEnabled.value ? form.discount_amount || 0 : 0,
      total_amount: computedTotal,
      items: form.items.map((item) => ({
        ...(item.id ? { id: item.id } : {}),
        product_id: item.product_id,
        quantity: item.quantity,
        unit_price: item.unit_price,
        total_price: getItemLineTotal(item),
      })),
      ...overrides,
    }
  }

  const appendPayloadToFormData = (formData: FormData, payload: DeliveryNotePayload) => {
    if (payload.supplier_id != null) {
      formData.append('supplier_id', String(payload.supplier_id))
    }

    if (payload.purchase_order_id != null) {
      formData.append('purchase_order_id', String(payload.purchase_order_id))
    }

    formData.append('delivery_date', payload.delivery_date)
    formData.append('status', String(payload.status))
    formData.append('notes', payload.notes ?? '')
    formData.append('invoice_number', payload.invoice_number ?? '')
    formData.append('subtotal', String(payload.subtotal))
    formData.append('tax_amount', String(payload.tax_amount))
    formData.append('discount_amount', String(payload.discount_amount))
    formData.append('total_amount', String(payload.total_amount))

    payload.items.forEach((item, index) => {
      if (item.id) {
        formData.append(`items[${index}][id]`, String(item.id))
      }
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
        '.delivery-note-form-root .is-invalid, .delivery-note-form-root .invalid-feedback.d-block',
      )
      firstInvalid?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    })
  }

  const prepareSubmit = () => {
    if (isFormDisabled.value) {
      return false
    }

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

    if (mode === 'edit' && deliveryNote?.id) {
      payload.delivery_note_id = deliveryNote.id
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
              ? 'Bon de livraison modifié avec succès !'
              : 'Bon de livraison créé avec succès !',
          )
          if (mode === 'create') {
            form.reset()
          }
          clientErrors.value = {}
        },
        onError: () => {
          error(
            mode === 'edit'
              ? 'Erreur lors de la modification du bon de livraison.'
              : 'Erreur lors de la création du bon de livraison.',
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
        }).post(route('delivery-notes.store'), submitOptions)
        return
      }

      if (!deliveryNote) return

      form.transform(() => {
        const formData = new FormData()
        appendPayloadToFormData(formData, payload)
        files.forEach((file) => {
          formData.append('attachments[]', file)
        })
        formData.append('_method', 'PUT')
        return formData
      }).post(route('delivery-notes.update', { id: deliveryNote.id }), submitOptions)
      return
    }

    if (mode === 'create') {
      form.transform(() => payload).post(route('delivery-notes.store'), {
        onSuccess: async () => {
          await draft.markSubmitted()
          success('Bon de livraison créé avec succès !')
          form.reset()
          clientErrors.value = {}
        },
        onError: () => {
          error('Erreur lors de la création du bon de livraison.')
        },
      })
      return
    }

    if (!deliveryNote) return

    form.transform(() => payload).put(route('delivery-notes.update', { id: deliveryNote.id }), {
      onSuccess: async () => {
        await draft.markSubmitted()
        success('Bon de livraison modifié avec succès !')
        clientErrors.value = {}
      },
      onError: () => {
        error('Erreur lors de la modification du bon de livraison.')
      },
    })
  }

  const loadReceiptSummaryForEdit = async () => {
    const purchaseOrderId = deliveryNote?.purchase_order_id
    if (!purchaseOrderId) {
      return
    }

    if (purchaseOrderReceipt) {
      receiptSummary.value = purchaseOrderReceipt
    } else {
      try {
        receiptSummary.value = await fetchReceiptSummary(purchaseOrderId, deliveryNote?.id)
      } catch {
        error('Impossible de charger les quantités restantes du bon de commande.')
        return
      }
    }

    form.items.forEach((item, index) => {
      if (item.product_id) {
        syncItemReceiptMetadata(item.product_id, index)
      }
    })
  }

  const initializePurchaseOrderFromProps = async () => {
    if (purchaseOrder) {
      selectedPurchaseOrder.value = purchaseOrder
      poSearchQuery.value = purchaseOrder.po_number
      if (!receiptSummary.value && purchaseOrder.id) {
        try {
          const excludeId = mode === 'edit' ? deliveryNote?.id : undefined
          receiptSummary.value = await fetchReceiptSummary(purchaseOrder.id, excludeId)
        } catch {
          // Le résumé peut déjà être fourni via purchaseOrderReceipt
        }
      }
      return
    }

    if (initialPurchaseOrderId) {
      const po = purchaseOrdersList.value.find((entry) => entry.id === initialPurchaseOrderId)
      if (po) {
        await selectPurchaseOrder(po)
      }
    }
  }

  const handleClickOutside = (event: MouseEvent) => {
    const target = event.target as HTMLElement
    if (!target.closest('.position-relative')) {
      showPOSuggestions.value = false
    }
  }

  onMounted(async () => {
    syncTaxPercentFromAmount()
    syncDiscountPercentFromAmount()

    if (mode === 'edit') {
      syncSelectedPurchaseOrderFromForm()
      await loadReceiptSummaryForEdit()
    } else {
      await initializePurchaseOrderFromProps()
    }

    if (form.items.length > 0) {
      syncFormTotals()
    }

    document.addEventListener('click', handleClickOutside)
  })

  onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside)
  })

  return {
    mode,
    deliveryNote,
    standalone,
    form,
    errors: computed(() => form.errors),
    processing: computed(() => form.processing),
    clientErrors,
    isFormDisabled,
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
    receiptSummary,
    selectedPurchaseOrder,
    poSearchQuery,
    showPOSuggestions,
    filteredPurchaseOrders,
    productsForSelection,
    getProduct,
    getReceiptLineForItem,
    syncItemReceiptMetadata,
    handlePOSearch,
    selectPurchaseOrder,
    handleSupplierChange,
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
    validateItemField,
    preparePreview,
    submit,
    draft,
  }
}
