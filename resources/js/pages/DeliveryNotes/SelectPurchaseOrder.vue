<template>
  <AppLayout>
    <div class="form-page form-page--wide">
      <FormPageHeader
        title="Créer un BL depuis un bon de commande"
        subtitle="Sélectionnez un bon de commande éligible pour préremplir le bon de livraison."
        :back-href="route('delivery-notes.index')"
        back-label="Bons de livraison"
      />

      <div v-if="eligiblePurchaseOrders.length === 0" class="alert alert-info">
        <i class="bi bi-info-circle me-1"></i>
        Aucun bon de commande éligible. Tous les bons confirmés sont peut-être entièrement livrés.
        <div class="mt-3">
          <Link :href="route('delivery-notes.create', { standalone: 1 })" class="btn btn-outline-primary btn-sm">
            Créer un BL sans bon de commande
          </Link>
        </div>
      </div>

      <div v-else class="row g-3">
        <div v-for="po in eligiblePurchaseOrders" :key="po.id" class="col-12 col-lg-6">
          <div class="card h-100 border shadow-sm">
            <div class="card-body d-flex flex-column">
              <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <div>
                  <div class="fw-semibold font-monospace">{{ po.po_number }}</div>
                  <div class="text-muted small">{{ po.supplier?.name || 'Fournisseur' }}</div>
                </div>
                <span class="badge bg-secondary">{{ po.status_label || po.status }}</span>
              </div>

              <div class="small text-muted mb-3">
                <div>Date : {{ formatDate(po.order_date) }}</div>
                <div>Montant : {{ formatCurrency(po.total_amount) }}</div>
              </div>

              <div class="row g-2 text-center mb-3">
                <div class="col-4">
                  <div class="text-muted small">Commandé</div>
                  <div class="fw-semibold">{{ po.receipt_summary.totals.ordered }}</div>
                </div>
                <div class="col-4">
                  <div class="text-muted small">Livré</div>
                  <div class="fw-semibold text-success">{{ po.receipt_summary.totals.delivered }}</div>
                </div>
                <div class="col-4">
                  <div class="text-muted small">Reste</div>
                  <div class="fw-semibold text-primary">{{ po.receipt_summary.totals.remaining }}</div>
                </div>
              </div>

              <div class="progress mb-3" style="height: 8px">
                <div
                  class="progress-bar"
                  role="progressbar"
                  :style="{ width: `${Math.min(po.receipt_summary.progress_percent, 100)}%` }"
                  :aria-valuenow="po.receipt_summary.progress_percent"
                  aria-valuemin="0"
                  aria-valuemax="100"
                ></div>
              </div>

              <Link
                :href="route('delivery-notes.create', { purchase_order_id: po.id })"
                class="btn btn-primary mt-auto"
              >
                <i class="bi bi-box-seam me-1"></i>
                Créer un BL
              </Link>
            </div>
          </div>
        </div>
      </div>

      <div class="mt-4 pt-3 border-top">
        <Link :href="route('delivery-notes.create', { standalone: 1 })" class="btn btn-outline-secondary">
          <i class="bi bi-file-earmark me-1"></i>
          BL sans bon de commande
        </Link>
      </div>
    </div>
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import FormPageHeader from '@/components/page/FormPageHeader.vue'
import { Link } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { formatCurrency } from '@/utils/currencyFormatter'
import type { PurchaseOrderReceiptSummary } from '@/composables/usePurchaseOrderReceipt'

interface EligiblePurchaseOrder {
  id: number
  po_number: string
  supplier?: { id: number; name: string }
  order_date: string
  status: string
  status_label?: string
  total_amount: number
  receipt_summary: PurchaseOrderReceiptSummary
}

defineProps<{
  eligiblePurchaseOrders: EligiblePurchaseOrder[]
}>()

const formatDate = (date: string) => new Date(date).toLocaleDateString('fr-FR')
</script>
