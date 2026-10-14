import { useQuery } from '@tanstack/react-query'
import { motion } from 'motion/react'
import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { api, ApiError } from '../api/client'
import type { BreakdownComponent, PlayerProfile, ScopeStats } from '../api/types'
import { AnimatedNumber } from '../components/AnimatedNumber'
import { Bar } from '../components/Bar'
import { ErrorState } from '../components/ErrorState'
import { TrustBadge } from '../components/TrustBadge'
import { POSITION_LABELS, formatNumber, formatScore, formatValue, ordinal } from '../lib/format'
import { NotFoundPage } from './NotFoundPage'
import { componentColor } from '../lib/colors'
import styles from './PlayerPage.module.css'


export function PlayerPage() {
  const raw = useParams().id ?? ''
  const id = /^\d+$/.test(raw) ? Number(raw) : null
  const { data, error, isPending } = useQuery({
    queryKey: ['player', id],
    queryFn: () => api.player(id as number),
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
      <Link to="/" className={styles.back}>
        ← Ranking
      </Link>
      <Header profile={data} />
      {data.score ? <Breakdown components={data.score.components} /> : <NotRanked profile={data} />}
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
          initial={{ opacity: 0, scale: 0.94 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{ duration: 0.5, ease: [0.22, 1, 0.36, 1] }}
        >
          <span className={`num ${styles.bigScore}`}>
            <AnimatedNumber value={score.score} />
          </span>
          <span className={styles.rankLine}>
            {score.rank !== null ? (
              <>
                <strong>{ordinal(score.rank)}</strong> of {formatNumber(score.ranked_players)}
              </>
            ) : (
              'Not ranked: too little data'
            )}
          </span>
          <span className={styles.confidenceLine} title="Share of the model that could be measured for this player">
            {Math.round(score.confidence * 100)}% of the model measured
          </span>
        </motion.div>
      )}
    </header>
  )
}

function Breakdown({ components }: { components: BreakdownComponent[] }) {
  return (
    <section className={styles.section} aria-labelledby="breakdown">
      <h2 id="breakdown">Why this score</h2>
      <p className={styles.sectionLead}>
        Each metric is a percentile among all eligible players (outfield, 900+ league minutes): 90 means better than 90% of
        them. A component averages its metrics; the score weighs the components.
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
                  <Bar value={metric.percentile} color={componentColor(ci)} height={4} delay={0.3 + ci * 0.08 + mi * 0.05} />
                  <span className={styles.percentile}>
                    {metric.percentile === null ? 'not measurable' : `${ordinal(Math.round(metric.percentile))} percentile`}
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
  const reason =
    group === 'GK'
      ? 'Goalkeepers are not part of the baseline model yet: they need their own metrics (saves, goals prevented), which arrive in the next model version.'
      : !profile.stats.league
        ? 'This player has Champions League data only. The baseline model ranks domestic league seasons.'
        : group === null
          ? 'No source gives this player’s position, and the baseline model ranks outfield players only.'
          : 'The baseline model ranks outfield players with at least 900 league minutes.'
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
        <div className={styles.tabs} role="tablist">
          {scopes.map((scope) => (
            <button key={scope.key} role="tab" aria-selected={scope.key === active} onClick={() => setActive(scope.key)}>
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
