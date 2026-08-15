import { ref } from 'vue'
import { debounce } from 'lodash-es'
import { route } from '@/lib/routes'
import type { CustomerDuplicateAnalysis } from '@/utils/customerIdentity'
import { buildDuplicateCheckCriteria } from '@/utils/duplicateCheckCriteria'

export interface DuplicateCheckPayload {
  name?: string
  email?: string
  phone?: string
  identity_document_type?: string | null
  identity_document_number?: string | null
  exclude_id?: number | null
  customer_id?: number | null
}

const emptyAnalysis = (): CustomerDuplicateAnalysis => ({
  identity_available: true,
  identity_conflict: null,
  phone_match: null,
  email_match: null,
  similar_names: [],
  has_duplicates: false,
  matches: [],
})

export function useCustomerDuplicateCheck(excludeId?: number | null) {
  const analysis = ref<CustomerDuplicateAnalysis>(emptyAnalysis())
  const isChecking = ref(false)
  const checkError = ref('')

  let abortController: AbortController | null = null
  let requestSequence = 0

  const runCheck = async (payload: DuplicateCheckPayload) => {
    const criteria = buildDuplicateCheckCriteria({
      ...payload,
      exclude_id: payload.exclude_id ?? payload.customer_id ?? excludeId ?? null,
    })

    if (!criteria) {
      analysis.value = emptyAnalysis()
      checkError.value = ''
      isChecking.value = false
      return
    }

    abortController?.abort()
    abortController = new AbortController()
    const currentSequence = ++requestSequence

    isChecking.value = true
    checkError.value = ''

    try {
      const params = new URLSearchParams(criteria)

      const response = await fetch(`${route('customers.check-duplicates')}?${params.toString()}`, {
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        signal: abortController.signal,
      })

      if (currentSequence !== requestSequence) {
        return
      }

      if (!response.ok) {
        if (response.status >= 500) {
          checkError.value = 'Impossible de vérifier les doublons pour le moment.'
        }
        return
      }

      analysis.value = await response.json()
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') {
        return
      }

      if (currentSequence === requestSequence) {
        checkError.value = 'Impossible de vérifier les doublons pour le moment.'
      }
    } finally {
      if (currentSequence === requestSequence) {
        isChecking.value = false
      }
    }
  }

  const debouncedCheck = debounce(runCheck, 600)

  const cancelPendingCheck = () => {
    debouncedCheck.cancel()
    abortController?.abort()
    isChecking.value = false
  }

  const resetAnalysis = () => {
    cancelPendingCheck()
    analysis.value = emptyAnalysis()
    checkError.value = ''
  }

  return {
    analysis,
    isChecking,
    checkError,
    runCheck,
    debouncedCheck,
    cancelPendingCheck,
    resetAnalysis,
  }
}
