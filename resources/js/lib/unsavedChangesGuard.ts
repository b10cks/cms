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
  /**
   * Language shown when `?lang` is absent. Lets a page's own normalization
   * (`?lang=de` → no `lang` once `de` became the default) pass as the same
   * document instead of prompting for a switch the user never made.
   */
  defaultLanguage?: Ref<string | undefined>
}

/** `?lang` picks which document is being edited, not just UI state. */
export function isDocumentIdentityChange(
  to: Pick<RouteLocationNormalized, 'query'>,
  from: Pick<RouteLocationNormalized, 'query'>,
  defaultLanguage?: string
): boolean {
  const lang = (query: RouteLocationNormalized['query']) => {
    const value = query.lang
    return (Array.isArray(value) ? value[0] : value) ?? defaultLanguage
  }

  return lang(to.query) !== lang(from.query)
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
    // Same path is usually UI state (tabs, hash). A `lang` (or other identity)
    // query change is a different document and must confirm like a leave.
    if (
      to &&
      from &&
      to.path === from.path &&
      !isDocumentIdentityChange(to, from, options.defaultLanguage?.value)
    ) {
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
