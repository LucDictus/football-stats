import { useQuery } from '@tanstack/react-query'
import { motion } from 'motion/react'
import { useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router'
import { api, ApiError } from '../api/client'
import type { PlayerProfile, ScopeStats, ScoreBreakdown } from '../api/types'
import { AnimatedNumber } from '../components/AnimatedNumber'
import { Bar } from '../components/Bar'
import { ErrorState } from '../components/ErrorState'
import { ImpactChart } from '../components/ImpactChart'
import { TrustBadge } from '../components/TrustBadge'
import { POSITION_LABELS, formatNumber, formatScore, formatValue, ordinal } from '../lib/format'
import { POSITION_PLURAL, describeScale } from '../lib/model'
import { NotFoundPage } from './NotFoundPage'
import { componentColor } from '../lib/colors'
import styles from './PlayerPage.module.css'


export function PlayerPage() {
  const raw = useParams().id ?? ''
  const id = /^\d+$/.test(raw) ? Number(raw) : null
  const model = useSearchParams()[0].get('model') ?? undefined
  const { data, error, isPending } = useQuery({
    queryKey: ['player', id, model],
    queryFn: () => api.player(id as number, model),
    enabled: id !== null,
  })

  if (id === null || (error instanceof ApiError && error.status === 404)) return <NotFoundPage />
  if (error) {
    return (
      <main className="page">
        <ErrorState error={error} />
      </main>
    )
  }
  if (isPending || !data) {
    return <main className="page" aria-busy="true" />
  }

  return (
    <main className="page">
      <Link to={model ? `/?model=${encodeURIComponent(model)}` : '/'} className={styles.back}>
        ← Ranking
      </Link>
      <Header profile={data} />
      {data.score ? <Breakdown score={data.score} /> : <NotRanked profile={data} />}
      <Stats profile={data} />
    </main>
  )
}

function Header({ profile }: { profile: PlayerProfile }) {
  const { player, season, score } = profile
  return (
    <header className={styles.header}>
      <div>
        <p className="eyebrow">
          {season.position_group ? POSITION_LABELS[season.position_group] : 'Player'} · Season {season.label}
        </p>
        <h1>{player.name}</h1>
        <p className={styles.sub}>
          {season.team}
          {player.birth_year && <> · born {player.birth_year}</>}
          {season.positions && <> · plays {season.positions.replace(',', ', ')}</>}
        </p>
        {season.stints.length > 1 && (
          <ul className={styles.stints} aria-label="Clubs this season">
            {season.stints.map((stint) => (
              <li key={stint.team}>
                {stint.team} <span className="muted">· {stint.competition.name} · {formatNumber(stint.minutes)} min</span>
              </li>
            ))}
          </ul>
        )}
      </div>
      {score && score.score !== null && (
        <motion.div
          className={`card ${styles.scoreCard}`}
          data-unranked={score.rank === null || undefined}
          initial={{ opacity: 0, scale: 0.94 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{ duration: 0.5, ease: [0.22, 1, 0.36, 1] }}
        >
          {score.rank !== null ? (
            <>
              <span className={`num ${styles.bigScore}`}>
                <AnimatedNumber value={score.score} />
              </span>
              <span className={styles.rankLine}>
                <strong>{ordinal(score.rank)}</strong> of {formatNumber(score.ranked_players)}
              </span>
            </>
          ) : (
            // A score built on a small part of the model is not comparable: no number.
            <span className={styles.rankLine}>
              <strong>Not ranked</strong>: too little data
            </span>
          )}
          <span className={styles.confidenceLine} title="Share of the model that could be measured for this player">
            {Math.round(score.confidence * 100)}% of the model measured
          </span>
        </motion.div>
      )}
    </header>
  )
}

function Breakdown({ score }: { score: ScoreBreakdown }) {
  const { components, normalization, role, impact } = score
  const peers = role ? POSITION_PLURAL[role] : 'eligible players'
  return (
    <>
      {impact && <Blend score={score} />}
      {impact && (
        <section className={styles.section} aria-labelledby="contribution">
          <h2 id="contribution">Contribution in goals</h2>
          <p className={styles.sectionLead}>
            What he added to his team’s goal difference, in the same unit for every position. Each expected goal a team creates is
            shared: half to the shooter, 30% to the last passer, the rest to the players who built the attack.
          </p>
          <div className={`card ${styles.impactCard}`}>
            <ImpactChart impact={impact} />
          </div>
        </section>
      )}
      <Positional score={score} components={components} normalization={normalization} role={role} peers={peers} />
    </>
  )
}

/** Blended models: how the final score is put together. */
function Blend({ score }: { score: ScoreBreakdown }) {
  const impact = score.impact
  if (!impact) return null
  const parts = [
    { label: 'Positional excellence', value: score.positional, weight: 1 - impact.weight, hint: 'How far he stands above others in his position' },
    { label: 'Contribution', value: impact.score, weight: impact.weight, hint: 'Goals added above a replacement-level player, compared across all positions' },
  ]
  return (
    <section className={styles.section} aria-labelledby="blend">
      <h2 id="blend">How the score is built</h2>
      <div className={styles.blend}>
        {parts.map((part, i) => (
          <div key={part.label} className={`card ${styles.blendPart}`}>
            <span className="muted">
              {part.label} · {Math.round(part.weight * 100)}%
            </span>
            <span className={styles.blendHint}>{part.hint}</span>
            <span className={`num ${styles.blendValue}`}>{formatScore(part.value)}</span>
            <Bar value={part.value} color={componentColor(i)} height={6} delay={0.1 + i * 0.1} />
          </div>
        ))}
      </div>
      <p className={styles.blendSum}>
        {Math.round((1 - impact.weight) * 100)}% × {formatScore(score.positional)} + {Math.round(impact.weight * 100)}% ×{' '}
        {formatScore(impact.score)} = <strong className="num">{formatScore(score.score)}</strong>
        {score.score !== null && score.score > 100 && ' (exceptional seasons can go above 100)'}
      </p>
    </section>
  )
}

function Positional({ score, components, normalization, role, peers }: {
  score: ScoreBreakdown
  components: ScoreBreakdown['components']
  normalization: ScoreBreakdown['normalization']
  role: ScoreBreakdown['role']
  peers: string
}) {
  return (
    <section className={styles.section} aria-labelledby="breakdown">
      <h2 id="breakdown">{score.impact ? 'Positional excellence' : 'Why this score'}</h2>
      <p className={styles.sectionLead}>
        Every metric is compared with other {peers} (900+ league minutes): {describeScale(normalization)}. A component averages
        its metrics, and the components are weighed into a combined score
        {score.rank === null ? (
          <>
            . Too little of the model could be measured for him to be ranked, so there is no final score: the components below
            are shown for information.
          </>
        ) : role && score.composite !== null ? (
          <>
            {' '}
            of <strong className="num">{formatScore(score.composite)}</strong>.{' '}
            {score.impact ? 'The positional score' : 'The final score'} says how far that stands above other {peers}, so every
            position can share one ranking.
          </>
        ) : (
          '.'
        )}
      </p>
      <div className={styles.components}>
        {components.map((component, ci) => (
          <motion.article
            key={component.key}
            className={`card ${styles.component}`}
            initial={{ opacity: 0, y: 14 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.1 + ci * 0.08, duration: 0.45, ease: [0.22, 1, 0.36, 1] }}
          >
            <div className={styles.componentHead}>
              <h3>{component.label}</h3>
              <span className="muted">{Math.round(component.weight * 100)}% of the score</span>
            </div>
            <div className={styles.componentScore}>
              <span className="num">{formatScore(component.score)}</span>
              <Bar value={component.score} color={componentColor(ci)} height={8} delay={0.2 + ci * 0.08} />
            </div>
            <p className={styles.description}>{component.description}</p>
            <ul className={styles.metrics}>
              {component.metrics.map((metric, mi) => (
                <li key={metric.key}>
                  <span className={styles.metricLabel}>{metric.label}</span>
                  <span className="num">{formatValue(metric.value)}</span>
                  {metric.adjusted !== null && metric.value !== null && Math.abs(metric.adjusted - metric.value) >= 0.005 && (
                    <span className={styles.adjusted} title="Adjusted for sample size: values built on few matches or duels are pulled toward the average for the role">
                      adjusted for sample size: {formatValue(metric.adjusted)}
                    </span>
                  )}
                  <Bar value={metric.normalised} color={componentColor(ci)} height={4} delay={0.3 + ci * 0.08 + mi * 0.05} />
                  <span className={styles.percentile}>
                    {metric.normalised === null
                      ? 'not measurable'
                      : normalization === 'standard'
                        ? `${formatScore(metric.normalised)} for ${peers}${metric.lower_is_better ? ' (fewer is better)' : ''}`
                        : `${ordinal(Math.round(metric.normalised))} percentile`}
                  </span>
                </li>
              ))}
            </ul>
          </motion.article>
        ))}
      </div>
    </section>
  )
}

function NotRanked({ profile }: { profile: PlayerProfile }) {
  const { position_group: group } = profile.season
  const league = profile.stats.league
  const reason = !league
    ? 'This player has Champions League data only. The ranking covers domestic league seasons.'
    : group === null
      ? 'No source gives this player’s position, so he cannot be compared with players in his role.'
      : league.minutes < 900
        ? `The ranking needs at least 900 league minutes; he played ${formatNumber(league.minutes)}.`
        : group === 'GK'
          ? 'This model does not rank goalkeepers. The positional model does: switch models on the ranking page.'
          : 'Too little of the model could be measured for him (missing or untrusted data).'
  return (
    <section className={`card ${styles.notRanked}`}>
      <h2>Not in this ranking</h2>
      <p className="muted">{reason}</p>
    </section>
  )
}

function Stats({ profile }: { profile: PlayerProfile }) {
  const scopes = [
    { key: 'league' as const, label: 'Domestic league', stats: profile.stats.league },
    { key: 'CL' as const, label: 'Champions League', stats: profile.stats.CL },
  ].filter((scope) => scope.stats !== null)
  const [active, setActive] = useState(scopes[0]?.key)
  const current = scopes.find((scope) => scope.key === active)

  if (!current?.stats) return null

  return (
    <section className={styles.section} aria-labelledby="stats">
      <div className={styles.statsHead}>
        <h2 id="stats">Statistics</h2>
        <div className={styles.tabs} role="group" aria-label="Competition">
          {scopes.map((scope) => (
            <button key={scope.key} type="button" aria-pressed={scope.key === active} onClick={() => setActive(scope.key)}>
              {scope.label}
              {scope.key === active && <motion.span layoutId="stats-tab" className={styles.tabLine} />}
            </button>
          ))}
        </div>
      </div>
      <motion.div key={current.key} initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ duration: 0.25 }}>
        <Providers stats={current.stats} />
        <StatTable stats={current.stats} />
      </motion.div>
    </section>
  )
}

function Providers({ stats }: { stats: ScopeStats }) {
  return (
    <div className={styles.providers}>
      <span className="muted">
        {formatNumber(stats.minutes)} minutes · import #{stats.batch.id} ({stats.batch.source})
      </span>
      {stats.providers.map((provider) => (
        <span key={provider.origin} className={styles.provider}>
          {provider.origin} <TrustBadge status={provider.status} reason={provider.reason} />
          {provider.minutes !== null && <span className="muted">{formatNumber(provider.minutes)} min</span>}
        </span>
      ))}
    </div>
  )
}

function StatTable({ stats }: { stats: ScopeStats }) {
  const categories = [...new Set(stats.fields.map((f) => f.category))]
  return (
    <div className={styles.statGrid}>
      {categories.map((category) => (
        <div key={category} className={`card ${styles.statCard}`}>
          <h3>{category}</h3>
          <table>
            <thead>
              <tr>
                <th scope="col">Statistic</th>
                <th scope="col" className={styles.right}>Total</th>
                <th scope="col" className={styles.right}>Per 90</th>
                <th scope="col">Source</th>
              </tr>
            </thead>
            <tbody>
              {stats.fields
                .filter((f) => f.category === category)
                .map((field) => (
                  <tr key={field.key} data-missing={field.value === null || undefined}>
                    <th scope="row">{field.label}</th>
                    <td className={`num ${styles.right}`}>{formatValue(field.value)}</td>
                    <td className={`num ${styles.right}`}>{field.per90 === null ? '' : field.per90.toFixed(2)}</td>
                    <td>
                      {field.value !== null && field.origin && (
                        <span className={styles.source}>
                          {field.origin} {field.status && field.status !== 'ok' && <TrustBadge status={field.status} />}
                        </span>
                      )}
                    </td>
                  </tr>
                ))}
            </tbody>
          </table>
        </div>
      ))}
    </div>
  )
}
