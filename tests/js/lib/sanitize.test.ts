import { describe, expect, it } from 'vitest'

import { isSafeFrameUrl, safeHref, sanitizeHtml, sanitizeSvgBody } from '~/lib/sanitize'

describe('sanitizeHtml', () => {
  it('keeps ordinary markup', () => {
    expect(sanitizeHtml('<p>Hello <strong>world</strong></p>')).toBe(
      '<p>Hello <strong>world</strong></p>'
    )
  })

  it('strips script tags', () => {
    expect(sanitizeHtml('<p>ok</p><script>alert(1)</script>')).toBe('<p>ok</p>')
  })

  it('strips inline event handlers', () => {
    expect(sanitizeHtml('<img src="x" onerror="alert(1)">')).not.toContain('onerror')
  })

  it('strips javascript: hrefs', () => {
    expect(sanitizeHtml('<a href="javascript:alert(1)">x</a>')).not.toContain('javascript:')
  })

  it('strips form controls, which would let authored content phish in our origin', () => {
    const html = sanitizeHtml(
      '<form action="https://evil.test"><input name="password"><textarea></textarea>' +
        '<select></select><button>Send</button></form>'
    )

    expect(html).not.toContain('evil.test')
    for (const tag of ['form', 'input', 'textarea', 'select', 'button']) {
      expect(html).not.toContain(`<${tag}`)
    }
  })
})

describe('sanitizeSvgBody', () => {
  it('preserves drawing primitives and returns the inner body', () => {
    const body = sanitizeSvgBody('<path d="M0 0L10 10"/><circle cx="5" cy="5" r="2"/>')

    expect(body).toContain('<path')
    expect(body).toContain('<circle')
    expect(body).not.toContain('<svg')
  })

  it('strips script and foreignObject', () => {
    const body = sanitizeSvgBody('<path d="M0 0"/><script>alert(1)</script><foreignObject/>')

    expect(body).toContain('<path')
    expect(body).not.toContain('script')
    expect(body.toLowerCase()).not.toContain('foreignobject')
  })

  it('strips event handlers', () => {
    expect(sanitizeSvgBody('<circle onload="alert(1)" r="2"/>')).not.toContain('onload')
  })

  it('strips external references via href / xlink:href', () => {
    const body = sanitizeSvgBody('<use href="https://evil.test/x.svg#a"/><image xlink:href="x"/>')

    expect(body).not.toContain('href')
  })

  it('returns an empty string for an empty body', () => {
    expect(sanitizeSvgBody('')).toBe('')
  })
})

describe('safeHref', () => {
  it.each(['https://example.com', 'http://example.com', 'mailto:a@b.test', 'tel:+43123'])(
    'passes %s through unchanged',
    (url) => {
      expect(safeHref(url)).toBe(url)
    }
  )

  it('allows relative URLs, resolved against the origin', () => {
    expect(safeHref('/spaces/1')).toBe('/spaces/1')
  })

  it.each(['javascript:alert(1)', 'data:text/html,<h1>x', 'vbscript:msgbox', 'ftp://example.com'])(
    'rejects %s',
    (url) => {
      expect(safeHref(url)).toBeUndefined()
    }
  )

  it('returns undefined for empty input', () => {
    expect(safeHref('')).toBeUndefined()
    expect(safeHref(null)).toBeUndefined()
    expect(safeHref(undefined)).toBeUndefined()
  })
})

describe('isSafeFrameUrl', () => {
  it('accepts http and https only', () => {
    expect(isSafeFrameUrl('https://example.com')).toBe(true)
    expect(isSafeFrameUrl('http://localhost:3000')).toBe(true)
  })

  it('rejects mailto and tel, which safeHref allows', () => {
    expect(isSafeFrameUrl('mailto:a@b.test')).toBe(false)
    expect(isSafeFrameUrl('tel:+43123')).toBe(false)
  })

  it('rejects active-content and empty URLs', () => {
    expect(isSafeFrameUrl('javascript:alert(1)')).toBe(false)
    expect(isSafeFrameUrl('data:text/html,<h1>x')).toBe(false)
    expect(isSafeFrameUrl('')).toBe(false)
    expect(isSafeFrameUrl(null)).toBe(false)
  })
})
