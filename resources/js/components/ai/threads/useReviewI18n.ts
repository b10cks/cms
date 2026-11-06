import { useI18n as useLocalI18n } from 'vue-i18n'

import de from '~/components/ai/threads/i18n/de.json'
import en from '~/components/ai/threads/i18n/en.json'

/**
 * The copy for reviewing proposals, kept beside its components as a local scope
 * that follows the app's locale. Languages without their own copy fall back to
 * English, like the global catalogs.
 */
export function useReviewI18n() {
  const { t } = useLocalI18n({ useScope: 'local', messages: { en, de }, fallbackRoot: false, missingWarn: false, fallbackWarn: false })

  return { t }
}
