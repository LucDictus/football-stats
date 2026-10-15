import type { PositionGroup } from '../api/types'

/** In display order: attack to defence. */
export const POSITION_LABELS: Record<PositionGroup, string> = {
  FW: 'Forward',
  MF: 'Midfielder',
  DF: 'Defender',
  ST: 'Striker',
  W: 'Winger',
  AM: 'Attacking midfielder',
  CM: 'Central midfielder',
  DM: 'Defensive midfielder',
  FB: 'Full-back',
  CB: 'Centre-back',
  GK: 'Goalkeeper',
}

/** Compact plural labels for the position filter. */
export const POSITION_FILTER_LABELS: Record<PositionGroup, string> = {
  FW: 'Forwards',
  MF: 'Midfielders',
  DF: 'Defenders',
  ST: 'Strikers',
  W: 'Wingers',
  AM: 'Attacking mids',
  CM: 'Central mids',
  DM: 'Holding mids',
  FB: 'Full-backs',
  CB: 'Centre-backs',
  GK: 'Goalkeepers',
}

/** Every group a model can compare by, in display order. */
export const POSITION_ORDER = Object.keys(POSITION_LABELS) as PositionGroup[]

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
