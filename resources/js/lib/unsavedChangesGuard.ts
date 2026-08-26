import { onBeforeUnmount, watch, type Ref } from 'vue'
import {
  onBeforeRouteLeave,
  onBeforeRouteUpdate,
  type NavigationGuardNext,
  type RouteLocationNormalized,
} from 'vue-router'

import { type DialogAction, useAlertDialog } from '~/composables/useAlertDialog'
import { useI18n } from '~/plugins/i18n'

/** What the leave prompt came back with. */
type LeaveChoice = 'stay' | 'discard' | 'save'

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
   * Adds a "save and leave" option to the prompt. Resolve `false` when the save
   * failed — the navigation is then cancelled rather than dropping the edits.
   */
  onSave?: () => Promise<boolean> | boolean
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

  async function promptLeave(): Promise<LeaveChoice> {
    const canSave = !!options.onSave
    // Dismissing the dialog (Escape, overlay) means "I did not decide" — the
    // safe reading of that is to stay put, so nothing but a button moves this.
    let choice: LeaveChoice = 'stay'

    const actions: DialogAction[] = [
      { type: 'cancel', label: t('labels.unsavedChanges.stay') },
      {
        type: 'destructive',
        label: t('labels.unsavedChanges.discard'),
        click: () => {
          choice = 'discard'
        },
      },
    ]

    if (canSave) {
      actions.push({
        type: 'primary',
        label: t('labels.unsavedChanges.save'),
        click: () => {
          choice = 'save'
        },
      })
    }

    await alert.dialog({
      title: t('labels.unsavedChanges.title'),
      message: canSave
        ? t('labels.unsavedChanges.messageWithSave')
        : t('labels.unsavedChanges.message'),
      actions,
    })

    return choice
  }

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

    if (!isDirty.value) {
      return next()
    }

    const choice = await promptLeave()

    if (choice === 'stay') {
      return next(false)
    }

    if (choice === 'save') {
      const saved = await options.onSave?.()
      return next(saved ? undefined : false)
    }

    options.onDiscardChanges?.()
    next()
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
