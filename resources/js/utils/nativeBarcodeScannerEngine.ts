export const NATIVE_BARCODE_FORMATS = [
  'ean_13',
  'ean_8',
  'upc_a',
  'upc_e',
  'code_128',
  'code_39',
] as const

export const NATIVE_DETECTION_INTERVAL_MS = 150
export const NATIVE_FATAL_ERROR_THRESHOLD = 3

export interface DetectedBarcodeLike {
  rawValue?: string
  format?: string
}

export interface BarcodeDetectorConstructorLike {
  new (options?: { formats?: string[] }): BarcodeDetectorLike
  getSupportedFormats?: () => Promise<string[]>
}

export interface BarcodeDetectorLike {
  detect(source: ImageBitmapSource): Promise<DetectedBarcodeLike[]>
}

export function isNativeBarcodeDetectorAvailable(): boolean {
  return typeof window !== 'undefined' && 'BarcodeDetector' in window
}

async function resolveSupportedFormats(
  DetectorClass: BarcodeDetectorConstructorLike,
): Promise<string[]> {
  if (typeof DetectorClass.getSupportedFormats !== 'function') {
    return [...NATIVE_BARCODE_FORMATS]
  }

  try {
    const supported = await DetectorClass.getSupportedFormats()
    const filtered = NATIVE_BARCODE_FORMATS.filter((format) => supported.includes(format))

    return filtered.length > 0 ? filtered : [...NATIVE_BARCODE_FORMATS]
  } catch {
    return [...NATIVE_BARCODE_FORMATS]
  }
}

export async function createNativeBarcodeDetector(): Promise<{
  detector: BarcodeDetectorLike
  formatsUsed: string[]
}> {
  if (!isNativeBarcodeDetectorAvailable()) {
    throw new Error('BarcodeDetector natif non disponible sur ce navigateur.')
  }

  const DetectorClass = window.BarcodeDetector as BarcodeDetectorConstructorLike

  try {
    const formats = await resolveSupportedFormats(DetectorClass)

    return {
      detector: new DetectorClass({ formats }),
      formatsUsed: formats,
    }
  } catch {
    try {
      return {
        detector: new DetectorClass(),
        formatsUsed: [],
      }
    } catch (error) {
      if (error instanceof Error) {
        throw error
      }

      throw new Error(String(error))
    }
  }
}

export function pickBestNativeBarcode(barcodes: DetectedBarcodeLike[]): DetectedBarcodeLike | null {
  if (barcodes.length === 0) {
    return null
  }

  const retailFormats = new Set(['ean_13', 'ean_8', 'upc_a', 'upc_e'])
  const retailMatch = barcodes.find((barcode) => retailFormats.has(barcode.format ?? ''))

  return retailMatch ?? barcodes[0] ?? null
}

export function formatNativeBarcodeFormat(format: string | undefined): string {
  if (!format) {
    return '—'
  }

  const labels: Record<string, string> = {
    ean_13: 'EAN-13',
    ean_8: 'EAN-8',
    upc_a: 'UPC-A',
    upc_e: 'UPC-E',
    code_128: 'Code 128',
    code_39: 'Code 39',
  }

  return labels[format] ?? format
}

declare global {
  interface Window {
    BarcodeDetector?: BarcodeDetectorConstructorLike
  }
}
