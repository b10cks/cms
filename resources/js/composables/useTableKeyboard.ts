import { useEventListener } from '@vueuse/core'
import type { Ref } from 'vue'
import { nextTick, onMounted, onUpdated, ref } from 'vue'

import { useShortcutBinding } from '~/lib/shortcuts'
import { useI18n } from '~/plugins/i18n'

const { t } = useI18n()

/** Marks the rows this composable navigates. Put it on every `<TableRow>`. */
export const TABLE_ROW_ATTR = 'data-table-row'

export interface TableKeyboardOptions {
  /** Enter on a focused row. Omit for read-only tables. */
  onOpen?: (row: HTMLElement, index: number) => void
  /** Page ref from `useTableQueryState`, enables mod+←/→ paging. */
  page?: Ref<number>
  /** Total page count; paging stops at the last page when supplied. */
  lastPage?: () => number | undefined
}

/**
 * Opt-in keyboard navigation for a data table.
 *
 * Row movement stays a focus-driven widget interaction (roving tabindex on a
 * container listener), so it never steals arrow keys from the rest of the page.
 * Only paging — page-level chrome — goes through the shortcut registry.
 */
export function useTableKeyboard(options: TableKeyboardOptions = {}) {
  const container = ref<HTMLElement | null>(null)
  const focusedIndex = ref(0)

  const rows = (): HTMLElement[] =>
    Array.from(container.value?.querySelectorAll<HTMLElement>(`[${TABLE_ROW_ATTR}]`) ?? [])

  const applyRovingTabindex = () => {
    const all = rows()
    if (all.length === 0) return

    focusedIndex.value = Math.min(focusedIndex.value, all.length - 1)
    all.forEach((row, index) => {
      row.tabIndex = index === focusedIndex.value ? 0 : -1
    })
  }

  const focusRow = (index: number) => {
    const all = rows()
    const target = all[Math.max(0, Math.min(index, all.length - 1))]
    if (!target) return

    focusedIndex.value = all.indexOf(target)
    applyRovingTabindex()
    target.focus()
  }

  useEventListener(container, 'keydown', (event: KeyboardEvent) => {
    const target = event.target
    if (!(target instanceof HTMLElement)) return

    // Only the row itself navigates. A focused link, button or context-menu
    // trigger inside the row keeps its own key semantics untouched.
    const row = target.closest<HTMLElement>(`[${TABLE_ROW_ATTR}]`)
    if (!row || row !== target) return

    const index = rows().indexOf(row)
    if (index < 0) return

    if (event.key === 'ArrowDown') {
      event.preventDefault()
      focusRow(index + 1)
      return
    }

    if (event.key === 'ArrowUp') {
      event.preventDefault()
      focusRow(index - 1)
      return
    }

    if (event.key === 'Enter' && options.onOpen) {
      event.preventDefault()
      options.onOpen(row, index)
    }
  })

  useEventListener(container, 'focusin', (event: FocusEvent) => {
    const row = (event.target as HTMLElement | null)?.closest<HTMLElement>(`[${TABLE_ROW_ATTR}]`)
    if (!row) return

    const index = rows().indexOf(row)
    if (index >= 0 && index !== focusedIndex.value) {
      focusedIndex.value = index
      applyRovingTabindex()
    }
  })

  onMounted(() => void nextTick(applyRovingTabindex))
  onUpdated(applyRovingTabindex)

  useShortcutBinding({
    keys: 'arrowup+arrowdown',
    scope: 'table',
    description: () => t('shortcuts.table.rowDown'),
    handler: null,
  })

  if (options.onOpen) {
    useShortcutBinding({
      keys: 'enter',
      scope: 'table',
      description: () => t('shortcuts.table.open'),
      handler: null,
    })
  }

  const { page } = options

  if (page) {
    const step = (delta: number) => {
      const last = options.lastPage?.()
      const next = page.value + delta

      if (next < 1 || (last !== undefined && next > last)) return

      page.value = next
    }

    useShortcutBinding({
      keys: 'mod+arrowright',
      scope: 'table',
      description: () => t('shortcuts.table.nextPage'),
      handler: () => step(1),
    })

    useShortcutBinding({
      keys: 'mod+arrowleft',
      scope: 'table',
      description: () => t('shortcuts.table.prevPage'),
      handler: () => step(-1),
    })
  }

  return { container, focusedIndex, focusRow }
}
