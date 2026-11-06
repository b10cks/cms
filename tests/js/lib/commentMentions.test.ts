import { describe, expect, it } from 'vitest'

import { commentTextToDoc, decodeCommentBody, parseCommentBody } from '~/lib/commentMentions'

const ANNA = '01jphjg6dn2f9a9e9pat1b6j4s'

describe('parseCommentBody', () => {
  it('splits text and mentions in order', () => {
    expect(parseCommentBody(`Ask @${ANNA} about it`)).toEqual([
      { type: 'text', text: 'Ask ' },
      { type: 'mention', id: ANNA },
      { type: 'text', text: ' about it' },
    ])
  })

  it('reads an id in either case, as the server does', () => {
    expect(parseCommentBody(`@${ANNA.toUpperCase()}`)).toEqual([
      { type: 'mention', id: ANNA.toUpperCase() },
    ])
  })

  it('leaves what is not an id alone', () => {
    for (const text of ['@anna', '@internationalizationsupport', `@${ANNA}x`, 'plain']) {
      expect(parseCommentBody(text)).toEqual([{ type: 'text', text }])
    }
  })
})

describe('decodeCommentBody', () => {
  it('gives back what the purifier encoded and keeps line breaks', () => {
    expect(decodeCommentBody('Tom &amp; Jerry\n1 &lt; 2')).toBe('Tom & Jerry\n1 < 2')
  })
})

describe('commentTextToDoc', () => {
  it('makes a paragraph per line and a node per mention', () => {
    expect(commentTextToDoc(`Hi @${ANNA}\n\nbye`, () => 'Anna')).toEqual({
      type: 'doc',
      content: [
        {
          type: 'paragraph',
          content: [
            { type: 'text', text: 'Hi ' },
            { type: 'mention', attrs: { id: ANNA, label: 'Anna' } },
          ],
        },
        { type: 'paragraph', content: [] },
        { type: 'paragraph', content: [{ type: 'text', text: 'bye' }] },
      ],
    })
  })
})
