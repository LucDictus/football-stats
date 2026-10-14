import { motion } from 'motion/react'
import type { ImpactBreakdown, ImpactKey } from '../api/types'
import { formatSigned } from '../lib/format'
import styles from './ImpactChart.module.css'

const LABELS: Record<ImpactKey, { label: string; hint: string }> = {
  shooting: { label: 'Shooting', hint: 'His share of the expected goals from his own shots' },
  finishing: { label: 'Finishing', hint: 'Goals scored above (or below) the expected goals of his shots, shrunk for small numbers' },
  creation: { label: 'Creation', hint: 'His share of the expected goals from chances he set up (xA)' },
  build_up: { label: 'Build-up', hint: 'His share of the expected goals of attacks he helped build (xGBuildup)' },
  goalkeeping: { label: 'Goalkeeping', hint: 'Goals prevented: expected goals of shots on target faced minus goals conceded, shrunk for small numbers' },
  defence: { label: 'Defence', hint: 'His share of his team’s goals prevented against the league average, by his share of its defensive actions' },
  errors: { label: 'Errors', hint: 'Minus a goal for each error of his that led to a goal' },
}

/** Contribution in goals per component, as bars from zero (negative values go left). */
export function ImpactChart({ impact }: { impact: ImpactBreakdown }) {
  const rows = impact.components.filter((c) => c.goals !== null)
  const scale = Math.max(1, ...rows.map((c) => Math.abs(c.goals ?? 0)))

  if (impact.total === null) {
    return (
      <p className={styles.unknown}>
        Contribution unknown: neither Understat nor SofaScore has expected-goals data for him, and the attack is the largest
        part of a contribution. Known parts are listed below for information.
        {rows.length > 0 && ' '}
        {rows.map((c) => `${LABELS[c.key].label} ${formatSigned(c.goals)}`).join(', ')}
      </p>
    )
  }

  return (
    <div className={styles.chart}>
      <ul>
        {rows.map((c, i) => {
          const goals = c.goals ?? 0
          const width = (Math.abs(goals) / scale) * 50
          return (
            <li key={c.key}>
              <span className={styles.label}>
                {LABELS[c.key].label}
                <span className={styles.evidence} data-evidence={c.evidence}>
                  {c.evidence} evidence
                </span>
              </span>
              <span className={styles.track}>
                <span className={styles.zero} />
                <motion.span
                  className={styles.bar}
                  data-negative={goals < 0 || undefined}
                  style={goals < 0 ? { right: '50%' } : { left: '50%' }}
                  initial={{ width: 0 }}
                  animate={{ width: `${width}%` }}
                  transition={{ duration: 0.8, delay: 0.15 + i * 0.06, ease: [0.22, 1, 0.36, 1] }}
                />
              </span>
              <span className={`num ${styles.value}`}>{formatSigned(goals)}</span>
            </li>
          )
        })}
      </ul>
      <dl className={styles.sum}>
        <div>
          <dt>Total contribution</dt>
          <dd className="num">{impact.total === null ? '–' : impact.total.toFixed(1)} goals</dd>
        </div>
        <div>
          <dt>Minus replacement level</dt>
          <dd className="num">{formatSigned(impact.replacement === null ? null : -impact.replacement)}</dd>
        </div>
        <div className={styles.above}>
          <dt>Above replacement</dt>
          <dd className="num">{formatSigned(impact.above)} goals</dd>
        </div>
      </dl>
      {impact.attack_source === 'SofaScore' && (
        <p className={styles.note}>
          Understat has no record of him, so xG and xA come from SofaScore
          {impact.calibration &&
            `, scaled to Understat's level (×${impact.calibration.xg.toFixed(2)} and ×${impact.calibration.xa.toFixed(2)}, measured on players both cover)`}
          .
          {impact.calibration?.build_up_per_pass != null
            ? ' Build-up is estimated from his accurate passes (weak evidence).'
            : ' Build-up could not be estimated.'}
        </p>
      )}
      <details className={styles.how}>
        <summary>How each part is measured</summary>
        <dl>
          {rows.map((c) => (
            <div key={c.key}>
              <dt>{LABELS[c.key].label}</dt>
              <dd>{LABELS[c.key].hint}</dd>
            </div>
          ))}
          <div>
            <dt>Replacement level</dt>
            <dd>What a low-level player in his position (20th percentile) adds in the same minutes.</dd>
          </div>
        </dl>
      </details>
    </div>
  )
}
