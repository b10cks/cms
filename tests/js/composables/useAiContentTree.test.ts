import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

import type { ContentTreePayload, TreeOperation } from '~/composables/useAiContentTree'

import {
  extractStreamingTreeOperations,
  parseTreeOperations,
  useAiContentTree,
} from '~/composables/useAiContentTree'

const encoder = new TextEncoder()
const fetchMock = vi.fn()

const body = (chunks: string[]) =>
  new ReadableStream<Uint8Array>({
    start(controller) {
      chunks.forEach((chunk) => controller.enqueue(encoder.encode(chunk)))
      controller.close()
    },
  })

const frame = (event: Record<string, unknown>) => `data: ${JSON.stringify(event)}\n\n`

const spies = () => ({
  onStatus: vi.fn(),
  onDelta: vi.fn(),
  onDone: vi.fn(),
  onError: vi.fn(),
})

const payload = (): ContentTreePayload => ({
  prompt: 'Add a blog section',
  tree: [
    {
      id: 'c1',
      parent_id: null,
      name: 'Home',
      slug: 'home',
      block_id: 'b1',
    },
  ],
  config_id: null,
  mentions: [{ type: 'content', id: 'c1', label: 'Home' }],
})

const types = (operations: TreeOperation[]) => operations.map((operation) => operation.type)

describe('streamTreeInteraction', () => {
  beforeEach(() => {
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    document.cookie = 'XSRF-TOKEN=csrf-token-1'
    fetchMock.mockImplementation(
      async () => new Response(body([frame({ type: 'done', content: '{"operations":[]}' })]))
    )
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
  })

  it('posts to the content-tree endpoint for the space', async () => {
    const callbacks = spies()

    await useAiContentTree('space-1').streamTreeInteraction(payload(), callbacks)

    const [url, init] = fetchMock.mock.calls[0]

    expect(url).toBe('/mgmt/v1/ai/content-tree-interaction/stream?spaceId=space-1')
    expect(JSON.parse(init.body)).toEqual(payload())
    expect(callbacks.onDone).toHaveBeenCalledWith('{"operations":[]}', undefined)
  })

  it('resolves a reactive space id at call time', async () => {
    const spaceId = ref('space-1')
    const { streamTreeInteraction } = useAiContentTree(spaceId)

    spaceId.value = 'space-2'
    await streamTreeInteraction(payload(), spies())

    expect(fetchMock.mock.calls[0][0]).toContain('spaceId=space-2')
  })

  it('refuses to stream without a space and says so', async () => {
    const callbacks = spies()

    await useAiContentTree('').streamTreeInteraction(payload(), callbacks)

    expect(callbacks.onError).toHaveBeenCalledWith(
      'No space selected. Please reload the page and try again.'
    )
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('exposes the shared streaming state', async () => {
    const { streamTreeInteraction, isStreaming, cancelStream } = useAiContentTree('space-1')

    expect(isStreaming.value).toBe(false)
    await streamTreeInteraction(payload(), spies())

    expect(isStreaming.value).toBe(false)
    expect(() => cancelStream()).not.toThrow()
  })
})

describe('parseTreeOperations', () => {
  it('parses a complete operations document', () => {
    const result = parseTreeOperations(
      '{"operations":[{"type":"create","name":"Blog","temp_id":"t1"},{"type":"move","id":"c1","position":2}]}'
    )

    expect(result?.operations).toEqual([
      { type: 'create', name: 'Blog', temp_id: 't1' },
      { type: 'move', id: 'c1', position: 2 },
    ])
  })

  it('parses a document wrapped in a markdown code fence', () => {
    const result = parseTreeOperations('```json\n{"operations":[{"type":"delete","id":"c1"}]}\n```')

    expect(types(result?.operations ?? [])).toEqual(['delete'])
  })

  it.each(['move', 'update', 'rename', 'delete', 'remove', 'restore'])(
    'keeps the %s operation type',
    (type) => {
      const result = parseTreeOperations(`{"operations":[{"type":"${type}"}]}`)

      expect(types(result?.operations ?? [])).toEqual([type])
    }
  )

  it.each(['name', 'slug', 'block_id', 'temp_id', 'parent_id'])(
    'keeps a create that carries %s',
    (field) => {
      const result = parseTreeOperations(`{"operations":[{"type":"create","${field}":"x"}]}`)

      expect(types(result?.operations ?? [])).toEqual(['create'])
    }
  )

  it('drops a create that carries nothing to create', () => {
    // A bare `{type: 'create'}` names no block, no parent and no title, so it
    // cannot describe anything the applier could build.
    expect(parseTreeOperations('{"operations":[{"type":"create"}]}')).toEqual({ operations: [] })
  })

  it('drops entries that are not recognisable operations', () => {
    const result = parseTreeOperations(
      '{"operations":[{"type":"create","name":"Blog"},{"type":"explode"},{"id":"c1"},null,"create",42,[],{"type":null}]}'
    )

    expect(types(result?.operations ?? [])).toEqual(['create'])
  })

  it('returns an empty operation list for an empty array', () => {
    expect(parseTreeOperations('{"operations":[]}')).toEqual({ operations: [] })
  })

  it.each([
    ['a missing operations key', '{"summary":"nothing to do"}'],
    ['a non-array operations value', '{"operations":{"type":"create"}}'],
    ['a null operations value', '{"operations":null}'],
    ['invalid JSON', '{"operations":[}'],
    ['an empty string', ''],
    ['prose', 'I cannot do that.'],
    ['a bare array', '[{"type":"create"}]'],
  ])('returns null for %s', (_label, input) => {
    expect(parseTreeOperations(input)).toBeNull()
  })

  it('recovers a document embedded in surrounding prose', () => {
    // The streaming path accepts prose-wrapped output, so the final parse must
    // too — it shares `parseAiJson`'s balanced extraction with the rest of the app.
    const result = parseTreeOperations('Sure! {"operations":[{"type":"create","name":"Blog"}]}')

    expect(types(result?.operations ?? [])).toEqual(['create'])
  })
})

describe('extractStreamingTreeOperations', () => {
  it('returns nothing before the operations array opens', () => {
    expect(extractStreamingTreeOperations('{"summary": "Adding a blog')).toEqual([])
    expect(extractStreamingTreeOperations('')).toEqual([])
  })

  it('returns nothing while the first operation is still incomplete', () => {
    expect(
      extractStreamingTreeOperations('{"operations": [{"type":"create","name":"Bl')
    ).toEqual([])
  })

  it('yields a complete operation while the array is still open', () => {
    const operations = extractStreamingTreeOperations(
      '{"operations": [{"type":"create","name":"Blog","temp_id":"t1"},'
    )

    expect(operations).toEqual([{ type: 'create', name: 'Blog', temp_id: 't1' }])
  })

  it('ignores the trailing incomplete operation but keeps the complete ones', () => {
    const operations = extractStreamingTreeOperations(
      '{"operations": [{"type":"create","name":"Blog"},{"type":"move","id":"c1"},{"type":"del'
    )

    expect(types(operations)).toEqual(['create', 'move'])
  })

  it('grows monotonically as the stream arrives character by character', () => {
    const full =
      '{"operations": [{"type":"create","name":"Blog"},{"type":"move","id":"c1","position":1},{"type":"delete","id":"c2"}]}'
    const counts = new Set<number>()
    let previous = 0

    for (let i = 1; i <= full.length; i++) {
      const count = extractStreamingTreeOperations(full.slice(0, i)).length
      expect(count).toBeGreaterThanOrEqual(previous)
      previous = count
      counts.add(count)
    }

    expect(previous).toBe(3)
    expect([...counts].sort()).toEqual([0, 1, 2, 3])
  })

  it('keeps a nested object inside an operation together', () => {
    const operations = extractStreamingTreeOperations(
      '{"operations": [{"type":"create","name":"Blog","meta":{"seo":{"title":"Blog"}}},{"type":"restore","id":"c1"}]'
    )

    expect(operations).toHaveLength(2)
    expect((operations[0] as { meta?: unknown }).meta).toEqual({ seo: { title: 'Blog' } })
  })

  it('does not split on braces inside a string value', () => {
    const operations = extractStreamingTreeOperations(
      '{"operations": [{"type":"rename","id":"c1","name":"{not} [an] object"}]'
    )

    expect(operations).toEqual([{ type: 'rename', id: 'c1', name: '{not} [an] object' }])
  })

  it('handles escaped quotes and backslashes inside a name', () => {
    const operations = extractStreamingTreeOperations(
      '{"operations": [{"type":"rename","id":"c1","name":"a \\" b \\\\ }"}]'
    )

    expect(operations).toEqual([{ type: 'rename', id: 'c1', name: 'a " b \\ }' }])
  })

  it('reads through a markdown fence that has not been closed yet', () => {
    const operations = extractStreamingTreeOperations(
      '```json\n{"operations": [{"type":"create","name":"Blog"}'
    )

    expect(types(operations)).toEqual(['create'])
  })

  it('reads a completed document too', () => {
    const operations = extractStreamingTreeOperations(
      '```json\n{"operations": [{"type":"create","name":"Blog"}]}\n```'
    )

    expect(types(operations)).toEqual(['create'])
  })

  it('tolerates whitespace and newlines around the operations key', () => {
    const operations = extractStreamingTreeOperations(
      '{\n  "operations"  :  [\n    {\n      "type": "create",\n      "name": "Blog"\n    }\n'
    )

    expect(types(operations)).toEqual(['create'])
  })

  it('drops complete objects that are not operations', () => {
    const operations = extractStreamingTreeOperations(
      '{"operations": [{"note":"thinking"},{"type":"nope"},{"type":"create","name":"Blog"}'
    )

    expect(types(operations)).toEqual(['create'])
  })

  it('ignores objects that follow the closed operations array', () => {
    // The scan stops at the array's own `]`, so a sibling key whose object
    // happens to carry an operation-shaped `type` is not a phantom operation.
    const operations = extractStreamingTreeOperations(
      '{"operations": [{"type":"create","name":"Blog"}], "summary": {"type":"rename","id":"x"}}'
    )

    expect(types(operations)).toEqual(['create'])
  })

  it('keeps an array nested inside an operation together with it', () => {
    const operations = extractStreamingTreeOperations(
      '{"operations": [{"type":"create","name":"Blog","tags":["a","b"]},{"type":"delete","id":"c1"}]}'
    )

    expect(types(operations)).toEqual(['create', 'delete'])
  })

  it('starts from the first operations key when the text repeats it', () => {
    const operations = extractStreamingTreeOperations(
      '{"operations": [{"type":"create","name":"Blog"}], "echo": "\\"operations\\": [{\\"type\\":\\"delete\\"}]"}'
    )

    expect(types(operations)).toEqual(['create'])
  })

  it('agrees with the whole-document parser once the stream is complete', () => {
    const full =
      '{"operations":[{"type":"create","name":"Blog"},{"type":"move","id":"c1","position":1}]}'

    expect(extractStreamingTreeOperations(full)).toEqual(parseTreeOperations(full)?.operations)
  })
})
