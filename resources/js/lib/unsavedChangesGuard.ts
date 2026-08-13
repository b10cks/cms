import { onBeforeUnmount, watch, type Ref } from 'vue'
import {
  onBeforeRouteLeave,
  onBeforeRouteUpdate,
  type NavigationGuardNext,
  type RouteLocationNormalized,
} from 'vue-router'

import { useAlertDialog } from '~/composables/useAlertDialog'
import { useI18n } from '~/plugins/i18n'

/**
 * Keeps an editor's unsaved changes from being dropped silently: confirms
 * in-app navigation away from the page and arms the browser's own
 * beforeunload prompt while the editor is dirty.
 *
 * Lives in `lib/` rather than in `composables/` so composables can import it
 * without dropping themselves out of the auto-import map.
 */
export interface UnsavedChangesGuardOptions {
  isDirty: Ref<boolean>
  /** Run when the user confirms leaving with unsaved changes. */
  onDiscardChanges?: () => void
}

export function useUnsavedChangesGuard(options: UnsavedChangesGuardOptions): void {
  const { t } = useI18n()
  const { alert } = useAlertDialog()
  const { isDirty } = options

  async function guardLeave(
    to: RouteLocationNormalized,
    from: RouteLocationNormalized,
    next: NavigationGuardNext
  ) {
    if (to && from && to.path === from.path) {
      return next()
    }

    if (isDirty.value) {
      const answer = await alert.confirm(
        t(
          'labels.contents.unsavedChanges',
          'You have unsaved changes. Are you sure you want to leave?'
        )
      )
      if (answer) {
        options.onDiscardChanges?.()
        next()
      } else {
        next(false)
      }
    } else {
      next()
    }
  }

  onBeforeRouteUpdate(guardLeave)
  onBeforeRouteLeave(guardLeave)

  const handleBeforeUnload = (e: BeforeUnloadEvent) => {
    if (isDirty.value) {
      e.preventDefault()
      e.returnValue = ''
    }
  }

  onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', handleBeforeUnload)
  })

  watch(
    isDirty,
    (dirtyNow) => {
      if (dirtyNow) {
        window.addEventListener('beforeunload', handleBeforeUnload)
      } else {
        window.removeEventListener('beforeunload', handleBeforeUnload)
      }
    },
    { immediate: true }
  )
}
