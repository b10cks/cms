// A length is only a pixel size when it carries no CSS unit — `100%` or `3em`
// say nothing about the icon's intrinsic pixel size, so they fall back to 24x24.
const PIXEL_LENGTH = /^[+-]?(\d+\.?\d*|\.\d+)(px)?$/

function parseLength(value: string | null): number {
  if (!value) return 0
  const trimmed = value.trim()
  return PIXEL_LENGTH.test(trimmed) ? parseFloat(trimmed) : 0
}

export function parseSvgDimensions(svg: string): { width: number; height: number } {
  const parser = new DOMParser()
  // Malformed XML yields a `<parsererror>` document rather than throwing.
  const doc = parser.parseFromString(svg, 'image/svg+xml')
  const el = doc.documentElement
  if (!el || el.tagName.toLowerCase() !== 'svg') return { width: 24, height: 24 }

  const viewBox = el.getAttribute('viewBox')
  if (viewBox) {
    const parts = viewBox.trim().split(/[\s,]+/)
    if (parts.length === 4) {
      const w = Math.round(parseLength(parts[2]))
      const h = Math.round(parseLength(parts[3]))
      if (w > 0 && h > 0) return { width: w, height: h }
    }
  }

  const w = parseLength(el.getAttribute('width'))
  const h = parseLength(el.getAttribute('height'))
  if (w > 0 && h > 0) return { width: Math.round(w), height: Math.round(h) }

  return { width: 24, height: 24 }
}

const COLOR_EXCEPTIONS = /^(none|transparent|currentColor|inherit|unset)$/i
const URL_VALUE = /^url\s*\(/i

function shouldReplace(value: string): boolean {
  const v = value.trim()
  return v !== '' && !COLOR_EXCEPTIONS.test(v) && !URL_VALUE.test(v)
}

// `(?<![\w-])` keeps `data-fill` / `mask-stroke` out: a plain `\b` matches
// after the hyphen and would rewrite unrelated attributes and properties.
const COLOR_ATTRIBUTE = /(?<![\w-])(fill|stroke)\s*=\s*(["'])([^"']*)\2/gi
const STYLE_ATTRIBUTE = /(?<![\w-])style\s*=\s*(["'])([^"']*)\1/gi
const STYLE_ELEMENT = /(<style\b[^>]*>)([\s\S]*?)(<\/style>)/gi
const COLOR_DECLARATION = /(?<![\w-])(fill|stroke)\s*:\s*([^;"'}]+)/gi

function replaceColorDeclarations(css: string): string {
  return css.replace(COLOR_DECLARATION, (match, prop: string, value: string) =>
    shouldReplace(value) ? `${prop}: currentColor` : match
  )
}

export function replaceColorsWithCurrentColor(svg: string): string {
  // Replace fill="..."/fill='...' and stroke attribute values
  let result = svg.replace(COLOR_ATTRIBUTE, (match, attr: string, quote: string, value: string) =>
    shouldReplace(value) ? `${attr}=${quote}currentColor${quote}` : match
  )

  // Replace fill:/stroke: inside style="..."/style='...' attributes
  result = result.replace(
    STYLE_ATTRIBUTE,
    (_, quote: string, styleContent: string) =>
      `style=${quote}${replaceColorDeclarations(styleContent)}${quote}`
  )

  // ...and inside inner <style> blocks, which theme an icon the same way.
  result = result.replace(
    STYLE_ELEMENT,
    (_, open: string, css: string, close: string) => open + replaceColorDeclarations(css) + close
  )

  return result
}
