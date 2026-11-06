/**
 * Google cuts snippets by rendered width, not by characters: a title of wide letters
 * loses words long before 60 characters. These are the desktop numbers SEO tools agree on.
 */
export const SERP_LIMITS = {
  title: { font: '20px Arial, sans-serif', width: 600 },
  description: { font: '14px Arial, sans-serif', width: 920 },
} as const

export type SerpPart = keyof typeof SERP_LIMITS

export interface SerpFit {
  /** What the result page shows, with an ellipsis when cut. */
  shown: string
  /** What falls past the cut. Empty when everything fits. */
  cut: string
  /** Rendered width of the whole text, in pixels. */
  width: number
  limit: number
}

let context: CanvasRenderingContext2D | null | undefined

const measure = (text: string, font: string): number => {
  context ??= typeof document === 'undefined' ? null : document.createElement('canvas').getContext('2d')
  if (!context) return text.length * 9
  context.font = font
  return context.measureText(text).width
}

/** Fits `text` into Google's width for `part`, cutting at a word boundary like Google does. */
export const fitSerp = (text: string, part: SerpPart): SerpFit => {
  const { font, width: limit } = SERP_LIMITS[part]
  const clean = text.replace(/\s+/g, ' ').trim()
  const width = measure(clean, font)
  if (width <= limit) return { shown: clean, cut: '', width, limit }

  // Longest prefix that still fits with the ellipsis, then back to the last whole word.
  let low = 0
  let high = clean.length
  while (low < high) {
    const mid = Math.ceil((low + high) / 2)
    if (measure(`${clean.slice(0, mid)} …`, font) <= limit) low = mid
    else high = mid - 1
  }

  const space = clean.lastIndexOf(' ', low)
  const end = space > low * 0.6 ? space : low
  return {
    shown: `${clean.slice(0, end).trimEnd()} …`,
    cut: clean.slice(end).trimStart(),
    width,
    limit,
  }
}
