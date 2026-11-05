import type { AiPreflight } from '~/lib/aiChangesets'

/** Why a task shows its plan and budget first, or null when it just runs. */
export type AiBulkPlanReason = 'entries' | 'cost'

/**
 * A small task runs. Past one run's entry limit, or estimated above what one run
 * may spend (`max_usd`, the same figure "The run stops at" shows), it goes through
 * the plan and budget for one approval.
 */
export function bulkPlanReason(preflight: Pick<AiPreflight, 'cost_usd' | 'max_usd' | 'truncated'>): AiBulkPlanReason | null {
  if (preflight.truncated) return 'entries'
  return preflight.cost_usd > preflight.max_usd ? 'cost' : null
}

export const bulkPreflightIsTiny = (costUsd: number) => costUsd > 0 && costUsd < 0.01

/** The estimate as a person reads it: a zero means no price is known, and a fraction of a cent is not $0.00. */
export function bulkEstimateLabel(
  costUsd: number,
  t: (key: string, named?: Record<string, unknown>) => string,
  formatCurrency: (value: number) => string
): string {
  if (costUsd <= 0) return t('components.aiBulk.estimateUnknown')
  return bulkPreflightIsTiny(costUsd) ? t('components.aiBulk.estimateTiny', { amount: formatCurrency(0.01) }) : formatCurrency(costUsd)
}
