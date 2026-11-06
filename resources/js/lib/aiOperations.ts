/**
 * Types and helpers for AI content operations: a frozen, recursive scope of
 * up to 10,000 entries, an approved plan, budgeted batches and grouped
 * review. The API contract is in docs/milestones/ai-content-operations.md.
 */
import type { AiChangeset, AiTargetType } from '~/lib/aiChangesets'
import type { AiRunState } from '~/lib/aiRun'

export type AiOperationKind = 'translation' | 'assets' | 'general'

export type AiOperationState = 'draft' | 'running' | 'paused' | 'completed' | 'cancelled'

export type AiOperationPauseReason = 'budget' | 'user' | 'access_revoked' | 'batch_failed' | 'batch_cancelled'

export const AI_OPERATION_ITEM_STATUSES = ['pending', 'running', 'written', 'proposed', 'conflict', 'unchanged', 'skipped', 'failed'] as const

export type AiOperationItemStatus = (typeof AI_OPERATION_ITEM_STATUSES)[number]

/** Kinds an editor can start per target type; `general` runs any bulk skill. */
export const AI_OPERATION_KINDS = {
  content: ['translation', 'general'],
  asset: ['assets', 'general'],
  data_entry: ['general'],
} as const satisfies Record<AiTargetType, readonly AiOperationKind[]>

/** Ability that steers an operation on each target type, as the backend checks it. */
export const AI_OPERATION_SURFACES = {
  content: 'bulk:content',
  asset: 'bulk:assets',
  data_entry: 'bulk:data_entries',
} as const satisfies Record<AiTargetType, string>

export const AI_ASSET_PERMISSIONS = ['overwrite', 'remove_tags', 'move'] as const

export type AiAssetPermission = (typeof AI_ASSET_PERMISSIONS)[number]

export const AI_TRANSLATION_INCLUDE = ['missing', 'empty', 'stale', 'same_as_source'] as const

export type AiTranslationInclude = (typeof AI_TRANSLATION_INCLUDE)[number]

/**
 * A recursive scope. A root is a parent (its children, all descendants when
 * recursive); explicit IDs count themselves plus their descendants when recursive.
 */
export interface AiOperationScope {
  type: AiTargetType
  ids?: string[] | null
  root_id?: string | null
  recursive?: boolean
  filters?: Record<string, string>
}

export interface AiOperationEstimate {
  input_tokens: number
  output_tokens: number
  cost_usd: number
}

export interface AiOperationCounts {
  total: number
  supported: number
  unsupported: number
  /** Entries per block slug or file family */
  groups: Record<string, number>
  tokens: number
  estimate: AiOperationEstimate
  /** Hash of the frozen entry IDs; approval sends it back */
  scope_hash: string
  /** Stored when the operation completes, pauses or is cancelled; absent before this existed */
  coverage?: AiOperationStoredCoverage
  /** Present only above zero, added as each batch settles */
  provider_retries?: number
  /** Interrupted provider calls whose cost may not be in the spend; present only above zero */
  unconfirmed_provider_calls?: number
}

export interface AiOperationStoredCoverage {
  /** False when the plan named no outputs; `complete` is null then */
  verified: boolean
  complete: boolean | null
  outputs: Record<string, number>
  entries: Partial<Record<AiOperationItemStatus, number>>
}

export interface AiPlannedFolder {
  key: string
  name: string
  parent_id: string | null
  parent_key: string | null
  reason: string
  /** Frozen asset IDs that justify the folder */
  examples: string[]
}

export interface AiPlannedTag {
  name: string
  reason: string
  examples: string[]
}

/** What each asset owes: field keys per language, and whether tags are requested. */
export interface AiAssetOutputs {
  fields: string[]
  tags: boolean
}

/** The metadata values generation may write. */
export const AI_METADATA_KEYS = ['title', 'description', 'ogTitle', 'ogDescription'] as const

export type AiMetadataKey = (typeof AI_METADATA_KEYS)[number]

/**
 * What metadata generation owes on each entry. An empty `fields` means every
 * meta field of the scope's blocks, and an empty `locales` every language the
 * plan allows. A nested field is named `<block slug>.<field key>`.
 */
export interface AiMetadataOutputs {
  keys: string[]
  overwrite_keys: string[]
  fields: string[]
  locales: string[]
}

export type AiPlanScalar = string | number | boolean | null
export type AiPlanOptionValue = AiPlanScalar | Array<string | number | boolean>

export interface AiOperationPlan {
  summary: string
  rules: string[]
  /** Domain settings; PHP sends an empty object as `[]` */
  options: Record<string, AiPlanOptionValue> | []
  locales?: string[]
  include?: AiTranslationInclude[]
  permissions?: Record<AiAssetPermission, boolean>
  /** Field keys an overwrite may replace; empty unless `permissions.overwrite` */
  overwrite_fields?: string[]
  /** Asset metadata language keys to write (`_default` for the default); empty writes all */
  languages?: string[]
  structure?: { folders: AiPlannedFolder[]; tags: AiPlannedTag[] }
  /** Assets: requested outputs. Absent on older plans, which stay unverified */
  outputs?: AiAssetOutputs
  /** General on content: turns the plan into metadata generation. Translation: also writes it from each locale's translated draft */
  metadata?: AiMetadataOutputs
}

/** The plan as the editor edits it: every key of its kind present. */
export interface AiEditablePlan {
  summary: string
  rules: string[]
  options: Record<string, AiPlanOptionValue>
  locales: string[]
  include: AiTranslationInclude[]
  permissions: Record<AiAssetPermission, boolean>
  overwrite_fields: string[]
  languages: string[]
  structure: { folders: AiPlannedFolder[]; tags: AiPlannedTag[] }
  /** Null keeps the key out of the plan, so its hash and meaning stay as they were */
  outputs: AiAssetOutputs | null
  metadata: AiMetadataOutputs | null
}

/** What approval sends: the plan and scope preview on screen, and the budget. */
export interface AiOperationApproval {
  plan_hash: string
  scope_hash: string
  max_usd: number
}

export interface AiOperation {
  id: string
  kind: AiOperationKind
  state: AiOperationState
  pause_reason: AiOperationPauseReason | null
  pause_requested: boolean
  error: string | null
  skill: string | null
  surface: string
  prompt: string
  conversation_id: string | null
  schedule_id: string | null
  scope: AiOperationScope & { recursive: boolean }
  counts: AiOperationCounts
  plan: AiOperationPlan | null
  plan_hash: string | null
  plan_run_id: string | null
  approved_plan: AiOperationPlan | null
  approved_plan_hash: string | null
  approved_at: string | null
  budget: { max_usd: number; spent_usd: number; remaining_usd: number }
  usage: { input_tokens: number; output_tokens: number }
  progress: {
    by_status: Record<AiOperationItemStatus, number>
    /** Pending or running: work not done yet */
    owed: number
    reasons: Partial<Record<'skipped' | 'failed', Record<string, number>>>
    batches: number
  }
  current_run: { id: string; state: AiRunState } | null
  changeset_id: string | null
  /** The shared changeset's counts, without items */
  changeset: (AiChangeset & { operation_id: string }) | null
  created_at: string | null
  finished_at: string | null
}

export interface StartAiOperationRequest {
  kind: AiOperationKind
  prompt: string
  skill?: string | null
  config_id?: string | null
  conversation_id?: string | null
  scope: AiOperationScope
}

/** One frozen entry of the ledger. */
export interface AiOperationItem {
  id: string
  position: number
  target_type: AiTargetType
  target_id: string
  label: string
  group: string | null
  status: AiOperationItemStatus
  reason: string | null
  /** The run's own words for an `agent` reason */
  note: string | null
  version_changed: boolean
  attempts: number
  batch: number | null
  run_id: string | null
  /** Outcomes keyed by locale or requested output path; PHP sends an empty object as `[]` */
  parts: Record<string, AiOperationPart> | []
  /** Parts this entry owes, frozen from the approved plan; null when it is one piece */
  expected_parts?: string[] | null
  /** Open parts holding a value the plan may replace */
  overwritable?: string[]
  /** Translation units written and remaining, by locale */
  units?: Record<string, { written: string[]; remaining: string[] }> | []
  proposals?: number
}

export interface AiOperationPart {
  outcome: string
  reason?: string
  note?: string
  question?: string
  batch?: number
}

export interface AiOperationItemPage {
  data: AiOperationItem[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

/** Blocked outputs asking the same thing. `fingerprint` pins its exact members for an answer to all of them. */
export interface AiOperationQuestion {
  id: string
  question: string | null
  note: string | null
  fingerprint: string
  outputs: number
  entries: number
}

export interface AiOperationQuestionsSummary {
  /** The queue has drained, so answers can resume work now */
  accepting: boolean
  questions: number
  outputs: number
  entries: number
  answered: number
  /** Answered outputs still waiting for their batch */
  resuming: number
}

export interface AiOperationQuestionPage {
  data: AiOperationQuestion[]
  meta: AiOperationItemPage['meta']
  summary: AiOperationQuestionsSummary
}

export interface AiOperationQuestionMember {
  id: string
  item_id: number
  target_type: string
  target_id: string
  label: string | null
  part: string
  question: string | null
  note: string | null
}

export interface AiOperationQuestionMemberPage {
  data: AiOperationQuestionMember[]
  meta: AiOperationItemPage['meta']
}

export interface AiOperationAnsweredQuestion {
  question: string | null
  answer: string
  answered_by: { id: string; name: string } | null
  answered_at: string
  outputs: number
  entries: number
}

export interface AiOperationAnsweredPage {
  data: AiOperationAnsweredQuestion[]
  meta: AiOperationItemPage['meta']
}

export type AiOperationAnswer = { question_id: string; answer: string } & (
  | { fingerprint: string; member_ids?: never }
  | { member_ids: string[]; fingerprint?: never }
)

export interface AiOperationAnswerResult {
  question_id: string
  status: 'recorded' | 'duplicate' | 'stale'
  recorded: number
  duplicate: number
  stale: number
}

/**
 * The answers worth sending: a whole group at the membership it was shown with,
 * and single outputs by name. The server applies single outputs first, so a
 * group answer covers what is left. Blank drafts stay unanswered.
 */
export function questionAnswers(
  questions: readonly AiOperationQuestion[],
  groupDrafts: Readonly<Record<string, string>>,
  memberDrafts: Readonly<Record<string, Readonly<Record<string, string>>>>
): AiOperationAnswer[] {
  const answers: AiOperationAnswer[] = []
  for (const question of questions) {
    for (const [memberId, draft] of Object.entries(memberDrafts[question.id] ?? {})) {
      if (draft.trim()) answers.push({ question_id: question.id, answer: draft.trim(), member_ids: [memberId] })
    }
    const draft = groupDrafts[question.id]?.trim()
    if (draft) answers.push({ question_id: question.id, answer: draft, fingerprint: question.fingerprint })
  }
  return answers
}

export interface AiOperationArrivals {
  count: number
  entries: Array<{ id: string; name: string }>
}

/** The 422 a scope above the limit returns; nothing was frozen. */
export interface AiScopeTooLarge {
  reason: 'scope_too_large'
  count: number
  limit: number
}

/** The 409 an approval gets when entries joined or left the scope since its preview. */
export interface AiScopeChanged {
  reason: 'scope_changed'
  added: number
  removed: number
}

interface ApiErrorShape {
  status?: number
  data?: { reason?: unknown; errors?: unknown; count?: unknown; limit?: unknown; added?: unknown; removed?: unknown }
}

const errorShape = (error: unknown): ApiErrorShape =>
  typeof error === 'object' && error !== null ? (error as ApiErrorShape) : {}

/** The `reason` code of a refused API call (`plan_changed`, `budget`, `concurrency_limit`, …). */
export function apiErrorReason(error: unknown): string | null {
  const reason = errorShape(error).data?.reason
  return typeof reason === 'string' ? reason : null
}

export function scopeTooLarge(error: unknown): AiScopeTooLarge | null {
  const data = errorShape(error).data
  if (data?.reason !== 'scope_too_large' || typeof data.count !== 'number' || typeof data.limit !== 'number') return null
  return { reason: 'scope_too_large', count: data.count, limit: data.limit }
}

export function scopeChanged(error: unknown): AiScopeChanged | null {
  const data = errorShape(error).data
  if (data?.reason !== 'scope_changed') return null
  const count = (value: unknown) => (typeof value === 'number' ? value : 0)
  return { reason: 'scope_changed', added: count(data.added), removed: count(data.removed) }
}

/** A recount refused because no entry is left in the scope. */
export function scopeEmpty(error: unknown): boolean {
  const { status, data } = errorShape(error)
  return status === 422 && typeof data?.errors === 'object' && data.errors !== null && 'scope' in data.errors
}

/** Field errors of a refused plan save, keyed without the `plan.` prefix. */
export function planErrors(error: unknown): Record<string, string> {
  const errors = errorShape(error).data?.errors
  if (typeof errors !== 'object' || errors === null) return {}

  return Object.fromEntries(
    Object.entries(errors).flatMap(([key, messages]) => {
      const message = Array.isArray(messages) ? messages[0] : messages
      return typeof message === 'string' ? [[key.replace(/^plan\./, ''), message]] : []
    })
  )
}

/** The output requirements a plan written from scratch starts with, so its coverage can be checked. */
function freshOutputs(operation: Pick<AiOperation, 'kind' | 'skill' | 'scope'>): Pick<AiEditablePlan, 'outputs' | 'metadata'> {
  return {
    outputs: operation.kind === 'assets' ? { fields: [], tags: true } : null,
    metadata: operation.kind === 'general' && operation.skill === 'meta-tags' && operation.scope.type === 'content' ? newMetadataOutputs() : null,
  }
}

export const newMetadataOutputs = (): AiMetadataOutputs => ({
  keys: ['title', 'description'],
  overwrite_keys: [],
  fields: [],
  locales: [],
})

/**
 * A complete draft of any plan, the server's or none yet. With no plan,
 * `operation` picks the requirements a new plan starts with. A plan that
 * exists keeps what it says, including naming no outputs at all.
 */
export function editablePlan(plan: AiOperationPlan | null, operation?: Pick<AiOperation, 'kind' | 'skill' | 'scope'>): AiEditablePlan {
  const options = plan?.options && !Array.isArray(plan.options) ? plan.options : {}
  return {
    summary: plan?.summary ?? '',
    rules: [...(plan?.rules ?? [])],
    options: { ...options },
    locales: [...(plan?.locales ?? [])],
    include: [...(plan?.include ?? ['missing', 'empty', 'stale'])],
    permissions: { overwrite: false, remove_tags: false, move: false, ...plan?.permissions },
    overwrite_fields: [...(plan?.overwrite_fields ?? [])],
    languages: [...(plan?.languages ?? [])],
    structure: {
      folders: (plan?.structure?.folders ?? []).map((folder) => ({ ...folder, examples: [...folder.examples] })),
      tags: (plan?.structure?.tags ?? []).map((tag) => ({ ...tag, examples: [...tag.examples] })),
    },
    ...(plan === null && operation
      ? freshOutputs(operation)
      : {
          outputs: plan?.outputs ? { fields: [...plan.outputs.fields], tags: plan.outputs.tags } : null,
          metadata: plan?.metadata
            ? {
                keys: [...plan.metadata.keys],
                overwrite_keys: [...plan.metadata.overwrite_keys],
                fields: [...plan.metadata.fields],
                locales: [...plan.metadata.locales],
              }
            : null,
        }),
  }
}

/** Tags are owed whenever the plan removes or plans them; the server refuses the plan otherwise. */
export const tagsRequired = (draft: Pick<AiEditablePlan, 'permissions' | 'structure'>) =>
  draft.permissions.remove_tags || draft.structure.tags.length > 0

/**
 * The draft as the API takes it: only the keys of the operation's kind,
 * blank rules dropped, overwrite fields only while overwriting is allowed.
 */
export function planPayload(kind: AiOperationKind, draft: AiEditablePlan): AiOperationPlan {
  const base = {
    summary: draft.summary.trim(),
    rules: draft.rules.map((rule) => rule.trim()).filter(Boolean),
    options: draft.options,
  }
  if (kind === 'translation') {
    return {
      ...base,
      locales: draft.locales,
      include: draft.include,
      ...metadataPayload(draft.metadata, (locale) => draft.locales.includes(locale)),
    }
  }
  if (kind === 'assets') {
    const structure = { folders: reachableFolders(draft.structure.folders), tags: draft.structure.tags }
    const overwrite_fields = draft.permissions.overwrite ? draft.overwrite_fields : []
    return {
      ...base,
      permissions: draft.permissions,
      overwrite_fields,
      languages: draft.languages,
      structure,
      ...(draft.outputs && { outputs: { fields: draft.outputs.fields, tags: draft.outputs.tags || tagsRequired(draft) } }),
    }
  }
  return { ...base, ...metadataPayload(draft.metadata, () => true) }
}

/** Absent stays absent, so a plan saved without metadata keeps its hash. */
function metadataPayload(metadata: AiMetadataOutputs | null, allowed: (locale: string) => boolean): Pick<AiOperationPlan, 'metadata'> {
  if (!metadata) return {}
  return {
    metadata: {
      ...metadata,
      overwrite_keys: metadata.overwrite_keys.filter((key) => metadata.keys.includes(key)),
      locales: metadata.locales.filter(allowed),
    },
  }
}

/** Whether the draft differs from the plan the server holds. */
export function planChanged(kind: AiOperationKind, draft: AiEditablePlan, plan: AiOperationPlan | null): boolean {
  if (!plan) return true
  return JSON.stringify(planPayload(kind, draft)) !== JSON.stringify(planPayload(kind, editablePlan(plan)))
}

/** Planned folders whose ancestors are all still in the draft: removing a parent drops its children. */
export function reachableFolders(folders: readonly AiPlannedFolder[]): AiPlannedFolder[] {
  const byKey = new Map(folders.map((folder) => [folder.key, folder]))
  const reachable = (folder: AiPlannedFolder, seen = new Set<string>()): boolean => {
    if (folder.parent_key === null) return true
    const parent = byKey.get(folder.parent_key)
    if (!parent || seen.has(parent.key)) return false
    seen.add(parent.key)
    return reachable(parent, seen)
  }
  return folders.filter((folder) => reachable(folder))
}

/** Entries done: everything not pending or running. */
export const settledCount = (operation: Pick<AiOperation, 'counts' | 'progress'>) =>
  operation.counts.total - operation.progress.owed

export const progressPercent = (operation: Pick<AiOperation, 'counts' | 'progress'>) =>
  operation.counts.total > 0 ? Math.round((settledCount(operation) / operation.counts.total) * 100) : 0

export type AiOperationOutcome =
  | 'draft'
  | 'running'
  | 'pausing'
  | 'paused'
  | 'completed'
  | 'completed_with_exceptions'
  | 'completed_with_gaps'
  | 'cancelled'
  | 'cancelled_partial'

/**
 * What the operation's state means for the editor. A completed operation
 * has no entry left in the queue, nothing more: with skipped or failed entries,
 * or with requested outputs that coverage says are unfulfilled, it is not
 * reported as a clean finish. A cancelled one with owed entries says they
 * were never processed.
 */
export function operationOutcome(
  operation: Pick<AiOperation, 'state' | 'pause_requested' | 'progress'> & { counts?: Pick<AiOperationCounts, 'coverage'> }
): AiOperationOutcome {
  const exceptions = operation.progress.by_status.skipped + operation.progress.by_status.failed
  switch (operation.state) {
    case 'running':
      return operation.pause_requested ? 'pausing' : 'running'
    case 'completed':
      if (exceptions > 0) return 'completed_with_exceptions'
      return operation.counts?.coverage?.complete === false ? 'completed_with_gaps' : 'completed'
    case 'cancelled':
      return operation.progress.owed > 0 ? 'cancelled_partial' : 'cancelled'
    default:
      return operation.state
  }
}

/** Outcomes that fulfil a requested output. `unchanged` is the translation word for `satisfied`. */
const FULFILLED_OUTCOMES: ReadonlySet<string> = new Set(['written', 'proposed', 'satisfied', 'unchanged', 'intentionally_empty'])

/** Not yet settled and no attempt ended on it. */
const OPEN_OUTCOME = 'open'

export type AiOutputTone = 'fulfilled' | 'open' | 'exception'

export const outcomeTone = (outcome: string): AiOutputTone =>
  FULFILLED_OUTCOMES.has(outcome) ? 'fulfilled' : outcome === OPEN_OUTCOME ? 'open' : 'exception'

/** What a part key names. Translation parts are locales, and sometimes one unit of a locale; other parts are outputs, some in a language. */
export type AiOutputTarget =
  | { type: 'locale'; language: string }
  | { type: 'unit'; language: string; unit: string }
  | { type: 'field'; language: string; field: string }
  | { type: 'metadata'; language: string; field: string; key: string }
  | { type: 'tags' | 'folder'; language: null }
  | { type: 'other'; language: null }

/** Reads `<locale>`, `<locale>#<unit>`, `<language>.<field>`, `tags`, `folder` or `<locale>.<field path>.<key>` by operation kind. */
export function outputTarget(kind: AiOperationKind, part: string): AiOutputTarget {
  if (kind === 'translation') {
    const separator = part.indexOf('#')
    if (separator > 0) return { type: 'unit', language: part.slice(0, separator), unit: part.slice(separator + 1) }
    if (!part.includes('.')) return { type: 'locale', language: part }
  }

  const first = part.indexOf('.')
  if (kind === 'assets') {
    if (part === 'tags' || part === 'folder') return { type: part, language: null }
    if (first > 0) return { type: 'field', language: part.slice(0, first), field: part.slice(first + 1) }
    return { type: 'other', language: null }
  }

  const last = part.lastIndexOf('.')
  if (first > 0 && last > first) {
    return { type: 'metadata', language: part.slice(0, first), field: part.slice(first + 1, last), key: part.slice(last + 1) }
  }
  return { type: 'other', language: null }
}

export interface AiOutputRow extends Omit<AiOperationPart, 'batch'> {
  part: string
  target: AiOutputTarget
  tone: AiOutputTone
  /** The plan allows replacing the value this part holds */
  overwritable: boolean
  units: { written: string[]; remaining: string[] } | null
}

/**
 * Every output an entry owes with its outcome. Translation entries owe the
 * approved locales unless they froze their own list; an owed part with no
 * record is open while the entry is queued and unfinished after that, which
 * is how coverage counts it too.
 */
export function outputRows(operation: Pick<AiOperation, 'kind' | 'approved_plan'>, item: AiOperationItem): AiOutputRow[] {
  const parts = Array.isArray(item.parts) ? {} : item.parts
  const units = item.units && !Array.isArray(item.units) ? item.units : {}
  const owed = item.expected_parts ?? (operation.kind === 'translation' ? (operation.approved_plan?.locales ?? []) : [])
  const queued = item.status === 'pending' || item.status === 'running'

  return [...new Set([...owed, ...Object.keys(parts)])].map((part) => {
    const stored = parts[part]
    const outcome = stored?.outcome ?? (queued ? OPEN_OUTCOME : 'unfinished')
    return {
      part,
      target: outputTarget(operation.kind, part),
      outcome,
      tone: outcomeTone(outcome),
      reason: stored?.reason,
      note: stored?.note,
      question: stored?.question,
      overwritable: item.overwritable?.includes(part) ?? false,
      units: units[part] ?? null,
    }
  })
}

/** Outcomes with their counts, exceptions first so they are not buried. */
function outcomeCounts(counts: Record<string, number>): Array<{ outcome: string; count: number; tone: AiOutputTone }> {
  const order: Record<AiOutputTone, number> = { exception: 0, open: 1, fulfilled: 2 }
  return Object.entries(counts)
    .filter(([, count]) => count > 0)
    .map(([outcome, count]) => ({ outcome, count, tone: outcomeTone(outcome) }))
    .sort((a, b) => order[a.tone] - order[b.tone])
}

/** How one entry's outputs came out, for its line in the ledger. */
export function outputSummary(rows: readonly AiOutputRow[]) {
  const counts: Record<string, number> = {}
  for (const row of rows) counts[row.outcome] = (counts[row.outcome] ?? 0) + 1
  return {
    total: rows.length,
    fulfilled: rows.filter((row) => row.tone === 'fulfilled').length,
    others: outcomeCounts(counts).filter((entry) => entry.tone !== 'fulfilled'),
  }
}

export type AiCoverageState = 'unverified' | 'counting' | 'complete' | 'incomplete'

/**
 * What the operation can say about the outputs it was asked for. A plan
 * naming no outputs, or one finished before coverage existed, stays unverified:
 * finishing its entries proves nothing per field. While it runs the stored
 * figures are from the last pause, so they are not shown as current.
 */
export function operationCoverage(operation: Pick<AiOperation, 'kind' | 'state' | 'approved_plan' | 'counts'>) {
  const stored = operation.counts.coverage
  const plan = operation.approved_plan
  const named = operation.kind === 'translation' || !!plan?.outputs || !!plan?.metadata
  const running = operation.state === 'running'

  const state: AiCoverageState =
    !named || stored?.verified === false || (!stored && !running)
      ? 'unverified'
      : running || !stored
        ? 'counting'
        : stored.complete
          ? 'complete'
          : 'incomplete'
  const counted = state === 'complete' || state === 'incomplete'

  return {
    state,
    outputs: counted ? outcomeCounts(stored?.outputs ?? {}) : [],
    questions: counted ? (stored?.outputs.blocked ?? 0) : 0,
    /** Entries that ended without their outputs being worked on */
    skipped: counted ? (stored?.entries.skipped ?? 0) : 0,
    failed: counted ? (stored?.entries.failed ?? 0) : 0,
  }
}

/** Whether the operation changes while nobody acts, so the client should poll. */
export const isOperationLive = (operation: Pick<AiOperation, 'state'> | null | undefined) =>
  operation?.state === 'running'

/**
 * A starting budget: the estimate with a quarter on top, rounded up to the
 * next ten cents. Null when nothing is estimated; the editor enters one.
 */
export function suggestedBudget(estimate: AiOperationEstimate | undefined): number | null {
  if (!estimate || estimate.cost_usd <= 0) return null
  return Math.min(1000, Math.max(0.1, Math.ceil(estimate.cost_usd * 1.25 * 10) / 10))
}

/** The descendants of a planned folder for display, depth first. */
export function plannedFolderTree(folders: readonly AiPlannedFolder[]): Array<{ folder: AiPlannedFolder; depth: number }> {
  const children = new Map<string | null, AiPlannedFolder[]>()
  for (const folder of folders) {
    const parent = folder.parent_key && folders.some((other) => other.key === folder.parent_key) ? folder.parent_key : null
    children.set(parent, [...(children.get(parent) ?? []), folder])
  }
  const rows: Array<{ folder: AiPlannedFolder; depth: number }> = []
  const walk = (parent: string | null, depth: number) => {
    for (const folder of children.get(parent) ?? []) {
      rows.push({ folder, depth })
      if (depth < 20) walk(folder.key, depth + 1)
    }
  }
  walk(null, 0)
  return rows
}
