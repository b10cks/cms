/**
 * Cleanup for HTML pasted from Microsoft Word / Office (and Google Docs export,
 * which shares the same `mso-*` conventions). Word emits deeply nested spans,
 * namespaced (`<o:p>`, `<w:...>`) tags, conditional comments and `mso-*` inline
 * styles that otherwise leak into the ProseMirror document as noise.
 *
 * Cleanup only runs when the payload actually looks like Office HTML, so normal
 * pastes — including copy/paste between two b10cks editors, which carry our own
 * `class` / `data-*` attributes — pass through untouched.
 */

const WORD_MARKERS =
  /(?:urn:schemas-microsoft-com|<o:p|<\/o:p|mso-|class=["']?Mso|WordDocument|<xml>)/i

export function isWordHtml(html: string): boolean {
  return WORD_MARKERS.test(html)
}

/** Namespaced / metadata elements to drop wholesale, keeping their text out too. */
const DROP_TAGS = new Set(['STYLE', 'META', 'LINK', 'XML', 'O:P', 'W:SDT', 'V:SHAPETYPE'])

function stripConditionalComments(html: string): string {
  // Word wraps fallbacks in `<!--[if gte mso 9]> … <![endif]-->` comments.
  return html.replace(/<!--\[if[\s\S]*?<!\[endif\]-->/gi, '').replace(/<!--[\s\S]*?-->/g, '')
}

function isListParagraph(el: Element): boolean {
  const cls = el.getAttribute('class') || ''
  const style = el.getAttribute('style') || ''
  return /MsoListParagraph/i.test(cls) || /mso-list\s*:/i.test(style)
}

/**
 * Word represents lists as flat `<p class=MsoListParagraph>` paragraphs whose
 * first run is the bullet/number glyph. Group consecutive ones back into a real
 * `<ul>`/`<ol>` and strip the leading marker text.
 */
function reconstructLists(container: HTMLElement, doc: Document): void {
  const children = Array.from(container.children)
  let i = 0
  while (i < children.length) {
    const el = children[i]
    if (el.tagName === 'P' && isListParagraph(el)) {
      const group: Element[] = []
      let j = i
      while (j < children.length && children[j].tagName === 'P' && isListParagraph(children[j])) {
        group.push(children[j])
        j++
      }

      // Ordered when the first visible glyph is a number (e.g. "1." / "a)").
      const firstText = (group[0].textContent || '').trimStart()
      const ordered = /^[0-9]+[.)]/.test(firstText)
      const list = doc.createElement(ordered ? 'ol' : 'ul')

      for (const p of group) {
        const li = doc.createElement('li')
        // Remove the leading marker glyph run (Word puts it in the first span).
        const marker = p.querySelector('span[style*="mso-list"]')
        if (marker) marker.remove()
        li.innerHTML = p.innerHTML
        // Drop any leftover leading bullet/number + whitespace.
        li.innerHTML = li.innerHTML.replace(/^\s*(?:[0-9]+[.)]|[•·▪◦‣o-])\s*/, '')
        list.appendChild(li)
      }

      group[0].replaceWith(list)
      for (let k = 1; k < group.length; k++) group[k].remove()

      // Re-read children since we mutated the tree.
      children.splice(i, group.length, list)
      i++
    } else {
      i++
    }
  }
}

function cleanElement(el: Element): void {
  // Depth-first so we can safely unwrap/remove on the way up.
  for (const child of Array.from(el.children)) cleanElement(child)

  if (DROP_TAGS.has(el.tagName) || el.tagName.includes(':')) {
    el.remove()
    return
  }

  // Word decorates everything with class / style / lang / align noise.
  el.removeAttribute('class')
  el.removeAttribute('style')
  el.removeAttribute('lang')
  el.removeAttribute('align')

  // Collapse empty spans Word scatters around every run.
  if (el.tagName === 'SPAN' && el.attributes.length === 0) {
    el.replaceWith(...Array.from(el.childNodes))
  }
}

export function cleanWordHtml(html: string): string {
  const withoutComments = stripConditionalComments(html)
  const doc = new DOMParser().parseFromString(withoutComments, 'text/html')
  const body = doc.body

  reconstructLists(body, doc)
  for (const child of Array.from(body.children)) cleanElement(child)

  return body.innerHTML
}

/** Entry point wired into TipTap's `editorProps.transformPastedHTML`. */
export function transformPastedHtml(html: string): string {
  return isWordHtml(html) ? cleanWordHtml(html) : html
}
