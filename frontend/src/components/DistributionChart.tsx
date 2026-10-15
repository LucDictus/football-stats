import { motion } from 'motion/react'
import { useMemo, useState } from 'react'
import { Link } from 'react-router'
import type { DistributionPoint } from '../api/types'
import { formatScore } from '../lib/format'
import styles from './DistributionChart.module.css'

export interface Highlight {
  id: number
  color: string
}

const WIDTH = 720
const MARGIN = { left: 16, right: 16, top: 34, bottom: 34 }

/**
 * Every player of a group as a dot on the points scale (50 = the group's average, 10 points = one
 * standard deviation), laid out as a beeswarm so dots do not overlap. Highlighted players are
 * coloured and named; hovering a dot shows the player and, when given, the value behind his points.
 */
export function DistributionChart({
  players,
  highlights = [],
  formatValue,
  label,
}: {
  players: DistributionPoint[]
  highlights?: Highlight[]
  /** How to show the value behind the points (e.g. "0.42 per 90"); omitted: points only. */
  formatValue?: (value: number) => string
  /** What the points are of, for the accessible description. */
  label: string
}) {
  const [hover, setHover] = useState<DistributionPoint | null>(null)
  const colorOf = useMemo(() => new Map(highlights.map((h) => [h.id, h.color])), [highlights])

  const { x, ticks, dots, height } = useMemo(() => layout(players), [players])
  const highlighted = dots.filter((d) => colorOf.has(d.player.id))

  return (
    <figure className={styles.figure}>
      <svg
        viewBox={`0 0 ${WIDTH} ${height}`}
        className={styles.svg}
        role="img"
        aria-label={`${label}: ${players.length} players. ${highlighted.map((d) => `${d.player.name} ${formatScore(d.player.points)}`).join(', ')}`}
        onMouseLeave={() => setHover(null)}
      >
        {/* One standard deviation either side of the average */}
        <rect x={x(40)} y={MARGIN.top - 18} width={x(60) - x(40)} height={height - MARGIN.top - MARGIN.bottom + 36} className={styles.band} />
        <line x1={x(50)} x2={x(50)} y1={MARGIN.top - 18} y2={height - MARGIN.bottom + 18} className={styles.average} />
        <text x={x(50)} y={MARGIN.top - 22} className={styles.averageLabel}>
          average
        </text>
        {ticks.map((t) => (
          <g key={t} transform={`translate(${x(t)}, ${height - MARGIN.bottom + 18})`}>
            <line y1={-4} y2={0} className={styles.tick} />
            <text y={14} className={styles.tickLabel}>
              {t}
            </text>
          </g>
        ))}
        {dots.map((d) => {
          const color = colorOf.get(d.player.id)
          return (
            <motion.circle
              key={d.player.id}
              cx={d.cx}
              cy={d.cy}
              r={color ? 6.5 : d.r}
              // Positions are attributes (always right); only opacity and scale animate.
              initial={{ opacity: 0, scale: 0.4 }}
              animate={{ opacity: 1, scale: 1 }}
              style={{ transformBox: 'fill-box', transformOrigin: 'center', ...(color ? { fill: color } : {}) }}
              transition={{ duration: 0.45, delay: Math.min(0.5, (d.cx / WIDTH) * 0.5), ease: [0.22, 1, 0.36, 1] }}
              className={color ? styles.highlight : styles.dot}
              onMouseEnter={() => setHover(d.player)}
            />
          )
        })}
        {highlighted.map((d) => (
          <motion.text
            key={`label-${d.player.id}`}
            x={d.cx}
            y={d.cy - 11}
            className={styles.name}
            style={{ fill: colorOf.get(d.player.id) }}
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ delay: 0.5 }}
          >
            {d.player.name.split(' ').slice(-1)[0]}
          </motion.text>
        ))}
      </svg>
      <figcaption className={styles.caption}>
        {hover ? (
          <>
            <Link to={`/players/${hover.id}`}>{hover.name}</Link>
            {hover.team && <span className="muted"> · {hover.team}</span>}
            {' · '}
            <strong className="num">{formatScore(hover.points)}</strong> points
            {formatValue && hover.value !== null && <span className="muted"> · {formatValue(hover.value)}</span>}
            {hover.rank !== null && <span className="muted"> · #{hover.rank} overall</span>}
          </>
        ) : (
          <span className="muted">
            {players.length} players. Shaded: within one standard deviation of the average (about two in three players). Hover a
            dot for the player.
          </span>
        )}
      </figcaption>
    </figure>
  )
}

interface Dot {
  player: DistributionPoint
  cx: number
  cy: number
  r: number
}

/** Beeswarm: dots in order of points, each placed at the free height nearest the middle line. */
function layout(players: DistributionPoint[]) {
  const points = players.map((p) => p.points)
  const lo = Math.min(20, Math.floor((Math.min(...points, 50) - 2) / 10) * 10)
  const hi = Math.max(80, Math.ceil((Math.max(...points, 50) + 2) / 10) * 10)
  const x = (v: number) => MARGIN.left + ((v - lo) / (hi - lo)) * (WIDTH - MARGIN.left - MARGIN.right)
  const ticks = Array.from({ length: (hi - lo) / 10 + 1 }, (_, i) => lo + i * 10)

  const r = players.length > 400 ? 2.6 : players.length > 200 ? 3.2 : 4
  const gap = r * 2 + 0.6
  const placed: { cx: number; cy: number }[] = []
  const dots: Dot[] = []
  for (const player of [...players].sort((a, b) => a.points - b.points)) {
    const cx = x(player.points)
    let offset = 0
    for (let step = 0; step < 400; step++) {
      // 0, +1, −1, +2, −2 … rows from the middle
      offset = (step % 2 === 0 ? 1 : -1) * Math.ceil(step / 2) * gap * 0.9
      const free = placed.every((p) => Math.abs(p.cx - cx) >= gap || Math.abs(p.cy - offset) >= gap)
      if (free) break
    }
    placed.push({ cx, cy: offset })
    dots.push({ player, cx, cy: offset, r })
  }
  const spread = Math.max(gap * 3, ...dots.map((d) => Math.abs(d.cy)))
  const height = MARGIN.top + MARGIN.bottom + spread * 2
  const middle = MARGIN.top + spread

  return { x, ticks, dots: dots.map((d) => ({ ...d, cy: middle + d.cy })), height }
}
