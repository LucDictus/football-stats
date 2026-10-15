import { motion } from 'motion/react'
import type { PositionGroup } from '../api/types'
import { POSITION_FILTER_LABELS, POSITION_ORDER } from '../lib/format'
import styles from './PositionFilter.module.css'

const OPTIONS: { value: PositionGroup | null; label: string; short: string }[] = [
  { value: null, label: 'All', short: 'All' },
  ...POSITION_ORDER.map((value) => ({ value, label: POSITION_FILTER_LABELS[value], short: value })),
]

/** Segmented control (toggle buttons); the highlight slides to the selected option. */
export function PositionFilter({ value, onChange, available }: {
  value: PositionGroup | null
  onChange: (value: PositionGroup | null) => void
  /** Position groups (or roles) the model ranks; others are hidden. */
  available: PositionGroup[]
}) {
  return (
    <div className={styles.group} role="group" aria-label="Position" data-many={available.length > 4 || undefined}>
      {OPTIONS.filter((option) => option.value === null || available.includes(option.value)).map((option) => {
        const selected = option.value === value
        return (
          <button
            key={option.label}
            type="button"
            aria-pressed={selected}
            aria-label={option.label}
            title={option.label}
            className={styles.option}
            onClick={() => onChange(option.value)}
          >
            {selected && (
              <motion.span layoutId="position-pill" className={styles.pill} transition={{ type: 'spring', stiffness: 420, damping: 34 }} />
            )}
            <span className={styles.label} aria-hidden="true">
              <span className={styles.long}>{option.label}</span>
              <span className={styles.short}>{option.short}</span>
            </span>
          </button>
        )
      })}
    </div>
  )
}
