import { useShortcutBinding } from '~/lib/shortcuts'
import { useI18n } from '~/plugins/i18n'

const { t } = useI18n()

/**
 * Editor keyboard shortcuts. Lives in `lib/` so composables and components can
 * import it without dropping anything out of the auto-import map.
 */
export interface SaveShortcutOptions {
  /** The very same action the editor's save button triggers. */
  save: () => unknown
  /** Mirrors the save button's enabled state; the shortcut is a no-op when false. */
  canSave: () => boolean
  /** Registry scope, so the help overlay can attribute the binding. */
  scope?: string
}

/**
 * Cmd/Ctrl+S for editors with an explicit save action. The registry always
 * suppresses the browser's own "save page" dialog while the editor is mounted,
 * whether or not it can currently save.
 */
export function useSaveShortcut(options: SaveShortcutOptions): void {
  let isSaving = false

  useShortcutBinding({
    keys: 'mod+s',
    scope: options.scope ?? 'editor',
    description: () => t('shortcuts.editor.save'),
    allowInInput: true,
    handler: async () => {
      if (isSaving || !options.canSave()) return

      isSaving = true
      try {
        await options.save()
      } finally {
        isSaving = false
      }
    },
  })
}
