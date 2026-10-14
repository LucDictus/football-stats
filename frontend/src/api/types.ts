// Shapes of the Symfony JSON API (src/Api/ApiPresenter.php).

export type PositionGroup = 'GK' | 'DF' | 'MF' | 'FW'
export type TrustStatus = 'ok' | 'partial' | 'suspect' | 'missing'
export type Provider = 'FBref' | 'Understat' | 'SofaScore' | 'derived'

export interface ModelMetric {
  key: string
  label: string
  fields: string[]
  mode: 'total' | 'per90' | 'rate'
  weight: number
  lower_is_better?: boolean
  shrinkage?: number
  sample_fields?: string[]
  transform?: 'sqrt'
  possession_adjusted?: boolean
}

export interface ModelComponent {
  key: string
  label: string
  description: string
  weight: number
  metrics: ModelMetric[]
}

export interface ScoringModel {
  id: string
  key: string
  version: string
  label: string
  description: string
  scope: 'league' | 'CL'
  min_minutes: number
  min_confidence: number
  position_groups: PositionGroup[]
  /** Shared components (models without roles). */
  components: ModelComponent[]
  /** Role models: components per position group, each normalised within its role. */
  roles?: Partial<Record<PositionGroup, ModelComponent[]>>
  normalization?: 'percentile' | 'standard'
  reference?: string
  z_cap?: number
  /** Blended models: contribution in goals and its weight in the final score. */
  impact?: {
    shooter_share: number
    passer_share: number
    build_up_share: number
    weight: number
    /** Absent: shared by the sum of defensive actions. */
    defence_sharing?: 'per_type'
    /** Goals an outfield player loses per error leading to a goal (absent: not counted). */
    error_goals?: number
  }
}

export interface ScoreRun {
  id: number
  model: ScoringModel
  season: string
  created_at: string
  batch_ids: number[]
  ranked: number
  unranked: number
}

export interface RankingRow {
  rank: number | null
  score: number | null
  confidence: number
  player: { id: number; name: string }
  team: string | null
  position: PositionGroup | null
  minutes: number
  components: Record<string, number | null>
  /** Blended models only. */
  positional: number | null
  impact_goals: number | null
}

export interface RankingResponse {
  run: ScoreRun
  total: number
  limit: number
  offset: number
  rows: RankingRow[]
}

export interface SearchResult {
  id: number
  name: string
  position: PositionGroup | null
  team: string | null
}

export interface StatField {
  key: string
  label: string
  category: string
  value: number | null
  per90: number | null
  origin: Provider | null
  status: TrustStatus | null
}

export interface ProviderCheck {
  origin: Provider
  status: TrustStatus
  minutes: number | null
  reason: string | null
}

export interface ScopeStats {
  minutes: number
  batch: { id: number; source: string; imported_at: string }
  providers: ProviderCheck[]
  fields: StatField[]
}

export interface BreakdownMetric extends ModelMetric {
  value: number | null
  /** Multiplier for the team's possession (possession-adjusted metrics only). */
  possession_factor: number | null
  /** After the possession factor and shrinkage toward the role average; this is what was normalised. */
  adjusted: number | null
  /** 0–100 within the comparison group: a percentile, or a T-score (50 = average) for standard models. */
  normalised: number | null
}

export interface BreakdownComponent {
  key: string
  label: string
  description: string
  weight: number
  score: number | null
  coverage: number
  metrics: BreakdownMetric[]
}

export interface ScoreBreakdown {
  run_id: number
  model_id: string
  rank: number | null
  ranked_players: number
  score: number | null
  composite: number | null
  role: PositionGroup | null
  normalization: 'percentile' | 'standard'
  confidence: number
  components: BreakdownComponent[]
  positional: number | null
  impact: ImpactBreakdown | null
}

export type ImpactKey = 'shooting' | 'finishing' | 'creation' | 'build_up' | 'goalkeeping' | 'defence' | 'errors'

export interface ImpactBreakdown {
  components: { key: ImpactKey; goals: number | null; evidence: 'strong' | 'moderate' | 'weak' }[]
  total: number | null
  replacement: number | null
  above: number | null
  /** Where npxG and xA come from: Understat, or SofaScore when Understat has no record of him. */
  attack_source: 'Understat' | 'SofaScore' | null
  /** SofaScore fallback only: scale factors to Understat's level, and xGBuildup per accurate pass (null: no build-up estimate). */
  calibration: { xg: number; xa: number; build_up_per_pass: number | null } | null
  score: number | null
  weight: number
}

export interface PlayerProfile {
  player: { id: number; name: string; birth_year: number | null }
  season: {
    label: string
    positions: string | null
    position_group: PositionGroup | null
    team: string | null
    stints: {
      team: string
      competition: { code: string; name: string }
      appearances: number | null
      starts: number | null
      minutes: number | null
    }[]
  }
  stats: { league: ScopeStats | null; CL: ScopeStats | null }
  score: ScoreBreakdown | null
}
