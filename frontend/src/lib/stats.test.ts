import { describe, expect, it } from 'vitest'
import { formatStat, niceScale } from './stats'

describe('niceScale', () => {
  it('rounds the domain outward to a round step', () => {
    expect(niceScale(0, 0.83)).toEqual([0, 1, [0, 0.2, 0.4, 0.6, 0.8, 1]])
    expect(niceScale(26.3, 74.9)).toEqual([20, 80, [20, 30, 40, 50, 60, 70, 80]])
  })

  it('shows all zeros from zero up', () => {
    expect(niceScale(0, 0)[0]).toBe(0)
  })

  it('copes with a single value', () => {
    const [lo, hi] = niceScale(5, 5)
    expect(lo).toBeLessThan(5)
    expect(hi).toBeGreaterThan(5)
  })
})

describe('formatStat', () => {
  it('shows precision by size', () => {
    expect(formatStat(0.456)).toBe('0.46')
    expect(formatStat(74.19)).toBe('74.2')
    expect(formatStat(2316)).toBe('2,316')
    expect(formatStat(12)).toBe('12')
  })
})
