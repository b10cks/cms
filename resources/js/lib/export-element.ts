/**
 * Exports for a page section: PNG through html-to-image (loaded on first use, it renders the
 * DOM through the browser itself, so oklch colors and SVG charts come out as on screen), PDF
 * through the browser's print dialog. `data-export-only` content prints, `data-export-hidden`
 * content is left out of both.
 */
export async function downloadPng(element: HTMLElement, filename: string) {
  const { toPng } = await import('html-to-image')
  const url = await toPng(element, {
    pixelRatio: 2,
    backgroundColor: getComputedStyle(document.body).backgroundColor,
    filter: (node) => !(node instanceof HTMLElement && node.dataset.exportHidden !== undefined),
  })
  const link = document.createElement('a')
  link.href = url
  link.download = `${filename}.png`
  link.click()
}

/** Prints only `element`: everything else on the page is hidden while the dialog is open. */
export function printElement(element: HTMLElement) {
  element.dataset.printRoot = ''
  document.documentElement.dataset.printing = ''
  const cleanup = () => {
    delete element.dataset.printRoot
    delete document.documentElement.dataset.printing
    window.removeEventListener('afterprint', cleanup)
  }
  window.addEventListener('afterprint', cleanup)
  window.print()
}
