import { useInfiniteQuery, useQuery } from '@tanstack/react-query'
import { AnimatePresence, motion } from 'motion/react'
import { Link, useSearchParams } from 'react-router'
import { api } from '../api/client'
import type { ModelComponent, PositionGroup, RankingRow, ScoringModel } from '../api/types'
import { AnimatedNumber } from '../components/AnimatedNumber'
import { Bar } from '../components/Bar'
import { ErrorState } from '../components/ErrorState'
import { PositionBadge } from '../components/PositionBadge'
import { PositionFilter } from '../components/PositionFilter'
import { componentColor } from '../lib/colors'
import { formatNumber, formatScore } from '../lib/format'
import { POSITION_PLURAL, componentsFor, isRoleModel } from '../lib/model'
import styles from './RankingPage.module.css'

const PAGE_SIZE = 50
const POSITIONS: PositionGroup[] = ['FW', 'MF', 'DF', 'GK']

export function RankingPage() {
  const [params, setParams] = useSearchParams()
  const models = useQuery({ queryKey: ['models'], queryFn: api.models })
  const modelId = params.get('model') ?? models.data?.default
  const raw = params.get('position')
  const position = POSITIONS.includes(raw as PositionGroup) ? (raw as PositionGroup) : null

  const update = (changes: Record<string, string | null>) => {
    const next = new URLSearchParams(params)
    for (const [key, value] of Object.entries(changes)) {
      if (value === null) next.delete(key)
      else next.set(key, value)
    }
    setParams(next, { replace: true })
  }

  // Later pages ask for the run of the first page, so a new score run can never mix in.
  const query = useInfiniteQuery({
    queryKey: ['ranking', modelId, position],
    queryFn: ({ pageParam }) =>
      api.ranking({ model: modelId, position: position ?? undefined, limit: PAGE_SIZE, offset: pageParam.offset, run: pageParam.run }),
    initialPageParam: { offset: 0, run: undefined as number | undefined },
    getNextPageParam: (last) =>
      last.offset + last.rows.length < last.total ? { offset: last.offset + last.rows.length, run: last.run.id } : undefined,
    enabled: modelId !== undefined,
  })

  const first = query.data?.pages[0]
  const model = first?.run.model
  const rows = query.data?.pages.flatMap((page) => page.rows) ?? []
  const podium = rows.slice(0, 3)
  const rest = rows.slice(3)
  // Mixed roles have different components: compare bars only within one role.
  const legendComponents = model && (!isRoleModel(model) || position) ? componentsFor(model, position) : null

  return (
    <main className="page">
      <section className={styles.hero}>
        <p className="eyebrow">
          Season {first?.run.season ?? '2025/26'} · {model?.label ?? 'Ranking'}
        </p>
        <h1>The season’s best players, explained.</h1>
        <p className={styles.lead}>
          {model?.description ??
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
        <PositionFilter
          value={position}
          available={model?.position_groups ?? POSITIONS}
          onChange={(value) => update({ position: value })}
        />
        {models.data && models.data.models.length > 1 && (
          <label className={styles.modelSelect}>
            <span className="visually-hidden">Model</span>
            <select
              value={modelId}
              onChange={(e) => {
                const next = models.data.models.find((m) => m.id === e.target.value)
                update({
                  model: e.target.value === models.data.default ? null : e.target.value,
                  // A position the new model does not rank would show an empty list.
                  position: position && next && !next.position_groups.includes(position) ? null : position,
                })
              }}
            >
              {models.data.models.map((m) => (
                <option key={m.id} value={m.id}>
                  {m.label}
                  {m.id === models.data.default ? ' (current)' : ''}
                </option>
              ))}
            </select>
          </label>
        )}
      </div>
      {model && (
        <div className={styles.legendRow}>
          {legendComponents ? (
            <Legend components={legendComponents} />
          ) : (
            <p className={styles.legendNote}>
              Each position is measured on its own components and compared with its own position. Pick a position to compare
              components.
            </p>
          )}
        </div>
      )}

      {models.isError || query.isError ? (
        <ErrorState error={(models.error ?? query.error) as Error} />
      ) : query.isPending || !model ? (
        <Skeleton />
      ) : (
        <>
          {rows.length === 0 && (
            <p className={`card ${styles.empty}`}>
              {position ? `${model.label} does not rank ${POSITION_PLURAL[position]}.` : 'No ranked players in this run.'}
            </p>
          )}
          <section className={styles.podium} aria-label="Top three">
            {podium.map((row, i) => (
              <PodiumCard key={`${modelId}-${position}-${row.player.id}`} row={row} place={i} model={model} />
            ))}
          </section>

          <ol className={styles.list} aria-label="Ranking">
            <AnimatePresence initial={true} mode="popLayout">
              {rest.map((row, i) => (
                <motion.li
                  key={`${modelId}-${position}-${row.player.id}`}
                  layout="position"
                  initial={{ opacity: 0, y: 10 }}
                  animate={{ opacity: 1, y: 0, transition: { delay: Math.min(i % PAGE_SIZE, 14) * 0.025, duration: 0.35 } }}
                  exit={{ opacity: 0, transition: { duration: 0.12 } }}
                >
                  <Row row={row} model={model} compare={legendComponents !== null} />
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
          <span style={{ background: componentColor(i) }} />
          {c.label} <span className="muted">{Math.round(c.weight * 100)}%</span>
        </li>
      ))}
    </ul>
  )
}

function PodiumCard({ row, place, model }: { row: RankingRow; place: number; model: ScoringModel }) {
  const components = componentsFor(model, row.position)
  return (
    <motion.div
      initial={{ opacity: 0, y: 18, scale: 0.98 }}
      animate={{ opacity: 1, y: 0, scale: 1 }}
      transition={{ delay: place * 0.08, duration: 0.5, ease: [0.22, 1, 0.36, 1] }}
      whileHover={{ y: -4 }}
    >
      <Link to={`/players/${row.player.id}${modelQuery(model)}`} className={`card ${styles.podiumCard}`} data-place={place + 1}>
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
              <Bar value={row.components[c.key] ?? null} color={componentColor(i)} delay={0.2 + place * 0.08 + i * 0.05} />
            </div>
          ))}
        </div>
      </Link>
    </motion.div>
  )
}

function Row({ row, model, compare }: { row: RankingRow; model: ScoringModel; compare: boolean }) {
  const components = componentsFor(model, row.position)
  const best = components.reduce<{ label: string; score: number } | null>((top, c) => {
    const score = row.components[c.key] ?? null
    return score !== null && (top === null || score > top.score) ? { label: c.label, score } : top
  }, null)

  return (
    <Link to={`/players/${row.player.id}${modelQuery(model)}`} className={styles.row}>
      <span className={`num ${styles.rank}`}>{row.rank ?? '–'}</span>
      <span className={styles.who}>
        <span className={styles.name}>{row.player.name}</span>
        <span className={styles.team}>{row.team}</span>
      </span>
      <PositionBadge position={row.position} />
      {compare ? (
        <span className={styles.components} style={{ gridTemplateColumns: `repeat(${components.length}, 1fr)` }}>
          {components.map((c, i) => (
            <span key={c.key} title={`${c.label}: ${formatScore(row.components[c.key] ?? null)}`}>
              <Bar value={row.components[c.key] ?? null} color={componentColor(i)} height={5} />
            </span>
          ))}
        </span>
      ) : (
        <span className={styles.strength} title={`Strongest component for ${row.position ? POSITION_PLURAL[row.position] : 'this player'}`}>
          {best && (
            <>
              <span className="muted">Best:</span> {best.label} <span className="num">{formatScore(best.score)}</span>
            </>
          )}
        </span>
      )}
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

/** Player links keep the model the ranking shows, so the profile explains the same score. */
function modelQuery(model: ScoringModel): string {
  return `?model=${encodeURIComponent(model.id)}`
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
