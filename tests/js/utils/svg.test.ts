import { describe, expect, it } from 'vitest'

import { parseSvgDimensions, replaceColorsWithCurrentColor } from '~/utils/svg'

const FALLBACK = { width: 24, height: 24 }

describe('parseSvgDimensions', () => {
  it('prefers the viewBox width and height', () => {
    expect(parseSvgDimensions('<svg viewBox="0 0 32 16"></svg>')).toEqual({ width: 32, height: 16 })
  })

  it('accepts a comma-separated viewBox', () => {
    expect(parseSvgDimensions('<svg viewBox="0,0,48,48"></svg>')).toEqual({ width: 48, height: 48 })
  })

  it('accepts a viewBox with irregular whitespace', () => {
    expect(parseSvgDimensions('<svg viewBox="  0 0\t20   10 "></svg>')).toEqual({
      width: 20,
      height: 10,
    })
  })

  it('rounds fractional viewBox dimensions', () => {
    expect(parseSvgDimensions('<svg viewBox="0 0 23.4 23.6"></svg>')).toEqual({
      width: 23,
      height: 24,
    })
  })

  it('ignores the viewBox min-x / min-y offsets', () => {
    expect(parseSvgDimensions('<svg viewBox="-10 -10 40 40"></svg>')).toEqual({
      width: 40,
      height: 40,
    })
  })

  it('falls back to width/height when the viewBox has the wrong arity', () => {
    expect(parseSvgDimensions('<svg viewBox="0 0 32" width="8" height="8"></svg>')).toEqual({
      width: 8,
      height: 8,
    })
  })

  it('falls back to width/height when the viewBox is non-numeric', () => {
    expect(parseSvgDimensions('<svg viewBox="a b c d" width="8" height="8"></svg>')).toEqual({
      width: 8,
      height: 8,
    })
  })

  it('falls back to width/height for a zero or negative viewBox size', () => {
    expect(parseSvgDimensions('<svg viewBox="0 0 0 0" width="8" height="8"></svg>')).toEqual({
      width: 8,
      height: 8,
    })
    expect(parseSvgDimensions('<svg viewBox="0 0 -5 -5" width="8" height="8"></svg>')).toEqual({
      width: 8,
      height: 8,
    })
  })

  it('reads the width and height attributes when there is no viewBox', () => {
    expect(parseSvgDimensions('<svg width="64" height="32"></svg>')).toEqual({
      width: 64,
      height: 32,
    })
  })

  it('rounds fractional width/height attributes', () => {
    expect(parseSvgDimensions('<svg width="15.6" height="15.4"></svg>')).toEqual({
      width: 16,
      height: 15,
    })
  })

  it('needs both attributes — one alone falls back', () => {
    expect(parseSvgDimensions('<svg width="64"></svg>')).toEqual(FALLBACK)
    expect(parseSvgDimensions('<svg height="64"></svg>')).toEqual(FALLBACK)
  })

  // A unit-bearing length says nothing about the intrinsic pixel size, and the
  // icon registry stores what comes out of here.
  it('rejects CSS units instead of stripping them', () => {
    expect(parseSvgDimensions('<svg width="100%" height="50%"></svg>')).toEqual(FALLBACK)
    expect(parseSvgDimensions('<svg width="3em" height="2em"></svg>')).toEqual(FALLBACK)
  })

  it('still accepts an explicit px unit', () => {
    expect(parseSvgDimensions('<svg width="48px" height="48px"></svg>')).toEqual({
      width: 48,
      height: 48,
    })
  })

  it.each([
    ['no attributes', '<svg></svg>'],
    ['an empty string', ''],
    ['plain text', 'not svg at all'],
    ['a non-svg root element', '<div width="10" height="10"></div>'],
    ['malformed XML', '<svg viewBox="0 0 10 10"'],
    ['an unclosed tag', '<svg><path d="M0 0"></svg'],
  ])('falls back to 24x24 given %s', (_label, input) => {
    expect(parseSvgDimensions(input)).toEqual(FALLBACK)
  })

  it('matches the svg root regardless of tag casing', () => {
    // XML is case sensitive, so <SVG> is a different element and the helper's
    // lowercase comparison is what lets it through.
    expect(parseSvgDimensions('<SVG viewBox="0 0 10 10"></SVG>')).toEqual({
      width: 10,
      height: 10,
    })
  })

  it('reads the outermost svg, not a nested one', () => {
    expect(
      parseSvgDimensions('<svg viewBox="0 0 40 40"><svg viewBox="0 0 5 5"></svg></svg>')
    ).toEqual({ width: 40, height: 40 })
  })

  it('tolerates a namespaced document', () => {
    expect(
      parseSvgDimensions('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 12 12"></svg>')
    ).toEqual({ width: 12, height: 12 })
  })
})

describe('replaceColorsWithCurrentColor', () => {
  it('rewrites a fill attribute to currentColor', () => {
    expect(replaceColorsWithCurrentColor('<path fill="#ff0000"/>')).toBe(
      '<path fill="currentColor"/>'
    )
  })

  it('rewrites a stroke attribute to currentColor', () => {
    expect(replaceColorsWithCurrentColor('<path stroke="rgb(1,2,3)"/>')).toBe(
      '<path stroke="currentColor"/>'
    )
  })

  it('rewrites every occurrence, not just the first', () => {
    expect(replaceColorsWithCurrentColor('<path fill="red"/><path fill="blue"/>')).toBe(
      '<path fill="currentColor"/><path fill="currentColor"/>'
    )
  })

  it.each(['none', 'transparent', 'currentColor', 'inherit', 'unset'])(
    'leaves fill="%s" alone',
    (value) => {
      expect(replaceColorsWithCurrentColor(`<path fill="${value}"/>`)).toBe(
        `<path fill="${value}"/>`
      )
    }
  )

  it('matches the keyword exceptions case-insensitively', () => {
    expect(replaceColorsWithCurrentColor('<path fill="NONE" stroke="CurrentColor"/>')).toBe(
      '<path fill="NONE" stroke="CurrentColor"/>'
    )
  })

  it('trims before comparing, so a padded keyword survives', () => {
    expect(replaceColorsWithCurrentColor('<path fill=" none "/>')).toBe('<path fill=" none "/>')
  })

  it('leaves gradient and pattern references alone', () => {
    expect(replaceColorsWithCurrentColor('<path fill="url(#grad)" stroke="URL( #p )"/>')).toBe(
      '<path fill="url(#grad)" stroke="URL( #p )"/>'
    )
  })

  it('matches the attribute name case-insensitively but re-emits the original casing', () => {
    expect(replaceColorsWithCurrentColor('<path FILL="red"/>')).toBe('<path FILL="currentColor"/>')
  })

  it('does not touch stroke-width or other hyphenated attributes', () => {
    expect(replaceColorsWithCurrentColor('<path stroke-width="2" stroke-linecap="round"/>')).toBe(
      '<path stroke-width="2" stroke-linecap="round"/>'
    )
  })

  it('leaves attributes that merely end in fill or stroke alone', () => {
    expect(replaceColorsWithCurrentColor('<path data-fill="#abc"/>')).toBe(
      '<path data-fill="#abc"/>'
    )
    expect(replaceColorsWithCurrentColor('<path data-stroke="#abc"/>')).toBe(
      '<path data-stroke="#abc"/>'
    )
  })

  it('leaves an empty fill alone — it is not a colour', () => {
    expect(replaceColorsWithCurrentColor('<path fill=""/>')).toBe('<path fill=""/>')
  })

  it('rewrites fill and stroke inside a style attribute', () => {
    expect(replaceColorsWithCurrentColor('<path style="fill:#f00;stroke:#00f"/>')).toBe(
      '<path style="fill: currentColor;stroke: currentColor"/>'
    )
  })

  it('normalises the spacing around the colon in a style declaration', () => {
    expect(replaceColorsWithCurrentColor('<path style="fill :   #f00"/>')).toBe(
      '<path style="fill: currentColor"/>'
    )
  })

  it('keeps other style declarations untouched', () => {
    expect(
      replaceColorsWithCurrentColor('<path style="opacity:0.5;fill:#f00;stroke-width:2"/>')
    ).toBe('<path style="opacity:0.5;fill: currentColor;stroke-width:2"/>')
  })

  it('honours the keyword exceptions inside a style attribute', () => {
    expect(replaceColorsWithCurrentColor('<path style="fill:none;stroke:url(#g)"/>')).toBe(
      '<path style="fill:none;stroke:url(#g)"/>'
    )
  })

  it('rewrites both an attribute and a style declaration in one element', () => {
    expect(replaceColorsWithCurrentColor('<path fill="#f00" style="stroke:#00f"/>')).toBe(
      '<path fill="currentColor" style="stroke: currentColor"/>'
    )
  })

  // Single quotes are legal SVG and uploaded icons use them.
  it('rewrites single-quoted attributes too, keeping the quote style', () => {
    expect(replaceColorsWithCurrentColor("<path fill='#f00'/>")).toBe(
      "<path fill='currentColor'/>"
    )
    expect(replaceColorsWithCurrentColor("<path style='fill:#f00'/>")).toBe(
      "<path style='fill: currentColor'/>"
    )
  })

  it('rewrites colours declared in a <style> block', () => {
    expect(
      replaceColorsWithCurrentColor('<svg><style>.a{fill:#f00}</style><path class="a"/></svg>')
    ).toBe('<svg><style>.a{fill: currentColor}</style><path class="a"/></svg>')
  })

  it('keeps the <style> tag and its attributes intact', () => {
    expect(
      replaceColorsWithCurrentColor('<style type="text/css">.a{stroke:#00f;fill:none}</style>')
    ).toBe('<style type="text/css">.a{stroke: currentColor;fill:none}</style>')
  })

  it('returns markup without colours unchanged', () => {
    expect(replaceColorsWithCurrentColor('<path d="M0 0L10 10"/>')).toBe('<path d="M0 0L10 10"/>')
  })

  it('returns an empty string for empty input', () => {
    expect(replaceColorsWithCurrentColor('')).toBe('')
  })

  it('is idempotent', () => {
    const once = replaceColorsWithCurrentColor('<path fill="#f00" style="stroke:#00f"/>')

    expect(replaceColorsWithCurrentColor(once)).toBe(once)
  })
})
