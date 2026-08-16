import { formatCurrency } from '@/utils/currencyFormatter'
import { useForm } from '@inertiajs/vue3'
import { computed, onMounted, ref, watch, type Ref } from 'vue'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { useFormDraft } from '@/composables/useFormDraft'
import { restoreInertiaFormData } from '@/drafts/restoreInertiaForm'
import { formatDateForInput, getTodayForInput, normalizeFormDateFields } from '@/utils/dateFormatter'
import {
  calculateSalePaymentState,
  getPaymentStatusBadgeClass,
  getPaymentStatusIcon,
  getPaymentStatusLabel,
  isPaymentMethodRequired as requiresPaymentMethod,
  resolveEffectiveDownPaymentAmount,
} from '@/utils/salePaymentStatus'

export type SaleFormMode = 'create' | 'edit'

export interface SaleFormCustomer {
  id: number
  name: string
  email?: string | null
  phone?: string | null
}

export interface SaleFormCategory {
  id: number
  name: string
  color: string
}

export interface SaleFormProduct {
  id: number
  name: string
  sku?: string | null
  barcode?: string | null
  price: number
  stock_quantity: number
  unit: string
  category?: SaleFormCategory
  image_url?: string | null
}

export interface SaleFormItem {
  product_id: number
  quantity: number
  unit_price: number
  total_price: number
}

export interface SaleFormSale {
  id: number
  sale_number: string
  customer_id?: number | null
  payment_method?: string | null
  notes?: string | null
  due_date?: string | null
  tax_amount?: number | string | null
  discount_amount?: number | string | null
  down_payment_amount?: number | string | null
  payment_status?: string | null
  saleItems?: SaleFormItem[]
}

interface UseSaleFormOptions {
  mode: SaleFormMode
  sale?: SaleFormSale
  products: Ref<SaleFormProduct[]> | SaleFormProduct[]
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

function getItemLineTotal(item: SaleFormItem): number {
  const storedTotal = toNumber(item.total_price)
  if (storedTotal > 0) {
    return storedTotal
  }

  return toNumber(item.quantity) * toNumber(item.unit_price)
}

function mapSaleItems(items?: SaleFormItem[]): SaleFormItem[] {
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

function buildInitialForm(mode: SaleFormMode, sale?: SaleFormSale) {
  if (mode === 'edit' && sale) {
    return {
      customer_id: sale.customer_id ?? null,
      payment_method: sale.payment_method || '',
      notes: sale.notes || '',
      due_date: formatDateForInput(sale.due_date),
      tax_amount: toNumber(sale.tax_amount),
      discount_amount: toNumber(sale.discount_amount),
      down_payment_amount: toNumber(sale.down_payment_amount),
      items: mapSaleItems(sale.saleItems),
    }
  }

  return {
    customer_id: null,
    payment_method: '',
    notes: '',
    due_date: '',
    tax_amount: 0,
    discount_amount: 0,
    down_payment_amount: 0,
    items: [] as SaleFormItem[],
  }
}

export function useSaleForm({ mode, sale, products }: UseSaleFormOptions) {
  const { success, error } = useSweetAlert()
  const clientErrors = ref<Record<string, string>>({})

  const taxMode = ref<'amount' | 'percent'>('amount')
  const discountMode = ref<'amount' | 'percent'>('amount')
  const taxPercent = ref(0)
  const discountPercent = ref(0)
  const taxPercentUserEdited = ref(false)
  const discountPercentUserEdited = ref(false)
  const taxEnabled = ref(mode === 'edit' ? toNumber(sale?.tax_amount) > 0 : true)
  const discountEnabled = ref(mode === 'edit' ? toNumber(sale?.discount_amount) > 0 : true)
  const cashReceivedAmount = ref(0)

  const form = useForm(buildInitialForm(mode, sale))

  const saleDraftBaseline = {
    ...buildInitialForm(mode, sale),
    ui: {
      taxMode: mode === 'edit' ? (toNumber(sale?.tax_amount) > 0 ? 'amount' : 'amount') : 'amount',
      discountMode: mode === 'edit' ? 'amount' : 'amount',
      taxPercent: 0,
      discountPercent: 0,
      taxEnabled: mode === 'edit' ? toNumber(sale?.tax_amount) > 0 : true,
      discountEnabled: mode === 'edit' ? toNumber(sale?.discount_amount) > 0 : true,
      cashReceivedAmount: 0,
    },
  }

  const getSaleDraftData = () => ({
    ...(form.data() as Record<string, unknown>),
    ui: {
      taxMode: taxMode.value,
      discountMode: discountMode.value,
      taxPercent: taxPercent.value,
      discountPercent: discountPercent.value,
      taxEnabled: taxEnabled.value,
      discountEnabled: discountEnabled.value,
      cashReceivedAmount: cashReceivedAmount.value,
    },
  })

  const restoreSaleDraftData = (data: Record<string, unknown>) => {
    const { ui, ...formData } = data
    restoreInertiaFormData(form as unknown as Record<string, unknown>, formData)
    normalizeFormDateFields(form as unknown as Record<string, unknown>, ['due_date'])

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
      cashReceivedAmount.value = toNumber(uiState.cashReceivedAmount)
    }
  }

  const saleDraftSnapshot = computed(() => getSaleDraftData())

  const draft = useFormDraft({
    formType: 'sale',
    mode,
    entityId: sale?.id ?? null,
    watchSource: saleDraftSnapshot,
    getData: () => getSaleDraftData(),
    restoreData: (data) => restoreSaleDraftData(data as Record<string, unknown>),
    getBaseline: () => saleDraftBaseline,
  })

  const productsList = computed(() => (Array.isArray(products) ? products : products.value))

  const pageTitle = computed(() =>
    mode === 'edit' && sale ? `Modifier la vente ${sale.sale_number}` : 'Nouvelle vente',
  )

  const breadcrumbLabel = computed(() => (mode === 'edit' ? 'Modifier' : 'Créer'))

  const submitLabel = computed(() =>
    mode === 'edit' ? 'Enregistrer les modifications' : 'Enregistrer la vente',
  )

  const submitLoadingLabel = computed(() => (mode === 'edit' ? 'Modification...' : 'Enregistrement...'))

  const submitIcon = computed(() => (mode === 'edit' ? 'bi-check-circle' : 'bi-save'))

  const today = getTodayForInput()

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

  const getProduct = (productId: number): SaleFormProduct | undefined => {
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

  const handleProductSelected = (product: SaleFormProduct, index: number) => {
    const item = form.items[index]
    item.product_id = product.id
    item.unit_price = product.price
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

  const getAvailableStock = (index: number): number => {
    const item = form.items[index]
    if (!item.product_id) return 0
    const product = productsList.value.find((p) => p.id === item.product_id)
    return product ? product.stock_quantity : 0
  }

  const getMaxQuantity = (index: number): number => getAvailableStock(index)

  const canIncrementQuantity = (index: number): boolean => {
    const item = form.items[index]
    if (!item.product_id) return true
    const max = getMaxQuantity(index)
    return max > 0 && item.quantity < max
  }

  const decrementQuantity = (index: number) => {
    const item = form.items[index]
    if (item.quantity <= 1) return
    item.quantity -= 1
    updateItemTotal(index)
  }

  const incrementQuantity = (index: number) => {
    const item = form.items[index]
    if (item.product_id) {
      const max = getMaxQuantity(index)
      if (max <= 0 || item.quantity >= max) return
    }
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
    const productMap = new Map<number, SaleFormItem>()

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

  const isQuantityExceedsStock = (index: number): boolean => {
    const item = form.items[index]
    if (!item.product_id || !item.quantity) return false
    return item.quantity > getAvailableStock(index)
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

  const updateTaxPercentFromAmount = () => {
    if (taxMode.value !== 'amount') return
    syncTaxPercentFromAmount()
  }

  const updateDiscountPercentFromAmount = () => {
    if (discountMode.value !== 'amount') return
    syncDiscountPercentFromAmount()
  }

  watch(taxMode, (newMode) => {
    taxPercentUserEdited.value = false
    syncTaxPercentFromAmount()
  })

  watch(discountMode, (newMode) => {
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

  const effectiveDownPaymentAmount = computed(() =>
    resolveEffectiveDownPaymentAmount({
      totalAmount: totalAmount.value,
      downPaymentAmount: form.down_payment_amount || 0,
      paymentMethod: form.payment_method,
      cashReceivedAmount: cashReceivedAmount.value,
    }),
  )

  const paymentState = computed(() =>
    calculateSalePaymentState(totalAmount.value, effectiveDownPaymentAmount.value),
  )

  const paymentStatusLabel = computed(() => getPaymentStatusLabel(paymentState.value.paymentStatus))
  const paymentStatusBadgeClass = computed(() => getPaymentStatusBadgeClass(paymentState.value.paymentStatus))
  const paymentStatusIcon = computed(() => getPaymentStatusIcon(paymentState.value.paymentStatus))
  const isSalePaid = computed(() => paymentState.value.paymentStatus === 'paid')
  const isPaymentMethodRequired = computed(() => requiresPaymentMethod(effectiveDownPaymentAmount.value))

  const onDownPaymentInput = () => {
    validateField('down_payment_amount', form.down_payment_amount)
    validateField('payment_method', form.payment_method)
  }

  const cashAmountExpected = computed(() => {
    const paid = form.down_payment_amount || 0
    if (paid > 0 && paid < totalAmount.value) {
      return Math.max(0, totalAmount.value - paid)
    }
    return totalAmount.value
  })

  const changeAmount = computed(() => {
    if (form.payment_method === 'cash' && cashReceivedAmount.value >= cashAmountExpected.value) {
      return cashReceivedAmount.value - cashAmountExpected.value
    }
    return 0
  })

  watch(totalAmount, (total) => {
    if ((form.down_payment_amount || 0) > total) {
      form.down_payment_amount = total
    }
  })

  const validateForm = () => {
    const errors: Record<string, string> = {}

    if (isPaymentMethodRequired.value && !form.payment_method) {
      errors.payment_method = 'Veuillez sélectionner un mode de paiement.'
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

    if (form.down_payment_amount < 0) {
      errors.down_payment_amount = 'Le montant payé ne peut pas être négatif'
    }

    if (form.down_payment_amount > totalAmount.value) {
      errors.down_payment_amount = 'Le montant payé ne peut pas dépasser le montant total'
    }

    form.items.forEach((item, index) => {
      if (!item.product_id) {
        errors[`items.${index}.product_id`] = 'Le produit est requis'
      }
      if (!item.quantity || item.quantity < 1) {
        errors[`items.${index}.quantity`] = 'La quantité doit être au moins 1'
      }
      if (!item.unit_price || item.unit_price < 0) {
        errors[`items.${index}.unit_price`] = 'Le prix unitaire doit être positif'
      }
      if (isQuantityExceedsStock(index)) {
        errors[`items.${index}.quantity`] = 'La quantité dépasse le stock disponible'
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
      case 'payment_method':
        if (isPaymentMethodRequired.value && !value) {
          errorMessage = 'Veuillez sélectionner un mode de paiement.'
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
    case 'down_payment_amount':
      if (typeof value === 'number' && value < 0) {
        errorMessage = 'Le montant payé ne peut pas être négatif'
      } else if (typeof value === 'number' && value > totalAmount.value) {
        errorMessage = 'Le montant payé ne peut pas dépasser le montant total'
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

  const buildPayload = (overrides: Record<string, unknown> = {}) => ({
    customer_id: form.customer_id || null,
    payment_method: form.payment_method || null,
    notes: form.notes || null,
    due_date: form.due_date || null,
    tax_amount: taxEnabled.value ? form.tax_amount || 0 : 0,
    discount_amount: discountEnabled.value ? form.discount_amount || 0 : 0,
    down_payment_amount: overrides.down_payment_amount ?? effectiveDownPaymentAmount.value,
    items: form.items.map((item) => ({
      product_id: item.product_id,
      quantity: item.quantity,
      unit_price: item.unit_price,
    })),
    ...overrides,
  })

  const scrollToFirstError = () => {
    requestAnimationFrame(() => {
      const firstInvalid = document.querySelector('.sale-form-root .is-invalid, .sale-form-root .invalid-feedback.d-block')
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

    if (form.payment_method === 'cash' && cashReceivedAmount.value > 0) {
      if (cashReceivedAmount.value < cashAmountExpected.value) {
        error(`Le montant reçu doit être au moins égal à ${formatCurrency(cashAmountExpected.value)}.`)
        return false
      }
    }

    if (form.items.some((_, index) => isQuantityExceedsStock(index))) {
      error('Veuillez corriger les quantités qui dépassent le stock disponible.')
      return false
    }

    return true
  }

  const preparePreview = (): Record<string, unknown> | false => {
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
      error('Veuillez ajouter au moins un article pour afficher l\'aperçu.')
      return false
    }

    const payload = buildPayload()

    if (mode === 'edit' && sale?.id) {
      payload.sale_id = sale.id
    }

    return payload
  }

  const submit = () => {
    if (!prepareSubmit()) return

    const formData = buildPayload()

    if (mode === 'create') {
      form.transform(() => formData).post(route('sales.store'), {
        onSuccess: async () => {
          await draft.markSubmitted()
          success('Vente enregistrée avec succès !')
          form.reset()
          clientErrors.value = {}
        },
        onError: () => {
          error("Erreur lors de l'enregistrement de la vente.")
        },
      })
      return
    }

    if (!sale) return

    form.transform(() => formData).put(route('sales.update', { id: sale.id }), {
      onSuccess: async () => {
        await draft.markSubmitted()
        success('Vente modifiée avec succès !')
        clientErrors.value = {}
      },
      onError: () => {
        error('Erreur lors de la modification de la vente.')
      },
    })
  }

  return {
    mode,
    sale,
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
    cashReceivedAmount,
    pageTitle,
    breadcrumbLabel,
    submitLabel,
    submitLoadingLabel,
    submitIcon,
    today,
    itemsCount,
    subtotal,
    taxAmount,
    discountAmount,
    totalAmount,
    effectiveDownPaymentAmount,
    paymentState,
    paymentStatusLabel,
    paymentStatusBadgeClass,
    paymentStatusIcon,
    isSalePaid,
    isPaymentMethodRequired,
    cashAmountExpected,
    changeAmount,
    hasDuplicateProducts,
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
    isQuantityExceedsStock,
    getAvailableStock,
    getProductUnit,
    formatCurrency,
    onDownPaymentInput,
    onTaxPercentInput,
    onDiscountPercentInput,
    validateField,
    updateTaxFromPercent,
    updateDiscountFromPercent,
    preparePreview,
    submit,
    draft,
  }
}
