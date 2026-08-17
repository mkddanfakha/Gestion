import { getDraftConfig } from './config'
import { buildDraftStorageKey } from './draftKey'
import { computeExpiresAt, isDraftExpired, sanitizeDraftData } from './draftUtils'
import type { DraftFormType, DraftMode, FormDraftRecord } from './types'

const DB_NAME = 'mkd-pro-drafts'
const DB_VERSION = 1
const STORE_NAME = 'drafts'

let dbPromise: Promise<IDBDatabase> | null = null

function isBrowser(): boolean {
  return typeof window !== 'undefined'
}

function openDatabase(): Promise<IDBDatabase> {
  if (!isBrowser() || !('indexedDB' in window)) {
    return Promise.reject(new Error('IndexedDB indisponible'))
  }

  if (dbPromise) {
    return dbPromise
  }

  dbPromise = new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION)

    request.onupgradeneeded = () => {
      const db = request.result
      if (!db.objectStoreNames.contains(STORE_NAME)) {
        db.createObjectStore(STORE_NAME, { keyPath: 'storageKey' })
      }
    }

    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error ?? new Error('Ouverture IndexedDB impossible'))
  })

  return dbPromise
}

async function idbGet(key: string): Promise<FormDraftRecord | null> {
  const db = await openDatabase()

  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, 'readonly')
    const store = tx.objectStore(STORE_NAME)
    const request = store.get(key)

    request.onsuccess = () => {
      const row = request.result as { record?: FormDraftRecord } | undefined
      resolve(row?.record ?? null)
    }
    request.onerror = () => reject(request.error ?? new Error('Lecture IndexedDB impossible'))
  })
}

async function idbSet(key: string, record: FormDraftRecord): Promise<void> {
  const db = await openDatabase()

  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, 'readwrite')
    const store = tx.objectStore(STORE_NAME)
    store.put({ storageKey: key, record })
    tx.oncomplete = () => resolve()
    tx.onerror = () => reject(tx.error ?? new Error('Écriture IndexedDB impossible'))
  })
}

async function idbDelete(key: string): Promise<void> {
  const db = await openDatabase()

  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, 'readwrite')
    const store = tx.objectStore(STORE_NAME)
    store.delete(key)
    tx.oncomplete = () => resolve()
    tx.onerror = () => reject(tx.error ?? new Error('Suppression IndexedDB impossible'))
  })
}

async function idbGetAllRecords(): Promise<FormDraftRecord[]> {
  const db = await openDatabase()

  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, 'readonly')
    const store = tx.objectStore(STORE_NAME)
    const request = store.getAll()

    request.onsuccess = () => {
      const rows = (request.result as Array<{ record?: FormDraftRecord }> | undefined) ?? []
      resolve(rows.map((row) => row.record).filter(Boolean) as FormDraftRecord[])
    }
    request.onerror = () => reject(request.error ?? new Error('Liste IndexedDB impossible'))
  })
}

function localGet(key: string): FormDraftRecord | null {
  try {
    const raw = localStorage.getItem(key)
    if (!raw) {
      return null
    }

    return JSON.parse(raw) as FormDraftRecord
  } catch {
    return null
  }
}

function localSet(key: string, record: FormDraftRecord): void {
  localStorage.setItem(key, JSON.stringify(record))
}

function localDelete(key: string): void {
  localStorage.removeItem(key)
}

function getAllLocalDraftKeys(): string[] {
  const keys: string[] = []

  for (let index = 0; index < localStorage.length; index += 1) {
    const key = localStorage.key(index)
    if (key?.startsWith('mkd-draft:v1:')) {
      keys.push(key)
    }
  }

  return keys
}

function usesIndexedDb(formType: DraftFormType): boolean {
  return getDraftConfig(formType).storage === 'indexeddb'
}

export async function loadDraftByKey(key: string, formType: DraftFormType): Promise<FormDraftRecord | null> {
  try {
    const record = usesIndexedDb(formType) ? await idbGet(key) : localGet(key)

    if (!record) {
      return null
    }

    if (isDraftExpired(record)) {
      await deleteDraftByKey(key, formType)
      return null
    }

    return record
  } catch {
    return null
  }
}

export async function saveDraftRecord(
  key: string,
  record: FormDraftRecord,
): Promise<boolean> {
  try {
    if (usesIndexedDb(record.formType)) {
      await idbSet(key, record)
    } else {
      localSet(key, record)
    }

    return true
  } catch (error) {
    console.warn('[draft] Échec sauvegarde locale', error)
    return false
  }
}

export async function deleteDraftByKey(key: string, formType: DraftFormType): Promise<void> {
  try {
    if (usesIndexedDb(formType)) {
      await idbDelete(key)
    } else {
      localDelete(key)
    }
  } catch (error) {
    console.warn('[draft] Échec suppression locale', error)
  }
}

export async function loadDraft(
  userId: number,
  formType: DraftFormType,
  mode: DraftMode,
  entityId: number | null,
  scopeContext?: string | null,
): Promise<FormDraftRecord | null> {
  const key = buildDraftStorageKey(userId, formType, mode, entityId, scopeContext)
  return loadDraftByKey(key, formType)
}

export async function saveDraft(
  userId: number,
  formType: DraftFormType,
  mode: DraftMode,
  entityId: number | null,
  data: Record<string, unknown>,
  options: {
    id?: string
    instanceId: string
    version?: number
    serverId?: string | null
    existing?: FormDraftRecord | null
  },
  scopeContext?: string | null,
): Promise<FormDraftRecord | null> {
  const config = getDraftConfig(formType)
  const key = buildDraftStorageKey(userId, formType, mode, entityId, scopeContext)
  const now = new Date().toISOString()
  const sanitized = sanitizeDraftData(data, config.excludedFields)

  const existing = options.existing ?? (await loadDraftByKey(key, formType))

  if (existing && existing.instanceId !== options.instanceId) {
    const existingTime = new Date(existing.updatedAt).getTime()
    const currentTime = Date.now()

    if (existingTime > currentTime - 50 && existing.version > (options.version ?? 1)) {
      console.warn('[draft] Conflit multi-onglet détecté, conservation de la version la plus récente.')
      return existing
    }
  }

  const record: FormDraftRecord = {
    id: options.id ?? existing?.id ?? crypto.randomUUID?.() ?? `draft-${Date.now()}`,
    userId,
    formType,
    mode,
    entityId,
    data: sanitized,
    createdAt: existing?.createdAt ?? now,
    updatedAt: now,
    expiresAt: computeExpiresAt(config.expirationDays),
    version: Math.max(existing?.version ?? 0, options.version ?? 0) + 1,
    instanceId: options.instanceId,
    serverId: options.serverId ?? existing?.serverId ?? null,
  }

  const saved = await saveDraftRecord(key, record)
  return saved ? record : null
}

export async function deleteDraft(
  userId: number,
  formType: DraftFormType,
  mode: DraftMode,
  entityId: number | null,
  scopeContext?: string | null,
): Promise<void> {
  const key = buildDraftStorageKey(userId, formType, mode, entityId, scopeContext)
  await deleteDraftByKey(key, formType)
}

export async function cleanupExpiredDrafts(currentUserId?: number): Promise<void> {
  if (!isBrowser()) {
    return
  }

  try {
    const localKeys = getAllLocalDraftKeys()
    for (const key of localKeys) {
      const record = localGet(key)
      if (!record) {
        continue
      }

      if (currentUserId !== undefined && record.userId !== currentUserId) {
        continue
      }

      if (isDraftExpired(record)) {
        localDelete(key)
      }
    }
  } catch (error) {
    console.warn('[draft] Nettoyage localStorage', error)
  }

  try {
    const records = await idbGetAllRecords()
    for (const record of records) {
      if (currentUserId !== undefined && record.userId !== currentUserId) {
        continue
      }

      if (isDraftExpired(record)) {
        const key = buildDraftStorageKey(record.userId, record.formType, record.mode, record.entityId)
        await idbDelete(key)
      }
    }
  } catch {
    // IndexedDB peut être indisponible — ignorer silencieusement
  }
}

export async function purgeDraftsForOtherUsers(currentUserId: number): Promise<void> {
  if (!isBrowser()) {
    return
  }

  try {
    const localKeys = getAllLocalDraftKeys()
    for (const key of localKeys) {
      const record = localGet(key)
      if (record && record.userId !== currentUserId) {
        localDelete(key)
      }
    }
  } catch {
    // ignore
  }

  try {
    const records = await idbGetAllRecords()
    for (const record of records) {
      if (record.userId !== currentUserId) {
        const key = buildDraftStorageKey(record.userId, record.formType, record.mode, record.entityId)
        await idbDelete(key)
      }
    }
  } catch {
    // ignore
  }
}
