import type { TrustStatus } from '../api/types'
import styles from './Badges.module.css'

const LABELS: Record<TrustStatus, string> = {
  ok: 'Trusted: the source covers the whole season and agrees with the reference minutes',
  partial: 'Partial: the source covers only part of the season; used for per-90 rates only',
  suspect: 'Suspect: disagrees with the reference data, probably another player’s record; not used',
  missing: 'Missing: no record in this source; never counted as zero',
}

export function TrustBadge({ status, reason }: { status: TrustStatus; reason?: string | null }) {
  return (
    <span className={styles.trust} data-status={status} title={reason ? `${LABELS[status]} (${reason})` : LABELS[status]}>
      {status}
    </span>
  )
}
