/**
 * Utilitaire pour formater les dates avec le fuseau horaire du Sénégal (Africa/Dakar)
 */

const TIMEZONE = 'Africa/Dakar'
const LOCALE = 'fr-FR'

/**
 * Formater une date au format français (DD/MM/YYYY)
 */
export const formatDate = (date: string | Date): string => {
  const dateObj = typeof date === 'string' ? new Date(date) : date
  
  return new Intl.DateTimeFormat(LOCALE, {
    timeZone: TIMEZONE,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric'
  }).format(dateObj)
}

/**
 * Formater une date avec l'heure au format français (DD/MM/YYYY HH:MM)
 */
export const formatDateTime = (date: string | Date): string => {
  const dateObj = typeof date === 'string' ? new Date(date) : date
  
  return new Intl.DateTimeFormat(LOCALE, {
    timeZone: TIMEZONE,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  }).format(dateObj)
}

/**
 * Formater uniquement l'heure (HH:MM)
 */
export const formatTime = (date: string | Date): string => {
  const dateObj = typeof date === 'string' ? new Date(date) : date
  
  return new Intl.DateTimeFormat(LOCALE, {
    timeZone: TIMEZONE,
    hour: '2-digit',
    minute: '2-digit'
  }).format(dateObj)
}

/**
 * Formater une date au format long (ex: 15 janvier 2025)
 */
export const formatDateLong = (date: string | Date): string => {
  const dateObj = typeof date === 'string' ? new Date(date) : date
  
  return new Intl.DateTimeFormat(LOCALE, {
    timeZone: TIMEZONE,
    day: 'numeric',
    month: 'long',
    year: 'numeric'
  }).format(dateObj)
}

/**
 * Formater une date au format court avec mois abrégé (ex: 15 jan. 2025)
 */
export const formatDateShort = (date: string | Date): string => {
  const dateObj = typeof date === 'string' ? new Date(date) : date
  
  return new Intl.DateTimeFormat(LOCALE, {
    timeZone: TIMEZONE,
    day: 'numeric',
    month: 'short',
    year: 'numeric'
  }).format(dateObj)
}

/**
 * Obtenir la date actuelle dans le fuseau horaire du Sénégal
 */
export const getCurrentDate = (): Date => {
  return new Date(new Date().toLocaleString('en-US', { timeZone: TIMEZONE }))
}

/**
 * Formater une date pour un input de type date (YYYY-MM-DD)
 */
export const formatDateForInput = (date: string | Date | null | undefined): string => {
  if (!date) return ''
  
  const dateObj = typeof date === 'string' ? new Date(date) : date
  
  // Utiliser le fuseau horaire du Sénégal
  const formatter = new Intl.DateTimeFormat('en-CA', {
    timeZone: TIMEZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit'
  })
  
  return formatter.format(dateObj)
}

/**
 * Formater une date relative (ex: "il y a 2 heures", "dans 3 jours")
 */
export const formatDateRelative = (date: string | Date): string => {
  const dateObj = typeof date === 'string' ? new Date(date) : date
  const now = getCurrentDate()
  const diffInSeconds = Math.floor((now.getTime() - dateObj.getTime()) / 1000)
  
  if (diffInSeconds < 60) {
    return 'à l\'instant'
  }
  
  const diffInMinutes = Math.floor(diffInSeconds / 60)
  if (diffInMinutes < 60) {
    return `il y a ${diffInMinutes} minute${diffInMinutes > 1 ? 's' : ''}`
  }
  
  const diffInHours = Math.floor(diffInMinutes / 60)
  if (diffInHours < 24) {
    return `il y a ${diffInHours} heure${diffInHours > 1 ? 's' : ''}`
  }
  
  const diffInDays = Math.floor(diffInHours / 24)
  if (diffInDays < 7) {
    return `il y a ${diffInDays} jour${diffInDays > 1 ? 's' : ''}`
  }
  
  const diffInWeeks = Math.floor(diffInDays / 7)
  if (diffInWeeks < 4) {
    return `il y a ${diffInWeeks} semaine${diffInWeeks > 1 ? 's' : ''}`
  }
  
  const diffInMonths = Math.floor(diffInDays / 30)
  if (diffInMonths < 12) {
    return `il y a ${diffInMonths} mois`
  }
  
  const diffInYears = Math.floor(diffInDays / 365)
  return `il y a ${diffInYears} an${diffInYears > 1 ? 's' : ''}`
}

/**
 * Obtenir le nom du mois actuel dans le fuseau horaire du Sénégal
 */
export const getCurrentMonthName = (): string => {
  const now = getCurrentDate()
  return new Intl.DateTimeFormat(LOCALE, {
    timeZone: TIMEZONE,
    month: 'long'
  }).format(now)
}

