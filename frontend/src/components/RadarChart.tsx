import { motion } from 'motion/react'
import styles from './RadarChart.module.css'

export interface RadarSeries {
  id: number
  label: string
  color: string
  /** Points per axis, in axis order (null: not measured). */
  values: (number | null)[]
}

const SIZE = 420
const CENTRE = SIZE / 2
const RADIUS = 150
/** The scale runs to 100 (exceptional); 50 is the role's average, drawn as a stronger ring. */
const MAX = 100

/** Measures as axes around a circle, one shape per player; the ring at 50 is the role's average. */
export function RadarChart({ axes, series }: { axes: string[]; series: RadarSeries[] }) {
  const angle = (i: number) => (Math.PI * 2 * i) / axes.length - Math.PI / 2
  const point = (i: number, value: number) => {
    const r = (Math.max(0, Math.min(MAX, value)) / MAX) * RADIUS
    return [CENTRE + r * Math.cos(angle(i)), CENTRE + r * Math.sin(angle(i))] as const
  }
  const ring = (value: number) => axes.map((_, i) => point(i, value).join(',')).join(' ')

  return (
    <svg
      viewBox={`0 0 ${SIZE} ${SIZE}`}
      className={styles.svg}
      role="img"
      aria-label={series.map((s) => `${s.label}: ${axes.map((a, i) => `${a} ${s.values[i] === null ? 'not measured' : Math.round(s.values[i] ?? 0)}`).join(', ')}`).join('. ')}
    >
      {[25, 75, 100].map((v) => (
        <polygon key={v} points={ring(v)} className={styles.ring} />
      ))}
      <polygon points={ring(50)} className={styles.average} />
      {axes.map((axis, i) => {
        const [x, y] = point(i, MAX)
        const [lx, ly] = point(i, MAX + 18)
        const anchor = Math.abs(lx - CENTRE) < 8 ? 'middle' : lx > CENTRE ? 'start' : 'end'
        return (
          <g key={axis}>
            <line x1={CENTRE} y1={CENTRE} x2={x} y2={y} className={styles.axis} />
            <text x={lx} y={ly} textAnchor={anchor} dominantBaseline="middle" className={styles.label}>
              {axis.length > 24 ? `${axis.slice(0, 22)}…` : axis}
              <title>{axis}</title>
            </text>
          </g>
        )
      })}
      <text x={CENTRE} y={CENTRE + (50 / MAX) * RADIUS + 12} textAnchor="middle" className={styles.ringLabel}>
        50 = average
      </text>
      {series.map((s, si) => {
        const shape = s.values.map((v, i) => point(i, v ?? 0).join(',')).join(' ')
        return (
          <motion.g
            key={s.id}
            style={{ transformOrigin: `${CENTRE}px ${CENTRE}px` }}
            initial={{ scale: 0.2, opacity: 0 }}
            animate={{ scale: 1, opacity: 1 }}
            transition={{ duration: 0.6, delay: 0.1 + si * 0.12, ease: [0.22, 1, 0.36, 1] }}
          >
            <polygon points={shape} className={styles.shape} style={{ fill: s.color, stroke: s.color }} />
            {s.values.map((v, i) => {
              if (v === null) return null
              const [x, y] = point(i, v)
              return <circle key={i} cx={x} cy={y} r={3.5} style={{ fill: s.color }} />
            })}
          </motion.g>
        )
      })}
    </svg>
  )
}
