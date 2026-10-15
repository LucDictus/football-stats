import { useQuery } from '@tanstack/react-query'
import { api } from '../api/client'
import type { DistributionPart, ModelMetric, PositionGroup } from '../api/types'
import { POSITION_PLURAL } from '../lib/model'
import { DistributionChart, type Highlight } from './DistributionChart'
import { ErrorState } from './ErrorState'
import styles from './DistributionPanel.module.css'

/** The value behind a metric's points, in its own unit. */
export function metricValueFormatter(metric?: ModelMetric): ((value: number) => string) | undefined {
  if (!metric) return undefined
  if (metric.mode === 'ratio' || metric.mode === 'rate') return (v) => `${(metric.mode === 'ratio' ? v * 100 : v).toFixed(1)}%`
  if (metric.mode === 'per90') return (v) => `${v.toFixed(2)} per 90`
  return (v) => v.toLocaleString('en-GB', { maximumFractionDigits: 0 })
}

/** Fetches one part of the score for a group and shows how it is spread, with players highlighted. */
export function DistributionPanel({
  model,
  run,
  position,
  part,
  component,
  metric,
  highlights,
}: {
  model?: string
  run?: number
  position?: PositionGroup | null
  part: DistributionPart
  component?: string
  metric?: string
  highlights: Highlight[]
}) {
  const query = useQuery({
    queryKey: ['distribution', model, run, position, part, component, metric],
    queryFn: () => api.distribution({ model, run, position: position ?? undefined, part, component, metric }),
  })
  if (query.error) return <ErrorState error={query.error} />
  if (!query.data) return <div className={styles.loading} aria-busy="true" />

  const { about, players } = query.data
  const group = position ? `ranked ${POSITION_PLURAL[position]}` : 'all ranked players'
  const formatValue =
    part === 'contribution' ? (v: number) => `${v >= 0 ? '+' : '−'}${Math.abs(v).toFixed(1)} goals above replacement` : metricValueFormatter(about.metric)

  return (
    <div className={styles.panel}>
      <p className={styles.explain}>
        <strong>{about.label}</strong> among {group}.{' '}
        {part === 'metric'
          ? 'Points come from his value (after the small-sample pull, square-root scale for counts and possession adjustment): 50 is the average, every 10 points is one standard deviation.'
          : part === 'contribution'
            ? 'Points from goals added above a replacement-level player, compared across all positions: 50 is the average.'
            : about.scale === 'blend'
              ? 'The final score is half positional excellence and half contribution, each on a scale where 50 is the average.'
              : '50 is the average, every 10 points is one standard deviation.'}
        {about.weight !== undefined && ` Counts for ${Math.round(about.weight * 100)}% of the positional score.`}
      </p>
      <DistributionChart players={players} highlights={highlights} formatValue={formatValue} label={`${about.label} among ${group}`} />
    </div>
  )
}
