import { BrowserMultiFormatReader } from '@zxing/browser'
import { isTransientBarcodeScanError } from '@/utils/barcodeCamera'
import { formatBarcodeFormatValue } from '@/utils/barcodeScannerDiagnostics'

const DEBUG = import.meta.env.DEV

export const ENGINE_VERSIONS = {
  zxingBrowser: '0.2.1',
  zxingLibrary: '0.23.0',
  barcodeDetectorPolyfill: '3.2.2',
  vueQrcodeReader: '5.7.3',
} as const

export type EngineId =
  | 'zxing'
  | 'barcode-detector-native'
  | 'barcode-detector-polyfill'
  | 'vue-qrcode-reader'

export type EngineStatus =
  | 'success'
  | 'not_found'
  | 'unavailable'
  | 'not_testable'
  | 'error'

export type VariantId = 'A' | 'B' | 'C' | 'D' | 'E' | 'F'

export type ReferenceImageSource = 'camera' | 'upload'

export interface ReferenceImageInfo {
  canvas: HTMLCanvasElement
  dataUrl: string
  width: number
  height: number
  aspectRatio: string
  source: ReferenceImageSource
}

export interface EngineDefinition {
  id: EngineId
  label: string
  version: string
  description: string
}

export interface EngineTestResult {
  engine: EngineId
  engineLabel: string
  status: EngineStatus
  rawValue: string | null
  format: string | null
  durationMs: number | null
  errorName: string | null
  errorMessage: string | null
  variantId: VariantId
  variantLabel: string
}

export interface VariantDefinition {
  id: VariantId
  label: string
  complementary: boolean
}

export interface HistoryEntry {
  id: string
  timestamp: string
  engine: string
  status: string
  rawValue: string | null
  format: string | null
  durationMs: number | null
  variantId: VariantId
}

interface DetectedBarcodeLike {
  rawValue?: string
  format?: string
}

interface BarcodeDetectorConstructorLike {
  new (options?: { formats?: string[] }): BarcodeDetectorLike
  getSupportedFormats?: () => Promise<string[]>
}

interface BarcodeDetectorLike {
  detect(source: ImageBitmapSource): Promise<DetectedBarcodeLike[]>
}

const REQUESTED_FORMATS = [
  'ean_13',
  'ean_8',
  'upc_a',
  'upc_e',
  'code_128',
  'code_39',
] as const

const VARIANT_DEFINITIONS: VariantDefinition[] = [
  { id: 'A', label: 'Image originale', complementary: false },
  { id: 'B', label: 'JPEG qualité 0.95', complementary: true },
  { id: 'C', label: 'PNG', complementary: true },
  { id: 'D', label: 'Recadrage central 80 %', complementary: true },
  { id: 'E', label: 'Recadrage central 60 %', complementary: true },
  { id: 'F', label: 'Image agrandie 2×', complementary: true },
]

export const ENGINE_DEFINITIONS: EngineDefinition[] = [
  {
    id: 'zxing',
    label: 'ZXing',
    version: ENGINE_VERSIONS.zxingBrowser,
    description: '@zxing/browser — decodeFromCanvas()',
  },
  {
    id: 'barcode-detector-native',
    label: 'BarcodeDetector natif',
    version: 'API navigateur',
    description: 'window.BarcodeDetector',
  },
  {
    id: 'barcode-detector-polyfill',
    label: 'BarcodeDetector polyfill',
    version: ENGINE_VERSIONS.barcodeDetectorPolyfill,
    description: 'barcode-detector/ponyfill — MIT',
  },
  {
    id: 'vue-qrcode-reader',
    label: 'vue-qrcode-reader',
    version: ENGINE_VERSIONS.vueQrcodeReader,
    description: 'Composant caméra — MIT',
  },
]

export function getVariantDefinitions(): VariantDefinition[] {
  return [...VARIANT_DEFINITIONS]
}

export function getEngineDefinition(id: EngineId): EngineDefinition {
  return ENGINE_DEFINITIONS.find((engine) => engine.id === id)
    ?? ENGINE_DEFINITIONS[0]
}

export function statusLabel(status: EngineStatus): string {
  switch (status) {
    case 'success':
      return 'SUCCESS'
    case 'not_found':
      return 'NOT FOUND'
    case 'unavailable':
      return 'NON DISPONIBLE'
    case 'not_testable':
      return 'NON TESTABLE'
    case 'error':
      return 'ERROR'
    default:
      return '—'
  }
}

function display(value: unknown): string {
  if (value == null || value === '') {
    return '—'
  }

  return String(value)
}

function cloneCanvas(source: HTMLCanvasElement): HTMLCanvasElement {
  const canvas = document.createElement('canvas')
  canvas.width = source.width
  canvas.height = source.height

  const context = canvas.getContext('2d')

  if (!context) {
    throw new Error('Contexte canvas indisponible.')
  }

  context.drawImage(source, 0, 0)

  return canvas
}

function cropCenterCanvas(source: HTMLCanvasElement, ratio: number): HTMLCanvasElement {
  const cropWidth = Math.max(1, Math.round(source.width * ratio))
  const cropHeight = Math.max(1, Math.round(source.height * ratio))
  const offsetX = Math.round((source.width - cropWidth) / 2)
  const offsetY = Math.round((source.height - cropHeight) / 2)

  const canvas = document.createElement('canvas')
  canvas.width = cropWidth
  canvas.height = cropHeight

  const context = canvas.getContext('2d')

  if (!context) {
    throw new Error('Contexte canvas indisponible.')
  }

  context.drawImage(
    source,
    offsetX,
    offsetY,
    cropWidth,
    cropHeight,
    0,
    0,
    cropWidth,
    cropHeight,
  )

  return canvas
}

function scaleCanvas(source: HTMLCanvasElement, scale: number): HTMLCanvasElement {
  const canvas = document.createElement('canvas')
  canvas.width = Math.max(1, Math.round(source.width * scale))
  canvas.height = Math.max(1, Math.round(source.height * scale))

  const context = canvas.getContext('2d')

  if (!context) {
    throw new Error('Contexte canvas indisponible.')
  }

  context.drawImage(source, 0, 0, canvas.width, canvas.height)

  return canvas
}

async function canvasFromDataUrl(dataUrl: string): Promise<HTMLCanvasElement> {
  const image = new Image()

  await new Promise<void>((resolve, reject) => {
    image.onload = () => resolve()
    image.onerror = () => reject(new Error('Impossible de charger l\'image.'))
    image.src = dataUrl
  })

  const canvas = document.createElement('canvas')
  canvas.width = image.width
  canvas.height = image.height

  const context = canvas.getContext('2d')

  if (!context) {
    throw new Error('Contexte canvas indisponible.')
  }

  context.drawImage(image, 0, 0)

  return canvas
}

export async function buildVariantCanvases(
  source: HTMLCanvasElement,
): Promise<Record<VariantId, HTMLCanvasElement>> {
  const jpegDataUrl = source.toDataURL('image/jpeg', 0.95)
  const pngDataUrl = source.toDataURL('image/png')

  const [jpegCanvas, pngCanvas] = await Promise.all([
    canvasFromDataUrl(jpegDataUrl),
    canvasFromDataUrl(pngDataUrl),
  ])

  return {
    A: cloneCanvas(source),
    B: jpegCanvas,
    C: pngCanvas,
    D: cropCenterCanvas(source, 0.8),
    E: cropCenterCanvas(source, 0.6),
    F: scaleCanvas(source, 2),
  }
}

export function buildReferenceImageInfo(
  canvas: HTMLCanvasElement,
  source: ReferenceImageSource,
): ReferenceImageInfo {
  const aspectRatio = canvas.height > 0
    ? (canvas.width / canvas.height).toFixed(2)
    : '—'

  return {
    canvas: cloneCanvas(canvas),
    dataUrl: canvas.toDataURL('image/png'),
    width: canvas.width,
    height: canvas.height,
    aspectRatio,
    source,
  }
}

export async function loadReferenceImageFromFile(file: File): Promise<ReferenceImageInfo> {
  const bitmap = await createImageBitmap(file)
  const canvas = document.createElement('canvas')
  canvas.width = bitmap.width
  canvas.height = bitmap.height

  const context = canvas.getContext('2d')

  if (!context) {
    bitmap.close()
    throw new Error('Contexte canvas indisponible.')
  }

  context.drawImage(bitmap, 0, 0)
  bitmap.close()

  return buildReferenceImageInfo(canvas, 'upload')
}

export function captureReferenceImageFromVideo(video: HTMLVideoElement): ReferenceImageInfo {
  if (video.videoWidth <= 0 || video.videoHeight <= 0) {
    throw new Error('Dimensions vidéo invalides.')
  }

  const canvas = document.createElement('canvas')
  canvas.width = video.videoWidth
  canvas.height = video.videoHeight

  const context = canvas.getContext('2d')

  if (!context) {
    throw new Error('Contexte canvas indisponible.')
  }

  context.drawImage(video, 0, 0, video.videoWidth, video.videoHeight)

  return buildReferenceImageInfo(canvas, 'camera')
}

function pickBestBarcode(barcodes: DetectedBarcodeLike[]): DetectedBarcodeLike | null {
  if (barcodes.length === 0) {
    return null
  }

  const retailFormats = new Set(['ean_13', 'ean_8', 'upc_a', 'upc_e'])
  const retailMatch = barcodes.find((barcode) => retailFormats.has(barcode.format ?? ''))

  return retailMatch ?? barcodes[0] ?? null
}

function formatNativeBarcodeFormat(format: string | undefined): string {
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

async function resolveDetectorFormats(
  DetectorClass: BarcodeDetectorConstructorLike,
): Promise<string[]> {
  if (typeof DetectorClass.getSupportedFormats !== 'function') {
    return [...REQUESTED_FORMATS]
  }

  try {
    const supportedFormats = await DetectorClass.getSupportedFormats()
    const filtered = REQUESTED_FORMATS.filter((format) => supportedFormats.includes(format))

    return filtered.length > 0 ? filtered : [...REQUESTED_FORMATS]
  } catch {
    return [...REQUESTED_FORMATS]
  }
}

async function runBarcodeDetectorEngine(
  canvas: HTMLCanvasElement,
  mode: 'native' | 'polyfill',
): Promise<Omit<EngineTestResult, 'engine' | 'engineLabel' | 'variantId' | 'variantLabel'>> {
  if (mode === 'native' && !('BarcodeDetector' in window)) {
    return {
      status: 'unavailable',
      rawValue: null,
      format: null,
      durationMs: null,
      errorName: null,
      errorMessage: 'BarcodeDetector natif non disponible sur ce navigateur.',
    }
  }

  const start = performance.now()

  try {
    const DetectorClass = mode === 'native'
      ? window.BarcodeDetector as BarcodeDetectorConstructorLike
      : (await import('barcode-detector/ponyfill')).BarcodeDetector as BarcodeDetectorConstructorLike

    const formats = await resolveDetectorFormats(DetectorClass)
    const detector = new DetectorClass({ formats })
    const barcodes = await detector.detect(canvas)
    const durationMs = Math.round(performance.now() - start)
    const best = pickBestBarcode(barcodes)

    if (!best?.rawValue) {
      return {
        status: 'not_found',
        rawValue: null,
        format: null,
        durationMs,
        errorName: null,
        errorMessage: null,
      }
    }

    return {
      status: 'success',
      rawValue: best.rawValue,
      format: formatNativeBarcodeFormat(best.format),
      durationMs,
      errorName: null,
      errorMessage: null,
    }
  } catch (error) {
    const durationMs = Math.round(performance.now() - start)

    if (error instanceof Error) {
      return {
        status: 'error',
        rawValue: null,
        format: null,
        durationMs,
        errorName: display(error.name),
        errorMessage: display(error.message),
      }
    }

    return {
      status: 'error',
      rawValue: null,
      format: null,
      durationMs,
      errorName: 'Error',
      errorMessage: String(error),
    }
  }
}

async function runZxingEngine(
  canvas: HTMLCanvasElement,
): Promise<Omit<EngineTestResult, 'engine' | 'engineLabel' | 'variantId' | 'variantLabel'>> {
  const start = performance.now()
  const reader = new BrowserMultiFormatReader()

  try {
    const result = reader.decodeFromCanvas(canvas)
    const durationMs = Math.round(performance.now() - start)

    return {
      status: 'success',
      rawValue: result.getText(),
      format: formatBarcodeFormatValue(result.getBarcodeFormat()),
      durationMs,
      errorName: null,
      errorMessage: null,
    }
  } catch (error) {
    const durationMs = Math.round(performance.now() - start)

    if (isTransientBarcodeScanError(error)) {
      return {
        status: 'not_found',
        rawValue: null,
        format: null,
        durationMs,
        errorName: null,
        errorMessage: null,
      }
    }

    if (error instanceof Error) {
      return {
        status: 'error',
        rawValue: null,
        format: null,
        durationMs,
        errorName: display(error.name),
        errorMessage: display(error.message),
      }
    }

    return {
      status: 'error',
      rawValue: null,
      format: null,
      durationMs,
      errorName: 'Error',
      errorMessage: String(error),
    }
  }
}

function runVueQrcodeReaderEngine(): Omit<EngineTestResult, 'engine' | 'engineLabel' | 'variantId' | 'variantLabel'> {
  return {
    status: 'not_testable',
    rawValue: null,
    format: null,
    durationMs: null,
    errorName: null,
    errorMessage: 'Ce moteur dépend du composant caméra et ne peut pas être comparé directement sur la même image.',
  }
}

export async function runEngineOnCanvas(
  engineId: EngineId,
  canvas: HTMLCanvasElement,
  variantId: VariantId,
): Promise<EngineTestResult> {
  if (!DEBUG) {
    return {
      engine: engineId,
      engineLabel: getEngineDefinition(engineId).label,
      status: 'error',
      rawValue: null,
      format: null,
      durationMs: null,
      errorName: 'EnvironmentError',
      errorMessage: 'Banc de test disponible uniquement en développement.',
      variantId,
      variantLabel: VARIANT_DEFINITIONS.find((variant) => variant.id === variantId)?.label ?? variantId,
    }
  }

  const variantLabel = VARIANT_DEFINITIONS.find((variant) => variant.id === variantId)?.label ?? variantId
  const engineLabel = getEngineDefinition(engineId).label

  let outcome: Omit<EngineTestResult, 'engine' | 'engineLabel' | 'variantId' | 'variantLabel'>

  switch (engineId) {
    case 'zxing':
      outcome = await runZxingEngine(canvas)
      break
    case 'barcode-detector-native':
      outcome = await runBarcodeDetectorEngine(canvas, 'native')
      break
    case 'barcode-detector-polyfill':
      outcome = await runBarcodeDetectorEngine(canvas, 'polyfill')
      break
    case 'vue-qrcode-reader':
      outcome = runVueQrcodeReaderEngine()
      break
    default:
      outcome = {
        status: 'error',
        rawValue: null,
        format: null,
        durationMs: null,
        errorName: 'EngineError',
        errorMessage: `Moteur inconnu : ${engineId}`,
      }
  }

  return {
    engine: engineId,
    engineLabel,
    variantId,
    variantLabel,
    ...outcome,
  }
}

export async function runAllEnginesOnCanvas(
  canvas: HTMLCanvasElement,
  variantId: VariantId = 'A',
): Promise<EngineTestResult[]> {
  const results: EngineTestResult[] = []

  for (const engine of ENGINE_DEFINITIONS) {
    try {
      results.push(await runEngineOnCanvas(engine.id, canvas, variantId))
    } catch (error) {
      results.push({
        engine: engine.id,
        engineLabel: engine.label,
        status: 'error',
        rawValue: null,
        format: null,
        durationMs: null,
        errorName: error instanceof Error ? error.name : 'Error',
        errorMessage: error instanceof Error ? error.message : String(error),
        variantId,
        variantLabel: VARIANT_DEFINITIONS.find((variant) => variant.id === variantId)?.label ?? variantId,
      })
    }
  }

  return results
}

export function buildHistoryEntry(result: EngineTestResult): HistoryEntry {
  return {
    id: `${Date.now()}-${result.engine}-${result.variantId}-${Math.random().toString(36).slice(2, 8)}`,
    timestamp: new Date().toLocaleTimeString(),
    engine: result.engineLabel,
    status: statusLabel(result.status),
    rawValue: result.rawValue,
    format: result.format,
    durationMs: result.durationMs,
    variantId: result.variantId,
  }
}

export function buildDiagnosticConclusion(results: EngineTestResult[]): string {
  const originalResults = results.filter((result) => result.variantId === 'A')
  const successes = originalResults.filter((result) => result.status === 'success')
  const zxingResult = originalResults.find((result) => result.engine === 'zxing')
  const notFoundResults = originalResults.filter((result) => result.status === 'not_found')
  const allNotFound = originalResults.length > 0
    && originalResults.every((result) => result.status === 'not_found' || result.status === 'not_testable' || result.status === 'unavailable')

  if (successes.length >= 2) {
    const lines = successes.map((result) => `- ${result.engineLabel} : ${result.rawValue} (${result.format}, ${result.durationMs} ms)`)

    return [
      'PLUSIEURS MOTEURS ONT RÉUSSI',
      '',
      'Comparer :',
      '- valeur',
      '- format',
      '- temps de décodage',
      '',
      ...lines,
      '',
      'Le moteur le plus fiable pourra ensuite être évalué pour une intégration future.',
    ].join('\n')
  }

  if (zxingResult?.status === 'not_found' && successes.length > 0) {
    const winner = successes[0]

    return [
      'CONCLUSION',
      '',
      'L\'image de référence est lisible par au moins un moteur.',
      '',
      'ZXing n\'a pas réussi à décoder cette image,',
      `mais ${winner.engineLabel} a détecté le code (${winner.rawValue}).`,
      '',
      'Le problème est donc très probablement lié',
      'au moteur ZXing ou à sa compatibilité avec ce format/image.',
    ].join('\n')
  }

  if (allNotFound && notFoundResults.length > 0) {
    return [
      'CONCLUSION',
      '',
      'Aucun moteur testé n\'a réussi à décoder cette image.',
      '',
      'La caméra et la capture semblent fonctionner,',
      'mais il faut poursuivre l\'analyse de l\'image,',
      'du format du code-barres ou des moteurs utilisés.',
    ].join('\n')
  }

  if (successes.length === 1) {
    const winner = successes[0]

    return [
      'CONCLUSION',
      '',
      `${winner.engineLabel} a détecté le code : ${winner.rawValue} (${winner.format}).`,
      '',
      'Comparez avec les autres moteurs pour confirmer si ZXing est en cause.',
    ].join('\n')
  }

  return 'En attente de résultats sur l\'image originale (variante A).'
}

export function buildDiagnosticClipboardText(input: {
  userAgent: string
  referenceImage: ReferenceImageInfo | null
  results: EngineTestResult[]
  conclusion: string
}): string {
  const lines = [
    'Barcode Engine Diagnostic',
    '',
    `Browser: ${input.userAgent}`,
    '',
  ]

  if (input.referenceImage) {
    lines.push(
      `Image: ${input.referenceImage.width}x${input.referenceImage.height}`,
      `Source: ${input.referenceImage.source}`,
      `Ratio: ${input.referenceImage.aspectRatio}`,
      '',
    )
  } else {
    lines.push('Image: —', '')
  }

  for (const result of input.results) {
    lines.push(`${result.engineLabel} (${result.variantLabel}):`)
    lines.push(statusLabel(result.status))

    if (result.rawValue) {
      lines.push(`Value: ${result.rawValue}`)
    }

    if (result.format) {
      lines.push(`Format: ${result.format}`)
    }

    if (result.durationMs != null) {
      lines.push(`Duration: ${result.durationMs} ms`)
    }

    if (result.errorMessage) {
      lines.push(`Message: ${result.errorMessage}`)
    }

    lines.push('')
  }

  lines.push(input.conclusion)

  return lines.join('\n')
}

declare global {
  interface Window {
    BarcodeDetector?: BarcodeDetectorConstructorLike
  }
}
