import { motion } from 'motion/react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router'
import type { StatPoint } from '../api/types'
import { POSITION_LABELS } from '../lib/format'
import { LINE_COLORS, niceScale } from '../lib/stats'
import styles from './ScatterChart.module.css'

export interface ScatterHighlight {
  id: number
  color: string
}

/** Drawn at the width it is shown at (text stays readable on a phone), 720 until measured. */
const DEFAULT_WIDTH = 720
const MARGIN = { left: 52, right: 16, top: 20, bottom: 48 }
/** Besides the highlighted players, this many of the most extreme players get a name. */
const LABEL_EXTREMES = 6

/**
 * Two statistics against each other: one dot per player, coloured by line, with dashed lines at
 * the average of the players shown (so the top-right quarter is "above average on both").
 * Highlighted players are larger and named; so are the few most extreme players. Clicking a dot
 * calls onPick (e.g. add the player to the comparison).
 */
export function ScatterChart({
  players,
  xLabel,
  yLabel,
  format,
  highlights = [],
  onPick,
}: {
  players: StatPoint[]
  xLabel: string
  yLabel: string
  format: (value: number) => string
  highlights?: ScatterHighlight[]
  onPick?: (player: StatPoint) => void
}) {
  const [hover, setHover] = useState<StatPoint | null>(null)
  const figure = useRef<HTMLElement>(null)
  const [WIDTH, setWidth] = useState(DEFAULT_WIDTH)
  useEffect(() => {
    const el = figure.current
    if (!el) return
    const observer = new ResizeObserver(([entry]) => entry && setWidth(Math.max(280, Math.round(entry.contentRect.width))))
    observer.observe(el)
    return () => observer.disconnect()
  }, [])
  const HEIGHT = Math.round(Math.min(460, Math.max(300, WIDTH * 0.64)))
  const colorOf = useMemo(() => new Map(highlights.map((h) => [h.id, h.color])), [highlights])
  const chart = useMemo(() => layout(players, WIDTH, HEIGHT), [players, WIDTH, HEIGHT])
  const { x, y, xTicks, yTicks, mean } = chart
  const labelled = useMemo(() => {
    const extremes = new Set(chart.extremes)
    return players.filter((p) => colorOf.has(p.id) || extremes.has(p.id))
  }, [players, chart.extremes, colorOf])
  // Highlighted players are drawn last, so they sit on top.
  const ordered = useMemo(() => [...players].sort((a, b) => Number(colorOf.has(a.id)) - Number(colorOf.has(b.id))), [players, colorOf])

  return (
    <figure className={styles.figure} ref={figure}>
      <svg
        viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
        className={styles.svg}
        role="img"
        aria-label={`${yLabel} against ${xLabel}: ${players.length} players.`}
        onMouseLeave={() => setHover(null)}
      >
        {xTicks.map((t) => (
          <g key={`x${t}`}>
            <line x1={x(t)} x2={x(t)} y1={MARGIN.top} y2={HEIGHT - MARGIN.bottom} className={styles.grid} />
            <text x={x(t)} y={HEIGHT - MARGIN.bottom + 16} className={styles.tickX}>
              {format(t)}
            </text>
          </g>
        ))}
        {yTicks.map((t) => (
          <g key={`y${t}`}>
            <line x1={MARGIN.left} x2={WIDTH - MARGIN.right} y1={y(t)} y2={y(t)} className={styles.grid} />
            <text x={MARGIN.left - 8} y={y(t)} className={styles.tickY}>
              {format(t)}
            </text>
          </g>
        ))}
        {mean && (
          <>
            <line x1={x(mean.x)} x2={x(mean.x)} y1={MARGIN.top} y2={HEIGHT - MARGIN.bottom} className={styles.average} />
            <line x1={MARGIN.left} x2={WIDTH - MARGIN.right} y1={y(mean.y)} y2={y(mean.y)} className={styles.average} />
            <text x={WIDTH - MARGIN.right - 4} y={MARGIN.top + 12} className={styles.quadrant}>
              above average on both
            </text>
          </>
        )}
        <text x={(MARGIN.left + WIDTH - MARGIN.right) / 2} y={HEIGHT - 8} className={styles.axisLabel}>
          {xLabel} →
        </text>
        <text
          transform={`translate(14, ${(MARGIN.top + HEIGHT - MARGIN.bottom) / 2}) rotate(-90)`}
          className={styles.axisLabel}
        >
          {yLabel} →
        </text>

        {ordered.map((p) => {
          const color = colorOf.get(p.id)
          return (
            <motion.circle
              key={p.id}
              cx={x(p.x)}
              cy={y(p.y)}
              r={color ? 7 : chart.r}
              initial={{ opacity: 0, scale: 0.3 }}
              animate={{ opacity: 1, scale: 1 }}
              transition={{ duration: 0.4, delay: Math.min(0.4, ((x(p.x) - MARGIN.left) / WIDTH) * 0.4) }}
              style={{
                transformBox: 'fill-box',
                transformOrigin: 'center',
                fill: color ?? (p.line ? LINE_COLORS[p.line] : 'var(--muted)'),
              }}
              className={color ? styles.highlight : styles.dot}
              onMouseEnter={() => setHover(p)}
              onClick={() => onPick?.(p)}
            />
          )
        })}
        {labelled.map((p) => (
          <text
            key={`label-${p.id}`}
            x={x(p.x)}
            y={y(p.y) - (colorOf.has(p.id) ? 11 : 7)}
            className={colorOf.has(p.id) ? styles.name : styles.extreme}
            style={colorOf.has(p.id) ? { fill: colorOf.get(p.id) } : undefined}
          >
            {p.name.split(' ').slice(-1)[0]}
          </text>
        ))}
      </svg>
      <figcaption className={styles.caption}>
        {hover ? (
          <>
            <Link to={`/players/${hover.id}`}>{hover.name}</Link>
            <span className="muted">
              {' '}
              · {hover.role ? POSITION_LABELS[hover.role] : 'no role'}
              {hover.team && ` · ${hover.team}`}
            </span>
            {' · '}
            {xLabel} <strong className="num">{format(hover.x)}</strong>
            {' · '}
            {yLabel} <strong className="num">{format(hover.y)}</strong>
            {hover.rank !== null && <span className="muted"> · #{hover.rank} overall</span>}
          </>
        ) : (
          <span className="muted">
            {players.length} players{chart.r_xy !== null && <> · correlation r = {chart.r_xy.toFixed(2)}</>}. Dashed lines: the
            average of the players shown. Hover a dot for the player{onPick ? ', click to add him to the comparison' : ''}.
          </span>
        )}
      </figcaption>
    </figure>
  )
}

/** The values' range; it starts at zero when the values lie close to it (counts per 90), not for scores. */
function extent(values: number[]): [number, number] {
  const min = Math.min(...values)
  const max = Math.max(...values)
  return min >= 0 && min <= max * 0.4 ? [0, max] : [min, max]
}

/** Scales with round ticks, the mean, the correlation and the most extreme players. */
function layout(players: StatPoint[], WIDTH: number, HEIGHT: number) {
  const xs = players.map((p) => p.x)
  const ys = players.map((p) => p.y)
  const [x0, x1, xTicks] = niceScale(...extent(xs))
  const [y0, y1, yTicks] = niceScale(...extent(ys))
  const x = (v: number) => MARGIN.left + ((v - x0) / (x1 - x0 || 1)) * (WIDTH - MARGIN.left - MARGIN.right)
  const y = (v: number) => HEIGHT - MARGIN.bottom - ((v - y0) / (y1 - y0 || 1)) * (HEIGHT - MARGIN.top - MARGIN.bottom)

  const n = players.length
  const mean = n ? { x: xs.reduce((a, b) => a + b, 0) / n, y: ys.reduce((a, b) => a + b, 0) / n } : null
  let r_xy: number | null = null
  let extremes: number[] = []
  if (mean && n > 2) {
    const sx = Math.sqrt(xs.reduce((a, v) => a + (v - mean.x) ** 2, 0) / n)
    const sy = Math.sqrt(ys.reduce((a, v) => a + (v - mean.y) ** 2, 0) / n)
    if (sx > 0 && sy > 0) {
      r_xy = players.reduce((a, p) => a + (p.x - mean.x) * (p.y - mean.y), 0) / (n * sx * sy)
      // Furthest from the middle in standard units, toward the high end of either axis.
      extremes = [...players]
        .sort((a, b) => Math.hypot((b.x - mean.x) / sx, (b.y - mean.y) / sy) - Math.hypot((a.x - mean.x) / sx, (a.y - mean.y) / sy))
        .filter((p) => p.x > mean.x || p.y > mean.y)
        .slice(0, LABEL_EXTREMES)
        .map((p) => p.id)
    }
  }
  const r = (n > 1000 ? 2.6 : n > 300 ? 3.2 : 4.2) * (WIDTH < 500 ? 0.8 : 1)

  return { x, y, xTicks, yTicks, mean, r_xy, extremes, r }
}
