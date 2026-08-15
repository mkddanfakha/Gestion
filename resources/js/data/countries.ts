import countriesJson from '../../data/countries.json'

export interface CountryOption {
  code: string
  name: string
}

export const COUNTRIES = countriesJson as CountryOption[]

export const SENEGAL_COUNTRY_CODE = 'SN'

export const COUNTRY_CODES = new Set(COUNTRIES.map((country) => country.code))

export function getCountryName(code?: string | null): string {
  if (!code) {
    return ''
  }

  return COUNTRIES.find((country) => country.code === code)?.name ?? code
}
