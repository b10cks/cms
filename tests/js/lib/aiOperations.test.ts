import { describe, expect, it } from 'vitest'

import type { AiOperation, AiOperationItem, AiOperationPlan, AiPlannedFolder } from '~/lib/aiOperations'
import {
  outcomeTone,
  apiErrorReason,
  editablePlan,
  operationCoverage,
  operationOutcome,
  outputRows,
  outputSummary,
  outputTarget,
  planChanged,
  planErrors,
  planPayload,
  plannedFolderTree,
  questionAnswers,
  scopeTooLarge,
  suggestedBudget,
} from '~/lib/aiOperations'

const folder = (key: string, parent_key: string | null = null): AiPlannedFolder => ({
  key,
  name: key,
  parent_id: parent_key ? null : 'root',
  parent_key,
  reason: 'Grouped products',
  examples: ['asset-1'],
})

const progress = (overrides: Partial<Record<'skipped' | 'failed', number>> = {}, owed = 0): AiOperation['progress'] => ({
  by_status: { pending: owed, running: 0, written: 0, proposed: 3, conflict: 0, unchanged: 1, skipped: 0, failed: 0, ...overrides },
  owed,
  reasons: {},
  batches: 2,
})

describe('planPayload', () => {
  it('sends only the keys of the operation kind', () => {
    const draft = editablePlan({ summary: ' Translate ', rules: ['Keep brand names', '  '], options: [] })

    expect(planPayload('general', draft)).toEqual({ summary: 'Translate', rules: ['Keep brand names'], options: {} })
    expect(Object.keys(planPayload('translation', draft))).toEqual(['summary', 'rules', 'options', 'locales', 'include'])
    expect(Object.keys(planPayload('assets', draft))).toEqual([
      'summary',
      'rules',
      'options',
      'permissions',
      'overwrite_fields',
      'languages',
      'structure',
    ])
  })

  it('sends overwrite fields only while overwriting is allowed', () => {
    const draft = editablePlan({
      summary: 'Fix alt texts',
      rules: [],
      options: [],
      permissions: { overwrite: true, remove_tags: false, move: false },
      overwrite_fields: ['alt'],
      languages: ['_default'],
    })

    expect(planPayload('assets', draft)).toMatchObject({ overwrite_fields: ['alt'], languages: ['_default'] })

    const off = { ...draft, permissions: { ...draft.permissions, overwrite: false } }
    expect(planPayload('assets', off)).toMatchObject({ overwrite_fields: [], languages: ['_default'] })
    expect(off.overwrite_fields).toEqual(['alt'])
  })

  it('drops planned folders whose parent folder was removed', () => {
    const draft = editablePlan({
      summary: 'Sort',
      rules: [],
      options: {},
      structure: { folders: [folder('shoes'), folder('boots', 'shoes'), folder('laces', 'gone')], tags: [] },
    })

    expect(planPayload('assets', draft).structure?.folders.map((entry) => entry.key)).toEqual(['shoes', 'boots'])
  })

  it('defaults asset permissions to off and translation work to missing, empty and stale', () => {
    const draft = editablePlan(null)

    expect(draft.permissions).toEqual({ overwrite: false, remove_tags: false, move: false })
    expect(draft.include).toEqual(['missing', 'empty', 'stale'])
  })
})

describe('planChanged', () => {
  it('compares what would be sent, not how the server spelled an empty value', () => {
    const plan = { summary: 'Translate', rules: [], options: [] as [], locales: ['de'], include: ['missing' as const] }
    const draft = editablePlan(plan)

    expect(planChanged('translation', draft, plan)).toBe(false)
    expect(planChanged('translation', { ...draft, locales: ['de', 'fr'] }, plan)).toBe(true)
    expect(planChanged('translation', draft, null)).toBe(true)
  })
})

describe('operationOutcome', () => {
  it('does not report skipped or failed entries as a clean finish', () => {
    expect(operationOutcome({ state: 'completed', pause_requested: false, progress: progress() })).toBe('completed')
    expect(operationOutcome({ state: 'completed', pause_requested: false, progress: progress({ failed: 2 }) })).toBe(
      'completed_with_exceptions'
    )
  })

  it('names a cancellation that left entries unprocessed and a requested pause', () => {
    expect(operationOutcome({ state: 'cancelled', pause_requested: false, progress: progress({}, 40) })).toBe('cancelled_partial')
    expect(operationOutcome({ state: 'cancelled', pause_requested: false, progress: progress() })).toBe('cancelled')
    expect(operationOutcome({ state: 'running', pause_requested: true, progress: progress({}, 40) })).toBe('pausing')
  })
})

describe('suggestedBudget', () => {
  it('adds a quarter to the estimate and rounds up to ten cents', () => {
    expect(suggestedBudget({ input_tokens: 1, output_tokens: 1, cost_usd: 1.01 })).toBe(1.3)
    expect(suggestedBudget({ input_tokens: 1, output_tokens: 1, cost_usd: 0.001 })).toBe(0.1)
  })

  it('leaves the budget to the editor when nothing is estimated', () => {
    expect(suggestedBudget({ input_tokens: 0, output_tokens: 0, cost_usd: 0 })).toBeNull()
  })
})

describe('API errors', () => {
  it('reads the refusal of a scope above the limit', () => {
    const error = { status: 422, data: { reason: 'scope_too_large', count: 12000, limit: 10000 } }

    expect(scopeTooLarge(error)).toEqual({ reason: 'scope_too_large', count: 12000, limit: 10000 })
    expect(scopeTooLarge({ data: { reason: 'plan_changed' } })).toBeNull()
    expect(apiErrorReason({ status: 409, data: { reason: 'plan_changed' } })).toBe('plan_changed')
    expect(apiErrorReason(new Error('offline'))).toBeNull()
  })

  it('keys plan field errors like the plan', () => {
    const error = { data: { errors: { 'plan.locales': ['Pick target locales'], 'plan.structure.folders.shoes.parent': ['Bad parent'] } } }

    expect(planErrors(error)).toEqual({ locales: 'Pick target locales', 'structure.folders.shoes.parent': 'Bad parent' })
  })
})

describe('plannedFolderTree', () => {
  it('nests planned folders under their planned parents', () => {
    const rows = plannedFolderTree([folder('boots', 'shoes'), folder('shoes'), folder('hats')])

    expect(rows.map((row) => [row.folder.key, row.depth])).toEqual([
      ['shoes', 0],
      ['boots', 1],
      ['hats', 0],
    ])
  })
})

describe('output requirements in plans', () => {
  const assetPlan: AiOperationPlan = {
    summary: 'Describe',
    rules: [],
    options: [],
    permissions: { overwrite: false, remove_tags: false, move: false },
    overwrite_fields: [],
    languages: [],
    structure: { folders: [], tags: [] },
  }
  const scope = { type: 'content' as const, recursive: true }

  it('keeps the keys out of a plan that never had them, so its hash and meaning stay', () => {
    const asset = planPayload('assets', editablePlan(assetPlan))
    expect(asset).not.toHaveProperty('outputs')
    expect(planPayload('general', editablePlan({ summary: 'Shorten', rules: [], options: [] }))).not.toHaveProperty('metadata')
  })

  it('round-trips outputs and metadata without marking the draft as changed', () => {
    const withOutputs: AiOperationPlan = { ...assetPlan, outputs: { fields: ['alt', 'title'], tags: false } }
    const metadata = { keys: ['title', 'ogTitle'], overwrite_keys: ['title'], fields: ['seo'], locales: ['de'] }
    const withMetadata: AiOperationPlan = { summary: 'Metadata', rules: [], options: [], metadata }

    expect(planPayload('assets', editablePlan(withOutputs)).outputs).toEqual({ fields: ['alt', 'title'], tags: false })
    expect(planPayload('general', editablePlan(withMetadata)).metadata).toEqual(metadata)
    expect(planChanged('assets', editablePlan(withOutputs), withOutputs)).toBe(false)
    expect(planChanged('general', editablePlan(withMetadata), withMetadata)).toBe(false)
  })

  it('starts a new asset or metadata plan with explicit requirements and leaves other tasks alone', () => {
    expect(editablePlan(null, { kind: 'assets', skill: null, scope: { type: 'asset', recursive: true } }).outputs).toEqual({ fields: [], tags: true })
    expect(editablePlan(null, { kind: 'general', skill: 'meta-tags', scope }).metadata?.keys).toEqual(['title', 'description'])
    expect(editablePlan(null, { kind: 'general', skill: 'summarize', scope }).metadata).toBeNull()
    expect(editablePlan(null, { kind: 'general', skill: 'meta-tags', scope: { type: 'asset', recursive: true } }).metadata).toBeNull()
  })

  it('requests tags when the plan removes or plans them, as the server requires', () => {
    const draft = editablePlan({ ...assetPlan, outputs: { fields: ['alt'], tags: false } })
    const removing = { ...draft, permissions: { ...draft.permissions, remove_tags: true } }

    expect(planPayload('assets', draft).outputs?.tags).toBe(false)
    expect(planPayload('assets', removing).outputs?.tags).toBe(true)
  })

  it('never sends an overwrite permission for a value that is not requested', () => {
    const draft = editablePlan({ summary: 'M', rules: [], options: [], metadata: { keys: ['title'], overwrite_keys: ['title', 'description'], fields: [], locales: [] } })

    expect(planPayload('general', draft).metadata?.overwrite_keys).toEqual(['title'])
  })
})

describe('outputRows', () => {
  const item = (overrides: Partial<AiOperationItem>): AiOperationItem => ({
    id: 'i1',
    position: 0,
    target_type: 'content',
    target_id: 't1',
    label: 'Home',
    group: 'page',
    status: 'proposed',
    reason: null,
    note: null,
    version_changed: false,
    attempts: 1,
    batch: 1,
    run_id: null,
    parts: [],
    ...overrides,
  })

  it('reads part keys by kind and does not take non-translation parts for locales', () => {
    expect(outputTarget('translation', 'de-AT')).toEqual({ type: 'locale', language: 'de-AT' })
    expect(outputTarget('assets', '_default.alt')).toEqual({ type: 'field', language: '_default', field: 'alt' })
    expect(outputTarget('assets', 'tags')).toEqual({ type: 'tags', language: null })
    expect(outputTarget('general', 'de.seo.ogTitle')).toEqual({ type: 'metadata', language: 'de', field: 'seo', key: 'ogTitle' })
    expect(outputTarget('general', 'de.body.b1.seo.title')).toEqual({ type: 'metadata', language: 'de', field: 'body.b1.seo', key: 'title' })
    expect(outputTarget('translation', 'de.seo.title')).toEqual({ type: 'metadata', language: 'de', field: 'seo', key: 'title' })
    expect(outputTarget('translation', 'de#body.b1.text')).toEqual({ type: 'unit', language: 'de', unit: 'body.b1.text' })
  })

  it('accounts for every owed part: open while queued, unfinished afterwards, with its detail', () => {
    const parts = {
      'de.alt': { outcome: 'blocked', reason: 'missing_fact', note: 'No product name', question: 'Which product is this?' },
      'de.title': { outcome: 'proposed', batch: 2 },
    }
    const assets = { kind: 'assets' as const, approved_plan: null }
    const rows = (status: AiOperationItem['status']) =>
      outputRows(assets, item({ status, expected_parts: ['de.alt', 'de.title', 'tags'], parts, overwritable: ['tags'] }))

    expect(rows('running').map((row) => [row.part, row.outcome, row.tone])).toEqual([
      ['de.alt', 'blocked', 'exception'],
      ['de.title', 'proposed', 'fulfilled'],
      ['tags', 'open', 'open'],
    ])
    expect(rows('failed').at(-1)).toMatchObject({ outcome: 'unfinished', tone: 'exception', overwritable: true })
    expect(rows('running')[0]).toMatchObject({ reason: 'missing_fact', note: 'No product name', question: 'Which product is this?' })

    const summary = outputSummary(rows('failed'))
    expect(summary).toMatchObject({ total: 3, fulfilled: 1 })
    expect(summary.others.map((other) => other.outcome)).toEqual(['blocked', 'unfinished'])
  })

  it('owes the approved locales of a translation and carries their units', () => {
    const plan: AiOperationPlan = { summary: 'T', rules: [], options: [], locales: ['de', 'fr'] }
    const rows = outputRows(
      { kind: 'translation', approved_plan: plan },
      item({ status: 'failed', parts: { de: { outcome: 'proposed' } }, units: { fr: { written: ['title'], remaining: ['body'] } } })
    )

    expect(rows.map((row) => [row.part, row.outcome])).toEqual([['de', 'proposed'], ['fr', 'unfinished']])
    expect(rows[1].units).toEqual({ written: ['title'], remaining: ['body'] })
  })

  it('has nothing to account for on a legacy entry', () => {
    expect(outputRows({ kind: 'general', approved_plan: null }, item({}))).toEqual([])
  })
})

describe('operationCoverage', () => {
  const coverageOf = (state: AiOperation['state'], kind: AiOperation['kind'], approved_plan: AiOperationPlan | null, coverage?: AiOperation['counts']['coverage']) =>
    operationCoverage({
      kind,
      state,
      approved_plan,
      counts: { total: 1, supported: 1, unsupported: 0, groups: {}, tokens: 0, estimate: { input_tokens: 0, output_tokens: 0, cost_usd: 0 }, scope_hash: 'x', coverage },
    })
  const plan: AiOperationPlan = { summary: 'S', rules: [], options: [] }
  const named: AiOperationPlan = { ...plan, outputs: { fields: ['alt'], tags: true } }

  it('stays unverified for a plan naming no outputs, whatever its state says', () => {
    expect(coverageOf('completed', 'general', plan, { verified: false, complete: null, outputs: {}, entries: { proposed: 1 } }).state).toBe('unverified')
    expect(coverageOf('running', 'assets', plan).state).toBe('unverified')
    expect(coverageOf('completed', 'translation', plan).state).toBe('unverified')
  })

  it('reads complete rather than the state and puts exceptions first', () => {
    const incomplete = coverageOf('completed', 'assets', named, {
      verified: true,
      complete: false,
      outputs: { proposed: 3, blocked: 2, satisfied: 1 },
      entries: { proposed: 1, skipped: 1 },
    })

    expect(incomplete.state).toBe('incomplete')
    expect(incomplete.outputs.map((row) => row.outcome)).toEqual(['blocked', 'proposed', 'satisfied'])
    expect(incomplete).toMatchObject({ questions: 2, skipped: 1, failed: 0 })
    expect(outcomeTone('written')).toBe('fulfilled')
    expect(outcomeTone('conflict')).toBe('exception')
    expect(coverageOf('completed', 'assets', named, { verified: true, complete: true, outputs: { proposed: 3 }, entries: {} }).state).toBe('complete')
  })

  it('does not show the figures of an earlier pause as current while running', () => {
    const running = coverageOf('running', 'assets', named, { verified: true, complete: false, outputs: { unfinished: 1 }, entries: {} })

    expect(running.state).toBe('counting')
    expect(running.outputs).toEqual([])
  })

  it('does not report a finished queue with missing outputs as a clean finish', () => {
    const coverage = { verified: true, complete: false, outputs: { uninferable: 1 }, entries: { proposed: 1 } }

    expect(operationOutcome({ state: 'completed', pause_requested: false, progress: progress(), counts: { coverage } })).toBe('completed_with_gaps')
    expect(operationOutcome({ state: 'completed', pause_requested: false, progress: progress() })).toBe('completed')
  })
})

describe('questionAnswers', () => {
  const question = { id: 'q1', question: 'Which model?', note: null, fingerprint: 'print', outputs: 2, entries: 2 }

  it('sends single outputs before their group and leaves blank drafts out', () => {
    expect(questionAnswers([question, { ...question, id: 'q2' }], { q1: ' All of them ', q2: '   ' }, { q1: { m1: ' Just one ', m2: '' } })).toEqual([
      { question_id: 'q1', answer: 'Just one', member_ids: ['m1'] },
      { question_id: 'q1', answer: 'All of them', fingerprint: 'print' },
    ])
  })
})

describe('linked metadata on a translation plan', () => {
  const base: AiOperationPlan = { summary: 'S', rules: [], options: [], locales: ['de', 'fr'], include: ['missing'] }

  it('keeps a plan without metadata free of the key, so its hash stays', () => {
    expect(planPayload('translation', editablePlan(base))).not.toHaveProperty('metadata')
  })

  it('carries what was picked, within the keys and target locales', () => {
    const draft = editablePlan({ ...base, metadata: { keys: ['title'], overwrite_keys: ['title', 'description'], fields: ['teaser.seo'], locales: ['de', 'es'] } })
    expect(planPayload('translation', draft).metadata).toEqual({ keys: ['title'], overwrite_keys: ['title'], fields: ['teaser.seo'], locales: ['de'] })
  })
})
