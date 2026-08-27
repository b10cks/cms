import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import {
  useAssetUploadBatch,
  type BatchUploadFn,
  type BatchUploadItem,
} from '~/composables/useAssetUploadBatch'

const item = (id: string): BatchUploadItem => ({
  id,
  file: new File([new Uint8Array(4)], `${id}.png`, { type: 'image/png' }),
  data: {},
  metadata: {},
  tags: [],
  type: 'image',
  progress: 0,
  status: 'pending',
  folderPath: '',
})

/** An upload the test resolves or rejects by hand. */
const deferred = () => {
  let settle: (value: never) => void = () => {}
  let reject: (error: Error) => void = () => {}

  const promise = new Promise<never>((res, rej) => {
    settle = res
    reject = rej
  })

  return { promise, settle, reject }
}

const failing: BatchUploadFn = () => Promise.reject(new Error('network blip'))

const succeeding: BatchUploadFn = () =>
  Promise.resolve({ status: 'success', asset: {} as AssetResource })

/** Lets every queued microtask run so the batch reaches its settled state. */
const drain = async () => {
  for (let round = 0; round < 10; round++) {
    await nextTick()
  }
}

describe('useAssetUploadBatch', () => {
  beforeEach(() => {
    useAssetUploadBatch().reset()
  })

  it('carries a settled batch failure into the next batch so retry keeps working', async () => {
    const batch = useAssetUploadBatch()

    batch.enqueue([item('a')], { upload: failing, onSettled: vi.fn() })
    await drain()

    expect(batch.isRunning.value).toBe(false)
    expect(batch.items.value.map((entry) => entry.status)).toEqual(['error'])

    batch.enqueue([item('b')], { upload: succeeding, onSettled: vi.fn() })
    await drain()

    // The failure survived the batch that replaced it, so the panel still
    // shows it and the Retry button still has something behind it.
    expect(batch.items.value.map((entry) => entry.id)).toEqual(['a', 'b'])
    expect(batch.items.value.map((entry) => entry.status)).toEqual(['error', 'complete'])

    batch.retryItem('a')

    // Not a no-op: the carried item kept its group, so it has an uploader again.
    expect(batch.items.value.find((entry) => entry.id === 'a')?.status).toBe('uploading')
  })

  it('ignores a lane still unwinding from a batch that reset() wiped', async () => {
    const batch = useAssetUploadBatch()
    const stale = deferred()

    batch.enqueue([item('stale')], { upload: () => stale.promise, onSettled: vi.fn() })
    await drain()

    expect(batch.isRunning.value).toBe(true)

    batch.reset()

    const live = deferred()
    batch.enqueue([item('live')], { upload: () => live.promise, onSettled: vi.fn() })
    await drain()

    stale.reject(new Error('aborted'))
    await drain()

    // The stale lane must not settle the batch that replaced it.
    expect(batch.isRunning.value).toBe(true)
    expect(batch.items.value.map((entry) => entry.status)).toEqual(['uploading'])
  })
})
