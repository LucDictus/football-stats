import type { ModelComponent, PositionGroup, ScoringModel } from '../api/types'

/** The components a player of this position is measured on under this model. */
export function componentsFor(model: ScoringModel, position: PositionGroup | null): ModelComponent[] {
  if (!model.roles) return model.components
  return (position && model.roles[position]) || []
}

/** Whether any defensive metric is adjusted for team possession. */
export function adjustsForPossession(model: ScoringModel): boolean {
  const groups = model.roles ? Object.values(model.roles) : [model.components]
  return groups.some((components) => components.some((c) => c.metrics.some((m) => m.possession_adjusted)))
}

export function isRoleModel(model: ScoringModel): boolean {
  return model.roles !== undefined
}

export function describeScale(normalization: ScoringModel['normalization']): string {
  return normalization === 'standard'
    ? '50 is average for the role, 60 one standard deviation above it'
    : 'a percentile: 90 means better than 90% of the group'
}

export const POSITION_PLURAL: Record<PositionGroup, string> = {
  FW: 'forwards',
  MF: 'midfielders',
  DF: 'defenders',
  GK: 'goalkeepers',
}
