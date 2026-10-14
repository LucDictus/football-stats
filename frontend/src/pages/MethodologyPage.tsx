import { useQuery } from '@tanstack/react-query'
import { motion } from 'motion/react'
import { api } from '../api/client'
import { ErrorState } from '../components/ErrorState'
import { TrustBadge } from '../components/TrustBadge'
import styles from './MethodologyPage.module.css'

const COMPONENT_COLORS = ['var(--c1)', 'var(--c2)', 'var(--c3)']

/** The model definition comes from the API, so this page can never drift from the code. */
export function MethodologyPage() {
  const { data, error } = useQuery({ queryKey: ['models'], queryFn: api.models })
  const model = data?.models.find((m) => m.id === data.default)

  return (
    <main className={`page ${styles.page}`}>
      <p className="eyebrow">Methodology</p>
      <h1>How a score is made</h1>
      <p className={styles.lead}>
        The ranking is a transparent, versioned model on public data. Every number on a player’s page shows where it came
        from and how far it can be trusted. A model version never changes once released; improvements become a new version.
      </p>

      {error && <ErrorState error={error} />}
      {model && (
        <section className={styles.section}>
          <h2>
            {model.label} <span className="muted">({model.id})</span>
          </h2>
          <p>{model.description}</p>
          <ul className={styles.facts}>
            <li>
              <strong>Scope</strong> {model.scope === 'league' ? 'Domestic league matches (Champions League follows in a later version)' : 'Champions League'}
            </li>
            <li>
              <strong>Who is ranked</strong> {model.position_groups.join(', ')} with at least {model.min_minutes} minutes
            </li>
            <li>
              <strong>Minimum data</strong> {Math.round(model.min_confidence * 100)}% of the model must be measurable
            </li>
          </ul>

          <div className={styles.weights} aria-label="Component weights">
            {model.components.map((c, i) => (
              <motion.div
                key={c.key}
                style={{ background: COMPONENT_COLORS[i], flexGrow: c.weight }}
                initial={{ scaleX: 0 }}
                animate={{ scaleX: 1 }}
                transition={{ delay: i * 0.1, duration: 0.6, ease: [0.22, 1, 0.36, 1] }}
              >
                {c.label} {Math.round(c.weight * 100)}%
              </motion.div>
            ))}
          </div>

          <div className={styles.components}>
            {model.components.map((c, i) => (
              <article key={c.key} className="card">
                <h3>
                  <span style={{ background: COMPONENT_COLORS[i] }} />
                  {c.label}
                </h3>
                <p className="muted">{c.description}</p>
                <ul>
                  {c.metrics.map((m) => (
                    <li key={m.key}>
                      {m.label}
                      <span className="muted">
                        {' '}
                        · {m.mode === 'per90' ? 'per 90' : 'season total'} · {Math.round(m.weight * 100)}%
                      </span>
                    </li>
                  ))}
                </ul>
              </article>
            ))}
          </div>
        </section>
      )}

      <section className={styles.section}>
        <h2>Principles</h2>
        <ol className={styles.principles}>
          <li>
            <strong>Percentiles, not raw numbers.</strong> Goals and minutes live on different scales, so every metric becomes a
            percentile among the ranked players: 90 means better than 90% of them.
          </li>
          <li>
            <strong>Quality and volume, both.</strong> Per-90 rates reward efficiency, season totals reward doing it all season.
            Each attacking component weighs both equally.
          </li>
          <li>
            <strong>Missing is never zero.</strong> When a number is unknown it is left out, not counted as 0. The score is
            built from what is known, and the share of the model that could be measured is shown as a percentage.
          </li>
          <li>
            <strong>Per-90 uses the source’s own minutes.</strong> After a transfer between leagues, some sources only cover
            one club. Their rates are divided by the minutes they actually cover.
          </li>
          <li>
            <strong>Reproducible.</strong> Every ranking is stored with the exact model definition and the data imports it
            used. The same inputs always give the same ranking.
          </li>
        </ol>
      </section>

      <section className={styles.section}>
        <h2>How far each source is trusted</h2>
        <p>Statistics come from FBref (minutes, goals, assists, shots), Understat (xG, xA) and SofaScore (defending, passing, goalkeeping, Champions League). Each source is checked against the reference minutes for every player:</p>
        <ul className={styles.trust}>
          <li><TrustBadge status="ok" /> covers the whole season and agrees with the reference</li>
          <li><TrustBadge status="partial" /> covers part of the season (a transfer between leagues): used for rates only</li>
          <li><TrustBadge status="suspect" /> disagrees, probably another player’s record: not used</li>
          <li><TrustBadge status="missing" /> no record: not used, never zero</li>
        </ul>
      </section>

      <section className={styles.section}>
        <h2>Known limitations of this version</h2>
        <ul className={styles.limits}>
          <li>Only attacking contribution and availability are measured, so defenders and goalkeepers are undervalued or not ranked. Position-aware metrics are next.</li>
          <li>Champions League performances are not in the score yet; they are shown on player pages.</li>
          <li>Percentiles flatten outliers: a 36-goal season and a 25-goal season can both be in the 99th percentile, so availability decides more among the elite than it should.</li>
          <li>All leagues count equally; league strength is not adjusted for yet.</li>
        </ul>
      </section>
    </main>
  )
}
