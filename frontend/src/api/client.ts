import type { DistributionPart, DistributionResponse, PlayerProfile, RankingResponse, ScoringModel, SearchResult, StatsResponse } from './types'

export class ApiError extends Error {
  readonly status: number

  constructor(status: number, message: string) {
    super(message)
    this.status = status
  }
}

async function get<T>(path: string, params: Record<string, string | number | boolean | undefined> = {}): Promise<T> {
  const query = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== '') query.set(key, String(value))
  }
  const response = await fetch(`/api${path}${query.size ? `?${query}` : ''}`, { headers: { Accept: 'application/json' } })
  if (!response.ok) {
    const body = (await response.json().catch(() => null)) as { error?: string } | null
    throw new ApiError(response.status, body?.error ?? `Request failed (${response.status})`)
  }
  return response.json() as Promise<T>
}

export const api = {
  models: () => get<{ default: string; models: ScoringModel[] }>('/models'),
  ranking: (params: { model?: string; run?: number; position?: string; limit?: number; offset?: number; unranked?: boolean }) =>
    get<RankingResponse>('/rankings', params),
  player: (id: number, model?: string) => get<PlayerProfile>(`/players/${id}`, { model }),
  search: (q: string) => get<{ players: SearchResult[] }>('/players', { q }),
  distribution: (params: { model?: string; run?: number; position?: string; part: DistributionPart; component?: string; metric?: string }) =>
    get<DistributionResponse>('/distribution', params),
  stats: (params: { model?: string; x: string; y: string; per?: '90' | 'total'; min_minutes?: number }) => get<StatsResponse>('/stats', params),
}
