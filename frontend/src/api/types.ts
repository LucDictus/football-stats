// Shapes of the Symfony JSON API (src/Api/ApiPresenter.php).

export type PositionGroup = 'GK' | 'DF' | 'MF' | 'FW'
export type TrustStatus = 'ok' | 'partial' | 'suspect' | 'missing'
export type Provider = 'FBref' | 'Understat' | 'SofaScore' | 'derived'

export interface ModelMetric {
  key: string
  label: string
  fields: string[]
  mode: 'total' | 'per90'
  weight: number
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
  components: ModelComponent[]
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
  percentile: number | null
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
  confidence: number
  components: BreakdownComponent[]
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
