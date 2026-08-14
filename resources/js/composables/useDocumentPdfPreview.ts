import { ref } from 'vue'
import { route } from '@/lib/routes'
import { getCsrfToken } from '@/lib/csrf'

export type DocumentPreviewPayloadRoute =
    | 'sales.invoice.preview'
    | 'quotes.preview'
    | 'purchase-orders.preview'
    | 'delivery-notes.preview'

type PreviewSource =
    | {
          kind: 'url'
          url: string
          filename: string
          cacheKey: string
      }
    | {
          kind: 'payload'
          routeName: DocumentPreviewPayloadRoute
          payload: Record<string, unknown>
          filename: string
          cacheKey: string
      }

const isOpen = ref(false)
const isLoading = ref(false)
const error = ref<string | null>(null)
const previewUrl = ref<string | null>(null)
const filename = ref('document.pdf')

let currentSource: PreviewSource | null = null

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

    return 'Impossible de générer l\'aperçu.'
}

async function resolvePreviewUrl(source: PreviewSource): Promise<string> {
    if (source.kind === 'url') {
        return source.url
    }

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
        throw new Error('Impossible de générer l\'aperçu.')
    }

    if (data.filename) {
        filename.value = data.filename
    }

    return data.preview_url
}

async function loadPreview(source: PreviewSource): Promise<void> {
    currentSource = source
    isOpen.value = true
    isLoading.value = true
    error.value = null
    previewUrl.value = null
    filename.value = source.filename

    try {
        previewUrl.value = await resolvePreviewUrl(source)
    } catch (previewError) {
        error.value =
            previewError instanceof Error ? previewError.message : 'Impossible de générer l\'aperçu.'
    } finally {
        isLoading.value = false
    }
}

export function invalidateDocumentPreviewCache(_cacheKey?: string): void {
    // Conservé pour compatibilité avec les appels existants.
}

export function useDocumentPdfPreview() {
    const openFromUrl = async (url: string, name: string, cacheKey = url) => {
        await loadPreview({
            kind: 'url',
            url,
            filename: name,
            cacheKey,
        })
    }

    const openFromPayload = async (
        routeName: DocumentPreviewPayloadRoute,
        payload: Record<string, unknown>,
        name: string,
    ) => {
        const cacheKey = `${routeName}:${stableStringify(payload)}`

        await loadPreview({
            kind: 'payload',
            routeName,
            payload,
            filename: name,
            cacheKey,
        })
    }

    const close = () => {
        isOpen.value = false
    }

    const retry = async () => {
        if (!currentSource) {
            return
        }

        await loadPreview(currentSource)
    }

    return {
        isOpen,
        isLoading,
        error,
        previewUrl,
        filename,
        openFromUrl,
        openFromPayload,
        close,
        retry,
    }
}
