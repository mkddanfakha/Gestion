<template>
  <AppLayout>
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
      <div>
        <h1 class="h2 mb-1">Doublons potentiels</h1>
        <p class="text-muted mb-0">Clients pouvant être en double selon la pièce d'identité, le téléphone ou l'email.</p>
      </div>
      <Link :href="route('customers.index')" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>
        Retour aux clients
      </Link>
    </div>

    <div v-if="groups.length === 0" class="card">
      <div class="card-body text-center py-5">
        <i class="bi bi-check-circle text-success fs-1"></i>
        <p class="mt-3 mb-0">Aucun doublon potentiel détecté pour le moment.</p>
      </div>
    </div>

    <div v-else class="vstack gap-4">
      <section v-for="(group, index) in groups" :key="`${group.reason}-${index}`" class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h2 class="h6 mb-0">{{ group.label }}</h2>
          <span class="badge bg-light text-dark">{{ group.customers.length }} client(s)</span>
        </div>
        <div class="list-group list-group-flush">
          <div
            v-for="customer in group.customers"
            :key="customer.id"
            class="list-group-item d-flex justify-content-between align-items-start gap-3 flex-wrap"
          >
            <div>
              <div class="fw-medium">{{ customer.name }}</div>
              <div v-if="customer.phone" class="small text-muted">{{ customer.phone }}</div>
              <div v-if="customer.email" class="small text-muted">{{ customer.email }}</div>
              <div
                v-if="customer.identity_document_type_short && customer.identity_document_number_masked"
                class="small text-muted"
              >
                {{ customer.identity_document_type_short }} : {{ customer.identity_document_number_masked }}
              </div>
            </div>
            <Link
              v-if="canViewCustomerDetails"
              :href="route('customers.show', { id: customer.id })"
              class="btn btn-sm btn-outline-primary"
            >
              Comparer
            </Link>
          </div>
        </div>
      </section>
    </div>
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import type { CustomerDuplicateMatch } from '@/utils/customerIdentity'

interface DuplicateGroup {
  reason: string
  label: string
  customers: CustomerDuplicateMatch[]
}

defineProps<{
  groups: DuplicateGroup[]
  canViewCustomerDetails: boolean
}>()
</script>
