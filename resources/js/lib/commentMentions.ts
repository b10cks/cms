import type { JSONContent } from '@tiptap/core'

/**
 * A mention is stored in the comment body as `@` and the user's ULID, which is
 * what MentionExtractor reads on the server. This is stricter than its regex,
 * because here a false match would hide what someone typed: ULIDs start with
 * 0-7 and have no i, l, o or u, and a longer word is not a mention.
 */
const MENTION = /@([0-7][0-9a-hjkmnp-tv-z]{25})(?![0-9a-z])/gi

export type CommentBodyPart = { type: 'text'; text: string } | { type: 'mention'; id: string }

/** The server purifies bodies, so `&` comes back as `&amp;`. This gives back what was typed. */
export const decodeCommentBody = (body: string): string =>
  new DOMParser().parseFromString(body, 'text/html').body.textContent ?? ''

/** Split plain comment text into text runs and mentions. */
export const parseCommentBody = (text: string): CommentBodyPart[] => {
  const parts: CommentBodyPart[] = []
  let position = 0

  for (const match of text.matchAll(MENTION)) {
    if (match.index > position) parts.push({ type: 'text', text: text.slice(position, match.index) })
    parts.push({ type: 'mention', id: match[1] })
    position = match.index + match[0].length
  }
  if (position < text.length) parts.push({ type: 'text', text: text.slice(position) })

  return parts
}

/** Plain comment text as a composer document: one paragraph per line, mentions as nodes. */
export const commentTextToDoc = (
  text: string,
  labelFor: (id: string) => string
): JSONContent => ({
  type: 'doc',
  content: text.split('\n').map((line) => ({
    type: 'paragraph',
    content: parseCommentBody(line).map((part) =>
      part.type === 'text'
        ? { type: 'text', text: part.text }
        : { type: 'mention', attrs: { id: part.id, label: labelFor(part.id) } }
    ),
  })),
})
