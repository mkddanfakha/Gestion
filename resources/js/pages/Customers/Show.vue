<template>
  <AppLayout>
    <div class="customer-crm">
      <CustomerCrmHeader
        :customer="customer"
        :can-create-sale="canCreate('sales')"
        :can-edit-customer="canAny('customers', ['edit', 'update'])"
        :can-delete-customer="canDelete('customers')"
        @delete="deleteCustomer"
      />

      <CustomerCrmKpiGrid :summary="crm" />

      <div class="customer-crm__middle row g-4">
        <div class="col-lg-7">
          <CustomerCrmSummary
            ref="summaryRef"
            :summary="crm"
            :unpaid-invoices="unpaidInvoices"
            :pending-quotes="pendingQuotes"
            @show-unpaid="summaryRef?.scrollToUnpaid()"
            @show-quotes="summaryRef?.scrollToQuotes()"
          />
        </div>
        <div class="col-lg-5">
          <section class="customer-crm-notes card h-100">
            <div class="card-header">
              <h2 class="card-title h6 mb-0">Remarques</h2>
            </div>
            <div class="card-body">
              <div v-if="customer.notes" class="customer-crm-notes__content">
                <i class="bi bi-chat-left-quote customer-crm-notes__icon"></i>
                <p class="mb-0">{{ customer.notes }}</p>
              </div>
              <div v-else class="customer-crm-empty text-center py-4">
                <i class="bi bi-chat-left-text customer-crm-empty__icon"></i>
                <p class="customer-crm-empty__message mb-0">Aucune remarque</p>
              </div>
            </div>
          </section>
        </div>
      </div>

      <CustomerCrmHistory
        :active-tab="activeTab"
        :sales="salesHistory"
        :payments="paymentsHistory"
        :invoices="invoicesHistory"
        :quotes="quotesHistory"
        :activity="activityHistory"
        @change-tab="changeTab"
        @paginate="followPagination"
      />
    </div>
  </AppLayout>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import CustomerCrmHeader from '@/components/customers/CustomerCrmHeader.vue'
import CustomerCrmKpiGrid from '@/components/customers/CustomerCrmKpiGrid.vue'
import CustomerCrmSummary from '@/components/customers/CustomerCrmSummary.vue'
import CustomerCrmHistory from '@/components/customers/CustomerCrmHistory.vue'
import type { CustomerCrmProfile } from '@/components/customers/CustomerCrmHeader.vue'
import type { CustomerCrmSummaryProps, PendingQuoteItem, UnpaidInvoiceItem } from '@/components/customers/CustomerCrmSummary.vue'
import type { CrmPaginator } from '@/components/customers/CustomerCrmHistory.vue'
import { route } from '@/lib/routes'
import { usePermissions } from '@/composables/usePermissions'
import { useSweetAlert } from '@/composables/useSweetAlert'

interface CustomerShowProps {
  customer: CustomerCrmProfile & { notes?: string | null }
  crm: CustomerCrmSummaryProps
  unpaidInvoices: UnpaidInvoiceItem[]
  pendingQuotes: PendingQuoteItem[]
  activeTab: string
  salesHistory: CrmPaginator<Record<string, unknown>>
  paymentsHistory: CrmPaginator<Record<string, unknown>>
  invoicesHistory: CrmPaginator<Record<string, unknown>>
  quotesHistory: CrmPaginator<Record<string, unknown>>
  activityHistory: CrmPaginator<Record<string, unknown>>
}

const props = defineProps<CustomerShowProps>()

const { canCreate, canAny, canDelete } = usePermissions()
const { success, error, confirm } = useSweetAlert()
const summaryRef = ref<InstanceType<typeof CustomerCrmSummary> | null>(null)

const changeTab = (tab: string) => {
  router.get(
    route('customers.show', { id: props.customer.id }),
    { tab },
    { preserveScroll: true, replace: true },
  )
}

const followPagination = (url: string) => {
  const target = new URL(url, window.location.origin)
  target.searchParams.set('tab', props.activeTab)

  router.get(
    `${target.pathname}${target.search}`,
    {},
    { preserveScroll: true },
  )
}

const deleteCustomer = async () => {
  const confirmed = await confirm(`Êtes-vous sûr de vouloir supprimer le client "${props.customer.name}" ?`)

  if (!confirmed) {
    return
  }

  router.delete(route('customers.destroy', { id: props.customer.id }), {
    onSuccess: () => {
      success(`Client "${props.customer.name}" supprimé avec succès !`)
    },
    onError: (errors) => {
      error(errors.message || 'Erreur lors de la suppression du client.')
    },
  })
}
</script>
