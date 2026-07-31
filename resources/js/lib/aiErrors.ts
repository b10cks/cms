/**
 * Maps the machine-readable `reason` returned by the AI backend (both in SSE
 * `error` events and in JSON error responses) to a localised, user-facing
 * message. Falls back to a provided message or a generic one.
 */
/**
 * Every reason with a message under `composables.ai.errors` in the locale
 * files. The union is derived from this list so the two cannot drift.
 */
export const AI_ERROR_REASONS = [
  'not_configured',
  'provider_unavailable',
  'not_provisioned',
  'plan_excluded',
  'no_result',
  'csrf',
  'csrfUnavailable',
  'noSpace',
  'noSpaceOrDataSource',
  'generic',
] as const

export type AiErrorReason = (typeof AI_ERROR_REASONS)[number]

const KNOWN_REASONS: ReadonlySet<string> = new Set<AiErrorReason>(AI_ERROR_REASONS)

type Translate = (key: string, ...args: unknown[]) => unknown

export function aiErrorMessage(
  t: Translate,
  reason?: string | null,
  fallback?: string | null
): string {
  if (reason && KNOWN_REASONS.has(reason)) {
    return t(`composables.ai.errors.${reason}`) as string
  }

  return fallback || (t('composables.ai.errors.generic') as string)
}

export function isPlanError(reason?: string | null): boolean {
  return reason === 'plan_excluded'
}
