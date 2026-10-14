import { useQuery } from '@tanstack/react-query'
import { motion } from 'motion/react'
import { useState } from 'react'
import { api } from '../api/client'
import type { ModelComponent, PositionGroup, ScoringModel } from '../api/types'
import { ErrorState } from '../components/ErrorState'
import { TrustBadge } from '../components/TrustBadge'
import { componentColor } from '../lib/colors'
import { POSITION_LABELS } from '../lib/format'
import { adjustsForPossession, componentsFor, describeScale, isRoleModel } from '../lib/model'
import styles from './MethodologyPage.module.css'

const MODE_LABELS = { total: 'season total', per90: 'per 90', rate: 'success rate' } as const

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
      {model && <ModelSection model={model} />}

      <section className={styles.section}>
        <h2>Principles</h2>
        <ol className={styles.principles}>
          <li>
            <strong>Compared with the right peers.</strong> A defender is measured on defending, build-up and duels, and compared
            only with other defenders. A score of 90 means exceptional for that role.
          </li>
          <li>
            <strong>One ranking for every position.</strong> Each player’s combined score is expressed as how far it stands
            above his positional peers, so a defender who dominates his position can rank above a forward who does not.
          </li>
          <li>
            <strong>Quality and volume, both.</strong> Per-90 rates reward efficiency, season totals and minutes reward doing it
            all season.
          </li>
          <li>
            <strong>Missing is never zero.</strong> When a number is unknown it is left out, not counted as 0. The score is
            built from what is known, and the share of the model that could be measured is shown as a percentage.
          </li>
          <li>
            <strong>Per-90 uses the source’s own minutes</strong>, and needs at least 450 of them. After a transfer between
            leagues, some sources only cover one club.
          </li>
          <li>
            <strong>Little evidence, little deviation.</strong> A rate over a few matches, or a success rate over a few duels, is
            pulled toward the average for the role: 15 tackles out of 15 is not a perfect season.
          </li>
          {model && adjustsForPossession(model) && (
            <li>
              <strong>Defending against opportunity.</strong> A team without the ball has more to defend, so clearances, blocks,
              aerial duels, tackles and interceptions per 90 are adjusted for the team’s possession. How strongly each depends on
              possession is measured from the data: clearances fully, tackles only a little.
            </li>
          )}
          <li>
            <strong>Skewed counts on a square-root scale.</strong> Most defenders score almost never and a few attacking
            full-backs often; on a square-root scale such a metric cannot outweigh the rest, while the best still stand out.
          </li>
          <li>
            <strong>Reproducible.</strong> Every ranking is stored with the exact model definition and the data imports it
            used. The same inputs always give the same ranking.
          </li>
        </ol>
      </section>

      <section className={styles.section}>
        <h2>How far each source is trusted</h2>
        <p>
          Statistics come from FBref (minutes, goals, assists, shots), Understat (xG, xA) and SofaScore (defending, passing,
          goalkeeping, Champions League). When Understat has no record of a player, his xG and xA come from SofaScore, scaled
          to Understat’s level on the players both cover, and his build-up is estimated from his accurate passes. Each source is
          checked against the reference minutes for every player:
        </p>
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
          <li>
            Position labels are broad: “midfielder” covers wingers and holding midfielders alike, so attacking midfielders stand
            out most against that mixed group. Finer roles are next.
          </li>
          {model && adjustsForPossession(model) ? (
            <li>
              Possession is not in the data, so it is estimated from each team’s passing; defensive counts are adjusted with it.
            </li>
          ) : (
            <li>
              Defensive action counts are not adjusted for how much the team has the ball, which favours defenders of teams
              that defend a lot. Duel success rates partly offset this.
            </li>
          )}
          <li>Champions League performances are not in the score yet; they are shown on player pages.</li>
          <li>All leagues count equally; league strength is not adjusted for yet.</li>
          <li>Goals prevented, the goalkeeper measure, is noisy over one season.</li>
        </ul>
      </section>
    </main>
  )
}

function ModelSection({ model }: { model: ScoringModel }) {
  const roles = isRoleModel(model) ? (model.position_groups as PositionGroup[]) : []
  const [role, setRole] = useState<PositionGroup | null>(roles[0] ?? null)
  const components = componentsFor(model, role)

  return (
    <section className={styles.section}>
      <h2>
        {model.label} <span className="muted">({model.id})</span>
      </h2>
      <p>{model.description}</p>
      <ul className={styles.facts}>
        <li>
          <strong>Scope</strong>{' '}
          {model.scope === 'league' ? 'Domestic league matches (Champions League follows in a later version)' : 'Champions League'}
        </li>
        <li>
          <strong>Who is ranked</strong> {model.position_groups.map((g) => POSITION_LABELS[g].toLowerCase() + 's').join(', ')} with
          at least {model.min_minutes} minutes
        </li>
        <li>
          <strong>Scale</strong> {describeScale(model.normalization)}
        </li>
        <li>
          <strong>Minimum data</strong> {Math.round(model.min_confidence * 100)}% of the model must be measurable
        </li>
        {model.reference && (
          <li>
            <strong>Average means</strong> the average of the {model.reference}
          </li>
        )}
        {model.z_cap && (
          <li>
            <strong>Outliers</strong> more than {model.z_cap} standard deviations from the average count as {model.z_cap}
          </li>
        )}
      </ul>

      {model.impact && <ImpactSection impact={model.impact} />}

      {model.impact && <h3 className={styles.subhead}>Positional excellence ({Math.round((1 - model.impact.weight) * 100)}%)</h3>}
      {roles.length > 0 && (
        <div className={styles.roleTabs} role="group" aria-label="Position">
          {roles.map((r) => (
            <button key={r} type="button" aria-pressed={r === role} onClick={() => setRole(r)}>
              {POSITION_LABELS[r]}s
              {r === role && <motion.span layoutId="role-tab" className={styles.roleTabLine} />}
            </button>
          ))}
        </div>
      )}

      <motion.div key={role ?? 'all'} initial={{ opacity: 0, y: 6 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }}>
        <Weights components={components} />
        <div className={styles.components}>
          {components.map((c, i) => (
            <article key={c.key} className="card">
              <h3>
                <span style={{ background: componentColor(i) }} />
                {c.label}
              </h3>
              <p className="muted">{c.description}</p>
              <ul>
                {c.metrics.map((m) => (
                  <li key={m.key}>
                    {m.label}
                    <span className="muted">
                      {' '}
                      · {MODE_LABELS[m.mode]}
                      {m.lower_is_better ? ', fewer is better' : ''}
                      {m.shrinkage ? `, small samples pulled toward average (${m.shrinkage} ${m.mode === 'rate' ? 'attempts' : 'matches'})` : ''}
                      {m.transform === 'sqrt' ? ', square-root scale' : ''} ·{' '}
                      {Math.round(m.weight * 100)}%
                    </span>
                  </li>
                ))}
              </ul>
            </article>
          ))}
        </div>
      </motion.div>
    </section>
  )
}

function ImpactSection({ impact }: { impact: NonNullable<ScoringModel['impact']> }) {
  const pct = (v: number) => `${Math.round(v * 100)}%`
  return (
    <div className={styles.impact}>
      <h3 className={styles.subhead}>Contribution in goals ({pct(impact.weight)})</h3>
      <p>
        Every position measured in the same unit: goals added. Each expected goal a team creates is shared out, so the
        credits add up to the team’s xG and no chance is counted twice: {pct(impact.shooter_share)} to the shooter,{' '}
        {pct(impact.passer_share)} to the last passer, {pct(impact.build_up_share)} to the players who built the attack (and the
        passer’s share too when there was no assist). Then:
      </p>
      <ul className={styles.limits}>
        <li>
          <strong>Finishing</strong>: goals scored above the expected goals of his shots, pulled toward zero for small numbers of
          shots. <span className="muted">Moderate evidence.</span>
        </li>
        <li>
          <strong>Goalkeeping</strong>: goals prevented, pulled toward zero by the number of shots on target faced (it is noisy
          over one season). <span className="muted">Moderate evidence.</span>
        </li>
        <li>
          <strong>Defence</strong>: the team’s goals prevented against the league average, shared among outfield players by their
          share of the team’s defensive actions
          {impact.defence_sharing === 'per_type' &&
            ' (of each kind, averaged, so frequent clearances do not outweigh tackles and interceptions)'}
          . <span className="muted">Weak evidence: it is a team measure.</span>
        </li>
        {impact.error_goals !== undefined && (
          <li>
            <strong>Errors</strong>: an outfield player loses {impact.error_goals} goal for each error leading to a goal (a
            goalkeeper’s errors are already in his goals prevented). <span className="muted">Moderate evidence.</span>
          </li>
        )}
        <li>
          <strong>Above replacement</strong>: minus what a low-level player in his position (20th percentile) adds in the same
          minutes, so playing time counts. The contribution score compares this across all positions.
        </li>
      </ul>
    </div>
  )
}

function Weights({ components }: { components: ModelComponent[] }) {
  return (
    <div className={styles.weights} aria-label="Component weights">
      {components.map((c, i) => (
        <motion.div
          key={c.key}
          style={{ background: componentColor(i), flexGrow: c.weight }}
          initial={{ scaleX: 0 }}
          animate={{ scaleX: 1 }}
          transition={{ delay: i * 0.08, duration: 0.5, ease: [0.22, 1, 0.36, 1] }}
          title={`${c.label} ${Math.round(c.weight * 100)}%`}
        >
          {c.label} {Math.round(c.weight * 100)}%
        </motion.div>
      ))}
    </div>
  )
}
