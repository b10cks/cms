import type { Translate } from '~/lib/toast-error'
import type { InstanceAuditActionKey } from '~/types/provider-console'

export const INSTANCE_AUDIT_ACTIONS: InstanceAuditActionKey[] = [
  'root.elevation.started',
  'root.elevation.ended',
  'root.impersonation.started',
  'root.impersonation.ended',
  'root.user.suspended',
  'root.user.restored',
  'root.user.signed_out',
  'root.team.ownership_transferred',
]

const isInstanceAction = (action: string): action is InstanceAuditActionKey =>
  (INSTANCE_AUDIT_ACTIONS as string[]).includes(action)

/** i18n key of an instance audit action: `root.user.signed_out` → `…actions.user_signed_out`. */
export const instanceAuditKey = (action: InstanceAuditActionKey) =>
  `providerConsole.audit.actions.${action.slice('root.'.length).replace('.', '_')}`

/**
 * A readable label for any management audit action. Root actions are
 * translated; the rest (`member_added`, …) fall back to their words.
 */
export function instanceAuditLabel(t: Translate, action: string): string {
  if (isInstanceAction(action)) return t(instanceAuditKey(action))

  const words = action.replace(/[._]/g, ' ').trim()
  return words.charAt(0).toUpperCase() + words.slice(1)
}
