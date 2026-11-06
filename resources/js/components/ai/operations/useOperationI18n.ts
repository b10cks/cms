import { useI18n as useLocalI18n } from 'vue-i18n'

import de from '~/components/ai/operations/i18n/de.json'
import en from '~/components/ai/operations/i18n/en.json'

/**
 * The operation outcome and coverage copy, kept beside its components as a
 * local scope that follows the app's locale. Languages without their own
 * copy fall back to English, like the global catalogs.
 */
export function useOperationI18n() {
  const { t, te } = useLocalI18n({ useScope: 'local', messages: { en, de }, fallbackRoot: false, missingWarn: false, fallbackWarn: false })
  const global = useI18n()

  /** Codes read as copy where there is some; free text a run wrote shows as it is. */
  const reasonLabel = (reason: string) => {
    const local = `reasons.${reason}`
    if (te(local)) return t(local)
    const shared = `components.aiOperations.reasons.${reason}`
    return global.te(shared) ? global.t(shared) : reason.replaceAll('_', ' ')
  }

  const outcomeLabel = (outcome: string) => {
    const key = `outcomes.${outcome}`
    return te(key) ? t(key) : outcome.replaceAll('_', ' ')
  }

  return { t, reasonLabel, outcomeLabel }
}
