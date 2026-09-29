const FOCUSABLE =
  'input:not([tabindex="-1"]), textarea:not([tabindex="-1"]), select:not([tabindex="-1"]), button:not([tabindex="-1"]), [contenteditable="true"], [tabindex]:not([tabindex="-1"])'

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
