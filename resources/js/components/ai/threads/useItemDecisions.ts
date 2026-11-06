import type { InjectionKey } from 'vue'

import type { AiChangesetItem, AiFindingOverride } from '~/lib/aiChangesets'
import { editedText, isOpenItem, overrideErrors } from '~/lib/aiChangesets'
import { isGlossaryHint, type AiGlossaryFinding } from '~/lib/aiRun'

/** A deterministic finding of a proposal to correct or keep with a reason, known from the item or only from a 422. */
export interface RequiredFinding {
  id: string
  finding?: AiGlossaryFinding
  /** The server's words, for a finding of an edit the item doesn't hold */
  message?: string
}

/**
 * `accepted` closed the item, `open` left it for the reviewer (a conflict, a
 * finding that needs a reason) and `failed` is a reported error.
 */
export type ItemAcceptOutcome = 'accepted' | 'open' | 'failed'

/**
 * What a reviewer has entered on a changeset's open items: edits and reasons
 * for keeping findings, by item, kept until the item is decided. A reason
 * commits with its decision, so a re-post sends it again.
 */
function createItemDecisions(spaceId: MaybeRefOrGetter<string>, changesetId: MaybeRefOrGetter<string>) {
  const { acceptMutation } = useAiThreads(spaceId)

  const edits = ref<Record<string, string | undefined>>({})
  const reasons = ref<Record<string, Record<string, string>>>({})
  /** Findings a 422 named, for the one text they were found in */
  const named = ref<Record<string, { text: string | undefined; findings: Array<{ id: string; message: string }> }>>({})
  /** Bumped when an accept is held back for a missing reason, so the field can take focus */
  const attempts = ref<Record<string, number>>({})
  /** The items on screen and the review group they show in */
  const shown = new Map<string, { item: AiChangesetItem; scope: string | null }>()

  const textOf = (item: AiChangesetItem) => editedText(item, edits.value[item.id])

  /** Keeps an item's entry current. */
  const track = (item: AiChangesetItem, scope: string | null) => {
    shown.set(item.id, { item, scope })
    if (!isOpenItem(item)) forget(item.id)
  }

  const forget = (id: string) => {
    delete edits.value[id]
    delete reasons.value[id]
    delete named.value[id]
    delete attempts.value[id]
  }

  const setEdit = (item: AiChangesetItem, value: string) => {
    edits.value[item.id] = value
  }

  const discardEdit = (item: AiChangesetItem) => {
    edits.value[item.id] = undefined
  }

  /** The violations of the text an accept would apply. Findings of the proposal don't bind an edit, and a hint binds nothing. Content has a draft, so its findings bind nothing either. */
  const required = (item: AiChangesetItem): RequiredFinding[] => {
    if (item.target_type === 'selection' || item.target_type === 'content') return []

    const text = textOf(item)
    const entry = named.value[item.id]
    const fromServer = entry && entry.text === text ? entry.findings : []
    const own = text === undefined ? item.glossary_findings.filter((finding) => !isGlossaryHint(finding)) : []
    const known = new Map(item.glossary_findings.map((finding) => [finding.id, finding]))

    return [
      ...own.map((finding): RequiredFinding => ({ id: finding.id, finding })),
      ...fromServer
        .filter(({ id }) => !own.some((finding) => finding.id === id))
        .map(({ id, message }): RequiredFinding => ({ id, finding: known.get(id), message })),
    ]
  }

  const reason = (itemId: string, findingId: string) => reasons.value[itemId]?.[findingId] ?? ''

  const setReason = (itemId: string, findingId: string, value: string) => {
    ;(reasons.value[itemId] ??= {})[findingId] = value
  }

  /** Only reasons for findings on screen go out; a tick or a blank never counts as one. */
  const overridesFor = (item: AiChangesetItem): AiFindingOverride[] =>
    required(item).flatMap(({ id }) => {
      const given = reason(item.id, id).trim()
      return given ? [{ finding_id: id, reason: given }] : []
    })

  const missingReasons = (item: AiChangesetItem) => required(item).filter(({ id }) => !reason(item.id, id).trim())

  /** Sends the text and reasons on screen. Called again for the same text only by a reviewer's click. */
  const accept = async (item: AiChangesetItem, overwrite = false): Promise<ItemAcceptOutcome> => {
    if (acceptMutation.isPending.value) return 'open'
    if (missingReasons(item).length > 0) {
      attempts.value[item.id] = (attempts.value[item.id] ?? 0) + 1
      return 'open'
    }

    const text = textOf(item)
    const overrides = overridesFor(item)
    try {
      const result = await acceptMutation.mutateAsync({
        changesetId: toValue(changesetId),
        decision: {
          item_ids: [item.id],
          overwrite,
          ...(text !== undefined && { values: { [item.id]: text } }),
          ...(overrides.length > 0 && { overrides: { [item.id]: overrides } }),
        },
        items: [item],
      })
      if ((result.accepted ?? 0) > 0) {
        forget(item.id)
        return 'accepted'
      }
      return 'open'
    } catch (error) {
      const findings = overrideErrors(error)[item.id]
      if (!findings) return 'failed'
      named.value[item.id] = { text, findings }
      attempts.value[item.id] = (attempts.value[item.id] ?? 0) + 1
      return 'open'
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
    attempts,
    track,
    setEdit,
    discardEdit,
    required,
    reason,
    setReason,
    missingReasons,
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
