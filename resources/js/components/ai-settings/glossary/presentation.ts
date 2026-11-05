/**
 * A list cell. The fill sits on the cells, over an opaque card, because the first
 * column has to cover what scrolls under it and the tree tokens are translucent.
 */
export const CELL =
  'h-11 border-b border-border bg-card px-3 align-middle group-hover/row:bg-[linear-gradient(var(--tree-hover),var(--tree-hover))] group-data-selected/row:bg-[linear-gradient(var(--tree-hover),var(--tree-hover))] group-data-open/row:bg-[linear-gradient(var(--tree-selected),var(--tree-selected))] group-focus-visible/row:shadow-[inset_0_2px_0_var(--ring),inset_0_-2px_0_var(--ring)]'

/** The first column stays in place while the languages scroll under it. */
export const FROZEN_CELL = `${CELL} sticky left-0 z-10 w-44 max-w-44 border-r @3xl:w-72 @3xl:max-w-72`
