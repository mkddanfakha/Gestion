/**
 * Preview Engine — couche frontend du Document Manager MKD-Pro.
 *
 * État, ouverture/fermeture, changement de document. Pas de stockage ni RBAC ici.
 *
 * @see docs/document-manager.md
 */
import { computed, ref } from 'vue'
import { route } from '@/lib/routes'
import { getCsrfToken } from '@/lib/csrf'
import type { AttachmentRecord } from '@/types/attachment'
import type {
    DocumentPreviewDescriptor,
    DocumentPreviewPayloadRoute,
    DocumentPreviewState,
    DocumentPreviewType,
} from '@/types/documentPreview'

import {
    buildPreviewDescriptorFromAttachment,
    guessMimeTypeFromFilename,
    normalizeMimeType,
    resolvePreviewContentKind,
    type PreviewContentKind,
} from '@/utils/documentPreview'
import {
    buildDocumentDownloadUrl,
    getCachedPdfEmbedSupport,
    isDevForceFallbackEnabled,
    markPdfEmbedSupported,
    markPdfEmbedUnsupported,
    probePdfEmbedSupport,
} from '@/composables/usePdfEmbedSupport'

export type {
    DocumentPreviewDescriptor,
    DocumentPreviewPayloadRoute,
    DocumentPreviewState,
    DocumentPreviewType,
}

/** Alias historique — préférer DocumentPreviewDescriptor. */
export type DocumentPreviewConfig = DocumentPreviewDescriptor

type PreviewSource =
    | {
          kind: 'document'
          config: DocumentPreviewDescriptor
      }
    | {
          kind: 'url'
          url: string
          filename: string
          cacheKey: string
          title?: string
          mimeType?: string | null
          documentType?: DocumentPreviewType
          downloadUrl?: string
      }
    | {
          kind: 'payload'
          routeName: DocumentPreviewPayloadRoute
          payload: Record<string, unknown>
          filename: string
          cacheKey: string
          title?: string
          documentType?: DocumentPreviewType
      }

const state = ref<DocumentPreviewState>('closed')
const error = ref<string | null>(null)
const documentUrl = ref<string | null>(null)
const downloadUrlValue = ref<string | null>(null)
const documentName = ref('document.pdf')
const mimeType = ref('application/pdf')
const documentTitle = ref('Aperçu du document')
const documentType = ref<DocumentPreviewType>('document')
const contentKind = ref<PreviewContentKind>('pdf')

let currentSource: PreviewSource | null = null

const isOpen = computed(() => state.value !== 'closed')
const isLoading = computed(() => state.value === 'loading')
const downloadUrl = computed(() => downloadUrlValue.value)

function stableStringify(value: unknown): string {
    if (value === null || typeof value !== 'object') {
        return JSON.stringify(value)
    }

    if (Array.isArray(value)) {
        return `[${value.map((entry) => stableStringify(entry)).join(',')}]`
    }

    const objectValue = value as Record<string, unknown>
    const keys = Object.keys(objectValue).sort()

    return `{${keys.map((key) => `${JSON.stringify(key)}:${stableStringify(objectValue[key])}`).join(',')}}`
}

async function readErrorMessage(response: Response): Promise<string> {
    const contentType = response.headers.get('content-type') ?? ''

    if (response.status === 419) {
        return 'Session expirée. Rechargez la page puis réessayez.'
    }

    if (response.status === 403) {
        return 'Accès refusé. Vous n\'avez pas la permission d\'afficher cet aperçu.'
    }

    if (contentType.includes('application/json')) {
        try {
            const data = (await response.json()) as {
                message?: string
                errors?: Record<string, string[]>
            }

            if (response.status === 422) {
                const firstFieldError = data.errors
                    ? Object.values(data.errors).flat().find(Boolean)
                    : undefined

                if (firstFieldError) {
                    return firstFieldError
                }

                if (data.message && data.message !== 'The given data was invalid.') {
                    return data.message
                }

                return 'Certaines données du formulaire sont invalides. Vérifiez les champs puis réessayez.'
            }

            if (data.message) {
                return data.message
            }
        } catch {
            // Ignore JSON parsing errors.
        }
    }

    if (response.status === 422) {
        return 'Certaines données du formulaire sont invalides. Vérifiez les champs puis réessayez.'
    }

    return 'Impossible de générer l\'aperçu du document.'
}

async function resolvePreviewUrlFromPayload(source: Extract<PreviewSource, { kind: 'payload' }>): Promise<string> {
    const csrfToken = getCsrfToken()

    const response = await fetch(route(source.routeName), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
            'X-Document-Preview-Mode': 'inline-url',
        },
        body: JSON.stringify(source.payload),
    })

    if (!response.ok) {
        throw new Error(await readErrorMessage(response))
    }

    const data = (await response.json()) as {
        preview_url?: string
        filename?: string
    }

    if (!data.preview_url) {
        throw new Error('Impossible de générer l\'aperçu du document.')
    }

    if (data.filename) {
        documentName.value = data.filename
        mimeType.value = normalizeMimeType(mimeType.value, data.filename)
    }

    return data.preview_url
}

async function resolvePdfDisplayState(): Promise<DocumentPreviewState> {
    if (isDevForceFallbackEnabled()) {
        return 'fallback'
    }

    if (getCachedPdfEmbedSupport() === 'unsupported') {
        return 'fallback'
    }

    return 'embedded'
}

async function resolveDisplayState(kind: PreviewContentKind): Promise<DocumentPreviewState> {
    if (kind === 'image') {
        return 'embedded'
    }

    if (kind === 'unsupported') {
        return 'fallback'
    }

    return resolvePdfDisplayState()
}

function applyConfig(config: DocumentPreviewDescriptor, titleFallback = 'Aperçu du document'): void {
    documentTitle.value = config.title ?? config.documentName ?? titleFallback
    documentName.value = config.documentName
    mimeType.value = normalizeMimeType(config.mimeType, config.documentName)
    documentType.value = config.documentType ?? 'document'
    contentKind.value = resolvePreviewContentKind(mimeType.value, documentName.value)
}

async function loadPreview(source: PreviewSource): Promise<void> {
    currentSource = source
    state.value = 'loading'
    error.value = null
    documentUrl.value = null
    downloadUrlValue.value = null

    try {
        if (source.kind === 'document') {
            applyConfig(source.config)

            if (!source.config.documentUrl) {
                throw new Error('Impossible de générer l\'aperçu du document.')
            }

            documentUrl.value = source.config.documentUrl
            downloadUrlValue.value =
                source.config.downloadUrl ?? buildDocumentDownloadUrl(source.config.documentUrl)
        } else if (source.kind === 'url') {
            documentName.value = source.filename
            mimeType.value = normalizeMimeType(
                source.mimeType ?? guessMimeTypeFromFilename(source.filename),
                source.filename,
            )
            documentTitle.value = source.title ?? 'Aperçu du document'
            documentType.value = source.documentType ?? 'document'
            contentKind.value = resolvePreviewContentKind(mimeType.value, documentName.value)
            documentUrl.value = source.url
            downloadUrlValue.value = source.downloadUrl ?? buildDocumentDownloadUrl(source.url)
        } else {
            documentName.value = source.filename
            mimeType.value = normalizeMimeType('application/pdf', source.filename)
            documentTitle.value = source.title ?? 'Aperçu du document'
            documentType.value = source.documentType ?? 'document'
            contentKind.value = 'pdf'
            documentUrl.value = await resolvePreviewUrlFromPayload(source)
            downloadUrlValue.value = buildDocumentDownloadUrl(documentUrl.value)
        }

        state.value = await resolveDisplayState(contentKind.value)

        if (contentKind.value === 'pdf') {
            void probePdfEmbedSupport()
        }
    } catch (previewError) {
        error.value =
            previewError instanceof Error
                ? previewError.message
                : 'Impossible de générer l\'aperçu du document.'
        state.value = 'error'
    }
}

export function invalidateDocumentPreviewCache(_cacheKey?: string): void {
    // Conservé pour compatibilité avec les appels existants.
}

export function useDocumentPreview() {
    const openDocument = async (config: DocumentPreviewDescriptor) => {
        await loadPreview({
            kind: 'document',
            config,
        })
    }

    const openAttachment = async (attachment: AttachmentRecord, title?: string) => {
        await openDocument(buildPreviewDescriptorFromAttachment(attachment, title))
    }

    const openFromUrl = async (
        url: string,
        name: string,
        cacheKey = url,
        title = 'Aperçu du document',
        options?: {
            mimeType?: string | null
            documentType?: DocumentPreviewType
            downloadUrl?: string
        },
    ) => {
        await loadPreview({
            kind: 'url',
            url,
            filename: name,
            cacheKey,
            title,
            mimeType: options?.mimeType ?? guessMimeTypeFromFilename(name),
            documentType: options?.documentType,
            downloadUrl: options?.downloadUrl,
        })
    }

    const openFromPayload = async (
        routeName: DocumentPreviewPayloadRoute,
        payload: Record<string, unknown>,
        name: string,
        title = 'Aperçu du document',
        documentTypeValue: DocumentPreviewType = 'document',
    ) => {
        const cacheKey = `${routeName}:${stableStringify(payload)}`

        await loadPreview({
            kind: 'payload',
            routeName,
            payload,
            filename: name,
            cacheKey,
            title,
            documentType: documentTypeValue,
        })
    }

    const close = () => {
        state.value = 'closed'
        error.value = null
        documentUrl.value = null
        downloadUrlValue.value = null
        currentSource = null
    }

    const retry = async () => {
        if (!currentSource) {
            return
        }

        await loadPreview(currentSource)
    }

    const enterFallback = () => {
        if (contentKind.value !== 'pdf') {
            return
        }

        if (state.value === 'embedded' || state.value === 'loading') {
            markPdfEmbedUnsupported()
            state.value = 'fallback'
        }
    }

    const confirmEmbedded = () => {
        if (state.value === 'embedded' && contentKind.value === 'pdf') {
            markPdfEmbedSupported()
        }
    }

    const enterError = (message = 'Impossible de générer l\'aperçu du document.') => {
        error.value = message
        state.value = 'error'
    }

    return {
        state,
        isOpen,
        isLoading,
        error,
        documentUrl,
        previewUrl: documentUrl,
        downloadUrl,
        documentName,
        filename: documentName,
        mimeType,
        documentTitle,
        documentType,
        contentKind,
        openDocument,
        openAttachment,
        openFromUrl,
        openFromPayload,
        close,
        retry,
        enterFallback,
        confirmEmbedded,
        enterError,
    }
}

/** Alias de compatibilité pour les modules existants. */
export function useDocumentPdfPreview() {
    return useDocumentPreview()
}
