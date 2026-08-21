import { isValidBarcode, normalizeBarcode } from '@/utils/barcodeNormalizer'

export const INVALID_BARCODE_MESSAGE_ORIGINS = [
  {
    message: 'Code-barres invalide. Utilisez uniquement des caractères alphanumériques.',
    file: 'resources/js/components/BarcodeScanner.vue',
    function: 'emitBarcode()',
    rule: 'isValidBarcode(normalizeBarcode(raw)) via barcodeNormalizer.ts',
  },
  {
    message: 'Code-barres invalide.',
    file: 'resources/js/composables/useProductBarcodeLookup.ts',
    function: 'lookupProduct()',
    rule: 'isValidBarcode(barcode) via barcodeNormalizer.ts',
  },
  {
    message: 'Code-barres invalide.',
    file: 'app/Http/Controllers/ProductController.php',
    function: 'lookup by barcode (API)',
    rule: 'Validation backend',
  },
] as const

export interface BarcodeDetectionHistoryEntry {
  id: number
  at: string
  rawValue: string
  format: string
  length: number
  digitsOnly: boolean
  alphanumeric: boolean
  ean13Structure: boolean
  ean13Checksum: boolean
}

export interface BarcodeRawAnalysis {
  rawValue: string
  valueType: string
  length: number
  json: string
  unicodeCodes: string
  characters: string
  format: string
  digitsOnly: boolean
  alphanumeric: boolean
  ean13Structure: boolean
  ean13Checksum: boolean
  normalizedValue: string
  normalizationModified: boolean
  appValidationPass: boolean
}

export type BarcodeDiagnosticScenario = 'A' | 'B' | 'C' | 'idle'

export function isValidEan13(value: string): boolean {
  if (!/^\d{13}$/.test(value)) {
    return false
  }

  const digits = value.split('').map((char) => Number(char))
  let sum = 0

  for (let index = 0; index < 12; index += 1) {
    sum += digits[index] * (index % 2 === 0 ? 1 : 3)
  }

  const checkDigit = (10 - (sum % 10)) % 10

  return checkDigit === digits[12]
}

export function analyzeRawBarcodeValue(
  rawValue: string,
  format = 'inconnu',
): BarcodeRawAnalysis {
  const normalizedValue = normalizeBarcode(rawValue)

  return {
    rawValue,
    valueType: typeof rawValue,
    length: rawValue.length,
    json: JSON.stringify(rawValue),
    unicodeCodes: [...rawValue].map((char) => char.charCodeAt(0)).join(' '),
    characters: [...rawValue].join(' '),
    format,
    digitsOnly: /^\d+$/.test(rawValue),
    alphanumeric: /^[A-Za-z0-9]+$/.test(rawValue),
    ean13Structure: /^\d{13}$/.test(rawValue),
    ean13Checksum: isValidEan13(rawValue),
    normalizedValue,
    normalizationModified: normalizedValue !== rawValue,
    appValidationPass: isValidBarcode(rawValue),
  }
}

export function formatBarcodeFormatLabel(format: string): string {
  switch (format) {
    case 'ean_13':
      return 'EAN-13'
    case 'ean_8':
      return 'EAN-8'
    case 'upc_a':
      return 'UPC-A'
    case 'upc_e':
      return 'UPC-E'
    case 'code_128':
      return 'Code 128'
    case 'code_39':
      return 'Code 39'
    default:
      return format || 'inconnu'
  }
}

export function buildDetectionHistoryEntry(
  id: number,
  at: string,
  analysis: BarcodeRawAnalysis,
): BarcodeDetectionHistoryEntry {
  return {
    id,
    at,
    rawValue: analysis.rawValue,
    format: analysis.format,
    length: analysis.length,
    digitsOnly: analysis.digitsOnly,
    alphanumeric: analysis.alphanumeric,
    ean13Structure: analysis.ean13Structure,
    ean13Checksum: analysis.ean13Checksum,
  }
}

export function resolveDiagnosticScenario(
  detectionCount: number,
  analysis: BarcodeRawAnalysis | null,
): BarcodeDiagnosticScenario {
  if (detectionCount === 0 || !analysis) {
    return 'idle'
  }

  if (
    analysis.digitsOnly
    && analysis.alphanumeric
    && analysis.ean13Structure
    && analysis.ean13Checksum
  ) {
    return 'A'
  }

  if (
    !analysis.digitsOnly
    || analysis.normalizationModified
    || /[\r\n\u0000-\u001F\u007F\u200B-\u200D\uFEFF]/.test(analysis.rawValue)
  ) {
    return 'C'
  }

  return 'B'
}

export function getDiagnosticConclusionText(
  scenario: BarcodeDiagnosticScenario,
  detectionCount: number,
  analysis: BarcodeRawAnalysis | null = null,
): string {
  if (detectionCount === 0) {
    return 'DIAGNOSTIC : Aucune détection vue-qrcode-reader. Le problème semble se situer au niveau de la détection caméra/moteur.'
  }

  switch (scenario) {
    case 'A':
      return 'DIAGNOSTIC : vue-qrcode-reader détecte correctement le code. La caméra et le décodage fonctionnent. Le problème semble se situer dans le pipeline applicatif après détection.'
    case 'C':
      return 'DIAGNOSTIC : vue-qrcode-reader détecte une valeur contenant des caractères inattendus. Vérifier la valeur brute, les codes Unicode et la normalisation.'
    default:
      if (analysis?.ean13Structure && !analysis.ean13Checksum) {
        return 'DIAGNOSTIC : 13 chiffres détectés mais checksum EAN-13 FAIL. Analyser la valeur brute.'
      }

      return 'DIAGNOSTIC : détection reçue — analyser les tests locaux ci-dessus.'
  }
}

export function formatPassFail(passed: boolean): string {
  return passed ? 'PASS' : 'FAIL'
}

export function logVueQrcodeReaderDiagnostic(message: string, payload?: unknown): void {
  if (!import.meta.env.DEV) {
    return
  }

  if (payload === undefined) {
    console.info(`[vue-qrcode-reader diagnostic] ${message}`)
    return
  }

  console.info(`[vue-qrcode-reader diagnostic] ${message}`, payload)
}
