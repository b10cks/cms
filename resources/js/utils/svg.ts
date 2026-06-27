export function parseSvgDimensions(svg: string): { width: number; height: number } {
  try {
    const parser = new DOMParser()
    const doc = parser.parseFromString(svg, 'image/svg+xml')
    const el = doc.documentElement
    if (!el || el.tagName.toLowerCase() !== 'svg') return { width: 24, height: 24 }

    const viewBox = el.getAttribute('viewBox')
    if (viewBox) {
      const parts = viewBox.trim().split(/[\s,]+/)
      if (parts.length === 4) {
        const w = Math.round(parseFloat(parts[2]))
        const h = Math.round(parseFloat(parts[3]))
        if (w > 0 && h > 0) return { width: w, height: h }
      }
    }

    const w = parseFloat(el.getAttribute('width') || '0')
    const h = parseFloat(el.getAttribute('height') || '0')
    if (w > 0 && h > 0) return { width: Math.round(w), height: Math.round(h) }
  } catch {
    // ignore parse errors
  }
  return { width: 24, height: 24 }
}

const COLOR_EXCEPTIONS = /^(none|transparent|currentColor|inherit|unset)$/i
const URL_VALUE = /^url\s*\(/i

function shouldReplace(value: string): boolean {
  const v = value.trim()
  return !COLOR_EXCEPTIONS.test(v) && !URL_VALUE.test(v)
}

export function replaceColorsWithCurrentColor(svg: string): string {
  // Replace fill="..." and stroke="..." attribute values
  let result = svg.replace(
    /\b(fill|stroke)="([^"]*)"/gi,
    (match, attr, value) => (shouldReplace(value) ? `${attr}="currentColor"` : match),
  )

  // Replace fill:/stroke: inside style="..." attributes
  result = result.replace(/\bstyle="([^"]*)"/gi, (_, styleContent) => {
    const replaced = styleContent.replace(
      /\b(fill|stroke)\s*:\s*([^;"}]+)/gi,
      (m: string, prop: string, value: string) =>
        shouldReplace(value) ? `${prop}: currentColor` : m,
    )
    return `style="${replaced}"`
  })

  return result
}
