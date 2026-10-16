import type { StatLine } from '../api/types'

export const LINE_COLORS: Record<StatLine, string> = {
  FW: 'var(--pos-fw)',
  MF: 'var(--pos-mf)',
  DF: 'var(--pos-df)',
  GK: 'var(--pos-gk)',
}

/** Shows a value with sensible precision for its size. */
export function formatStat(value: number): string {
  const abs = Math.abs(value)
  const decimals = abs >= 100 || Number.isInteger(value) ? 0 : abs >= 10 ? 1 : 2
  return value.toLocaleString('en-GB', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
}

/** Domain rounded outward to a 1–2–5 step, and its ticks (about five). */
export function niceScale(min: number, max: number): [number, number, number[]] {
  if (min === max) {
    min -= 1
    max += 1
  }
  const raw = (max - min) / 5
  const power = 10 ** Math.floor(Math.log10(raw))
  const step = ([1, 2, 2.5, 5, 10].find((m) => m * power >= raw) ?? 10) * power
  const lo = Math.floor(min / step) * step
  const hi = Math.ceil(max / step) * step
  const ticks: number[] = []
  for (let t = lo; t <= hi + step / 2; t += step) ticks.push(Number(t.toPrecision(12)))
  return [lo, hi, ticks]
}
