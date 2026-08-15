import { usePage } from '@inertiajs/vue3'
import { useDebounceFn } from '@vueuse/core'
import { computed, onBeforeUnmount, onMounted, reactive, ref, unref, watch } from 'vue'
import { getDraftConfig, DRAFT_LOCAL_SAVE_DEBOUNCE_MS, DRAFT_SERVER_SYNC_DEBOUNCE_MS } from '@/drafts/config'
import { createDraftBroadcastManager } from '@/drafts/draftBroadcast'
import { createInstanceId } from '@/drafts/draftKey'
import {
  cleanupExpiredDrafts,
  deleteDraft,
  loadDraft,
  purgeDraftsForOtherUsers,
  saveDraft,
} from '@/drafts/draftStorage'
import {
  deleteServerDraftByScope,
  fetchServerDraft,
  isOnline,
  syncDraftToServer,
} from '@/drafts/draftSyncService'
import {
  hasMeaningfulDraftChanges,
  isEmptyDraftData,
  pickNewerDraft,
  sanitizeDraftData,
} from '@/drafts/draftUtils'
import type { DraftSaveState, FormDraftRecord, UseFormDraftOptions } from '@/drafts/types'

export function useFormDraft<T extends Record<string, unknown>>(
  options: UseFormDraftOptions<T>,
) {
  const page = usePage()
  const config = getDraftConfig(options.formType)
  const enabled = options.enabled ?? config.enabled

  const userId = computed(() => page.props.auth?.user?.id as number | undefined)
  const entityId = computed(() => options.entityId ?? null)

  const status = ref<DraftSaveState>('clean')
  const lastSavedAt = ref<string | null>(null)
  const showRestoreDialog = ref(false)
  const pendingDraft = ref<FormDraftRecord | null>(null)
  const restoredNotice = ref(false)
  const instanceId = createInstanceId()
  const currentVersion = ref(0)
  const isInitialized = ref(false)
  const isRestoring = ref(false)
  const isPaused = ref(false)

  let disposed = false

  const draftScopeKey = computed(
    () => `${options.formType}:${options.mode}:${entityId.value ?? 'new'}`,
  )

  const broadcast = createDraftBroadcastManager({
    isActive: () => !disposed && enabled,
    onMessage: (payload) => {
      if (payload.type !== 'draft-updated' || payload.instanceId === instanceId) {
        return
      }

      if (payload.key === draftScopeKey.value && payload.updatedAt) {
        console.info('[draft] Mise à jour détectée depuis un autre onglet.')
      }
    },
  })

  const baselineData = computed(() => {
    const baseline = options.getBaseline?.() ?? ({} as T)
    return sanitizeDraftData(baseline as Record<string, unknown>, config.excludedFields)
  })

  const notifyOtherTabs = (saved: FormDraftRecord): void => {
    broadcast.notify({
      type: 'draft-updated',
      key: draftScopeKey.value,
      updatedAt: saved.updatedAt,
      instanceId,
      version: saved.version,
    })
  }

  const persistLocally = async (): Promise<void> => {
    if (disposed || !enabled || !isInitialized.value || isPaused.value || isRestoring.value) {
      return
    }

    const currentUserId = userId.value
    if (!currentUserId) {
      return
    }

    const rawData = options.getData()
    const data = sanitizeDraftData(rawData as Record<string, unknown>, config.excludedFields)

    if (isEmptyDraftData(data)) {
      if (!disposed) {
        status.value = 'clean'
      }
      return
    }

    if (!hasMeaningfulDraftChanges(data, baselineData.value)) {
      if (!disposed) {
        status.value = 'clean'
      }
      return
    }

    if (!disposed) {
      status.value = 'saving'
    }

    const saved = await saveDraft(
      currentUserId,
      options.formType,
      options.mode,
      entityId.value,
      data,
      {
        instanceId,
        version: currentVersion.value,
      },
    )

    if (disposed) {
      return
    }

    if (!saved) {
      status.value = 'error'
      return
    }

    currentVersion.value = saved.version
    lastSavedAt.value = saved.updatedAt
    status.value = 'saved'

    notifyOtherTabs(saved)

    if (config.serverSync) {
      debouncedServerSync()
    }
  }

  const debouncedLocalSave = useDebounceFn(() => {
    void persistLocally().catch((error) => {
      console.warn('[draft] persistLocally failed', error)
    })
  }, DRAFT_LOCAL_SAVE_DEBOUNCE_MS)

  const debouncedServerSync = useDebounceFn(async () => {
    if (disposed || !enabled || !config.serverSync || isPaused.value || isRestoring.value) {
      return
    }

    const currentUserId = userId.value
    if (!currentUserId) {
      return
    }

    if (!isOnline()) {
      status.value = 'offline'
      return
    }

    const data = sanitizeDraftData(
      options.getData() as Record<string, unknown>,
      config.excludedFields,
    )

    if (isEmptyDraftData(data) || !hasMeaningfulDraftChanges(data, baselineData.value)) {
      return
    }

    status.value = 'saving'

    const synced = await syncDraftToServer({
      form_type: options.formType,
      mode: options.mode,
      entity_id: entityId.value,
      data,
      version: currentVersion.value,
      instance_id: instanceId,
    })

    if (disposed) {
      return
    }

    if (!synced) {
      status.value = isOnline() ? 'error' : 'offline'
      return
    }

    currentVersion.value = synced.version
    lastSavedAt.value = synced.updatedAt
    status.value = 'saved'
  }, DRAFT_SERVER_SYNC_DEBOUNCE_MS)

  const flushSave = async (): Promise<void> => {
    debouncedLocalSave.cancel?.()
    debouncedServerSync.cancel?.()

    try {
      await persistLocally()

      if (!disposed && config.serverSync && isOnline()) {
        await debouncedServerSync()
      }
    } catch (error) {
      console.warn('[draft] flushSave failed', error)
    }
  }

  const evaluateDraftForRestore = async (): Promise<void> => {
    const currentUserId = userId.value
    if (!enabled || !currentUserId || disposed) {
      return
    }

    await cleanupExpiredDrafts(currentUserId)
    await purgeDraftsForOtherUsers(currentUserId)

    if (disposed) {
      return
    }

    const localDraft = await loadDraft(
      currentUserId,
      options.formType,
      options.mode,
      entityId.value,
    )

    let remoteDraft: FormDraftRecord | null = null
    if (config.serverSync && isOnline()) {
      remoteDraft = await fetchServerDraft(options.formType, options.mode, entityId.value)
    }

    if (disposed) {
      return
    }

    const candidate = pickNewerDraft(localDraft, remoteDraft)

    if (!candidate) {
      return
    }

    if (isEmptyDraftData(candidate.data)) {
      await clearDraft()
      return
    }

    if (!hasMeaningfulDraftChanges(candidate.data, baselineData.value)) {
      await clearDraft()
      return
    }

    pendingDraft.value = candidate
    currentVersion.value = candidate.version
    showRestoreDialog.value = true
  }

  const restoreDraft = (): void => {
    if (!pendingDraft.value) {
      return
    }

    isRestoring.value = true
    showRestoreDialog.value = false

    options.restoreData(pendingDraft.value.data as T)
    currentVersion.value = pendingDraft.value.version
    lastSavedAt.value = pendingDraft.value.updatedAt
    status.value = 'restored'
    restoredNotice.value = true

    window.setTimeout(() => {
      if (disposed) {
        return
      }

      restoredNotice.value = false
      status.value = 'saved'
    }, 3000)

    pendingDraft.value = null
    isRestoring.value = false
  }

  const dismissDraft = async (): Promise<void> => {
    showRestoreDialog.value = false
    pendingDraft.value = null
    await clearDraft()
  }

  const clearDraft = async (): Promise<void> => {
    const currentUserId = userId.value
    if (!currentUserId) {
      return
    }

    isPaused.value = true

    await deleteDraft(currentUserId, options.formType, options.mode, entityId.value)

    if (config.serverSync && !disposed) {
      await deleteServerDraftByScope(options.formType, options.mode, entityId.value)
    }

    if (disposed) {
      isPaused.value = false
      return
    }

    status.value = 'clean'
    lastSavedAt.value = null
    pendingDraft.value = null
    showRestoreDialog.value = false
    currentVersion.value = 0

    isPaused.value = false
  }

  const markSubmitted = async (): Promise<void> => {
    await clearDraft()
    if (!disposed) {
      status.value = 'clean'
    }
  }

  const onVisibilityChange = (): void => {
    if (document.visibilityState === 'hidden' && !disposed) {
      void flushSave()
    }
  }

  onMounted(async () => {
    if (!enabled) {
      isInitialized.value = true
      return
    }

    await evaluateDraftForRestore()

    if (disposed) {
      return
    }

    isInitialized.value = true
    broadcast.init()
    document.addEventListener('visibilitychange', onVisibilityChange)
    window.addEventListener('online', debouncedServerSync)
  })

  onBeforeUnmount(() => {
    disposed = true

    debouncedLocalSave.cancel?.()
    debouncedServerSync.cancel?.()
    document.removeEventListener('visibilitychange', onVisibilityChange)
    window.removeEventListener('online', debouncedServerSync)

    void flushSave().finally(() => {
      broadcast.close()
    })
  })

  watch(
    () => unref(options.watchSource) ?? options.getData(),
    () => {
      if (disposed || !isInitialized.value || isRestoring.value) {
        return
      }

      status.value = 'dirty'
      debouncedLocalSave()
    },
    { deep: true },
  )

  return reactive({
    enabled,
    config,
    status,
    lastSavedAt,
    showRestoreDialog,
    pendingDraft,
    restoredNotice,
    restoreDraft,
    dismissDraft,
    clearDraft,
    markSubmitted,
    flushSave,
  })
}
