export type PdfEmbedSupport = 'supported' | 'unsupported' | 'unknown'

const STORAGE_KEY = 'mkd:pdf-embed-support'
const FORCE_FALLBACK_KEY = 'mkd:pdf-preview-force-fallback'

/** PDF minimal valide (1 page vide) pour tester le rendu inline. */
const MINIMAL_PDF_BYTES = new Uint8Array([
    0x25, 0x50, 0x44, 0x46, 0x2d, 0x31, 0x2e, 0x30, 0x0a, 0x25, 0xe2, 0xe3, 0xcf, 0xd3, 0x0a, 0x31,
    0x20, 0x30, 0x20, 0x6f, 0x62, 0x6a, 0x3c, 0x3c, 0x2f, 0x54, 0x79, 0x70, 0x65, 0x2f, 0x43, 0x61,
    0x74, 0x61, 0x6c, 0x6f, 0x67, 0x2f, 0x50, 0x61, 0x67, 0x65, 0x73, 0x20, 0x32, 0x20, 0x30, 0x20,
    0x52, 0x3e, 0x3e, 0x65, 0x6e, 0x64, 0x6f, 0x62, 0x6a, 0x0a, 0x32, 0x20, 0x30, 0x20, 0x6f, 0x62,
    0x6a, 0x3c, 0x3c, 0x2f, 0x54, 0x79, 0x70, 0x65, 0x2f, 0x50, 0x61, 0x67, 0x65, 0x73, 0x2f, 0x4b,
    0x69, 0x64, 0x73, 0x5b, 0x33, 0x20, 0x30, 0x20, 0x52, 0x5d, 0x2f, 0x43, 0x6f, 0x75, 0x6e, 0x74,
    0x20, 0x31, 0x3e, 0x3e, 0x65, 0x6e, 0x64, 0x6f, 0x62, 0x6a, 0x0a, 0x33, 0x20, 0x30, 0x20, 0x6f,
    0x62, 0x6a, 0x3c, 0x3c, 0x2f, 0x54, 0x79, 0x70, 0x65, 0x2f, 0x50, 0x61, 0x67, 0x65, 0x2f, 0x4d,
    0x65, 0x64, 0x69, 0x61, 0x42, 0x6f, 0x78, 0x5b, 0x30, 0x20, 0x30, 0x20, 0x33, 0x20, 0x33, 0x5d,
    0x3e, 0x3e, 0x65, 0x6e, 0x64, 0x6f, 0x62, 0x6a, 0x0a, 0x78, 0x72, 0x65, 0x66, 0x0a, 0x30, 0x20,
    0x34, 0x0a, 0x30, 0x30, 0x30, 0x30, 0x30, 0x30, 0x30, 0x30, 0x30, 0x30, 0x20, 0x36, 0x35, 0x35,
    0x33, 0x35, 0x20, 0x66, 0x20, 0x0a, 0x30, 0x30, 0x30, 0x30, 0x30, 0x30, 0x30, 0x30, 0x30, 0x39,
    0x20, 0x30, 0x30, 0x30, 0x30, 0x30, 0x20, 0x6e, 0x20, 0x0a, 0x30, 0x30, 0x30, 0x30, 0x30, 0x30,
    0x30, 0x30, 0x35, 0x32, 0x20, 0x30, 0x30, 0x30, 0x30, 0x30, 0x20, 0x6e, 0x20, 0x0a, 0x30, 0x30,
    0x30, 0x30, 0x30, 0x30, 0x30, 0x31, 0x30, 0x31, 0x20, 0x30, 0x30, 0x30, 0x30, 0x30, 0x20, 0x6e,
    0x20, 0x0a, 0x74, 0x72, 0x61, 0x69, 0x6c, 0x65, 0x72, 0x3c, 0x3c, 0x2f, 0x53, 0x69, 0x7a, 0x65,
    0x20, 0x34, 0x2f, 0x52, 0x6f, 0x6f, 0x74, 0x20, 0x31, 0x20, 0x30, 0x20, 0x52, 0x3e, 0x3e, 0x0a,
    0x73, 0x74, 0x61, 0x72, 0x74, 0x78, 0x72, 0x65, 0x66, 0x0a, 0x31, 0x34, 0x39, 0x0a, 0x25, 0x25,
    0x45, 0x4f, 0x46, 0x0a,
])

const PRINT_TO_DOWNLOAD: Array<{ pattern: RegExp; replacement: string }> = [
    { pattern: /^\/quotes\/([^/]+)\/print\/?$/, replacement: '/quotes/$1/download' },
    { pattern: /^\/purchase-orders\/([^/]+)\/print\/?$/, replacement: '/purchase-orders/$1/download' },
    { pattern: /^\/delivery-notes\/([^/]+)\/print\/?$/, replacement: '/delivery-notes/$1/download' },
    {
        pattern: /^\/sales\/([^/]+)\/invoice\/print\/?$/,
        replacement: '/sales/$1/invoice/download',
    },
]

let probePromise: Promise<PdfEmbedSupport> | null = null

export function getCachedPdfEmbedSupport(): PdfEmbedSupport | null {
    try {
        const value = sessionStorage.getItem(STORAGE_KEY)

        if (value === 'supported' || value === 'unsupported' || value === 'unknown') {
            return value
        }
    } catch {
        // sessionStorage indisponible.
    }

    return null
}

export function markPdfEmbedSupported(): void {
    try {
        sessionStorage.setItem(STORAGE_KEY, 'supported')
    } catch {
        // Ignorer.
    }
}

export function markPdfEmbedUnsupported(): void {
    try {
        sessionStorage.setItem(STORAGE_KEY, 'unsupported')
    } catch {
        // Ignorer.
    }
}

/**
 * Dev uniquement : forcer l'écran fallback pour tests visuels.
 * Activer via ?pdfPreviewFallback=1 ou sessionStorage mkd:pdf-preview-force-fallback=1
 */
export function isDevForceFallbackEnabled(): boolean {
    if (!import.meta.env.DEV) {
        return false
    }

    try {
        if (sessionStorage.getItem(FORCE_FALLBACK_KEY) === '1') {
            return true
        }

        return new URLSearchParams(window.location.search).get('pdfPreviewFallback') === '1'
    } catch {
        return false
    }
}

function cacheSupport(value: PdfEmbedSupport): void {
    if (value === 'supported') {
        markPdfEmbedSupported()
        return
    }

    if (value === 'unsupported') {
        markPdfEmbedUnsupported()
    }
}

/**
 * Probe comportementale (sans user-agent) pour mémoriser la compatibilité embed.
 * Ne bloque pas l'affichage intégré : sert uniquement au cache session.
 */
export function probePdfEmbedSupport(): Promise<PdfEmbedSupport> {
    const cached = getCachedPdfEmbedSupport()

    if (cached) {
        return Promise.resolve(cached)
    }

    if (probePromise) {
        return probePromise
    }

    probePromise = new Promise((resolve) => {
        if (typeof navigator.pdfViewerEnabled === 'boolean') {
            const result: PdfEmbedSupport = navigator.pdfViewerEnabled ? 'supported' : 'unsupported'
            cacheSupport(result)
            resolve(result)
            return
        }

        let settled = false
        let objectUrl: string | null = null
        const iframe = document.createElement('iframe')

        const finish = (result: PdfEmbedSupport) => {
            if (settled) {
                return
            }

            settled = true
            window.clearTimeout(timeoutId)
            iframe.remove()

            if (objectUrl) {
                URL.revokeObjectURL(objectUrl)
            }

            if (result !== 'unknown') {
                cacheSupport(result)
            }

            resolve(result)
        }

        const timeoutId = window.setTimeout(() => finish('unknown'), 2500)

        iframe.style.cssText =
            'position:fixed;left:-9999px;top:-9999px;width:1px;height:1px;border:0;visibility:hidden'
        iframe.title = 'Test de compatibilité PDF'

        iframe.onload = () => finish('supported')
        iframe.onerror = () => finish('unsupported')

        try {
            const blob = new Blob([MINIMAL_PDF_BYTES], { type: 'application/pdf' })
            objectUrl = URL.createObjectURL(blob)
            iframe.src = objectUrl
            document.body.appendChild(iframe)
        } catch {
            finish('unknown')
        }
    }).finally(() => {
        probePromise = null
    })

    return probePromise
}

export function buildDocumentDownloadUrl(previewUrl: string): string {
    const parsed = new URL(previewUrl, window.location.origin)

    if (parsed.pathname.startsWith('/documents/preview/')) {
        parsed.searchParams.set('download', '1')
        return parsed.toString()
    }

    for (const mapping of PRINT_TO_DOWNLOAD) {
        const match = parsed.pathname.match(mapping.pattern)

        if (match) {
            const downloadPath = mapping.replacement.replace('$1', match[1] ?? '')
            parsed.pathname = downloadPath
            parsed.search = ''
            return parsed.toString()
        }
    }

    return parsed.toString()
}

export function hasFinePointerInput(): boolean {
    return window.matchMedia('(pointer: fine)').matches && window.matchMedia('(hover: hover)').matches
}
