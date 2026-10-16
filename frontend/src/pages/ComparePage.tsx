import { useQueries, useQuery } from '@tanstack/react-query'
import { AnimatePresence, motion } from 'motion/react'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { api } from '../api/client'
import type { DistributionPart, ImpactKey, PlayerProfile } from '../api/types'
import { DistributionPanel } from '../components/DistributionPanel'
import { ErrorState } from '../components/ErrorState'
import { PositionBadge } from '../components/PositionBadge'
import { RadarChart } from '../components/RadarChart'
import { SearchBox } from '../components/SearchBox'
import { StatExplorer } from '../components/StatExplorer'
import { formatScore, formatSigned, POSITION_LABELS } from '../lib/format'
import { POSITION_PLURAL } from '../lib/model'
import styles from './ComparePage.module.css'

const MAX_PLAYERS = 4
/** One colour per compared player, in order. */
export const PLAYER_COLORS = ['var(--accent)', 'var(--c2)', 'var(--c3)', 'var(--pos-fw)']

const IMPACT_LABELS: Record<ImpactKey, string> = {
  shooting: 'Shooting',
  finishing: 'Finishing',
  creation: 'Creation',
  build_up: 'Build-up',
  goalkeeping: 'Goalkeeping',
  defence: 'Defence',
  errors: 'Errors',
}

/** Two to four players side by side: how each score is built, and where each stands. */
export function ComparePage() {
  const [params, setParams] = useSearchParams()
  const ids = (params.get('ids') ?? '')
    .split(',')
    .map(Number)
    .filter((id, i, all) => Number.isInteger(id) && id > 0 && all.indexOf(id) === i)
    .slice(0, MAX_PLAYERS)
  const model = params.get('model') ?? undefined
  const setIds = (next: number[]) => {
    const p = new URLSearchParams(params)
    if (next.length) p.set('ids', next.join(','))
    else p.delete('ids')
    setParams(p, { replace: true })
  }

  const profiles = useQueries({
    queries: ids.map((id) => ({ queryKey: ['player', id, model], queryFn: () => api.player(id, model) })),
  })
  const error = profiles.find((q) => q.error)?.error
  const loaded = profiles.every((q) => q.data)
  const players = profiles.flatMap((q) => (q.data ? [q.data] : []))

  return (
    <main className={`page ${styles.page}`}>
      <p className="eyebrow">Compare</p>
      <h1>Compare players</h1>
      <p className={styles.lead}>
        Put up to four players side by side: how each final score is built, what each added in goals, how they compare on the
        measures of their role, and where they stand among everyone else. Below, the stat explorer puts any two statistics
        against each other for the roles or lines you choose.
      </p>

      <div className={styles.picker}>
        <ul className={styles.chips} aria-label="Compared players">
          {ids.map((id, i) => {
            const profile = profiles[i]?.data
            return (
              <li key={id} style={{ borderColor: PLAYER_COLORS[i] }}>
                <span className={styles.dot} style={{ background: PLAYER_COLORS[i] }} />
                {profile ? profile.player.name : '…'}
                <button type="button" aria-label={`Remove ${profile?.player.name ?? 'player'}`} onClick={() => setIds(ids.filter((x) => x !== id))}>
                  ×
                </button>
              </li>
            )
          })}
        </ul>
        {ids.length < MAX_PLAYERS && (
          <div className={styles.search}>
            <SearchBox placeholder={ids.length ? 'Add a player' : 'Search a player to compare'} onSelect={(id) => !ids.includes(id) && setIds([...ids, id])} />
          </div>
        )}
      </div>

      {ids.length < 2 && <Suggestions model={model} ids={ids} onAdd={(id) => setIds([...ids, id])} />}
      {error && <ErrorState error={error} />}
      {ids.length >= 1 && loaded && players.length === ids.length && <Comparison players={players} model={model} />}
      <StatExplorer
        model={model}
        highlights={ids.map((id, i) => ({ id, color: PLAYER_COLORS[i] ?? 'var(--accent)' }))}
        onPick={(id) => !ids.includes(id) && ids.length < MAX_PLAYERS && setIds([...ids, id])}
      />
    </main>
  )
}

/** The current top of the ranking, one click to add. */
function Suggestions({ model, ids, onAdd }: { model?: string; ids: number[]; onAdd: (id: number) => void }) {
  const { data } = useQuery({ queryKey: ['ranking-top', model], queryFn: () => api.ranking({ model, limit: 8 }) })
  const rows = (data?.rows ?? []).filter((r) => !ids.includes(r.player.id))
  if (!rows.length) return null
  return (
    <p className={styles.suggestions}>
      <span className="muted">{ids.length ? 'Add one more, for example:' : 'Or start with the top of the ranking:'}</span>
      {rows.map((r) => (
        <button key={r.player.id} type="button" onClick={() => onAdd(r.player.id)}>
          {r.player.name}
        </button>
      ))}
    </p>
  )
}

function Comparison({ players, model }: { players: PlayerProfile[]; model?: string }) {
  const scored = players.filter((p) => p.score)
  const roles = new Set(scored.map((p) => p.score?.role))
  const sameRole = roles.size === 1 && scored.length === players.length ? scored[0]?.score?.role ?? null : null

  return (
    <>
      <section className={styles.cards}>
        {players.map((p, i) => (
          <motion.article
            key={p.player.id}
            className={`card ${styles.card}`}
            style={{ borderTopColor: PLAYER_COLORS[i] }}
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: i * 0.06 }}
          >
            <div className={styles.cardHead}>
              <Link to={`/players/${p.player.id}${model ? `?model=${model}` : ''}`}>{p.player.name}</Link>
              <PositionBadge position={p.score?.role ?? p.season.role ?? p.season.position_group} />
            </div>
            <span className="muted">{p.season.team}</span>
            <span className={`num ${styles.cardScore}`}>{formatScore(p.score?.score ?? null)}</span>
            <span className="muted">{p.score?.rank ? `#${p.score.rank} of ${p.score.ranked_players}` : 'not ranked'}</span>
          </motion.article>
        ))}
      </section>

      {scored.some((p) => p.score?.impact) && <Build players={players} />}
      {scored.some((p) => p.score?.impact) && <Contribution players={players} />}
      <Positional players={players} sameRole={sameRole} />
      <Spread players={players} sameRole={sameRole} model={model} />
    </>
  )
}

/** Final score = positional half + contribution half, as stacked bars on one scale. */
function Build({ players }: { players: PlayerProfile[] }) {
  const max = Math.max(100, ...players.map((p) => p.score?.score ?? 0))
  return (
    <section className={styles.section} aria-labelledby="build">
      <h2 id="build">How each score is built</h2>
      <p className={styles.sectionLead}>
        Half positional excellence (how far he stands above others in his role) plus half contribution (goals added, compared
        across all positions).
      </p>
      <div className={styles.build}>
        {players.map((p, i) => {
          const s = p.score
          if (!s?.impact) return null
          const w = s.impact.weight
          const positional = (1 - w) * (s.positional ?? 0)
          const contribution = w * (s.impact.score ?? 0)
          return (
            <div key={p.player.id} className={styles.buildRow}>
              <span className={styles.buildName} style={{ color: PLAYER_COLORS[i] }}>
                {p.player.name}
              </span>
              <div className={styles.buildBar}>
                <motion.span
                  className={styles.buildPositional}
                  style={{ background: PLAYER_COLORS[i] }}
                  initial={{ width: 0 }}
                  animate={{ width: `${(Math.max(0, positional) / max) * 100}%` }}
                  transition={{ duration: 0.7, delay: 0.1 + i * 0.08, ease: [0.22, 1, 0.36, 1] }}
                  title={`${Math.round((1 - w) * 100)}% × ${formatScore(s.positional)} positional`}
                >
                  {formatScore(positional)}
                </motion.span>
                <motion.span
                  className={styles.buildContribution}
                  style={{ borderColor: PLAYER_COLORS[i] }}
                  initial={{ width: 0 }}
                  animate={{ width: `${(Math.max(0, contribution) / max) * 100}%` }}
                  transition={{ duration: 0.7, delay: 0.25 + i * 0.08, ease: [0.22, 1, 0.36, 1] }}
                  title={`${Math.round(w * 100)}% × ${formatScore(s.impact.score)} contribution`}
                >
                  {formatScore(contribution)}
                </motion.span>
              </div>
              <span className={`num ${styles.buildTotal}`}>{formatScore(s.score)}</span>
              <span className={styles.buildSum}>
                {Math.round((1 - w) * 100)}% × {formatScore(s.positional)} + {Math.round(w * 100)}% × {formatScore(s.impact.score)}
              </span>
            </div>
          )
        })}
      </div>
      <p className={styles.legend}>
        <span className={styles.legendPositional} /> positional half <span className={styles.legendContribution} /> contribution half
      </p>
    </section>
  )
}

/** Goals added per part of the game, players side by side. */
function Contribution({ players }: { players: PlayerProfile[] }) {
  const keys = (Object.keys(IMPACT_LABELS) as ImpactKey[]).filter((key) =>
    players.some((p) => p.score?.impact?.components.some((c) => c.key === key && c.goals !== null)),
  )
  const goals = (p: PlayerProfile, key: ImpactKey) => p.score?.impact?.components.find((c) => c.key === key)?.goals ?? null
  const rows: { label: string; value: (p: PlayerProfile) => number | null; strong?: boolean }[] = [
    ...keys.map((key) => ({ label: IMPACT_LABELS[key], value: (p: PlayerProfile) => goals(p, key) })),
    {
      label: 'Minus replacement level',
      value: (p) => {
        const replacement = p.score?.impact?.replacement
        return replacement === null || replacement === undefined ? null : -replacement
      },
    },
    { label: 'Goals above replacement', value: (p) => p.score?.impact?.above ?? null, strong: true },
  ]
  const scale = Math.max(1, ...rows.flatMap((r) => players.map((p) => Math.abs(r.value(p) ?? 0))))

  return (
    <section className={styles.section} aria-labelledby="goals">
      <h2 id="goals">Contribution in goals</h2>
      <p className={styles.sectionLead}>What each added to his team’s goal difference, per part of the game; bars from zero.</p>
      <div className={`card ${styles.goals}`}>
        {rows.map((row, ri) => (
          <div key={row.label} className={`${styles.goalRow} ${row.strong ? styles.goalTotal : ''}`}>
            <span className={styles.goalLabel}>{row.label}</span>
            <div className={styles.goalBars}>
              {players.map((p, i) => {
                const v = row.value(p)
                return (
                  <div key={p.player.id} className={styles.goalBar}>
                    <span className={styles.goalTrack}>
                      <span className={styles.goalZero} />
                      {v !== null && (
                        <motion.span
                          className={styles.goalFill}
                          style={{ background: PLAYER_COLORS[i], ...(v < 0 ? { right: '50%' } : { left: '50%' }) }}
                          initial={{ width: 0 }}
                          animate={{ width: `${(Math.abs(v) / scale) * 50}%` }}
                          transition={{ duration: 0.6, delay: 0.05 * ri + 0.03 * i, ease: [0.22, 1, 0.36, 1] }}
                        />
                      )}
                    </span>
                    <span className={`num ${styles.goalValue}`}>{v === null ? '–' : formatSigned(v)}</span>
                  </div>
                )
              })}
            </div>
          </div>
        ))}
      </div>
    </section>
  )
}

/** The measures of the role: a radar when all players share a role, otherwise each on his own. */
function Positional({ players, sameRole }: { players: PlayerProfile[]; sameRole: PlayerProfile['season']['role'] }) {
  const first = players[0]?.score
  if (!first) return null
  return (
    <section className={styles.section} aria-labelledby="positional">
      <h2 id="positional">Positional excellence</h2>
      {sameRole && first ? (
        <>
          <p className={styles.sectionLead}>
            All {POSITION_PLURAL[sameRole]}: the same measures, each scored against all {POSITION_PLURAL[sameRole]} (50 = their
            average, 10 points = one standard deviation). The weight says how much a measure counts; weight × points, summed, gives
            the combined score that is then compared with all {POSITION_PLURAL[sameRole]} again.
          </p>
          <div className={styles.positionalGrid}>
            <div className="card">
              <RadarChart
                axes={first.components.map((c) => c.label)}
                series={players.map((p, i) => ({
                  id: p.player.id,
                  label: p.player.name,
                  color: PLAYER_COLORS[i] ?? 'var(--accent)',
                  values: first.components.map((c) => p.score?.components.find((x) => x.key === c.key)?.score ?? null),
                }))}
              />
            </div>
            <div className={`card ${styles.tableCard}`}>
              <table className={styles.table}>
                <thead>
                  <tr>
                    <th>Measure</th>
                    <th className="num">Weight</th>
                    {players.map((p, i) => (
                      <th key={p.player.id} className="num" style={{ color: PLAYER_COLORS[i] }}>
                        {p.player.name.split(' ').slice(-1)[0]}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {first.components.map((c) => {
                    const values = players.map((p) => p.score?.components.find((x) => x.key === c.key)?.score ?? null)
                    const best = Math.max(...values.map((v) => v ?? -Infinity))
                    return (
                      <tr key={c.key}>
                        <td>{c.label}</td>
                        <td className="num muted">{Math.round(c.weight * 100)}%</td>
                        {values.map((v, i) => (
                          <td key={i} className={`num ${v === best && players.length > 1 ? styles.best : ''}`}>
                            {formatScore(v)}
                          </td>
                        ))}
                      </tr>
                    )
                  })}
                  <tr className={styles.sumRow}>
                    <td>Combined (weighted)</td>
                    <td />
                    {players.map((p) => (
                      <td key={p.player.id} className="num">
                        {formatScore(p.score?.composite ?? null)}
                      </td>
                    ))}
                  </tr>
                  <tr className={styles.sumRow}>
                    <td>Positional score</td>
                    <td />
                    {players.map((p) => (
                      <td key={p.player.id} className="num">
                        <strong>{formatScore(p.score?.positional ?? p.score?.score ?? null)}</strong>
                      </td>
                    ))}
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </>
      ) : (
        <>
          <p className={styles.sectionLead}>
            These players play different roles, so they are measured on different measures. Each positional score already says how
            far a player stands above others in his own role, on the same scale (50 = average), so those scores compare directly.
          </p>
          <div className={styles.ownRoles}>
            {players.map((p, i) => (
              <div key={p.player.id} className="card">
                <h3 style={{ color: PLAYER_COLORS[i] }}>
                  {p.player.name}{' '}
                  <span className="muted">· {p.score?.role ? POSITION_LABELS[p.score.role] : 'no role'}</span>
                </h3>
                <p className={`num ${styles.ownScore}`}>{formatScore(p.score?.positional ?? p.score?.score ?? null)}</p>
                <ul className={styles.ownList}>
                  {(p.score?.components ?? []).map((c) => (
                    <li key={c.key}>
                      <span>{c.label}</span>
                      <span className="muted">{Math.round(c.weight * 100)}%</span>
                      <span className="num">{formatScore(c.score)}</span>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
        </>
      )}
    </section>
  )
}

/** Where the compared players stand among everyone, on a part of the score of your choice. */
function Spread({ players, sameRole, model }: { players: PlayerProfile[]; sameRole: PlayerProfile['season']['role']; model?: string }) {
  const first = players[0]?.score
  const options: { key: string; label: string; part: DistributionPart; component?: string }[] = [
    { key: 'final', label: 'Final score', part: 'final' },
    ...(first?.impact ? [{ key: 'contribution', label: 'Contribution', part: 'contribution' as const }] : []),
    { key: 'positional', label: 'Positional', part: 'positional' },
    ...(sameRole && first ? first.components.map((c) => ({ key: c.key, label: c.label, part: 'component' as const, component: c.key })) : []),
  ]
  const [selected, setSelected] = useState('final')
  const option = options.find((o) => o.key === selected) ?? options[0]
  if (!first || !option) return null
  const position = option.part === 'component' || (option.part === 'positional' && sameRole) ? sameRole : null

  return (
    <section className={styles.section} aria-labelledby="spread">
      <h2 id="spread">Where they stand among everyone</h2>
      <p className={styles.sectionLead}>
        Every ranked player as a dot{position ? ` (all ${POSITION_PLURAL[position]})` : ''}; the compared players in their
        colours. This is how points arise: from the distance to the average of the group.
      </p>
      <div className={styles.options} role="group" aria-label="Part of the score">
        {options.map((o) => (
          <button key={o.key} type="button" aria-pressed={o.key === option.key} onClick={() => setSelected(o.key)}>
            {o.label}
          </button>
        ))}
      </div>
      <AnimatePresence mode="wait">
        <motion.div key={option.key} className="card" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} transition={{ duration: 0.2 }}>
          <div className={styles.spreadCard}>
            <DistributionPanel
              model={model ?? first.model_id}
              run={first.run_id}
              position={position}
              part={option.part}
              component={option.component}
              highlights={players.map((p, i) => ({ id: p.player.id, color: PLAYER_COLORS[i] ?? 'var(--accent)' }))}
            />
          </div>
        </motion.div>
      </AnimatePresence>
    </section>
  )
}
