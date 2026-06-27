/**
 * Maps the machine-readable `reason` returned by the AI backend (both in SSE
 * `error` events and in JSON error responses) to a localised, user-facing
 * message. Falls back to a provided message or a generic one.
 */
export type AiErrorReason =
  | 'not_configured'
  | 'provider_unavailable'
  | 'not_provisioned'
  | 'plan_excluded'
  | 'no_result'
  | 'csrf'
  | 'generic'

const KNOWN_REASONS: ReadonlySet<string> = new Set<AiErrorReason>([
  'not_configured',
  'provider_unavailable',
  'not_provisioned',
  'plan_excluded',
  'no_result',
  'csrf',
  'generic',
])

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
