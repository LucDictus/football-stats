import { motion } from 'motion/react'
import styles from './Bar.module.css'

/** A 0–100 bar that grows in (values beyond the scale fill it, the label keeps the value). A null value draws an empty, hatched track: unknown, not zero. */
export function Bar({ value, color = 'var(--accent)', delay = 0, height = 6, label }: {
  value: number | null
  color?: string
  delay?: number
  height?: number
  label?: string
}) {
  return (
    <div
      className={styles.track}
      data-unknown={value === null || undefined}
      style={{ height }}
      role="img"
      aria-label={label ?? (value === null ? 'unknown' : `score ${value.toFixed(0)}`)}
    >
      {value !== null && (
        <motion.div
          className={styles.fill}
          style={{ background: color }}
          initial={{ width: 0 }}
          animate={{ width: `${Math.max(0, Math.min(100, value))}%` }}
          transition={{ duration: 0.9, delay, ease: [0.22, 1, 0.36, 1] }}
        />
      )}
    </div>
  )
}
