import type { PositionGroup } from '../api/types'

export const POSITION_LABELS: Record<PositionGroup, string> = {
  GK: 'Goalkeeper',
  DF: 'Defender',
  MF: 'Midfielder',
  FW: 'Forward',
}

export function formatScore(score: number | null): string {
  return score === null ? '–' : score.toFixed(1)
}

export function formatNumber(value: number | null, decimals = 0): string {
  if (value === null) return '–'
  return value.toLocaleString('en-GB', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
}

/** Whole numbers stay whole, fractions get two decimals. */
export function formatValue(value: number | null): string {
  if (value === null) return '–'
  return Number.isInteger(value) ? formatNumber(value) : formatNumber(value, 2)
}

/** 1st, 2nd, 3rd, 4th, 11th, 21st, 112th */
export function ordinal(n: number): string {
  const lastTwo = n % 100
  if (lastTwo >= 11 && lastTwo <= 13) return `${n}th`
  return `${n}${({ 1: 'st', 2: 'nd', 3: 'rd' } as Record<number, string>)[n % 10] ?? 'th'}`
}

/** A signed value with one decimal: +1.2, −0.4, and 0.0 for anything that rounds to zero. */
export function formatSigned(value: number | null): string {
  if (value === null) return '–'
  const rounded = Math.round(value * 10) / 10
  if (rounded === 0) return '0.0'
  return `${rounded > 0 ? '+' : '−'}${Math.abs(rounded).toFixed(1)}`
}
