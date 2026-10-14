import type { PositionGroup } from '../api/types'
import { POSITION_LABELS } from '../lib/format'
import styles from './Badges.module.css'

export function PositionBadge({ position }: { position: PositionGroup | null }) {
  if (position === null) return <span className={styles.position}>–</span>
  return (
    <span className={styles.position} data-position={position} title={POSITION_LABELS[position]}>
      {position}
    </span>
  )
}
