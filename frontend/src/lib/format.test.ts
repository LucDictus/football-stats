import { describe, expect, it } from 'vitest'
import { formatScore, formatSigned, formatValue, ordinal } from './format'

describe('ordinal', () => {
  it.each([
    [1, '1st'],
    [2, '2nd'],
    [3, '3rd'],
    [4, '4th'],
    [11, '11th'],
    [12, '12th'],
    [13, '13th'],
    [21, '21st'],
    [112, '112th'],
    [1423, '1423rd'],
  ])('%i → %s', (n, expected) => {
    expect(ordinal(n)).toBe(expected)
  })
})

describe('missing values', () => {
  it('shows a dash for unknown values, never zero', () => {
    expect(formatScore(null)).toBe('–')
    expect(formatValue(null)).toBe('–')
  })

  it('keeps whole numbers whole and rounds fractions to two decimals', () => {
    expect(formatValue(25)).toBe('25')
    expect(formatValue(2.536)).toBe('2.54')
    expect(formatValue(0)).toBe('0')
  })
})

describe('formatSigned', () => {
  it.each([
    [1.24, '+1.2'],
    [-0.44, '−0.4'],
    [0.04, '0.0'],
    [-0.04, '0.0'],
    [null, '–'],
  ])('%s → %s', (value, expected) => {
    expect(formatSigned(value)).toBe(expected)
  })
})
