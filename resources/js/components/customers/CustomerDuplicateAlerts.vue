<template>
  <div v-if="hasAlerts" class="customer-duplicate-alerts">
    <div
      v-if="analysis.identity_conflict"
      class="alert alert-danger customer-duplicate-alerts__item"
      role="alert"
    >
      <div class="fw-semibold mb-1">Client existant</div>
      <p class="mb-2">Un client possède déjà cette pièce d'identité.</p>
      <div class="customer-duplicate-alerts__match">
        <div class="fw-medium">{{ analysis.identity_conflict.name }}</div>
        <div v-if="analysis.identity_conflict.phone" class="small">{{ analysis.identity_conflict.phone }}</div>
      </div>
      <div v-if="showActions" class="d-flex flex-wrap gap-2 mt-3">
        <Link
          v-if="canViewCustomer"
          :href="route('customers.show', { id: analysis.identity_conflict.id })"
          class="btn btn-sm btn-outline-danger"
        >
          Voir la fiche
        </Link>
        <button
          v-if="allowSelect"
          type="button"
          class="btn btn-sm btn-danger"
          @click="emit('select-existing', analysis.identity_conflict!)"
        >
          Sélectionner ce client
        </button>
      </div>
    </div>

    <div
      v-else-if="showIdentityAvailable && identityFieldsComplete && analysis.identity_available"
      class="customer-duplicate-alerts__hint text-success small"
    >
      <i class="bi bi-check-circle me-1"></i>
      Numéro disponible
    </div>

    <div
      v-if="analysis.phone_match"
      class="alert alert-warning customer-duplicate-alerts__item"
      role="alert"
    >
      <div class="fw-semibold mb-1">Doublon potentiel</div>
      <p class="mb-2">Un client utilise déjà ce numéro de téléphone.</p>
      <div class="customer-duplicate-alerts__match">
        <div class="fw-medium">{{ analysis.phone_match.name }}</div>
        <div class="small">{{ analysis.phone_match.phone }}</div>
      </div>
      <div v-if="showActions" class="d-flex flex-wrap gap-2 mt-3">
        <Link
          v-if="canViewCustomer"
          :href="route('customers.show', { id: analysis.phone_match.id })"
          class="btn btn-sm btn-outline-warning"
        >
          Voir le client
        </Link>
        <button
          v-if="allowSelect"
          type="button"
          class="btn btn-sm btn-warning"
          @click="emit('select-existing', analysis.phone_match!)"
        >
          Utiliser ce client
        </button>
      </div>
    </div>

    <div
      v-if="analysis.email_match"
      class="alert alert-warning customer-duplicate-alerts__item"
      role="alert"
    >
      <div class="fw-semibold mb-1">Doublon potentiel</div>
      <p class="mb-2">Un client utilise déjà cette adresse email.</p>
      <div class="customer-duplicate-alerts__match">
        <div class="fw-medium">{{ analysis.email_match.name }}</div>
        <div class="small">{{ analysis.email_match.email }}</div>
      </div>
      <div v-if="showActions" class="d-flex flex-wrap gap-2 mt-3">
        <Link
          v-if="canViewCustomer"
          :href="route('customers.show', { id: analysis.email_match.id })"
          class="btn btn-sm btn-outline-warning"
        >
          Voir le client
        </Link>
        <button
          v-if="allowSelect"
          type="button"
          class="btn btn-sm btn-warning"
          @click="emit('select-existing', analysis.email_match!)"
        >
          Utiliser ce client
        </button>
      </div>
    </div>

    <div
      v-if="analysis.similar_names.length"
      class="alert alert-info customer-duplicate-alerts__item"
      role="alert"
    >
      <div class="fw-semibold mb-1">Clients similaires</div>
      <p class="mb-2">
        {{ analysis.similar_names.length > 1
          ? `${analysis.similar_names.length} clients portent un nom similaire.`
          : 'Un client portant un nom similaire existe déjà.' }}
      </p>
      <ul class="list-unstyled mb-0 customer-duplicate-alerts__list">
        <li v-for="match in analysis.similar_names" :key="match.id" class="customer-duplicate-alerts__list-item">
          <div>
            <div class="fw-medium">{{ match.name }}</div>
            <div v-if="match.phone" class="small">{{ match.phone }}</div>
          </div>
          <Link
            v-if="canViewCustomer"
            :href="route('customers.show', { id: match.id })"
            class="btn btn-sm btn-outline-info"
          >
            Voir
          </Link>
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import type { CustomerDuplicateAnalysis, CustomerDuplicateMatch } from '@/utils/customerIdentity'

const props = withDefaults(defineProps<{
  analysis: CustomerDuplicateAnalysis
  identityFieldsComplete?: boolean
  showIdentityAvailable?: boolean
  showActions?: boolean
  allowSelect?: boolean
  canViewCustomer?: boolean
}>(), {
  identityFieldsComplete: false,
  showIdentityAvailable: true,
  showActions: true,
  allowSelect: false,
  canViewCustomer: true,
})

const emit = defineEmits<{
  'select-existing': [match: CustomerDuplicateMatch]
}>()

const hasAlerts = computed(() =>
  Boolean(props.analysis.identity_conflict)
  || Boolean(props.analysis.phone_match)
  || Boolean(props.analysis.email_match)
  || props.analysis.similar_names.length > 0
  || (props.showIdentityAvailable && props.identityFieldsComplete && props.analysis.identity_available),
)
</script>
