import { ref, type Ref } from 'vue'
import { route } from '@/lib/routes'

export type UserActivityDetailPayload = {
  type: string
  type_label: string
  fields: Array<{ label: string; value: string | null }>
  amounts: Array<{ label: string; value: number | null }>
  items: Array<{
    label: string
    quantity: number | null
    unit_price: number | null
    total: number | null
  }>
  notes: string | null
}

function csrfToken(): string {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
}

export function useUserActivityDetail(): {
  open: Ref<boolean>
  loading: Ref<boolean>
  error: Ref<string | null>
  detail: Ref<UserActivityDetailPayload | null>
  type: Ref<string | null>
  id: Ref<number | null>
  openDetail: (activityType: string, subjectId: number) => Promise<void>
  close: () => void
} {
  const open = ref(false)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const detail = ref<UserActivityDetailPayload | null>(null)
  const type = ref<string | null>(null)
  const id = ref<number | null>(null)

  function close() {
    open.value = false
    loading.value = false
    error.value = null
    detail.value = null
    type.value = null
    id.value = null
  }

  async function openDetail(activityType: string, subjectId: number): Promise<void> {
    type.value = activityType
    id.value = subjectId
    open.value = true
    loading.value = true
    error.value = null
    detail.value = null

    try {
      const response = await fetch(
        route('user-activities.detail', { type: activityType, id: subjectId }),
        {
          method: 'GET',
          headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
          },
          credentials: 'same-origin',
        },
      )

      if (response.status === 403 || response.status === 404 || !response.ok) {
        error.value = mapDetailHttpError(response.status)
        return
      }

      detail.value = (await response.json()) as UserActivityDetailPayload
    } catch {
      error.value = mapDetailHttpError(0)
    } finally {
      loading.value = false
    }
  }

  return {
    open,
    loading,
    error,
    detail,
    type,
    id,
    openDetail,
    close,
  }
}

/** Helper testable : construit l'URL de détail sans navigation métier. */
export function buildUserActivityDetailUrl(activityType: string, subjectId: number): string {
  return route('user-activities.detail', { type: activityType, id: subjectId })
}

export function mapDetailHttpError(status: number): string {
  if (status === 403) {
    return 'Accès refusé. Vous n\'avez pas la permission de consulter ce document.'
  }
  if (status === 404) {
    return 'Document introuvable.'
  }
  return 'Impossible de charger les détails.'
}
