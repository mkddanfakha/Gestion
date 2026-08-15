<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'

export interface PaginationLink {
  url: string | null
  label: string
  active: boolean
}

interface Props {
  links?: PaginationLink[] | null
  from?: number | null
  to?: number | null
  total?: number | null
  centered?: boolean
  minLinks?: number
}

const props = withDefaults(defineProps<Props>(), {
  links: null,
  centered: false,
  minLinks: 1,
})

const visible = computed(() => {
  if (!props.links?.length) {
    return false
  }

  return props.links.length > props.minLinks
})

const showSummary = computed(() =>
  !props.centered &&
  props.from != null &&
  props.to != null &&
  props.total != null,
)
</script>

<template>
  <div v-if="visible" class="card-footer">
    <div
      class="page-footer-toolbar"
      :class="{ 'page-footer-toolbar--centered': centered }"
    >
      <div v-if="showSummary" class="d-none d-md-block">
        <p class="text-muted mb-0">
          Affichage de
          <span class="fw-medium">{{ from }}</span>
          à
          <span class="fw-medium">{{ to }}</span>
          sur
          <span class="fw-medium">{{ total }}</span>
          résultats
        </p>
      </div>
      <nav class="page-footer-toolbar__pagination" aria-label="Pagination">
        <ul class="pagination pagination-sm mb-0">
          <template v-for="(link, index) in links" :key="`${link.label}-${index}`">
            <li class="page-item" :class="{ active: link.active, disabled: !link.url }">
              <Link
                v-if="link.url"
                :href="link.url"
                class="page-link"
                v-html="link.label"
              />
              <span v-else class="page-link" v-html="link.label" />
            </li>
          </template>
        </ul>
      </nav>
    </div>
  </div>
</template>
