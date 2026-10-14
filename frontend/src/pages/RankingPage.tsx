import { useInfiniteQuery } from '@tanstack/react-query'
import { AnimatePresence, motion } from 'motion/react'
import { Link, useSearchParams } from 'react-router'
import { api } from '../api/client'
import type { ModelComponent, PositionGroup, RankingRow } from '../api/types'
import { AnimatedNumber } from '../components/AnimatedNumber'
import { Bar } from '../components/Bar'
import { PositionBadge } from '../components/PositionBadge'
import { PositionFilter } from '../components/PositionFilter'
import { ErrorState } from '../components/ErrorState'
import { formatNumber, formatScore } from '../lib/format'
import styles from './RankingPage.module.css'

const PAGE_SIZE = 50
const COMPONENT_COLORS = ['var(--c1)', 'var(--c2)', 'var(--c3)']
const POSITIONS: PositionGroup[] = ['FW', 'MF', 'DF']

export function RankingPage() {
  const [params, setParams] = useSearchParams()
  const raw = params.get('position')
  const position = POSITIONS.includes(raw as PositionGroup) ? (raw as PositionGroup) : null

  const query = useInfiniteQuery({
    queryKey: ['ranking', position],
    queryFn: ({ pageParam }) => api.ranking({ position: position ?? undefined, limit: PAGE_SIZE, offset: pageParam }),
    initialPageParam: 0,
    getNextPageParam: (last) => (last.offset + last.rows.length < last.total ? last.offset + last.rows.length : undefined),
  })

  const first = query.data?.pages[0]
  const rows = query.data?.pages.flatMap((page) => page.rows) ?? []
  const components = first?.run.model.components ?? []
  const podium = rows.slice(0, 3)
  const rest = rows.slice(3)

  return (
    <main className="page">
      <section className={styles.hero}>
        <p className="eyebrow">
          Season {first?.run.season ?? '2025/26'} · {first?.run.model.label ?? 'Baseline'}
        </p>
        <h1>The season’s best players, explained.</h1>
        <p className={styles.lead}>
          {first?.run.model.description ??
            'Every score is built from public data, with the source and trust level of each number on the player’s page.'}{' '}
          <Link to="/methodology">How it works →</Link>
        </p>
        {first && (
          <p className={styles.meta}>
            <strong className="num">{formatNumber(first.run.ranked)}</strong> players ranked ·{' '}
            <strong className="num">{formatNumber(first.run.unranked)}</strong> with too little data · score run #{first.run.id}
          </p>
        )}
      </section>

      <div className={styles.toolbar}>
        <PositionFilter value={position} onChange={(value) => setParams(value ? { position: value } : {}, { replace: true })} />
        <Legend components={components} />
      </div>

      {query.isError ? (
        <ErrorState error={query.error} />
      ) : query.isPending ? (
        <Skeleton />
      ) : (
        <>
          <section className={styles.podium} aria-label="Top three">
            {podium.map((row, i) => (
              <PodiumCard key={`${position}-${row.player.id}`} row={row} place={i} components={components} />
            ))}
          </section>

          <ol className={styles.list} aria-label="Ranking">
            <AnimatePresence initial={true} mode="popLayout">
              {rest.map((row, i) => (
                <motion.li
                  key={`${position}-${row.player.id}`}
                  layout="position"
                  initial={{ opacity: 0, y: 10 }}
                  animate={{ opacity: 1, y: 0, transition: { delay: Math.min(i % PAGE_SIZE, 14) * 0.025, duration: 0.35 } }}
                  exit={{ opacity: 0, transition: { duration: 0.12 } }}
                >
                  <Row row={row} components={components} />
                </motion.li>
              ))}
            </AnimatePresence>
          </ol>

          {query.hasNextPage && (
            <div className={styles.more}>
              <button type="button" onClick={() => query.fetchNextPage()} disabled={query.isFetchingNextPage}>
                {query.isFetchingNextPage ? 'Loading…' : `Show more (${formatNumber((first?.total ?? 0) - rows.length)} left)`}
              </button>
            </div>
          )}
        </>
      )}
    </main>
  )
}

function Legend({ components }: { components: ModelComponent[] }) {
  return (
    <ul className={styles.legend} aria-label="Components">
      {components.map((c, i) => (
        <li key={c.key} title={c.description}>
          <span style={{ background: COMPONENT_COLORS[i] }} />
          {c.label} <span className="muted">{Math.round(c.weight * 100)}%</span>
        </li>
      ))}
    </ul>
  )
}

function PodiumCard({ row, place, components }: { row: RankingRow; place: number; components: ModelComponent[] }) {
  return (
    <motion.div
      initial={{ opacity: 0, y: 18, scale: 0.98 }}
      animate={{ opacity: 1, y: 0, scale: 1 }}
      transition={{ delay: place * 0.08, duration: 0.5, ease: [0.22, 1, 0.36, 1] }}
      whileHover={{ y: -4 }}
    >
      <Link to={`/players/${row.player.id}`} className={`card ${styles.podiumCard}`} data-place={place + 1}>
        <div className={styles.podiumTop}>
          <span className={styles.podiumRank}>#{row.rank}</span>
          <PositionBadge position={row.position} />
        </div>
        <h2>{row.player.name}</h2>
        <p className="muted">{row.team}</p>
        <div className={styles.podiumScore}>
          <span className="num">{row.score !== null ? <AnimatedNumber value={row.score} /> : '–'}</span>
          <span className="muted">score</span>
        </div>
        <div className={styles.podiumBars}>
          {components.map((c, i) => (
            <div key={c.key} className={styles.podiumBar}>
              <span>{c.label}</span>
              <span className="num">{formatScore(row.components[c.key] ?? null)}</span>
              <Bar value={row.components[c.key] ?? null} color={COMPONENT_COLORS[i]} delay={0.2 + place * 0.08 + i * 0.05} />
            </div>
          ))}
        </div>
      </Link>
    </motion.div>
  )
}

function Row({ row, components }: { row: RankingRow; components: ModelComponent[] }) {
  return (
    <Link to={`/players/${row.player.id}`} className={styles.row}>
      <span className={`num ${styles.rank}`}>{row.rank ?? '–'}</span>
      <span className={styles.who}>
        <span className={styles.name}>{row.player.name}</span>
        <span className={styles.team}>{row.team}</span>
      </span>
      <PositionBadge position={row.position} />
      <span className={styles.components}>
        {components.map((c, i) => (
          <span key={c.key} title={`${c.label}: ${formatScore(row.components[c.key] ?? null)}`}>
            <Bar value={row.components[c.key] ?? null} color={COMPONENT_COLORS[i]} height={5} />
          </span>
        ))}
      </span>
      <span className={styles.score}>
        <span className="num">{formatScore(row.score)}</span>
        {row.confidence < 1 && (
          <span className={styles.confidence} title={`Only ${Math.round(row.confidence * 100)}% of the model could be measured for this player`}>
            {Math.round(row.confidence * 100)}%
          </span>
        )}
      </span>
    </Link>
  )
}

function Skeleton() {
  return (
    <div className={styles.skeleton} aria-busy="true" aria-label="Loading the ranking">
      {Array.from({ length: 8 }, (_, i) => (
        <div key={i} style={{ animationDelay: `${i * 80}ms` }} />
      ))}
    </div>
  )
}
