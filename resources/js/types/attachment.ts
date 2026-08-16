export interface AttachmentUser {
  id: number
  name: string
}

export interface AttachmentRecord {
  id: number
  original_name: string
  mime_type: string
  extension: string
  size: number
  formatted_size: string
  is_image: boolean
  is_pdf: boolean
  file_icon: string
  show_url: string
  download_url: string
  created_at: string
  uploaded_by?: AttachmentUser | null
}

export interface AttachmentConfig {
  maxFiles: number
  maxSizeKb: number
  allowedExtensions: string[]
  accept: string
}

export const DEFAULT_ATTACHMENT_CONFIG: AttachmentConfig = {
  maxFiles: 10,
  maxSizeKb: 10240,
  allowedExtensions: ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
  accept: '.pdf,.jpg,.jpeg,.png,.webp',
}
