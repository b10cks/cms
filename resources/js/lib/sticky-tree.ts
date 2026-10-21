import { onScopeDispose, ref, shallowRef, watch, type Ref } from 'vue'

/** A row of a flattened tree, in render order. Root rows have level 1. */
export type StickyTreeRow = { id: string; level: number }

export type StickyTreeIndex = {
  /** Index of each row's parent, -1 for roots. */
  parent: Int32Array
  /** Index of each row's last visible descendant, the row itself when it has none. */
  end: Int32Array
}

export type StickyRowPlacement = { index: number; top: number }

export const indexTreeRows = (rows: readonly StickyTreeRow[]): StickyTreeIndex => {
  const parent = new Int32Array(rows.length).fill(-1)
  const end = new Int32Array(rows.length)
  const stack: number[] = []

  rows.forEach((row, index) => {
    while (stack.length > 0 && rows[stack.at(-1)!]!.level >= row.level) {
      end[stack.pop()!] = index - 1
    }
    parent[index] = stack.at(-1) ?? -1
    stack.push(index)
  })
  for (const index of stack) {
    end[index] = rows.length - 1
  }

  return { parent, end }
}

type ResolveOptions = {
  scrollTop: number
  slotHeight: number
  max: number
  rowTop: (index: number) => number
  rowBottom: (index: number) => number
}

/**
 * Picks the ancestors to pin above the scrolled tree, root first. An ancestor
 * sticks while its subtree is in view and gets pushed up by the end of it.
 * The cap keeps the outermost ancestors, so rows never jump between slots.
 */
export const resolveStickyRows = (
  rowCount: number,
  { parent, end }: StickyTreeIndex,
  { scrollTop, slotHeight, max, rowTop, rowBottom }: ResolveOptions
): StickyRowPlacement[] => {
  const placements: StickyRowPlacement[] = []

  for (let depth = 0; depth < max; depth++) {
    const line = scrollTop + depth * slotHeight
    const at = firstRowBelow(rowCount, line, rowBottom)
    if (at === -1) {
      break
    }

    const chain: number[] = []
    for (let index = at; index !== -1; index = parent[index]!) {
      chain.unshift(index)
    }

    const candidate = chain[depth]
    if (candidate === undefined || end[candidate] === candidate || rowTop(candidate) >= line) {
      break
    }

    const pushedTop = rowBottom(end[candidate]!) - scrollTop - slotHeight
    placements.push({ index: candidate, top: Math.min(depth * slotHeight, pushedTop) })
    if (pushedTop < depth * slotHeight) {
      break
    }
  }

  return placements
}

const firstRowBelow = (rowCount: number, line: number, rowBottom: (index: number) => number) => {
  let low = 0
  let high = rowCount - 1
  let found = -1
  while (low <= high) {
    const mid = (low + high) >> 1
    if (rowBottom(mid) > line) {
      found = mid
      high = mid - 1
    } else {
      low = mid + 1
    }
  }

  return found
}

type UseStickyTreeRowsOptions<Row extends StickyTreeRow> = {
  scroller: Ref<HTMLElement | null>
  rows: Ref<readonly Row[]>
  getRowElement: (id: string) => HTMLElement | null
  max: number
}

/**
 * Tracks which ancestor rows of a flattened tree should stick to the top of
 * its scroller. `revealRow` scrolls a row out from under the pinned rows.
 */
export const useStickyTreeRows = <Row extends StickyTreeRow>({
  scroller,
  rows,
  getRowElement,
  max,
}: UseStickyTreeRowsOptions<Row>) => {
  const stickyRows = shallowRef<(Row & { top: number })[]>([])
  const slotHeight = ref(0)
  let index = indexTreeRows(rows.value)
  let frame = 0

  const measure = (element: HTMLElement) => {
    const scrollerElement = scroller.value!
    const offset = scrollerElement.getBoundingClientRect().top - scrollerElement.scrollTop
    const rect = element.getBoundingClientRect()
    return { top: rect.top - offset, bottom: rect.bottom - offset }
  }

  const measureRow = (rowIndex: number) => {
    const element = getRowElement(rows.value[rowIndex]!.id)
    return element ? measure(element) : { top: 0, bottom: 0 }
  }

  const update = () => {
    frame = 0
    const element = scroller.value
    const first = rows.value[0] && getRowElement(rows.value[0].id)
    if (!element || !first || max < 1) {
      stickyRows.value = []
      return
    }

    const second = rows.value[1] && getRowElement(rows.value[1].id)
    slotHeight.value = second ? measure(second).top - measure(first).top : first.offsetHeight

    stickyRows.value = resolveStickyRows(rows.value.length, index, {
      scrollTop: element.scrollTop,
      slotHeight: slotHeight.value,
      max,
      rowTop: (rowIndex) => measureRow(rowIndex).top,
      rowBottom: (rowIndex) => measureRow(rowIndex).bottom,
    }).map(({ index: rowIndex, top }) => ({ ...rows.value[rowIndex]!, top }))
  }

  const schedule = () => {
    frame ||= requestAnimationFrame(update)
  }

  watch(
    rows,
    (value) => {
      index = indexTreeRows(value)
      schedule()
    },
    { flush: 'post' }
  )

  watch(
    scroller,
    (element, _, onCleanup) => {
      if (!element) {
        return
      }
      element.addEventListener('scroll', schedule, { passive: true })
      schedule()
      onCleanup(() => element.removeEventListener('scroll', schedule))
    },
    { immediate: true }
  )

  onScopeDispose(() => cancelAnimationFrame(frame))

  const revealRow = (id: string) => {
    const element = scroller.value
    const row = getRowElement(id)
    const rowIndex = rows.value.findIndex((candidate) => candidate.id === id)
    if (!element || !row || rowIndex === -1) {
      return
    }

    let ancestors = 0
    for (let parent = index.parent[rowIndex]!; parent !== -1; parent = index.parent[parent]!) {
      ancestors++
    }

    const target = measure(row).top - Math.min(ancestors, max) * slotHeight.value
    if (target < element.scrollTop) {
      element.scrollTop = target
    }
  }

  return { stickyRows, slotHeight, revealRow }
}
