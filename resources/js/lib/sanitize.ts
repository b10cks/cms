import DOMPurify from 'dompurify'

/**
 * Client-side sanitization helpers. These are defense-in-depth: the backend
 * also sanitizes/validates untrusted markup, but any value that reaches a
 * `v-html` sink or a URL binding must be sanitized here too so a gap on the
 * server can never become DOM-based XSS.
 */

/**
 * Sanitize an HTML fragment for use with `v-html` (e.g. rendered markdown).
 */
export function sanitizeHtml(html: string): string {
  return DOMPurify.sanitize(html, { USE_PROFILES: { html: true } })
}

/**
 * Sanitize the inner body of an SVG icon before it is injected into the DOM.
 * Event handlers, `<script>`, `<foreignObject>` and external references are
 * stripped while the drawing primitives are preserved.
 */
export function sanitizeSvgBody(body: string): string {
  return DOMPurify.sanitize(body, {
    USE_PROFILES: { svg: true, svgFilters: true },
    FORBID_TAGS: ['script', 'foreignObject', 'a'],
    FORBID_ATTR: ['href', 'xlink:href'],
  })
}

const SAFE_URL_PROTOCOLS = new Set(['http:', 'https:', 'mailto:', 'tel:'])

/**
 * Returns the URL if it uses a safe protocol, otherwise `undefined`. Guards
 * `:href`/`:src` bindings against `javascript:` and other active-content URLs.
 */
export function safeHref(url: string | null | undefined): string | undefined {
  if (!url) {
    return undefined
  }

  try {
    const parsed = new URL(url, window.location.origin)

    return SAFE_URL_PROTOCOLS.has(parsed.protocol) ? url : undefined
  } catch {
    return undefined
  }
}

/**
 * True when the URL is safe to use as an iframe `src` (http/https only).
 */
export function isSafeFrameUrl(url: string | null | undefined): boolean {
  if (!url) {
    return false
  }

  try {
    const parsed = new URL(url, window.location.origin)

    return parsed.protocol === 'http:' || parsed.protocol === 'https:'
  } catch {
    return false
  }
}
