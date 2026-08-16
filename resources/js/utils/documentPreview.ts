import type { AttachmentRecord } from '@/types/attachment'
import type { DocumentPreviewDescriptor } from '@/types/documentPreview'

export type PreviewContentKind = 'pdf' | 'image' | 'unsupported'

const EXTENSION_MIME_MAP: Record<string, string> = {
    pdf: 'application/pdf',
    jpg: 'image/jpeg',
    jpeg: 'image/jpeg',
    png: 'image/png',
    webp: 'image/webp',
    gif: 'image/gif',
    doc: 'application/msword',
    docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    xls: 'application/vnd.ms-excel',
    xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    csv: 'text/csv',
    zip: 'application/zip',
}

export function guessMimeTypeFromFilename(filename: string): string {
    const extension = filename.split('.').pop()?.toLowerCase() ?? ''

    return EXTENSION_MIME_MAP[extension] ?? 'application/octet-stream'
}

export function normalizeMimeType(mimeType: string | null | undefined, filename: string): string {
    const normalized = mimeType?.trim().toLowerCase()

    if (normalized && normalized !== 'application/octet-stream') {
        return normalized
    }

    return guessMimeTypeFromFilename(filename)
}

export function resolvePreviewContentKind(mimeType: string | null | undefined, filename: string): PreviewContentKind {
    const mime = normalizeMimeType(mimeType, filename)

    if (mime === 'application/pdf' || mime.endsWith('/pdf')) {
        return 'pdf'
    }

    if (mime.startsWith('image/')) {
        return 'image'
    }

    return 'unsupported'
}

export function isPreviewableInline(mimeType: string | null | undefined, filename: string): boolean {
    const kind = resolvePreviewContentKind(mimeType, filename)

    return kind === 'pdf' || kind === 'image'
}

/** Pont Attachment Manager → Preview Engine. */
export function buildPreviewDescriptorFromAttachment(
    attachment: AttachmentRecord,
    title?: string,
): DocumentPreviewDescriptor {
    return {
        title: title ?? attachment.original_name,
        documentUrl: attachment.show_url,
        downloadUrl: attachment.download_url,
        documentName: attachment.original_name,
        mimeType: attachment.mime_type,
        documentType: 'attachment',
    }
}
