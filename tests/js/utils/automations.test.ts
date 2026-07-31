import { describe, expect, it } from 'vitest'

import {
  buildAutomationPlaceholderOptions,
  buildConditionPathOptions,
  buildWatchColumnOptions,
  conditionsToRows,
  defaultActionConfig,
  defaultPlaceholderTable,
  defaultTriggerConfig,
  findTriggerTableDefinition,
  formatAutomationPlaceholderToken,
  getActionTypeLabel,
  getTriggerTable,
  getTriggerTableLabel,
  getTriggerTypeLabel,
  isContentLifecycleTrigger,
  isEventTrigger,
  objectToRows,
  rowsToConditions,
  rowsToObject,
  rowsToValues,
  summarizeAction,
  summarizeTrigger,
  valuesToRows,
} from '~/utils/automations'

const t = (key: string) => key

const table = (name: string, columns: string[], label = name) =>
  ({ table: name, label, columns }) as AutomationTriggerTableDefinition

const catalog = (tables: AutomationTriggerTableDefinition[]) =>
  ({ tables }) as AutomationTriggerCatalogResource

describe('key/value row conversion', () => {
  it('turns an object into rows with stringified values', () => {
    expect(objectToRows({ a: 1, b: null, c: 'x' })).toEqual([
      { key: 'a', value: '1' },
      { key: 'b', value: '' },
      { key: 'c', value: 'x' },
    ])
  })

  it('returns no rows for null or undefined', () => {
    expect(objectToRows(null)).toEqual([])
    expect(objectToRows(undefined)).toEqual([])
  })

  it('turns rows back into an object, trimming both sides', () => {
    expect(rowsToObject([{ key: '  a  ', value: '  1  ' }])).toEqual({ a: '1' })
  })

  it('drops rows with a blank key but keeps blank values', () => {
    expect(rowsToObject([{ key: '', value: 'x' }, { key: '  ', value: 'y' }, { key: 'a', value: '' }])).toEqual({
      a: '',
    })
  })

  it('lets a later duplicate key win', () => {
    expect(rowsToObject([{ key: 'a', value: '1' }, { key: 'a', value: '2' }])).toEqual({ a: '2' })
  })

  it('round-trips a plain string object', () => {
    const object = { a: '1', b: '2' }

    expect(rowsToObject(objectToRows(object))).toEqual(object)
  })
})

describe('value row conversion', () => {
  it('wraps values into rows', () => {
    expect(valuesToRows(['a', 'b'])).toEqual([{ value: 'a' }, { value: 'b' }])
    expect(valuesToRows(null)).toEqual([])
  })

  it('unwraps rows, trimming and dropping blanks', () => {
    expect(rowsToValues([{ value: '  a  ' }, { value: '' }, { value: '   ' }, { value: 'b' }])).toEqual([
      'a',
      'b',
    ])
  })
})

describe('condition row conversion', () => {
  it('stringifies condition values into rows', () => {
    expect(
      conditionsToRows([
        { path: 'record.status', operator: 'eq', value: 1 },
        { path: 'record.slug', operator: 'exists', value: null },
      ] as AutomationConditionRule[])
    ).toEqual([
      { path: 'record.status', operator: 'eq', value: '1' },
      { path: 'record.slug', operator: 'exists', value: '' },
    ])
  })

  it('returns no rows for null', () => {
    expect(conditionsToRows(null)).toEqual([])
  })

  it('trims and drops rows without a path', () => {
    expect(
      rowsToConditions([
        { path: '  record.status  ', operator: 'eq', value: '  live  ' },
        { path: '   ', operator: 'eq', value: 'x' },
      ] as never)
    ).toEqual([{ path: 'record.status', operator: 'eq', value: 'live' }])
  })
})

describe('defaultActionConfig', () => {
  it('gives a webhook a POST method and a timeout', () => {
    expect(defaultActionConfig('webhook')).toEqual({
      method: 'POST',
      timeout_seconds: 15,
      headers: {},
      parameters: {},
    })
  })

  it('gives an email empty recipient lists', () => {
    expect(defaultActionConfig('email')).toEqual({
      to: [],
      cc: [],
      bcc: [],
      reply_to: [],
      subject: '',
      body: '',
    })
  })

  it('gives a void action just a message', () => {
    expect(defaultActionConfig('void')).toEqual({ message: '' })
  })

  it('returns a fresh object each call so two actions cannot share config', () => {
    const first = defaultActionConfig('webhook') as { headers: Record<string, string> }

    first.headers.x = '1'

    expect((defaultActionConfig('webhook') as typeof first).headers).toEqual({})
  })
})

describe('defaultTriggerConfig', () => {
  it('gives a time-based trigger an hourly schedule and the local timezone', () => {
    const config = defaultTriggerConfig('time_based')

    expect(config.schedule).toBe('0 * * * *')
    expect(config.timezone).toBe(Intl.DateTimeFormat().resolvedOptions().timeZone)
  })

  it.each(['manual', 'content_published', 'content_unpublished'] as const)(
    'gives a %s trigger no table',
    (type) => {
      expect(defaultTriggerConfig(type)).toEqual({ payload: {}, conditions: [] })
    }
  )

  it.each(['on_insert', 'on_update', 'on_delete'] as const)(
    'gives a %s trigger an empty table and watch list',
    (type) => {
      expect(defaultTriggerConfig(type)).toEqual({
        table: '',
        watch_columns: [],
        payload: {},
        conditions: [],
      })
    }
  )
})

describe('trigger classification', () => {
  it.each([
    ['on_insert', true],
    ['on_update', true],
    ['on_delete', true],
    ['manual', false],
    ['time_based', false],
    ['content_published', false],
  ] as const)('isEventTrigger(%s) is %s', (type, expected) => {
    expect(isEventTrigger(type)).toBe(expected)
  })

  it.each([
    ['content_published', true],
    ['content_unpublished', true],
    ['on_insert', false],
    ['manual', false],
  ] as const)('isContentLifecycleTrigger(%s) is %s', (type, expected) => {
    expect(isContentLifecycleTrigger(type)).toBe(expected)
  })
})

describe('trigger table resolution', () => {
  const contents = table('contents', ['name', 'slug'], 'Contents')
  const registry = catalog([contents, table('assets', ['filename'], 'Assets')])

  it('reads the table, falling back to the legacy resource key', () => {
    expect(getTriggerTable({ table: ' contents ' } as never)).toBe('contents')
    expect(getTriggerTable({ resource: 'assets' } as never)).toBe('assets')
    expect(getTriggerTable(null)).toBe('')
    expect(getTriggerTable({} as never)).toBe('')
  })

  it('finds the matching catalog definition', () => {
    expect(findTriggerTableDefinition('contents', registry)).toBe(contents)
  })

  it('returns null for an unknown table or a missing catalog', () => {
    expect(findTriggerTableDefinition('nope', registry)).toBeNull()
    expect(findTriggerTableDefinition('contents', null)).toBeNull()
    expect(findTriggerTableDefinition('', registry)).toBeNull()
  })

  it('prefers the catalog label, falling back to the raw table name', () => {
    expect(getTriggerTableLabel({ table: 'contents' } as never, registry)).toBe('Contents')
    expect(getTriggerTableLabel({ table: 'unknown' } as never, registry)).toBe('unknown')
    expect(getTriggerTableLabel({ table: 'contents' } as never, null)).toBe('contents')
    expect(getTriggerTableLabel(null, registry)).toBe('')
  })
})

describe('buildConditionPathOptions', () => {
  const values = (type: AutomationTriggerType, definition?: AutomationTriggerTableDefinition | null) =>
    buildConditionPathOptions(type, definition).map((option) => option.value)

  it('offers the trigger-independent paths without a table', () => {
    expect(values('on_insert')).toEqual(['source', 'actor.id', 'record_id'])
  })

  it('adds a record path per column', () => {
    expect(values('on_insert', table('assets', ['filename']))).toEqual([
      'source',
      'actor.id',
      'record_id',
      'record.filename',
    ])
  })

  it('adds cache_tags only for the contents table', () => {
    expect(values('on_insert', table('contents', []))).toContain('cache_tags')
    expect(values('on_insert', table('assets', []))).not.toContain('cache_tags')
  })

  it('adds the before/after paths only for on_update', () => {
    const updateValues = values('on_update', table('assets', ['filename']))

    expect(updateValues).toEqual([
      'source',
      'actor.id',
      'record_id',
      'record.filename',
      'changed_fields',
      'previous.filename',
      'changes.filename.before',
      'changes.filename.after',
    ])
    expect(values('on_delete', table('assets', ['filename']))).not.toContain('changed_fields')
  })
})

describe('buildWatchColumnOptions', () => {
  it('maps each column to a value/label pair', () => {
    expect(buildWatchColumnOptions(table('assets', ['filename', 'size']))).toEqual([
      { value: 'filename', label: 'filename' },
      { value: 'size', label: 'size' },
    ])
  })

  it('is empty without a table definition', () => {
    expect(buildWatchColumnOptions(null)).toEqual([])
  })
})

describe('defaultPlaceholderTable', () => {
  it('prefers contents wherever it appears in the catalog', () => {
    expect(defaultPlaceholderTable(catalog([table('assets', []), table('contents', [])]))).toBe(
      'contents'
    )
  })

  it('falls back to the first table', () => {
    expect(defaultPlaceholderTable(catalog([table('assets', []), table('redirects', [])]))).toBe(
      'assets'
    )
  })

  it('is empty for an empty or missing catalog', () => {
    expect(defaultPlaceholderTable(catalog([]))).toBe('')
    expect(defaultPlaceholderTable(null)).toBe('')
  })
})

describe('formatAutomationPlaceholderToken', () => {
  it('wraps the value in handlebars braces', () => {
    expect(formatAutomationPlaceholderToken('record.id')).toBe('{{ record.id }}')
    expect(formatAutomationPlaceholderToken('  record.id  ')).toBe('{{ record.id }}')
  })
})

describe('buildAutomationPlaceholderOptions', () => {
  const values = (...args: Parameters<typeof buildAutomationPlaceholderOptions>) =>
    buildAutomationPlaceholderOptions(...args).map((option) => option.value)

  it('always offers the workflow placeholders', () => {
    expect(values()).toEqual([
      'automation.name',
      'automation.description',
      'action.name',
      'action.type',
      'space.name',
      'trigger.type',
      'triggered_at',
      'actor.id',
      'record_id',
      'secret.api_key',
    ])
  })

  it('offers a singularized alias alongside the record paths', () => {
    const result = values(table('contents', ['slug']))

    expect(result).toContain('record.slug')
    expect(result).toContain('content.slug')
    expect(result).toContain('content.title')
  })

  it.each([
    ['contents', 'content'],
    ['assets', 'asset'],
    ['redirects', 'redirect'],
    ['stories', 'story'],
    ['asset_folders', 'asset_folder'],
    ['data_entries', 'data_entry'],
  ])('singularizes %s to %s', (name, alias) => {
    expect(values(table(name, ['id']))).toContain(`${alias}.id`)
  })

  it('adds the cache placeholders only for contents', () => {
    expect(values(table('contents', []))).toEqual(expect.arrayContaining(['cache_tags', 'cache.ttl']))
    expect(values(table('assets', []))).not.toContain('cache.ttl')
  })

  it('groups change placeholders under changes', () => {
    const options = buildAutomationPlaceholderOptions(table('assets', ['size']))

    expect(options.find((option) => option.value === 'changes.size.before')?.group).toBe('changes')
    expect(options.find((option) => option.value === 'record.size')?.group).toBe('record')
  })

  it('lists the configured secrets instead of the api_key placeholder', () => {
    const result = values(null, ['token', 'password'])

    expect(result).toContain('secret.token')
    expect(result).toContain('secret.password')
    expect(result).not.toContain('secret.api_key')
  })

  it('trims, drops blank and deduplicates secret keys', () => {
    expect(values(null, ['  token  ', 'token', '', '   '])).toEqual(
      expect.arrayContaining(['secret.token'])
    )
    expect(values(null, ['  token  ', 'token']).filter((value) => value === 'secret.token')).toHaveLength(1)
  })

  it('deduplicates the whole list', () => {
    const result = values(table('contents', ['id', 'title']))

    expect(new Set(result).size).toBe(result.length)
  })
})

describe('labels and summaries', () => {
  it('builds the label translation keys', () => {
    expect(getActionTypeLabel(t, 'webhook')).toBe('labels.automationActions.types.webhook')
    expect(getTriggerTypeLabel(t, 'on_update')).toBe('labels.automations.triggerTypes.on_update')
  })

  it.each([
    ['webhook', { url: 'https://hook.test' }, 'https://hook.test'],
    ['email', { subject: 'Published' }, 'Published'],
    ['void', { message: 'noop' }, 'noop'],
  ] as const)('summarizes a %s action from its config', (type, config, expected) => {
    expect(summarizeAction({ type, config } as never, t)).toBe(expected)
  })

  it.each([
    ['webhook', 'labels.automationActions.summary.webhook'],
    ['email', 'labels.automationActions.summary.email'],
    ['void', 'labels.automationActions.summary.void'],
  ] as const)('falls back to a translated summary for an unconfigured %s action', (type, key) => {
    expect(summarizeAction({ type, config: {} } as never, t)).toBe(key)
  })

  const automation = (trigger_type: string, config: Record<string, unknown> = {}) =>
    ({ trigger_type, trigger: { config } }) as unknown as AutomationResource

  it('summarizes a time-based trigger by its schedule', () => {
    expect(summarizeTrigger(automation('time_based', { schedule: '*/5 * * * *' }), t)).toBe(
      '*/5 * * * *'
    )
    expect(summarizeTrigger(automation('time_based'), t)).toBe('labels.automations.summary.timeBased')
  })

  it('summarizes a manual trigger with a fixed label', () => {
    expect(summarizeTrigger(automation('manual', { table: 'contents' }), t)).toBe(
      'labels.automations.summary.manual'
    )
  })

  it('summarizes an event trigger by its table label', () => {
    expect(
      summarizeTrigger(
        automation('on_update', { table: 'contents' }),
        t,
        catalog([table('contents', [], 'Contents')])
      )
    ).toBe('Contents')
  })

  it('falls back when an event trigger has no table', () => {
    expect(summarizeTrigger(automation('on_update'), t)).toBe(
      'labels.automations.summary.anyResource'
    )
  })
})
