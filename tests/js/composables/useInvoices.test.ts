import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const forSpace = vi.fn(() => ({ invoices: { index } }))

vi.mock('~/api', () => ({ api: { forSpace } }))

const { useInvoices } = await import('~/composables/useInvoices')

const SPACE = 'space-1'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

type Query = ReturnType<ReturnType<typeof useInvoices>['useInvoicesQuery']>

let harness: Harness<Query> | undefined

const setup = (spaceId: MaybeRef<string> = SPACE, seed?: Array<[readonly unknown[], unknown]>) => {
  harness = withSetup<Query>(() => useInvoices(spaceId).useInvoicesQuery(), { seed })
  return harness.result
}

beforeEach(() => {
  index.mockReset()
  forSpace.mockClear()
  index.mockResolvedValue({ data: [] })
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('useInvoicesQuery', () => {
  it('unwraps the data envelope', async () => {
    index.mockResolvedValue({ data: [{ id: 'inv-1', total: 1900 }] })

    const query = setup()
    await flush()

    expect(query.data.value).toEqual([{ id: 'inv-1', total: 1900 }])
  })

  it('scopes the request to the space', async () => {
    setup()
    await flush()

    expect(forSpace).toHaveBeenCalledWith(SPACE)
    expect(index).toHaveBeenCalledWith()
  })

  it('caches under the invoices list key for that space', async () => {
    index.mockResolvedValue({ data: [{ id: 'inv-1' }] })

    setup()
    await flush()

    expect(harness?.queryClient.getQueryData(queryKeys.invoices(SPACE).lists())).toEqual([
      { id: 'inv-1' },
    ])
  })

  it('stays idle without a space id', async () => {
    const query = setup('')
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(index).not.toHaveBeenCalled()
  })

  it('serves a seeded entry without refetching — 5 minutes of staleTime', async () => {
    const query = setup(SPACE, [[queryKeys.invoices(SPACE).lists(), [{ id: 'seeded' }]]])
    await flush()

    expect(query.data.value).toEqual([{ id: 'seeded' }])
    expect(index).not.toHaveBeenCalled()
  })

  it('refetches under a new key when the space id changes', async () => {
    const spaceId = ref(SPACE)

    setup(spaceId)
    await flush()
    spaceId.value = 'space-2'
    await nextTick()
    await flush()

    expect(harness?.queryClient.getQueryData(queryKeys.invoices('space-2').lists())).toEqual([])
    expect(forSpace).toHaveBeenCalledWith('space-2')
  })

  it('keeps invoices of two spaces apart', () => {
    expect(queryKeys.invoices('a').lists()).not.toEqual(queryKeys.invoices('b').lists())
  })

  it('nests the list under the space invoices namespace', () => {
    const namespace = queryKeys.invoices(SPACE).all()

    expect(queryKeys.invoices(SPACE).lists().slice(0, namespace.length)).toEqual([...namespace])
  })
})
