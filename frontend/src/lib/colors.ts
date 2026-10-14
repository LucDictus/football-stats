/** Colour per model component, in model order; wraps around if a model has more components. */
const PALETTE = ['var(--c1)', 'var(--c2)', 'var(--c3)', 'var(--pos-fw)', 'var(--pos-df)']

export function componentColor(index: number): string {
  return PALETTE[index % PALETTE.length] ?? 'var(--accent)'
}
