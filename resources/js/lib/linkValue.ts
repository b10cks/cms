export type LinkKind = LinkValue['type']

/** A link of `kind` with nothing filled in yet, in the shape the field always stored. */
export const emptyLink = (kind: LinkKind): LinkValue => {
  switch (kind) {
    case 'url':
      return { type: 'url', url: '', target: '_self' }
    case 'email':
      return { type: 'email', email: '' }
    case 'internal':
      return { type: 'internal', content: '' }
  }
}

/** True while the link's one main value (entry, URL or address) is still empty. */
export const isEmptyLink = (link: LinkValue): boolean => {
  switch (link.type) {
    case 'url':
      return !link.url.trim()
    case 'email':
      return !link.email?.trim()
    case 'internal':
      return !link.content
  }
}

/**
 * True when a setting behind "More options" is in use, so the options open by themselves:
 * a target other than the default, same tab or new tab, rel, query params, or any email part.
 */
export const hasLinkOptions = (link: LinkValue): boolean => {
  if (link.type === 'email') {
    return [link.subject, link.body, link.cc, link.bcc].some((part) => !!part?.trim())
  }

  const unusualTarget = !!link.target && link.target !== '_self' && link.target !== '_blank'
  const hasParams = Object.keys(link.params ?? {}).length > 0
  const hasRel = link.type === 'url' && !!link.rel?.trim()

  return unusualTarget || hasParams || hasRel
}
