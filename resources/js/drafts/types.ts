export type DraftFormType =
  | 'customer'
  | 'product'
  | 'expense'
  | 'sale'
  | 'quote'
  | 'purchase_order'
  | 'delivery_note'

export type DraftMode = 'create' | 'edit'

export type DraftStorageBackend = 'local' | 'indexeddb'

export type DraftSaveState =
  | 'clean'
  | 'dirty'
  | 'saving'
  | 'saved'
  | 'error'
  | 'offline'
  | 'restored'

export interface FormDraftRecord {
  id: string
  userId: number
  formType: DraftFormType
  mode: DraftMode
  entityId: number | null
  data: Record<string, unknown>
  createdAt: string
  updatedAt: string
  expiresAt: string
  version: number
  instanceId: string
  serverId?: string | null
}

export interface DraftFormConfig {
  enabled: boolean
  storage: DraftStorageBackend
  serverSync: boolean
  expirationDays: number
  excludedFields: string[]
  label: string
  createRestoreTitle: string
  createRestoreContinue: string
  createRestoreDiscard: string
  editRestoreTitle: string
  editRestoreContinue: string
  editRestoreDiscard: string
}

export interface UseFormDraftOptions<T extends Record<string, unknown>> {
  formType: DraftFormType
  mode: DraftMode
  entityId?: number | null
  getData: () => T
  restoreData: (data: T) => void
  getBaseline?: () => T
  watchSource?: MaybeRef<object>
  enabled?: boolean
  scopeContext?: MaybeRef<string | null | undefined>
}

export interface DraftSyncPayload {
  form_type: DraftFormType
  mode: DraftMode
  entity_id?: number | null
  data: Record<string, unknown>
  version?: number
  instance_id?: string
}

export interface DraftApiRecord {
  id: string
  userId: number
  formType: DraftFormType
  mode: DraftMode
  entityId: number | null
  data: Record<string, unknown>
  version: number
  instanceId: string | null
  createdAt: string
  updatedAt: string
  expiresAt: string
}
