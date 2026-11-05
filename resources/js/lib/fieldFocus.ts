/** Controls a field can be focused on. The comment button beside a label is not the field. */
const FOCUSABLE =
  'input:not([tabindex="-1"]), textarea:not([tabindex="-1"]), select:not([tabindex="-1"]), button:not([tabindex="-1"]):not([data-field-side-action]), [contenteditable="true"], [tabindex]:not([tabindex="-1"]):not([data-field-side-action])'

/**
 * The rendered container of the field at `path` (`content.body.0.title`), or
 * of its closest rendered parent field.
 */
export function findFieldContainer(path: string): HTMLElement | null {
  let currentPath = path

  while (currentPath) {
    const escapedPath =
      typeof CSS !== 'undefined' && typeof CSS.escape === 'function'
        ? CSS.escape(currentPath)
        : currentPath
    const container = document.querySelector<HTMLElement>(`[data-field-path="${escapedPath}"]`)

    if (container) {
      return container
    }

    const separatorIndex = currentPath.lastIndexOf('.')
    if (separatorIndex === -1) {
      break
    }

    currentPath = currentPath.slice(0, separatorIndex)
  }

  return null
}

/** Scroll the field at `path` (see findFieldContainer) into view and focus its input. */
export function focusField(path: string): void {
  if (typeof document === 'undefined') return

  const container = findFieldContainer(path)
  if (!container) return

  container.scrollIntoView({
    behavior: 'smooth',
    block: 'center',
  })

  const validationTarget = container.querySelector<HTMLElement>('[data-validation-target="true"]')
  const focusable =
    (validationTarget?.matches(FOCUSABLE) ? validationTarget : null) ||
    validationTarget?.querySelector<HTMLElement>(FOCUSABLE) ||
    container.querySelector<HTMLElement>(FOCUSABLE)
  focusable?.focus()
}

/**
 * Center `element` now and again once rows above it have finished expanding,
 * which moves it after the first scroll.
 */
export function scrollIntoViewAfterExpand(element: Element | null | undefined): void {
  element?.scrollIntoView({ block: 'center' })
  setTimeout(() => element?.scrollIntoView({ block: 'center' }), 300)
}
