import { readFileSync } from 'node:fs'

import { describe, expect, it } from 'vitest'

// app.blade.php paints the page and the browser bar before main.css loads, so
// it carries copies of the --surface colours. This keeps the copies honest.
const css = readFileSync('resources/js/assets/css/main.css', 'utf8')
const blade = readFileSync('resources/views/app.blade.php', 'utf8')

function match(source: string, pattern: RegExp): string {
  const found = source.match(pattern)?.[1]
  if (!found) {
    throw new Error(`No match for ${pattern}`)
  }
  return found
}

/** Resolves `--surface` inside a CSS block to the oklch value of the token it points at. */
function surfaceIn(block: string): string {
  const token = match(block, /^\s*--surface:\s*var\((--[\w-]+)\);/m)
  return match(css, new RegExp(`^\\s*${token}:\\s*(oklch\\([^)]*\\));`, 'm'))
}

/** CSS `oklch(L% C H)` to sRGB hex, via OKLab and linear sRGB. */
function oklchToHex(oklch: string): string {
  const [l, c, h] = match(oklch, /oklch\(([^)]*)\)/)
    .split(/\s+/)
    .map((part) => Number.parseFloat(part))
  const lightness = l / 100
  const a = c * Math.cos((h * Math.PI) / 180)
  const b = c * Math.sin((h * Math.PI) / 180)
  const lms = [
    (lightness + 0.3963377774 * a + 0.2158037573 * b) ** 3,
    (lightness - 0.1055613458 * a - 0.0638541728 * b) ** 3,
    (lightness - 0.0894841775 * a - 1.291485548 * b) ** 3,
  ]
  const linear = [
    4.0767416621 * lms[0] - 3.3077115913 * lms[1] + 0.2309699292 * lms[2],
    -1.2684380046 * lms[0] + 2.6097574011 * lms[1] - 0.3413193965 * lms[2],
    -0.0041960863 * lms[0] - 0.7034186147 * lms[1] + 1.707614701 * lms[2],
  ]
  return `#${linear
    .map((value) => {
      const srgb = value <= 0.0031308 ? 12.92 * value : 1.055 * value ** (1 / 2.4) - 0.055
      return Math.round(Math.min(1, Math.max(0, srgb)) * 255)
        .toString(16)
        .padStart(2, '0')
    })
    .join('')}`
}

const darkStart = css.indexOf('\n.dark {')
const light = surfaceIn(css.slice(0, darkStart))
const dark = surfaceIn(css.slice(darkStart, css.indexOf('\n}', darkStart)))
const meta = match(blade, /(<meta name="theme-color"[^>]*>)/)

describe('app.blade.php theme colours', () => {
  it('paints the html background with the --surface tokens', () => {
    expect(match(blade, /html \{ background: ([^;]+);/)).toBe(light)
    expect(match(blade, /html\.dark \{ background: ([^;]+);/)).toBe(dark)
  })

  it('gives the theme-color meta the same colours as hex, light by default', () => {
    expect(match(meta, /data-light="([^"]+)"/)).toBe(oklchToHex(light))
    expect(match(meta, /data-dark="([^"]+)"/)).toBe(oklchToHex(dark))
    expect(match(meta, /content="([^"]+)"/)).toBe(oklchToHex(light))
  })
})
