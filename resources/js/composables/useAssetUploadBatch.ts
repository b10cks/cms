/**
 * App-scope upload batch: the state lives at module scope so a running batch
 * survives the upload dialog closing, in-app navigation and a space switch.
 * Components read and control it through `useAssetUploadBatch()`; the docked
 * `UploadBatchPanel` renders it whenever items exist.
 *
 * The upload function itself is handed in at enqueue time (a `silent`
 * `uploadAsset` bound to its space), so this file needs no sibling composable
 * imports and stays out of the auto-import trap. Each enqueue forms a group
 * that keeps its own uploader and settle callback, which is what lets a batch
 * started in space A finish there while space B queues behind it.
 */

export type BatchItemStatus = 'pending' | 'uploading' | 'complete' | 'error'

export interface BatchUploadItem extends UploadFile {
  progress: number
  status: BatchItemStatus
  errorMessage?: string
  /** Folder path relative to the drop target, '' when dropped at the root. */
  folderPath: string
  /** Not retryable, e.g. over the server's size limit; never uploaded. */
  permanentError?: boolean
  /** Failed because the batch was cancelled, not because the upload did. */
  cancelled?: boolean
  /** Assigned by `enqueue`; binds the item to the uploader it arrived with. */
  groupId?: string
}

/**
 * A batch item while the upload dialog still stages it. The dialog and its tree
 * rows share this shape; the batch itself never reads `enqueued`.
 */
export interface StagedUploadFile extends BatchUploadItem {
  /** Already handed to the batch; enqueuing it again would upload it twice. */
  enqueued?: boolean
}

type BatchUploadOutcome =
  | { status: 'success'; asset: AssetResource }
  | { status: 'duplicate'; duplicate: AssetUploadDuplicate }
  | null

export type BatchUploadFn = (
  payload: UploadAssetPayload,
  onProgress: (progress: number) => void,
  options: { force?: boolean; signal?: AbortSignal }
) => Promise<BatchUploadOutcome>

export interface BatchEnqueueDeps {
  upload: BatchUploadFn
  /** Runs once when this group's items finish; per-upload invalidation is off. */
  onSettled: () => void
}

interface BatchGroup extends BatchEnqueueDeps {
  settled: boolean
}

type DuplicateDecision = 'copies' | 'use-existing'

const CONCURRENT_UPLOADS = 3

const items = ref<BatchUploadItem[]>([])
const isRunning = ref(false)
const isCancelled = ref(false)
const isPanelDismissed = ref(false)
const duplicatePrompt = ref<{ filename: string; duplicate: AssetUploadDuplicate } | null>(null)

let duplicateDecision: DuplicateDecision | null = null
let duplicateWaiters: Array<(decision: DuplicateDecision) => void> = []
let activeLanes = 0
let abortController: AbortController | null = null
let groupSequence = 0
/**
 * Bumped by `reset()`. A lane captures it when it opens and checks it before
 * touching `activeLanes` again, so a lane still unwinding from a wiped batch
 * cannot decrement the counter of the batch that replaced it.
 */
let generation = 0
const groups = new Map<string, BatchGroup>()

const guardUnload = (event: BeforeUnloadEvent) => {
  event.preventDefault()
}

const batchTotals = computed(() => {
  let complete = 0
  let failed = 0
  let progressSum = 0

  for (const item of items.value) {
    if (item.status === 'complete') complete++
    if (item.status === 'error') failed++
    progressSum += item.status === 'complete' ? 100 : item.progress
  }

  const total = items.value.length

  return {
    total,
    complete,
    failed,
    settled: complete + failed,
    percent: total ? Math.round(progressSum / total) : 0,
  }
})

const startRunning = () => {
  if (isRunning.value) {
    return
  }

  isRunning.value = true
  window.addEventListener('beforeunload', guardUnload)
}

const failItem = (item: BatchUploadItem, message?: string, cancelled = false) => {
  item.status = 'error'
  item.cancelled = cancelled || undefined
  item.errorMessage = cancelled ? undefined : message
}

/**
 * Calls `onSettled` for every group that has no live item left. Groups settle
 * independently, so the space that queued first refreshes its asset list
 * without waiting for whatever was queued after it.
 */
const flushSettledGroups = () => {
  const live = new Set<string>()

  for (const item of items.value) {
    if (item.groupId && (item.status === 'pending' || item.status === 'uploading')) {
      live.add(item.groupId)
    }
  }

  for (const [id, group] of groups) {
    if (!group.settled && !live.has(id)) {
      group.settled = true
      group.onSettled()
    }
  }
}

const maybeSettle = () => {
  if (!isRunning.value || activeLanes > 0) {
    return
  }

  if (items.value.some((item) => item.status === 'pending')) {
    if (!isCancelled.value) {
      return
    }

    // A cancelled batch fails what it never started. Settling over pending work
    // would leave those items stuck at `pending` with nothing left to run them.
    for (const item of items.value) {
      if (item.status === 'pending') {
        failItem(item, undefined, true)
      }
    }
  }

  isRunning.value = false
  window.removeEventListener('beforeunload', guardUnload)
  flushSettledGroups()
}

const payloadOf = (item: BatchUploadItem): UploadAssetPayload => ({
  file: item.file,
  folder_id: item.folder_id,
  tags: item.tags,
  metadata: item.metadata,
  data: item.data,
})

const promptForDuplicate = (
  item: BatchUploadItem,
  duplicate: AssetUploadDuplicate
): Promise<DuplicateDecision> => {
  return new Promise((resolve) => {
    duplicateWaiters.push(resolve)

    // The first lane to hit a duplicate opens the prompt; later lanes wait for
    // the same answer. One prompt per batch, the decision applies to the rest.
    if (!duplicatePrompt.value) {
      duplicatePrompt.value = { filename: item.file.name, duplicate }
    }
  })
}

const resolveDuplicatePrompt = (decision: DuplicateDecision) => {
  duplicateDecision = decision
  duplicatePrompt.value = null

  const waiters = duplicateWaiters
  duplicateWaiters = []
  waiters.forEach((resolve) => resolve(decision))
}

const performUpload = async (item: BatchUploadItem, group: BatchGroup) => {
  const signal = abortController?.signal
  const onProgress = (progress: number) => {
    item.progress = progress
  }

  try {
    const outcome = await group.upload(payloadOf(item), onProgress, {
      force: duplicateDecision === 'copies',
      signal,
    })

    if (outcome?.status === 'success') {
      item.status = 'complete'
      return
    }

    if (outcome?.status === 'duplicate') {
      const decision = duplicateDecision ?? (await promptForDuplicate(item, outcome.duplicate))

      if (decision === 'use-existing') {
        // The existing asset already satisfies the intent of this upload.
        item.status = 'complete'
        return
      }

      const forced = await group.upload(payloadOf(item), onProgress, { force: true, signal })

      if (forced?.status === 'success') {
        item.status = 'complete'
        return
      }
    }

    failItem(item)
  } catch (error) {
    failItem(item, error instanceof Error ? error.message : undefined, signal?.aborted === true)
  }
}

const pump = () => {
  while (!isCancelled.value && activeLanes < CONCURRENT_UPLOADS) {
    const next = items.value.find((item) => item.status === 'pending')

    if (!next) {
      break
    }

    const group = next.groupId ? groups.get(next.groupId) : undefined

    if (!group) {
      // Nothing can upload this item any more; failing it beats leaving it
      // pending forever and blocking the batch from settling.
      failItem(next)
      continue
    }

    const laneGeneration = generation

    activeLanes++
    next.status = 'uploading'
    next.progress = 0

    void performUpload(next, group).finally(() => {
      // A lane can outlive its batch: `ensureCsrfCookie` is not abortable, so a
      // lane parked in it survives `reset()` for as long as that fetch takes.
      // Its bookkeeping went with the batch, so it must stay out of the new one.
      if (laneGeneration !== generation) {
        return
      }

      activeLanes--
      flushSettledGroups()
      pump()
    })
  }

  maybeSettle()
}

/**
 * Clears the settled batch to make room for the next one. Failures are carried
 * over: they are the only items the user still has to act on, and dropping them
 * would take the panel's failure list and its Retry button with them. They keep
 * their group, so a retry still has the uploader that item arrived with.
 */
const resetForNewBatch = () => {
  const carried = items.value.filter((item) => item.status === 'error')
  const carriedGroups = new Set(carried.map((item) => item.groupId))

  items.value = carried
  isCancelled.value = false
  duplicateDecision = null
  duplicatePrompt.value = null
  duplicateWaiters = []

  // Deleting the entry the iterator is on is safe on a Map.
  for (const id of groups.keys()) {
    if (!carriedGroups.has(id)) {
      groups.delete(id)
    }
  }
}

/**
 * Reuses the current controller while it is still live: a retry must not hand
 * running lanes a signal that `cancel()` can no longer abort.
 */
const ensureAbortController = () => {
  if (!abortController || abortController.signal.aborted) {
    abortController = new AbortController()
  }
}

const requeue = (toRetry: BatchUploadItem[]) => {
  // Retry is a settled-batch action. Running it mid-flight would un-cancel a
  // draining batch and race the lanes still unwinding.
  if (isRunning.value || !toRetry.length) {
    return
  }

  isCancelled.value = false
  ensureAbortController()

  for (const item of toRetry) {
    item.status = 'pending'
    item.progress = 0
    item.errorMessage = undefined
    item.cancelled = undefined

    const group = item.groupId ? groups.get(item.groupId) : undefined

    if (group) {
      group.settled = false
    }
  }

  isPanelDismissed.value = false
  startRunning()
  pump()
}

/**
 * Drops everything: in-flight requests, queued items and the unload guard. The
 * session that started the batch is gone, so its filenames and its uploader
 * must not survive into the next one.
 */
const resetAssetUploadBatch = () => {
  abortController?.abort()
  abortController = null
  generation++
  activeLanes = 0
  isRunning.value = false
  // Emptied before resetForNewBatch(), which would otherwise carry the previous
  // session's failures over. Nothing of that session may survive.
  items.value = []
  isPanelDismissed.value = false
  window.removeEventListener('beforeunload', guardUnload)

  const waiters = duplicateWaiters
  duplicateWaiters = []
  waiters.forEach((resolve) => resolve('copies'))

  resetForNewBatch()
}

export function useAssetUploadBatch() {
  /**
   * Adds items to the batch. While a batch runs, new items join its queue and
   * totals; a settled batch is replaced, except for its unresolved failures,
   * which are carried over. Items pre-marked as permanent errors (oversize
   * files) surface in the failure list without ever uploading.
   *
   * Items already in the batch are ignored: re-adding one would upload it twice
   * and double-count it. Use `retryItem`/`retryFailed` to run a failure again.
   */
  const enqueue = (candidates: BatchUploadItem[], deps: BatchEnqueueDeps) => {
    const known = new Set(items.value.map((item) => item.id))
    const newItems = candidates.filter((item) => !known.has(item.id))

    if (!newItems.length) {
      return
    }

    if (!isRunning.value) {
      resetForNewBatch()
    }

    ensureAbortController()

    // New work must not inherit a cancel that is still draining, or it would
    // never be pumped.
    isCancelled.value = false

    const groupId = String(++groupSequence)
    groups.set(groupId, { ...deps, settled: false })

    for (const item of newItems) {
      item.groupId = groupId
    }

    isPanelDismissed.value = false

    items.value.push(...newItems)
    startRunning()
    pump()
  }

  /**
   * Stops the queue and aborts in-flight requests. Everything already
   * uploaded, including folders already created, stays.
   */
  const cancel = () => {
    if (!isRunning.value) {
      return
    }

    isCancelled.value = true

    for (const item of items.value) {
      if (item.status === 'pending') {
        failItem(item, undefined, true)
      }
    }

    // Lanes waiting on the duplicate prompt resume and fail right away on the
    // aborted signal. The answer is not recorded as the batch decision.
    duplicatePrompt.value = null
    const waiters = duplicateWaiters
    duplicateWaiters = []
    waiters.forEach((resolve) => resolve('copies'))

    abortController?.abort()
    maybeSettle()
  }

  const retryFailed = () => {
    requeue(items.value.filter((item) => item.status === 'error' && !item.permanentError))
  }

  const retryItem = (id: string) => {
    requeue(
      items.value.filter(
        (item) => item.id === id && item.status === 'error' && !item.permanentError
      )
    )
  }

  const dismissPanel = () => {
    if (isRunning.value) {
      return
    }

    isPanelDismissed.value = true
  }

  return {
    items,
    isRunning,
    isCancelled,
    isPanelDismissed,
    duplicatePrompt,
    batchTotals,
    enqueue,
    cancel,
    retryFailed,
    retryItem,
    dismissPanel,
    resolveDuplicatePrompt,
    reset: resetAssetUploadBatch,
  }
}
