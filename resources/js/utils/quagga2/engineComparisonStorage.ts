/**
 * Persistance locale DEV pour comparaison moteurs barcode.
 */

export const STORAGE_KEY_QUAGGA2 = 'mkd-dev-barcode-engine-quagga2'
export const STORAGE_KEY_BARCODE_DETECTOR = 'mkd-dev-barcode-engine-barcode-detector'
export const STORAGE_KEY_HTML5_QRCODE = 'mkd-dev-barcode-engine-html5-qrcode'

export interface EngineComparisonMetrics {
  detections: number
  correct: number
  incorrect: number
  detectionRate: string
  correctRate: string
  falsePositiveRate: string
  checkDigitValid: number
  temporalStability: string
  timeToFirstDetectionMs: number | null
  timeToFirstCorrectMs: number | null
  overallScore: number
}

export interface EngineComparisonSnapshot {
  engine: 'barcode_detector' | 'quagga2' | 'html5_qrcode'
  savedAt: string
  device: {
    userAgent: string
    browser: string
    devicePixelRatio: number
  }
  camera: {
    facingMode: string | null
    requestedResolution: string | null
    actualResolution: string | null
    fps: number | null
  }
  expectedBarcode: string
  expectedFormat: string
  durationSeconds: number
  metrics: EngineComparisonMetrics
  sourceLabel: string
}

export interface EngineComparisonRow {
  metric: string
  barcodeDetector: string
  quagga2: string
  winner: 'barcode_detector' | 'quagga2' | 'tie' | '—'
}

function readStorage(key: string): EngineComparisonSnapshot | null {
  if (typeof window === 'undefined') {
    return null
  }

  try {
    const raw = window.localStorage.getItem(key)

    return raw ? JSON.parse(raw) as EngineComparisonSnapshot : null
  } catch {
    return null
  }
}

export function saveEngineSnapshot(key: string, snapshot: EngineComparisonSnapshot): void {
  window.localStorage.setItem(key, JSON.stringify(snapshot))
}

export function loadQuagga2Snapshot(): EngineComparisonSnapshot | null {
  return readStorage(STORAGE_KEY_QUAGGA2)
}

export function loadBarcodeDetectorSnapshot(): EngineComparisonSnapshot | null {
  return readStorage(STORAGE_KEY_BARCODE_DETECTOR)
}

export function loadHtml5QrcodeSnapshot(): EngineComparisonSnapshot | null {
  return readStorage(STORAGE_KEY_HTML5_QRCODE)
}

export function clearEngineSnapshots(): void {
  window.localStorage.removeItem(STORAGE_KEY_QUAGGA2)
  window.localStorage.removeItem(STORAGE_KEY_BARCODE_DETECTOR)
  window.localStorage.removeItem(STORAGE_KEY_HTML5_QRCODE)
}

export function snapshotFromHtml5QrcodeBenchmark(options: {
  environmentBrowser: string
  expectedBarcode: string
  expectedFormat: string
  durationSeconds: number
  bestResult: {
    requestedWidth: number | null
    requestedHeight: number | null
    actualWidth: number | null
    actualHeight: number | null
    actualFps: number | null
    detections: number
    correct: number
    incorrect: number
    detectionRate: string
    correctRate: string
    falsePositiveRate: string
    checkDigitValidDetections: number
    temporalStability: string
    timeToFirstDetectionMs: number | null
    timeToFirstCorrectMs: number | null
    score: { total: number }
    label: string
  }
}): EngineComparisonSnapshot {
  return {
    engine: 'html5_qrcode',
    savedAt: new Date().toISOString(),
    device: {
      userAgent: navigator.userAgent,
      browser: options.environmentBrowser,
      devicePixelRatio: window.devicePixelRatio,
    },
    camera: {
      facingMode: 'environment',
      requestedResolution: options.bestResult.requestedWidth != null && options.bestResult.requestedHeight != null
        ? `${options.bestResult.requestedWidth}×${options.bestResult.requestedHeight}`
        : 'auto',
      actualResolution: `${options.bestResult.actualWidth ?? '—'}×${options.bestResult.actualHeight ?? '—'}`,
      fps: options.bestResult.actualFps,
    },
    expectedBarcode: options.expectedBarcode,
    expectedFormat: options.expectedFormat,
    durationSeconds: options.durationSeconds,
    metrics: {
      detections: options.bestResult.detections,
      correct: options.bestResult.correct,
      incorrect: options.bestResult.incorrect,
      detectionRate: options.bestResult.detectionRate,
      correctRate: options.bestResult.correctRate,
      falsePositiveRate: options.bestResult.falsePositiveRate,
      checkDigitValid: options.bestResult.checkDigitValidDetections,
      temporalStability: options.bestResult.temporalStability,
      timeToFirstDetectionMs: options.bestResult.timeToFirstDetectionMs,
      timeToFirstCorrectMs: options.bestResult.timeToFirstCorrectMs,
      overallScore: options.bestResult.score.total,
    },
    sourceLabel: options.bestResult.label,
  }
}

export function snapshotFromQuagga2Benchmark(options: {
  environmentBrowser: string
  expectedBarcode: string
  expectedFormat: string
  durationSeconds: number
  bestResult: {
    requestedWidth: number
    requestedHeight: number
    actualWidth: number | null
    actualHeight: number | null
    actualFps: number | null
    detections: number
    correct: number
    incorrect: number
    detectionRate: string
    correctRate: string
    falsePositiveRate: string
    checkDigitValidDetections: number
    temporalStability: string
    timeToFirstDetectionMs: number | null
    timeToFirstCorrectMs: number | null
    score: { total: number }
    label: string
  }
}): EngineComparisonSnapshot {
  return {
    engine: 'quagga2',
    savedAt: new Date().toISOString(),
    device: {
      userAgent: navigator.userAgent,
      browser: options.environmentBrowser,
      devicePixelRatio: window.devicePixelRatio,
    },
    camera: {
      facingMode: 'environment',
      requestedResolution: `${options.bestResult.requestedWidth}×${options.bestResult.requestedHeight}`,
      actualResolution: `${options.bestResult.actualWidth ?? '—'}×${options.bestResult.actualHeight ?? '—'}`,
      fps: options.bestResult.actualFps,
    },
    expectedBarcode: options.expectedBarcode,
    expectedFormat: options.expectedFormat,
    durationSeconds: options.durationSeconds,
    metrics: {
      detections: options.bestResult.detections,
      correct: options.bestResult.correct,
      incorrect: options.bestResult.incorrect,
      detectionRate: options.bestResult.detectionRate,
      correctRate: options.bestResult.correctRate,
      falsePositiveRate: options.bestResult.falsePositiveRate,
      checkDigitValid: options.bestResult.checkDigitValidDetections,
      temporalStability: options.bestResult.temporalStability,
      timeToFirstDetectionMs: options.bestResult.timeToFirstDetectionMs,
      timeToFirstCorrectMs: options.bestResult.timeToFirstCorrectMs,
      overallScore: options.bestResult.score.total,
    },
    sourceLabel: options.bestResult.label,
  }
}

function parseRate(value: string): number | null {
  const parsed = Number.parseFloat(value)

  return Number.isFinite(parsed) ? parsed : null
}

function pickWinnerHigher(a: number | null, b: number | null): EngineComparisonRow['winner'] {
  if (a == null || b == null) {
    return '—'
  }

  if (a === b) {
    return 'tie'
  }

  return a > b ? 'barcode_detector' : 'quagga2'
}

function pickWinnerLower(a: number | null, b: number | null): EngineComparisonRow['winner'] {
  if (a == null || b == null) {
    return '—'
  }

  if (a === b) {
    return 'tie'
  }

  return a < b ? 'barcode_detector' : 'quagga2'
}

export interface LibraryComparisonRow {
  library: string
  detections: string
  correct: string
  accuracy: string
  stability: string
  score: string
  available: boolean
}

export function buildLibraryComparisonTable(
  barcodeDetector: EngineComparisonSnapshot | null,
  quagga2: EngineComparisonSnapshot | null,
  html5Qrcode: EngineComparisonSnapshot | null,
): LibraryComparisonRow[] {
  const rows: LibraryComparisonRow[] = [
    {
      library: 'BarcodeDetector',
      detections: barcodeDetector ? String(barcodeDetector.metrics.detections) : '—',
      correct: barcodeDetector ? String(barcodeDetector.metrics.correct) : '—',
      accuracy: barcodeDetector?.metrics.correctRate ?? '—',
      stability: barcodeDetector?.metrics.temporalStability ?? '—',
      score: barcodeDetector ? String(barcodeDetector.metrics.overallScore) : '—',
      available: barcodeDetector != null,
    },
    {
      library: 'Quagga2',
      detections: quagga2 ? String(quagga2.metrics.detections) : '—',
      correct: quagga2 ? String(quagga2.metrics.correct) : '—',
      accuracy: quagga2?.metrics.correctRate ?? '—',
      stability: quagga2?.metrics.temporalStability ?? '—',
      score: quagga2 ? String(quagga2.metrics.overallScore) : '—',
      available: quagga2 != null,
    },
    {
      library: 'html5-qrcode',
      detections: html5Qrcode ? String(html5Qrcode.metrics.detections) : '—',
      correct: html5Qrcode ? String(html5Qrcode.metrics.correct) : '—',
      accuracy: html5Qrcode?.metrics.correctRate ?? '—',
      stability: html5Qrcode?.metrics.temporalStability ?? '—',
      score: html5Qrcode ? String(html5Qrcode.metrics.overallScore) : '—',
      available: html5Qrcode != null,
    },
  ]

  return rows
}

export function buildComparisonRows(
  barcodeDetector: EngineComparisonSnapshot | null,
  quagga2: EngineComparisonSnapshot | null,
): EngineComparisonRow[] {
  const bd = barcodeDetector?.metrics
  const qg = quagga2?.metrics

  return [
    {
      metric: 'Détections',
      barcodeDetector: bd ? String(bd.detections) : '—',
      quagga2: qg ? String(qg.detections) : '—',
      winner: pickWinnerHigher(bd?.detections ?? null, qg?.detections ?? null),
    },
    {
      metric: 'Correctes',
      barcodeDetector: bd ? String(bd.correct) : '—',
      quagga2: qg ? String(qg.correct) : '—',
      winner: pickWinnerHigher(bd?.correct ?? null, qg?.correct ?? null),
    },
    {
      metric: 'Incorrectes',
      barcodeDetector: bd ? String(bd.incorrect) : '—',
      quagga2: qg ? String(qg.incorrect) : '—',
      winner: pickWinnerLower(bd?.incorrect ?? null, qg?.incorrect ?? null),
    },
    {
      metric: 'Taux détection',
      barcodeDetector: bd?.detectionRate ?? '—',
      quagga2: qg?.detectionRate ?? '—',
      winner: pickWinnerHigher(parseRate(bd?.detectionRate ?? ''), parseRate(qg?.detectionRate ?? '')),
    },
    {
      metric: 'Taux correct',
      barcodeDetector: bd?.correctRate ?? '—',
      quagga2: qg?.correctRate ?? '—',
      winner: pickWinnerHigher(parseRate(bd?.correctRate ?? ''), parseRate(qg?.correctRate ?? '')),
    },
    {
      metric: 'Faux positifs',
      barcodeDetector: bd?.falsePositiveRate ?? '—',
      quagga2: qg?.falsePositiveRate ?? '—',
      winner: pickWinnerLower(parseRate(bd?.falsePositiveRate ?? ''), parseRate(qg?.falsePositiveRate ?? '')),
    },
    {
      metric: 'Check digit valide',
      barcodeDetector: bd ? String(bd.checkDigitValid) : '—',
      quagga2: qg ? String(qg.checkDigitValid) : '—',
      winner: pickWinnerHigher(bd?.checkDigitValid ?? null, qg?.checkDigitValid ?? null),
    },
    {
      metric: 'Stabilité',
      barcodeDetector: bd?.temporalStability ?? '—',
      quagga2: qg?.temporalStability ?? '—',
      winner: pickWinnerHigher(parseRate(bd?.temporalStability ?? ''), parseRate(qg?.temporalStability ?? '')),
    },
    {
      metric: 'Temps première lecture',
      barcodeDetector: bd?.timeToFirstDetectionMs != null ? `${bd.timeToFirstDetectionMs} ms` : '—',
      quagga2: qg?.timeToFirstDetectionMs != null ? `${qg.timeToFirstDetectionMs} ms` : '—',
      winner: pickWinnerLower(bd?.timeToFirstDetectionMs ?? null, qg?.timeToFirstDetectionMs ?? null),
    },
    {
      metric: 'Temps première correcte',
      barcodeDetector: bd?.timeToFirstCorrectMs != null ? `${bd.timeToFirstCorrectMs} ms` : '—',
      quagga2: qg?.timeToFirstCorrectMs != null ? `${qg.timeToFirstCorrectMs} ms` : '—',
      winner: pickWinnerLower(bd?.timeToFirstCorrectMs ?? null, qg?.timeToFirstCorrectMs ?? null),
    },
    {
      metric: 'Score global',
      barcodeDetector: bd ? String(bd.overallScore) : '—',
      quagga2: qg ? String(qg.overallScore) : '—',
      winner: pickWinnerHigher(bd?.overallScore ?? null, qg?.overallScore ?? null),
    },
  ]
}
