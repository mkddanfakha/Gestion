/**
 * Descripteur générique consommé par DocumentPreviewModal.
 * Indépendant de l'origine du fichier (Attachment Manager, PDF généré, etc.).
 */
export type DocumentPreviewState = 'closed' | 'loading' | 'embedded' | 'fallback' | 'error'

/** Contexte métier optionnel — n'influence pas le rendu technique (MIME type prioritaire). */
export type DocumentPreviewType =
    | 'quote'
    | 'purchase_order'
    | 'delivery_note'
    | 'invoice'
    | 'sale'
    | 'expense_attachment'
    | 'attachment'
    | 'document'

export interface DocumentPreviewDescriptor {
    title?: string
    documentUrl: string
    downloadUrl?: string
    documentName: string
    mimeType?: string | null
    documentType?: DocumentPreviewType
}

export type DocumentPreviewPayloadRoute =
    | 'sales.invoice.preview'
    | 'quotes.preview'
    | 'purchase-orders.preview'
    | 'delivery-notes.preview'
