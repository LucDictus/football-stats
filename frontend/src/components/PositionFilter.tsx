import { motion } from 'motion/react'
import type { PositionGroup } from '../api/types'
import styles from './PositionFilter.module.css'

const OPTIONS: { value: PositionGroup | null; label: string; short: string }[] = [
  { value: null, label: 'All', short: 'All' },
  { value: 'FW', label: 'Forwards', short: 'FW' },
  { value: 'MF', label: 'Midfielders', short: 'MF' },
  { value: 'DF', label: 'Defenders', short: 'DF' },
  { value: 'GK', label: 'Goalkeepers', short: 'GK' },
]

/** Segmented control (toggle buttons); the highlight slides to the selected option. */
export function PositionFilter({ value, onChange, available }: {
  value: PositionGroup | null
  onChange: (value: PositionGroup | null) => void
  /** Position groups the model ranks; others are hidden. */
  available: PositionGroup[]
}) {
  return (
    <div className={styles.group} role="group" aria-label="Position">
      {OPTIONS.filter((option) => option.value === null || available.includes(option.value)).map((option) => {
        const selected = option.value === value
        return (
          <button
            key={option.label}
            type="button"
            aria-pressed={selected}
            aria-label={option.label}
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
