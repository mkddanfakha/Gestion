<template>
  <div v-if="paginator.links && paginator.total && paginator.total > 0" class="customer-crm-pagination">
    <p class="customer-crm-pagination__meta text-muted small mb-2">
      Affichage de
      <span class="fw-medium">{{ paginator.from ?? 0 }}</span>
      à
      <span class="fw-medium">{{ paginator.to ?? 0 }}</span>
      sur
      <span class="fw-medium">{{ paginator.total }}</span>
      résultats
    </p>
    <nav>
      <ul class="pagination pagination-sm mb-0 flex-wrap">
        <li
          v-for="link in paginator.links"
          :key="`${link.label}-${link.active}`"
          class="page-item"
          :class="{ active: link.active, disabled: !link.url }"
        >
          <button
            type="button"
            class="page-link"
            :disabled="!link.url"
            @click="link.url && emit('paginate', link.url)"
            v-html="link.label"
          ></button>
        </li>
      </ul>
    </nav>
  </div>
</template>

<script setup lang="ts">
import type { CrmPaginator } from '@/components/customers/CustomerCrmHistory.vue'

defineProps<{
  paginator: CrmPaginator<unknown>
}>()

const emit = defineEmits<{
  paginate: [url: string]
}>()
</script>
