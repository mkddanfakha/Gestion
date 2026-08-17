/**
 * Normalise un code-barres sans supprimer les zéros en tête.
 */
export function normalizeBarcode(raw: string): string {
  const withoutControlChars = raw.replace(/[\u0000-\u001F\u007F\u200B-\u200D\uFEFF]/g, '')

  return withoutControlChars.trim()
}

export function isValidBarcode(raw: string): boolean {
  const normalized = normalizeBarcode(raw)

  return normalized.length > 0 && /^[A-Za-z0-9]+$/.test(normalized)
}
