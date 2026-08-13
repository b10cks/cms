import { computed, ref } from 'vue'

import type { ShortcutInfo, ShortcutOptions } from '~/lib/shortcuts'
import {
  formatKeys,
  isEditableTarget,
  isInsideOverlay,
  listShortcuts,
  topScope,
  useShortcutBinding,
} from '~/lib/shortcuts'

/**
 * Binds a keyboard shortcut for the lifetime of the calling component.
 *
 * Scope lifecycle is component lifecycle: a mounted dialog or page shadows
 * `global` bindings automatically, and unmounting releases the key again.
 * Pass `handler: null` to document a shortcut a widget handles itself.
 */
export function useShortcut(options: ShortcutOptions): void {
  useShortcutBinding(options)
}

const helpOpen = ref(false)

/** Open state of the single generated help overlay mounted in `app.vue`. */
export function useShortcutHelp() {
  return {
    open: helpOpen,
    show: () => {
      helpOpen.value = true
    },
  }
}

/** Registry view for the generated help overlay. */
export function useShortcutRegistry() {
  const shortcuts = computed(() => listShortcuts())

  const groups = computed(() => {
    const byScope = new Map<string, ShortcutInfo[]>()

    for (const shortcut of shortcuts.value) {
      const bucket = byScope.get(shortcut.scope)
      if (bucket) {
        bucket.push(shortcut)
      } else {
        byScope.set(shortcut.scope, [shortcut])
      }
    }

    // Contextual scopes first, `global` last — the most relevant keys read first.
    return [...byScope.entries()]
      .map(([scope, items]) => ({ scope, items }))
      .sort((a, b) => Number(a.scope === 'global') - Number(b.scope === 'global'))
  })

  return {
    shortcuts,
    groups,
    activeScope: computed(() => topScope()),
    formatKeys,
    isEditableTarget,
    isInsideOverlay,
  }
}
