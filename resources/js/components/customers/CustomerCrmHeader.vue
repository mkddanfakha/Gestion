<template>
  <section class="customer-crm-header card">
    <div class="card-body">
      <div class="customer-crm-header__layout">
        <div class="customer-crm-header__identity">
          <div class="customer-crm-header__avatar" aria-hidden="true">{{ initials }}</div>
          <div class="customer-crm-header__info">
            <div class="customer-crm-header__title-row">
              <h1 class="customer-crm-header__name">{{ customer.name }}</h1>
              <span
                class="badge"
                :class="customer.is_active ? 'bg-success' : 'bg-danger'"
              >
                {{ customer.is_active ? 'Actif' : 'Inactif' }}
              </span>
            </div>

            <ul class="customer-crm-header__contacts">
              <li v-if="customer.phone">
                <i class="bi bi-telephone me-2"></i>
                <a :href="`tel:${customer.phone}`">{{ customer.phone }}</a>
              </li>
              <li v-if="customer.email">
                <i class="bi bi-envelope me-2"></i>
                <a :href="`mailto:${customer.email}`">{{ customer.email }}</a>
              </li>
              <li>
                <i class="bi bi-cake2 me-2"></i>
                <span>{{ birthdayLabel }}</span>
              </li>
              <li>
                <i class="bi bi-calendar-check me-2"></i>
                <span>Client depuis le {{ memberSinceLabel }}</span>
              </li>
              <li v-if="customer.identity_document_type || customer.identity_document_number">
                <i class="bi bi-person-vcard me-2"></i>
                <span>{{ identityLabel }}</span>
              </li>
              <li v-else>
                <i class="bi bi-person-vcard me-2"></i>
                <span>Pièce d'identité non renseignée</span>
              </li>
            </ul>

            <p v-if="hasAddress" class="customer-crm-header__address text-muted small mb-0">
              <i class="bi bi-geo-alt me-1"></i>
              {{ addressLine }}
            </p>
          </div>
        </div>

        <div class="customer-crm-header__actions">
          <Link
            v-if="canCreateSale"
            :href="newSaleUrl"
            class="btn btn-success"
          >
            <i class="bi bi-cart-plus me-1"></i>
            Nouvelle vente
          </Link>
          <Link
            v-if="canEditCustomer"
            :href="route('customers.edit', { id: customer.id })"
            class="btn btn-outline-primary"
          >
            <i class="bi bi-pencil me-1"></i>
            Modifier
          </Link>
          <Link
            :href="route('customers.index')"
            class="btn btn-outline-secondary"
          >
            <i class="bi bi-arrow-left me-1"></i>
            Retour
          </Link>
          <button
            v-if="canDeleteCustomer"
            type="button"
            class="btn btn-outline-danger"
            @click="emit('delete')"
          >
            <i class="bi bi-trash me-1"></i>
            Supprimer
          </button>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { formatLongDate, getCustomerInitials } from '@/utils/dateFormatter'
import { getIdentityTypeLabel } from '@/utils/customerIdentity'

export interface CustomerCrmProfile {
  id: number
  name: string
  email?: string | null
  phone?: string | null
  identity_document_type?: string | null
  identity_document_type_label?: string | null
  identity_document_number?: string | null
  identity_document_number_masked?: string | null
  birthday?: string | null
  address?: string | null
  city?: string | null
  postal_code?: string | null
  country?: string | null
  is_active: boolean
  created_at?: string | null
}

const props = defineProps<{
  customer: CustomerCrmProfile
  canCreateSale: boolean
  canEditCustomer: boolean
  canDeleteCustomer: boolean
}>()

const emit = defineEmits<{
  delete: []
}>()

const initials = computed(() => getCustomerInitials(props.customer.name))
const birthdayLabel = computed(() =>
  props.customer.birthday ? formatLongDate(props.customer.birthday) : 'Date d\'anniversaire non renseignée',
)
const memberSinceLabel = computed(() => formatLongDate(props.customer.created_at))
const identityLabel = computed(() => {
  if (!props.customer.identity_document_type && !props.customer.identity_document_number) {
    return 'Pièce d\'identité non renseignée'
  }

  const type = props.customer.identity_document_type_label
    || getIdentityTypeLabel(props.customer.identity_document_type)
  const number = props.customer.identity_document_number
    || props.customer.identity_document_number_masked

  return `${type} : ${number}`
})
const newSaleUrl = computed(() => `${route('sales.create')}?customer_id=${props.customer.id}`)

const hasAddress = computed(() =>
  Boolean(props.customer.address || props.customer.city || props.customer.postal_code || props.customer.country),
)

const addressLine = computed(() =>
  [props.customer.address, props.customer.postal_code, props.customer.city, props.customer.country]
    .filter(Boolean)
    .join(', '),
)
</script>
