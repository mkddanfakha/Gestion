import { formatCurrency } from '@/utils/currencyFormatter'
import { useForm } from '@inertiajs/vue3'
import { computed, onMounted, ref, watch, type Ref } from 'vue'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { useFormDraft } from '@/composables/useFormDraft'
import { restoreInertiaFormData } from '@/drafts/restoreInertiaForm'

export type QuoteFormMode = 'create' | 'edit'

export type QuoteStatus = 'draft' | 'sent' | 'accepted' | 'rejected' | 'expired'

export interface QuoteFormCustomer {
  id: number
  name: string
  email?: string | null
  phone?: string | null
}

export interface QuoteFormCategory {
  id: number
  name: string
  color: string
}

export interface QuoteFormProduct {
  id: number
  name: string
  sku?: string | null
  barcode?: string | null
  price: number
  stock_quantity: number
  unit: string
  category?: QuoteFormCategory
  image_url?: string | null
}

export interface QuoteFormItem {
  product_id: number
  quantity: number
  unit_price: number
  total_price: number
}

export interface QuoteFormQuote {
  id: number
  quote_number: string
  customer_id?: number | null
  status?: QuoteStatus | string | null
  notes?: string | null
  valid_until?: string | null
  tax_amount?: number | string | null
  discount_amount?: number | string | null
  quoteItems?: QuoteFormItem[]
}

interface UseQuoteFormOptions {
  mode: QuoteFormMode
  quote?: QuoteFormQuote
  products: Ref<QuoteFormProduct[]> | QuoteFormProduct[]
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

function getItemLineTotal(item: QuoteFormItem): number {
  const storedTotal = toNumber(item.total_price)
  if (storedTotal > 0) {
    return storedTotal
  }

  return toNumber(item.quantity) * toNumber(item.unit_price)
}

function mapQuoteItems(items?: QuoteFormItem[]): QuoteFormItem[] {
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

function buildInitialForm(mode: QuoteFormMode, quote?: QuoteFormQuote) {
  if (mode === 'edit' && quote) {
    return {
      customer_id: quote.customer_id ?? null,
      status: (quote.status as QuoteStatus) || 'draft',
      notes: quote.notes || '',
      valid_until: quote.valid_until ? new Date(quote.valid_until).toISOString().split('T')[0] : '',
      tax_amount: toNumber(quote.tax_amount),
      discount_amount: toNumber(quote.discount_amount),
      items: mapQuoteItems(quote.quoteItems),
    }
  }

  return {
    customer_id: null,
    status: 'draft' as QuoteStatus,
    notes: '',
    valid_until: '',
    tax_amount: 0,
    discount_amount: 0,
    items: [] as QuoteFormItem[],
  }
}

export function useQuoteForm({ mode, quote, products }: UseQuoteFormOptions) {
  const { success, error } = useSweetAlert()
  const clientErrors = ref<Record<string, string>>({})

  const taxMode = ref<'amount' | 'percent'>('amount')
  const discountMode = ref<'amount' | 'percent'>('amount')
  const taxPercent = ref(0)
  const discountPercent = ref(0)
  const taxPercentUserEdited = ref(false)
  const discountPercentUserEdited = ref(false)
  const taxEnabled = ref(mode === 'edit' ? toNumber(quote?.tax_amount) > 0 : true)
  const discountEnabled = ref(mode === 'edit' ? toNumber(quote?.discount_amount) > 0 : true)

  const form = useForm(buildInitialForm(mode, quote))

  const quoteDraftBaseline = {
    ...buildInitialForm(mode, quote),
    ui: {
      taxMode: 'amount' as const,
      discountMode: 'amount' as const,
      taxPercent: 0,
      discountPercent: 0,
      taxEnabled: mode === 'edit' ? toNumber(quote?.tax_amount) > 0 : true,
      discountEnabled: mode === 'edit' ? toNumber(quote?.discount_amount) > 0 : true,
    },
  }

  const getQuoteDraftData = () => ({
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

  const restoreQuoteDraftData = (data: Record<string, unknown>) => {
    const { ui, ...formData } = data
    restoreInertiaFormData(form as unknown as Record<string, unknown>, formData)

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

  const quoteDraftSnapshot = computed(() => getQuoteDraftData())

  const draft = useFormDraft({
    formType: 'quote',
    mode,
    entityId: quote?.id ?? null,
    watchSource: quoteDraftSnapshot,
    getData: () => getQuoteDraftData(),
    restoreData: (data) => restoreQuoteDraftData(data as Record<string, unknown>),
    getBaseline: () => quoteDraftBaseline,
  })

  const productsList = computed(() => (Array.isArray(products) ? products : products.value))

  const pageTitle = computed(() =>
    mode === 'edit' && quote ? `Modifier le devis ${quote.quote_number}` : 'Nouveau devis',
  )

  const pageSubtitle = computed(() =>
    mode === 'edit' ? 'Modifiez les informations du devis' : 'Créez un devis pour votre client',
  )

  const submitLabel = computed(() => (mode === 'edit' ? 'Enregistrer les modifications' : 'Créer le devis'))

  const submitLoadingLabel = computed(() => (mode === 'edit' ? 'Modification...' : 'Enregistrement...'))

  const submitIcon = computed(() => (mode === 'edit' ? 'bi-check-circle' : 'bi-save'))

  const today = new Date().toISOString().split('T')[0]

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

  const getProduct = (productId: number): QuoteFormProduct | undefined => {
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

  const handleProductSelected = (product: QuoteFormProduct, index: number) => {
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
    const productMap = new Map<number, QuoteFormItem>()

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
      }
      if (!item.quantity || item.quantity < 1) {
        errors[`items.${index}.quantity`] = 'La quantité doit être au moins 1'
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

  const buildPayload = (overrides: Record<string, unknown> = {}) => ({
    customer_id: form.customer_id || null,
    status: form.status,
    notes: form.notes || null,
    valid_until: form.valid_until || null,
    tax_amount: taxEnabled.value ? form.tax_amount || 0 : 0,
    discount_amount: discountEnabled.value ? form.discount_amount || 0 : 0,
    items: form.items.map((item) => ({
      product_id: item.product_id,
      quantity: item.quantity,
      unit_price: item.unit_price,
    })),
    ...overrides,
  })

  const scrollToFirstError = () => {
    requestAnimationFrame(() => {
      const firstInvalid = document.querySelector(
        '.sale-form-root .is-invalid, .sale-form-root .invalid-feedback.d-block',
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

    if (mode === 'edit' && quote?.id) {
      payload.quote_id = quote.id
    }

    return payload
  }

  const submit = () => {
    if (!prepareSubmit()) return

    const formData = buildPayload()

    if (mode === 'create') {
      form.transform(() => formData).post(route('quotes.store'), {
        onSuccess: async () => {
          await draft.markSubmitted()
          success('Devis créé avec succès !')
          form.reset()
          clientErrors.value = {}
        },
        onError: () => {
          error('Erreur lors de la création du devis.')
        },
      })
      return
    }

    if (!quote) return

    form.transform(() => formData).put(route('quotes.update', { id: quote.id }), {
      onSuccess: async () => {
        await draft.markSubmitted()
        success('Devis modifié avec succès !')
        clientErrors.value = {}
      },
      onError: () => {
        error('Erreur lors de la modification du devis.')
      },
    })
  }

  return {
    mode,
    quote,
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
