import { toast } from 'vue-sonner'

import { outdatedBy } from '~/lib/appVersion'

/**
 * Offers a reload once the server runs a newer release than this tab, so old
 * editor code stops writing content. Reloading waits for the click, and a
 * dirty editor's beforeunload guard still asks before anything is dropped.
 */
export function useAppUpdateNotice() {
  const { t } = useI18n()

  watch(outdatedBy, (release) => {
    if (!release) return

    toast.info(t('notifications.appUpdate.message'), {
      id: 'app-update',
      duration: Infinity,
      action: {
        label: t('notifications.appUpdate.reload'),
        onClick: () => window.location.reload(),
      },
    })
  })
}
