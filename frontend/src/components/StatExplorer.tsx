import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { api } from '../api/client'
import type { PositionGroup, StatAxis, StatLine, StatPoint } from '../api/types'
import { POSITION_FILTER_LABELS } from '../lib/format'
import { ErrorState } from './ErrorState'
import { formatStat, LINE_COLORS } from '../lib/stats'
import { ScatterChart, type ScatterHighlight } from './ScatterChart'
import styles from './StatExplorer.module.css'

const LINES: StatLine[] = ['FW', 'MF', 'DF', 'GK']
const ROLES: PositionGroup[] = ['ST', 'W', 'AM', 'CM', 'DM', 'FB', 'CB', 'GK']
const LINE_OF: Record<string, StatLine> = { ST: 'FW', W: 'FW', AM: 'MF', CM: 'MF', DM: 'MF', FB: 'DF', CB: 'DF', GK: 'GK' }
const LINE_LABELS: Record<StatLine, string> = { FW: 'Forwards', MF: 'Midfield', DF: 'Defence', GK: 'Goalkeepers' }
const MIN_MINUTES = [450, 900, 1800]
const PAGE = 25

type SortKey = 'x' | 'y' | 'minutes' | 'name'

/**
 * Any statistic against any other, for the players of the roles or lines you choose: a scatter
 * chart and a sortable table. Highlights the compared players; clicking a dot adds one.
 */
export function StatExplorer({
  model,
  highlights,
  onPick,
}: {
  model?: string
  highlights: (ScatterHighlight & { name?: string })[]
  onPick?: (id: number) => void
}) {
  const [params, setParams] = useSearchParams()
  const x = params.get('sx') ?? 'np_xg'
  const y = params.get('sy') ?? 'xa'
  const per = params.get('sper') === 'total' ? 'total' : '90'
  const minMinutes = MIN_MINUTES.includes(Number(params.get('smin'))) ? Number(params.get('smin')) : 900
  const rolesParam = params.get('sroles') ?? ''
  const roles = useMemo(() => new Set(rolesParam.split(',').filter((r): r is PositionGroup => ROLES.includes(r as PositionGroup))), [rolesParam])
  const update = (changes: Record<string, string | null>) => {
    const p = new URLSearchParams(params)
    for (const [key, value] of Object.entries(changes)) {
      if (value === null || value === '') p.delete(key)
      else p.set(key, value)
    }
    setParams(p, { replace: true, preventScrollReset: true })
  }
  const setRoles = (next: Set<PositionGroup>) => update({ sroles: ROLES.filter((r) => next.has(r)).join(',') || null })

  const query = useQuery({
    queryKey: ['stats', model, x, y, per, minMinutes],
    queryFn: () => api.stats({ model, x, y, per, min_minutes: minMinutes }),
    placeholderData: keepPreviousData,
  })
  const data = query.data
  const axes = data?.axes ?? []
  const axis = (key: string) => axes.find((a) => a.key === key)
  const label = (a: StatAxis | undefined, key: string) => (a ? `${a.label}${a.per90 && per === '90' ? ' per 90' : ''}` : key)
  const xLabel = label(axis(x), x)
  const yLabel = label(axis(y), y)

  const shown = useMemo(() => (data?.players ?? []).filter((p) => roles.size === 0 || (p.role !== null && roles.has(p.role))), [data, roles])
  const toggleLine = (line: StatLine) => {
    const lineRoles = ROLES.filter((r) => LINE_OF[r] === line)
    const all = lineRoles.every((r) => roles.has(r))
    const next = new Set(roles)
    for (const r of lineRoles) {
      if (all) next.delete(r)
      else next.add(r)
    }
    setRoles(next)
  }
  const toggleRole = (role: PositionGroup) => {
    const next = new Set(roles)
    if (next.has(role)) next.delete(role)
    else next.add(role)
    setRoles(next)
  }

  const unknown = Object.entries(data?.unknown ?? {}).reduce((sum, [role, n]) => sum + (roles.size === 0 || roles.has(role as PositionGroup) ? n : 0), 0)
  const anyPer90 = !!(axis(x)?.per90 || axis(y)?.per90)
  const goalkeeping = axis(x)?.goalkeeper || axis(y)?.goalkeeper

  return (
    <section className={styles.section} aria-labelledby="explorer">
      <h2 id="explorer">Stat explorer</h2>
      <p className={styles.lead}>
        Any statistic against any other, for the players you choose. Every dot is a player with {minMinutes}+ league minutes;
        colours are lines. Per-90 values use the minutes of the provider that supplied the number, and a value we do not know is
        left out, never counted as zero.
      </p>

      <div className={`card ${styles.controls}`}>
        <div className={styles.axes}>
          <AxisSelect id="explorer-x" label="Across (x)" value={x} axes={axes} onChange={(v) => update({ sx: v })} />
          <button type="button" className={styles.swap} onClick={() => update({ sx: y, sy: x })} aria-label="Swap the axes" title="Swap the axes">
            ⇄
          </button>
          <AxisSelect id="explorer-y" label="Up (y)" value={y} axes={axes} onChange={(v) => update({ sy: v })} />
        </div>

        <div className={styles.filterRow} role="group" aria-label="Lines">
          <span className={styles.filterLabel}>Lines</span>
          <button type="button" aria-pressed={roles.size === 0} onClick={() => setRoles(new Set())}>
            All
          </button>
          {LINES.map((line) => {
            const lineRoles = ROLES.filter((r) => LINE_OF[r] === line)
            return (
              <button key={line} type="button" aria-pressed={lineRoles.every((r) => roles.has(r))} onClick={() => toggleLine(line)}>
                <span className={styles.swatch} style={{ background: LINE_COLORS[line] }} />
                {LINE_LABELS[line]}
              </button>
            )
          })}
        </div>
        <div className={styles.filterRow} role="group" aria-label="Roles">
          <span className={styles.filterLabel}>Roles</span>
          {ROLES.map((role) => (
            <button key={role} type="button" aria-pressed={roles.has(role)} onClick={() => toggleRole(role)}>
              {POSITION_FILTER_LABELS[role]}
            </button>
          ))}
        </div>
        <div className={styles.filterRow}>
          {anyPer90 && (
            <span role="group" aria-label="Per 90 or season total" className={styles.segment}>
              <button type="button" aria-pressed={per === '90'} onClick={() => update({ sper: null })}>
                Per 90
              </button>
              <button type="button" aria-pressed={per === 'total'} onClick={() => update({ sper: 'total' })}>
                Season total
              </button>
            </span>
          )}
          <span role="group" aria-label="Minimum minutes" className={styles.segment}>
            {MIN_MINUTES.map((m) => (
              <button key={m} type="button" aria-pressed={m === minMinutes} onClick={() => update({ smin: m === 900 ? null : String(m) })}>
                {m}+ min
              </button>
            ))}
          </span>
        </div>
      </div>

      {query.error ? (
        <ErrorState error={query.error} />
      ) : !data ? (
        <div className={`card ${styles.loading}`}>Loading…</div>
      ) : (
        <>
          <div className={`card ${styles.chartCard}`} style={{ opacity: query.isPlaceholderData ? 0.6 : 1 }}>
            {shown.length ? (
              <ScatterChart
                players={shown}
                xLabel={xLabel}
                yLabel={yLabel}
                format={formatStat}
                highlights={highlights}
                onPick={onPick ? (p) => onPick(p.id) : undefined}
              />
            ) : (
              <p className="muted">No player in this selection has both values.</p>
            )}
            <p className={styles.note}>
              {unknown > 0 && (
                <>
                  {unknown} {unknown === 1 ? 'player' : 'players'} in this selection with {minMinutes}+ minutes {unknown === 1 ? 'is' : 'are'}{' '}
                  left out because one of the two values is unknown.{' '}
                </>
              )}
              {goalkeeping && 'Goalkeeping statistics exist for goalkeepers only. '}
              {[axis(x), axis(y)].filter((a) => a?.note).map((a) => `${a?.label}: ${a?.note}.`).join(' ')}
            </p>
          </div>
          <StatTable players={shown} xLabel={xLabel} yLabel={yLabel} highlights={highlights} />
        </>
      )}
    </section>
  )
}

function AxisSelect({ id, label, value, axes, onChange }: { id: string; label: string; value: string; axes: StatAxis[]; onChange: (v: string) => void }) {
  const categories = [...new Set(axes.map((a) => a.category))]
  return (
    <label className={styles.axis} htmlFor={id}>
      <span className={styles.filterLabel}>{label}</span>
      <select id={id} value={value} onChange={(e) => onChange(e.target.value)}>
        {!axes.length && <option value={value}>{value}</option>}
        {categories.map((c) => (
          <optgroup key={c} label={c}>
            {axes
              .filter((a) => a.category === c)
              .map((a) => (
                <option key={a.key} value={a.key}>
                  {a.label}
                </option>
              ))}
          </optgroup>
        ))}
      </select>
    </label>
  )
}

/** The same players as the chart, as a table you can sort by either statistic. */
function StatTable({ players, xLabel, yLabel, highlights }: { players: StatPoint[]; xLabel: string; yLabel: string; highlights: ScatterHighlight[] }) {
  const [sort, setSort] = useState<{ key: SortKey; desc: boolean }>({ key: 'y', desc: true })
  const [all, setAll] = useState(false)
  const colorOf = new Map(highlights.map((h) => [h.id, h.color]))
  const sorted = useMemo(() => {
    const value = (p: StatPoint) => (sort.key === 'name' ? p.name : p[sort.key])
    return [...players].sort((a, b) => {
      const va = value(a)
      const vb = value(b)
      const c = typeof va === 'string' ? va.localeCompare(vb as string) : (va as number) - (vb as number)
      return (sort.desc ? -c : c) || a.id - b.id
    })
  }, [players, sort])
  const position = new Map(sorted.map((p, i) => [p.id, i + 1]))
  // The compared players always show, even below the cut.
  const rows = all ? sorted : sorted.filter((p, i) => i < PAGE || colorOf.has(p.id))

  const header = (key: SortKey, text: string, className = 'num') => (
    <th className={className} aria-sort={sort.key === key ? (sort.desc ? 'descending' : 'ascending') : undefined}>
      <button type="button" onClick={() => setSort({ key, desc: sort.key === key ? !sort.desc : key !== 'name' })}>
        {text}
        {sort.key === key ? (sort.desc ? ' ↓' : ' ↑') : ''}
      </button>
    </th>
  )

  return (
    <div className={`card ${styles.tableCard}`}>
      <table className={styles.table}>
        <thead>
          <tr>
            <th className="num">#</th>
            {header('name', 'Player', '')}
            <th>Role</th>
            {header('minutes', 'Min', `num ${styles.minutes}`)}
            {header('x', xLabel)}
            {header('y', yLabel)}
          </tr>
        </thead>
        <tbody>
          {rows.map((p) => {
            const color = colorOf.get(p.id)
            return (
              <tr key={p.id} className={color ? styles.highlighted : undefined} style={color ? { boxShadow: `inset 3px 0 0 ${color}` } : undefined}>
                <td className="num muted">{position.get(p.id)}</td>
                <td>
                  <Link to={`/players/${p.id}`} style={color ? { color } : undefined}>
                    {p.name}
                  </Link>
                  {p.team && <span className={`muted ${styles.team}`}> {p.team}</span>}
                </td>
                <td>
                  {p.line && <span className={styles.swatch} style={{ background: LINE_COLORS[p.line] }} />}
                  {p.role ?? '–'}
                </td>
                <td className={`num muted ${styles.minutes}`}>{Math.round(p.minutes).toLocaleString('en-GB')}</td>
                <td className="num">{formatStat(p.x)}</td>
                <td className="num">{formatStat(p.y)}</td>
              </tr>
            )
          })}
        </tbody>
      </table>
      {sorted.length > PAGE && (
        <button type="button" className={styles.more} onClick={() => setAll(!all)}>
          {all ? 'Show the top 25' : `Show all ${sorted.length} players`}
        </button>
      )}
    </div>
  )
}
