import type { UseSortableOptions } from '@vueuse/integrations/useSortable'

/** No movement for people who asked the system for less motion. */
export const sortAnimation = (): number =>
  window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 0 : 150

/** The Sortable options lists set themselves. `sortablejs`' own `Options` type doesn't resolve here. */
export interface ListSortOptions {
  /** Selector of this list's grips. */
  handle: string
  /** Selector of the rows that move, when the list holds other children too. */
  draggable?: string
  /** Lists sharing a group trade rows. */
  group?: string
  disabled?: boolean
  /** False for table rows: a `tr` clone on the body loses its column widths, the browser's own drag image keeps them. */
  forceFallback?: boolean
  onEnd?: (event: { oldIndex?: number | null; newIndex?: number | null }) => void
}

/**
 * The drag every reorderable list uses, taken from the blocks field: only the grip starts it,
 * a fallback clone follows the pointer, and the gap is the dashed drop slot (`.sortable-ghost`
 * in main.css). Give each list its own `handle` selector, so a list nested in a row never
 * picks up the grips of the list around it.
 */
export const sortableOptions = (options: ListSortOptions): UseSortableOptions =>
  ({
    forceFallback: true,
    fallbackOnBody: true,
    fallbackTolerance: 4,
    animation: sortAnimation(),
    forceAutoScrollFallback: true,
    scrollSensitivity: 80,
    scrollSpeed: 14,
    ...options,
  }) as UseSortableOptions

/** Moves `list[index]` one step in place, for the grip's arrow keys. False at either end. */
export const moveInList = <T>(list: T[], index: number, delta: -1 | 1): boolean => {
  const target = index + delta
  if (target < 0 || target >= list.length) return false

  const [moved] = list.splice(index, 1)
  list.splice(target, 0, moved)
  return true
}

/** Focuses the grip at `index` in `container` after a keyboard move dropped focus. */
export const focusGrip = (container: Element | null | undefined, handle: string, index: number) => {
  const grips = container?.querySelectorAll<HTMLElement>(handle)
  grips?.[Math.min(index, grips.length - 1)]?.focus()
}
