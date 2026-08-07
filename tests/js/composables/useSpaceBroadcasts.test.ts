import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'

import { queryKeys } from '~/composables/useQueryClient'
import { useSpaceBroadcasts } from '~/composables/useSpaceBroadcasts'

import { withSetup, type Harness } from '../support/harness'

interface FakeChannel {
  name: string
  listeners: Map<string, (payload: unknown) => void>
}

const createFakeEcho = () => {
  const left: string[] = []
  const channels: FakeChannel[] = []
  const connectionHandlers: Array<(states: { previous: string; current: string }) => void> = []

  const connection = {
    bind: (_event: string, callback: (states: { previous: string; current: string }) => void) => {
      connectionHandlers.push(callback)
    },
    unbind: (_event: string, callback: (states: { previous: string; current: string }) => void) => {
      const index = connectionHandlers.indexOf(callback)
      if (index >= 0) connectionHandlers.splice(index, 1)
    },
  }

  const echo = {
    connector: { pusher: { connection } },
    channel: (name: string) => {
      const channel: FakeChannel = { name, listeners: new Map() }
      channels.push(channel)

      const chainable = {
        listen: (event: string, callback: (payload: unknown) => void) => {
          channel.listeners.set(event, callback)
          return chainable
        },
      }

      return chainable
    },
    leave: (name: string) => {
      left.push(name)
    },
  }

  return { echo, left, channels, connectionHandlers }
}

type FakeEcho = ReturnType<typeof createFakeEcho>

let fake: FakeEcho
let harness: Harness<void> | undefined
let invalidate: ReturnType<typeof vi.spyOn>

const setup = (spaceId: MaybeRef<string | null> = 'space-1') => {
  harness = withSetup(() => useSpaceBroadcasts(spaceId))
  invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')
  return harness
}

const channelNames = () => fake.channels.map((channel) => channel.name)

const emit = (channel: string, event: string, payload: unknown = {}) => {
  const target = fake.channels.find((entry) => entry.name === channel)
  target?.listeners.get(event)?.(payload)
}

/** Query keys the last emit asked to invalidate. */
const invalidated = (): unknown[][] =>
  (invalidate.mock.calls as Array<[{ queryKey: unknown[] }]>).map(([options]) => options.queryKey)

beforeEach(() => {
  fake = createFakeEcho()
  window.Echo = fake.echo as unknown as typeof window.Echo
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
  Reflect.deleteProperty(window, 'Echo')
})

describe('subscribing', () => {
  it('joins one channel per resource group', () => {
    setup()

    expect(channelNames()).toEqual([
      'spaces.space-1.blocks',
      'spaces.space-1.assets',
      'spaces.space-1.icons',
      'spaces.space-1.redirects',
    ])
  })

  it('joins nothing without a space id', () => {
    setup(null)

    expect(channelNames()).toEqual([])
  })

  it('joins nothing when Echo is missing', () => {
    Reflect.deleteProperty(window, 'Echo')

    expect(() => setup()).not.toThrow()
  })

  it('swallows an Echo that throws while subscribing', () => {
    window.Echo = {
      channel: () => {
        throw new Error('no socket')
      },
      leave: () => {},
    } as unknown as typeof window.Echo

    expect(() => setup()).not.toThrow()
  })
})

describe('block events', () => {
  beforeEach(() => setup())

  it('invalidates the block lists on create and update', () => {
    emit('spaces.space-1.blocks', '.block:created')
    emit('spaces.space-1.blocks', '.block:updated')

    expect(invalidated()).toEqual([
      queryKeys.blocks('space-1').lists(),
      queryKeys.blocks('space-1').lists(),
    ])
  })

  it('invalidates list and detail on delete', () => {
    emit('spaces.space-1.blocks', '.block:deleted', { id: 'block-9' })

    expect(invalidated()).toEqual([
      queryKeys.blocks('space-1').lists(),
      queryKeys.blocks('space-1').detail('block-9'),
    ])
  })

  it('invalidates block folder lists', () => {
    emit('spaces.space-1.blocks', '.block_folder:created')

    expect(invalidated()).toEqual([queryKeys.blockFolders('space-1').lists()])
  })

  it('invalidates block folder list and detail on delete', () => {
    emit('spaces.space-1.blocks', '.block_folder:deleted', { id: 'folder-1' })

    expect(invalidated()).toEqual([
      queryKeys.blockFolders('space-1').lists(),
      queryKeys.blockFolders('space-1').detail('folder-1'),
    ])
  })

  it('invalidates only the tag lists on a tag delete — tags have no detail query', () => {
    emit('spaces.space-1.blocks', '.block_tag:deleted', { id: 'tag-1' })

    expect(invalidated()).toEqual([queryKeys.blockTags('space-1').lists()])
  })
})

describe('asset events', () => {
  beforeEach(() => setup())

  it('invalidates asset lists on update', () => {
    emit('spaces.space-1.assets', '.asset:updated')

    expect(invalidated()).toEqual([queryKeys.assets('space-1').lists()])
  })

  it('invalidates asset list and detail on delete', () => {
    emit('spaces.space-1.assets', '.asset:deleted', { id: 'asset-1' })

    expect(invalidated()).toEqual([
      queryKeys.assets('space-1').lists(),
      queryKeys.assets('space-1').detail('asset-1'),
    ])
  })

  it('invalidates asset folder list and detail on delete', () => {
    emit('spaces.space-1.assets', '.asset_folder:deleted', { id: 'folder-1' })

    expect(invalidated()).toEqual([
      queryKeys.assetFolders('space-1').lists(),
      queryKeys.assetFolders('space-1').detail('folder-1'),
    ])
  })

  it('invalidates asset tag lists', () => {
    emit('spaces.space-1.assets', '.asset_tag:updated')

    expect(invalidated()).toEqual([queryKeys.assetTags('space-1').lists()])
  })
})

describe('icon events', () => {
  beforeEach(() => setup())

  it('invalidates both the icon lists and the tag facet on create', () => {
    emit('spaces.space-1.icons', '.icon:created')

    expect(invalidated()).toEqual([
      queryKeys.icons('space-1').lists(),
      queryKeys.icons('space-1').tags(),
    ])
  })

  it('adds the detail key on delete', () => {
    emit('spaces.space-1.icons', '.icon:deleted', { id: 'icon-1' })

    expect(invalidated()).toEqual([
      queryKeys.icons('space-1').lists(),
      queryKeys.icons('space-1').tags(),
      queryKeys.icons('space-1').detail('icon-1'),
    ])
  })
})

describe('redirect events', () => {
  beforeEach(() => setup())

  it('invalidates redirect lists on create', () => {
    emit('spaces.space-1.redirects', '.redirect:created')

    expect(invalidated()).toEqual([queryKeys.redirects('space-1').lists()])
  })

  it('invalidates redirect list and detail on delete', () => {
    emit('spaces.space-1.redirects', '.redirect:deleted', { id: 'redirect-1' })

    expect(invalidated()).toEqual([
      queryKeys.redirects('space-1').lists(),
      queryKeys.redirects('space-1').detail('redirect-1'),
    ])
  })

  it('passes an undefined id straight through to the detail key', () => {
    emit('spaces.space-1.redirects', '.redirect:deleted', {})

    // No guard on the payload: a broadcast without an id still invalidates a
    // detail key, it just matches nothing.
    expect(invalidated()[1]).toEqual(queryKeys.redirects('space-1').detail(undefined as never))
  })
})

describe('teardown', () => {
  it('leaves every channel on unmount', () => {
    setup()
    harness?.unmount()
    harness = undefined

    expect(fake.left).toEqual([
      'spaces.space-1.blocks',
      'spaces.space-1.assets',
      'spaces.space-1.icons',
      'spaces.space-1.redirects',
    ])
  })

  it('leaves nothing when it never subscribed', () => {
    setup(null)
    harness?.unmount()
    harness = undefined

    expect(fake.left).toEqual([])
  })

  it('swallows an Echo that disappears before teardown', () => {
    setup()
    Reflect.deleteProperty(window, 'Echo')

    expect(() => harness?.unmount()).not.toThrow()
  })
})

describe('reconnect catch-up', () => {
  const stateChange = (previous: string, current: string) =>
    fake.connectionHandlers.forEach((handler) => handler({ previous, current }))

  it('invalidates everything space-scoped after a drop and reconnect', () => {
    setup()

    stateChange('connected', 'unavailable')
    stateChange('unavailable', 'connected')

    expect(invalidated()).toEqual([['spaces', 'space-1']])
  })

  it('does not invalidate on the initial connect', () => {
    setup()

    stateChange('connecting', 'connected')

    expect(invalidated()).toEqual([])
  })

  it('invalidates only once per drop', () => {
    setup()

    stateChange('connected', 'unavailable')
    stateChange('unavailable', 'connected')
    stateChange('connecting', 'connected')

    expect(invalidated()).toEqual([['spaces', 'space-1']])
  })

  it('unbinds the connection handler on unmount', () => {
    setup()
    harness?.unmount()
    harness = undefined

    expect(fake.connectionHandlers).toEqual([])
  })

  it('targets the space that is current at reconnect time', async () => {
    const spaceId = ref<string | null>('space-1')
    setup(spaceId)

    stateChange('connected', 'unavailable')
    spaceId.value = 'space-2'
    await nextTick()
    stateChange('unavailable', 'connected')

    expect(invalidated()).toEqual([['spaces', 'space-2']])
  })
})

describe('switching space', () => {
  it('leaves the OLD space channels and joins the new ones', async () => {
    const spaceId = ref<string | null>('space-1')
    setup(spaceId)

    spaceId.value = 'space-2'
    await nextTick()

    expect(fake.left).toEqual([
      'spaces.space-1.blocks',
      'spaces.space-1.assets',
      'spaces.space-1.icons',
      'spaces.space-1.redirects',
    ])
    expect(channelNames().slice(4)).toEqual([
      'spaces.space-2.blocks',
      'spaces.space-2.assets',
      'spaces.space-2.icons',
      'spaces.space-2.redirects',
    ])
  })

  it('invalidates against the new space after the switch', async () => {
    const spaceId = ref<string | null>('space-1')
    setup(spaceId)

    spaceId.value = 'space-2'
    await nextTick()
    invalidate.mockClear()

    emit('spaces.space-2.blocks', '.block:created')

    expect(invalidated()).toEqual([queryKeys.blocks('space-2').lists()])
  })

  it('only leaves when the space id is cleared', async () => {
    const spaceId = ref<string | null>('space-1')
    setup(spaceId)

    spaceId.value = null
    await nextTick()

    expect(fake.left).toHaveLength(4)
    expect(channelNames()).toHaveLength(4)
  })

  it('subscribes when a space id arrives after mounting without one', async () => {
    const spaceId = ref<string | null>(null)
    setup(spaceId)

    spaceId.value = 'space-1'
    await nextTick()

    expect(channelNames()).toHaveLength(4)
    expect(fake.left).toEqual([])
  })
})
