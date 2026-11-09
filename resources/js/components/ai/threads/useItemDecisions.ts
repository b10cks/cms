import type { InjectionKey } from 'vue'

import type { AiChangesetItem } from '~/lib/aiChangesets'
import { editedText, isOpenItem } from '~/lib/aiChangesets'

/** `accepted` closed the item, `open` left it for the reviewer (a conflict) and `failed` is a reported error. */
export type ItemAcceptOutcome = 'accepted' | 'open' | 'failed'

/** What a reviewer has edited on a changeset's open items, by item, kept until the item is decided. */
function createItemDecisions(spaceId: MaybeRefOrGetter<string>, changesetId: MaybeRefOrGetter<string>) {
  const { acceptMutation } = useAiThreads(spaceId)

  const edits = ref<Record<string, string | undefined>>({})
  /** The items on screen and the review group they show in */
  const shown = new Map<string, { item: AiChangesetItem; scope: string | null }>()

  const textOf = (item: AiChangesetItem) => editedText(item, edits.value[item.id])

  /** Keeps an item's entry current. */
  const track = (item: AiChangesetItem, scope: string | null) => {
    shown.set(item.id, { item, scope })
    if (!isOpenItem(item)) delete edits.value[item.id]
  }

  const setEdit = (item: AiChangesetItem, value: string) => {
    edits.value[item.id] = value
  }

  const discardEdit = (item: AiChangesetItem) => {
    edits.value[item.id] = undefined
  }

  /** Sends the text on screen. */
  const accept = async (item: AiChangesetItem, overwrite = false): Promise<ItemAcceptOutcome> => {
    if (acceptMutation.isPending.value) return 'open'

    const text = textOf(item)
    try {
      const result = await acceptMutation.mutateAsync({
        changesetId: toValue(changesetId),
        decision: {
          item_ids: [item.id],
          overwrite,
          ...(text !== undefined && { values: { [item.id]: text } }),
        },
        items: [item],
      })
      if ((result.accepted ?? 0) > 0) {
        delete edits.value[item.id]
        return 'accepted'
      }
      return 'open'
    } catch {
      return 'failed'
    }
  }

  /** Open edits in the given review groups. */
  const unsentEdits = (scopes: readonly string[]) =>
    [...shown.values()]
      .filter(({ item, scope }) => scope !== null && scopes.includes(scope) && isOpenItem(item) && textOf(item) !== undefined)
      .map(({ item }) => item)

  /** Edits a bulk accept may apply itself; the server leaves the rest open whatever is sent. */
  const editsOf = (items: readonly AiChangesetItem[]) =>
    Object.fromEntries(
      items.flatMap((item) => {
        const text = textOf(item)
        return text === undefined ? [] : [[item.id, text] as const]
      })
    )

  return {
    acceptMutation,
    edits,
    track,
    setEdit,
    discardEdit,
    accept,
    unsentEdits,
    editsOf,
  }
}

export type ItemDecisions = ReturnType<typeof createItemDecisions>

const ITEM_DECISIONS: InjectionKey<ItemDecisions> = Symbol('aiItemDecisions')

/** One per changeset review, shared by every card and group under it. */
export function provideItemDecisions(spaceId: MaybeRefOrGetter<string>, changesetId: MaybeRefOrGetter<string>) {
  const decisions = createItemDecisions(spaceId, changesetId)
  provide(ITEM_DECISIONS, decisions)
  return decisions
}

export function useItemDecisions(): ItemDecisions {
  const decisions = inject(ITEM_DECISIONS)
  if (!decisions) throw new Error('useItemDecisions needs provideItemDecisions in a parent')
  return decisions
}
