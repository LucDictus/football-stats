import { motion } from 'motion/react'
import type { PositionGroup } from '../api/types'
import styles from './PositionFilter.module.css'

const OPTIONS: { value: PositionGroup | null; label: string }[] = [
  { value: null, label: 'All' },
  { value: 'FW', label: 'Forwards' },
  { value: 'MF', label: 'Midfielders' },
  { value: 'DF', label: 'Defenders' },
]

/** Segmented control; the highlight slides to the selected option. */
export function PositionFilter({ value, onChange }: { value: PositionGroup | null; onChange: (value: PositionGroup | null) => void }) {
  return (
    <div className={styles.group} role="radiogroup" aria-label="Position">
      {OPTIONS.map((option) => {
        const selected = option.value === value
        return (
          <button
            key={option.label}
            type="button"
            role="radio"
            aria-checked={selected}
            className={styles.option}
            onClick={() => onChange(option.value)}
          >
            {selected && (
              <motion.span layoutId="position-pill" className={styles.pill} transition={{ type: 'spring', stiffness: 420, damping: 34 }} />
            )}
            <span className={styles.label}>{option.label}</span>
          </button>
        )
      })}
    </div>
  )
}
