/**
 * Vérification manuelle des cas de péremption (exécuter avec : npx tsx scripts/verify-expiration-status.ts)
 */
import { diffCalendarDays, formatExpirationStatus } from '../resources/js/modules/NotificationCenter/utils/expirationStatus'

function assertEqual(actual: unknown, expected: unknown, label: string): void {
    if (actual !== expected) {
        throw new Error(`${label}: attendu "${expected}", reçu "${actual}"`)
    }
}

function referenceDateFromTodayOffset(offsetDays: number): Date {
    const reference = new Date('2026-08-09T12:00:00.000Z')
    reference.setUTCDate(reference.getUTCDate() + offsetDays)
    return reference
}

const reference = referenceDateFromTodayOffset(0)

const cases = [
    { expiration: '2026-08-14', label: 'Expire dans 5 jours' },
    { expiration: '2026-08-10', label: 'Expire dans 1 jour' },
    { expiration: '2026-08-09', label: 'Expire aujourd\'hui' },
    { expiration: '2026-08-08', label: 'Expiré depuis 1 jour' },
    { expiration: '2026-08-02', label: 'Expiré depuis 7 jours' },
] as const

for (const testCase of cases) {
    const result = formatExpirationStatus(testCase.expiration, reference)
    assertEqual(result?.label, testCase.label, testCase.expiration)
}

assertEqual(diffCalendarDays('2026-08-14', reference), 5, 'diff +5')
assertEqual(diffCalendarDays('2026-08-09', reference), 0, 'diff 0')
assertEqual(diffCalendarDays('2026-08-02', reference), -7, 'diff -7')

console.log('Tous les cas de péremption sont validés.')
