import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'

import type {
  AiGlossaryConceptRow,
  AiGlossaryConceptRowsMeta,
  AiGlossaryResource,
} from '~/api/resources/ai-knowledge'

const conceptRows = vi.fn()
const conceptRow = vi.fn()
const updateConceptRow = vi.fn()
const bulkConceptRows = vi.fn()
const checkGlossaryText = vi.fn()
const dialog = vi.fn()
const confirm = vi.fn()
const runStart = vi.fn()

vi.mock('vue-sonner', () => ({ toast: { info: vi.fn(), warning: vi.fn(), success: vi.fn(), error: vi.fn() } }))
vi.mock('~/api', () => ({
  api: {
    aiKnowledge: () => ({
      conceptRows,
      conceptRow,
      updateConceptRow,
      bulkConceptRows,
      checkGlossaryText,
      exportUrl: () => '/export',
    }),
  },
}))
vi.mock('~/composables/useAlertDialog', () => ({
  useAlertDialog: () => ({ alert: { dialog, confirm } }),
}))
vi.mock('~/composables/useAiEnabled', () => ({ useAiEnabled: () => ({ value: true }) }))
vi.mock('~/composables/useAiRun', () => ({
  useAiRun: () => ({ start: runStart, cancel: vi.fn(), status: { value: 'idle' } }),
}))
vi.mock('~/composables/useAiErrorToast', () => ({ useAiErrorToast: () => ({ showAiError: vi.fn() }) }))

const AiGlossaryDetail = (await import('~/components/ai-settings/AiGlossaryDetail.vue')).default
const { queryKeys } = await import('~/composables/useQueryClient')

const owner = { kind: 'space' as const, id: 'space-1' }

const language = (use: string) => ({ use, avoid: [], also: [] })

const rowOf = (id: string, de: string, fr: string | null, extra: Partial<AiGlossaryConceptRow> = {}): AiGlossaryConceptRow => ({
  id,
  key: null,
  keep_exact: false,
  note: null,
  languages: { de: language(de), ...(fr ? { fr: language(fr) } : {}) },
  coverage: { de: 'own', fr: fr ? 'own' : 'missing' },
  ...extra,
})

const rows = [rowOf('a', 'Bett', 'Lit'), rowOf('b', 'Tisch', null), rowOf('c', 'Stuhl', 'Chaise'), rowOf('d', 'Sofa', 'Canapé')]

const meta = (extra: Partial<AiGlossaryConceptRowsMeta> = {}): AiGlossaryConceptRowsMeta => ({
  current_page: 1,
  from: 1,
  to: rows.length,
  last_page: 1,
  per_page: 50,
  path: '',
  links: [],
  total: rows.length,
  read_only: false,
  default_locale: 'de',
  available_locales: [
    { code: 'de', default: true, enabled: true, unused: false },
    { code: 'fr', default: false, enabled: true, unused: false },
  ],
  facets: {
    total: 4,
    locales: [],
    missing: [{ value: 'de', count: 0 }, { value: 'fr', count: 1 }],
  },
  ...extra,
})

const regionalLocales = [
  { code: 'de', default: true, enabled: true, unused: false },
  { code: 'de-at', default: false, enabled: true, unused: false },
  { code: 'fr', default: false, enabled: true, unused: false },
]

const body = () => document.body
const mounted: { unmount: () => void }[] = []

const mountEditor = async (query: Record<string, string> = {}, glossary: Partial<AiGlossaryResource> = {}, canManage = true) => {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } })
  queryClient.setQueryData(queryKeys.aiKnowledge(owner).glossaries(), [
    { id: 'glossary-1', scope: 'space', owner_id: 'space-1', name: 'Shop', enabled: true, updated_at: null, ...glossary },
  ])
  const Page = defineComponent({
    setup: () => () => h(AiGlossaryDetail, { owner, glossaryId: 'glossary-1', canManage }),
  })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: Page },
      { path: '/x/:name(.*)*', name: 'space-settings-ai-knowledge', component: { template: '<p />' } },
      { path: '/y/:name(.*)*', name: 'team-ai', component: { template: '<p />' } },
      { path: '/other', component: { template: '<p id="other" />' } },
    ],
  })
  await router.push({ path: '/', query })
  await router.isReady()
  const wrapper = mount(defineComponent({ setup: () => () => h(RouterView) }), {
    attachTo: document.body,
    global: {
      plugins: [router, [VueQueryPlugin, { queryClient }]],
      stubs: { Icon: true, Teleport: false },
    },
  })
  mounted.push(wrapper)
  await flushPromises()
  return { wrapper, router }
}

const tableRow = (id: string) => body().querySelector<HTMLElement>(`[data-row-id="${id}"]`)
const input = (id: string) => body().querySelector<HTMLInputElement>(`#${id}`)
const setValue = async (element: HTMLInputElement | HTMLTextAreaElement | null, value: string) => {
  if (!element) throw new Error('No such field')
  element.value = value
  element.dispatchEvent(new Event('input', { bubbles: true }))
  await nextTick()
}
const buttonNamed = (name: string | RegExp) =>
  [...body().querySelectorAll('button')].find((button) => {
    const text = `${button.textContent ?? ''} ${button.getAttribute('aria-label') ?? ''}`
    return typeof name === 'string' ? text.includes(name) : name.test(text)
  })
const settle = async () => {
  await flushPromises()
  await new Promise((resolve) => setTimeout(resolve, 0))
  await flushPromises()
}
const openConcept = async (id: string, router: ReturnType<typeof createRouter>) => {
  tableRow(id)?.click()
  await settle()
  await router.isReady()
  await settle()
}

beforeEach(() => {
  vi.clearAllMocks()
  window.matchMedia = vi.fn().mockReturnValue({ matches: false })
  conceptRows.mockResolvedValue({ data: rows, meta: meta() })
  dialog.mockResolvedValue(undefined)
  confirm.mockResolvedValue(true)
})

afterEach(() => {
  mounted.splice(0).forEach((wrapper) => wrapper.unmount())
  document.body.innerHTML = ''
})

describe('the concept list', () => {
  it('keeps a filter in the URL and asks the server for it', async () => {
    const { router } = await mountEditor({ missing: 'fr', q: 'Tisch' })

    expect(conceptRows).toHaveBeenCalledWith('glossary-1', expect.objectContaining({ missing: 'fr', search: 'Tisch', page: 1 }))

    const french = [...body().querySelectorAll('button[aria-pressed]')].find((button) => button.textContent?.includes('French'))
    expect(french?.getAttribute('aria-pressed')).toBe('true')
    ;(french as HTMLElement).click()
    await settle()

    expect(router.currentRoute.value.query.missing).toBeUndefined()
    expect(conceptRows).toHaveBeenLastCalledWith('glossary-1', expect.not.objectContaining({ missing: 'fr' }))
  })

  it('shows a base language differently from a missing one, and selects a range with shift', async () => {
    conceptRows.mockResolvedValue({
      data: [
        rowOf('a', 'Bett', null, { coverage: { de: 'own', 'de-at': 'fallback', fr: 'missing' } }),
        rowOf('b', 'Tisch', 'Table', {
          languages: { de: language('Tisch'), 'de-at': language('Tischerl'), fr: language('Table') },
        }),
        ...rows.slice(2),
      ],
      meta: meta({ available_locales: regionalLocales }),
    })
    await mountEditor()

    const cells = tableRow('a')?.querySelectorAll('td') ?? []
    expect(cells[1].textContent).toContain('Uses the')
    expect(cells[2].textContent).toContain('Missing')

    const box = (id: string) => tableRow(id)?.querySelector<HTMLElement>('span.cursor-pointer')
    box('a')?.click()
    await nextTick()
    box('c')?.dispatchEvent(new MouseEvent('click', { bubbles: true, shiftKey: true }))
    await nextTick()

    expect(body().querySelector('[role="toolbar"]')?.textContent).toContain('3 selected')
    expect(tableRow('b')?.hasAttribute('data-selected')).toBe(true)
    expect(tableRow('d')?.hasAttribute('data-selected')).toBe(false)
  })

  it('folds a language with no wording of its own on the page, and shows it on request', async () => {
    conceptRows.mockResolvedValue({
      data: rows.map((row) => ({ ...row, coverage: { ...row.coverage, 'de-at': 'fallback' } })),
      meta: meta({ available_locales: regionalLocales }),
    })
    const { router } = await mountEditor()
    const headers = () => [...body().querySelectorAll('th')].map((th) => th.textContent?.trim())

    expect(headers()).toEqual(['German', 'French'])
    expect(body().querySelector('[role="group"]')?.textContent).toContain('Austrian German')

    buttonNamed('Show Austrian German')?.click()
    await settle()

    expect(headers()).toEqual(['German', 'Austrian German', 'French'])
    expect(router.currentRoute.value.query.columns).toBe('all')
  })

  it('keeps the language being filtered on in view', async () => {
    conceptRows.mockResolvedValue({ data: [rows[1]], meta: meta({ total: 1 }) })
    await mountEditor({ missing: 'fr' })

    expect([...body().querySelectorAll('th')].map((th) => th.textContent?.trim())).toEqual(['German', 'French'])
  })

  it('marks a row with Avoid and no Use as a ban', async () => {
    conceptRows.mockResolvedValue({
      data: [rowOf('ban', 'x', null, { languages: { de: { ...language('x'), use: null, avoid: ['Billigbett'] } } })],
      meta: meta({ total: 1 }),
    })
    const { router } = await mountEditor()

    expect(tableRow('ban')?.querySelector('td')?.textContent).toContain('Billigbett')
    expect(tableRow('ban')?.querySelector('td')?.textContent).toContain('Ban')

    await openConcept('ban', router)
    expect(input('glossary-term-de')?.value).toBe('')
    expect(body().querySelector('[role="dialog"]')?.textContent).toContain('Ban: the AI must never write this.')
  })

  it('names the count before deleting a selection', async () => {
    bulkConceptRows.mockResolvedValue({ data: { deleted: 2 } })
    await mountEditor()

    tableRow('a')?.querySelector<HTMLElement>('span.cursor-pointer')?.click()
    tableRow('b')?.querySelector<HTMLElement>('span.cursor-pointer')?.click()
    await nextTick()
    buttonNamed('Delete')?.click()
    await settle()

    expect(confirm.mock.calls[0][0]).toContain('2 terms')
    expect(bulkConceptRows).toHaveBeenCalledWith('glossary-1', { action: 'delete', row_ids: ['a', 'b'] })
  })

  it('offers what to do next when the glossary is empty, and when a filter finds nothing', async () => {
    conceptRows.mockResolvedValue({ data: [], meta: meta({ total: 0, facets: { ...meta().facets, total: 0 } }) })
    await mountEditor()
    expect(body().textContent).toContain('Import a sheet')
    expect(body().querySelector('a[href="/export"]')).not.toBeNull()

    mounted.splice(0).forEach((wrapper) => wrapper.unmount())
    document.body.innerHTML = ''
    conceptRows.mockResolvedValue({ data: [], meta: meta({ total: 0 }) })
    await mountEditor({ q: 'Hocker' })
    expect(buttonNamed('Clear filters')).toBeDefined()
    expect(buttonNamed('Create "Hocker"')).toBeDefined()
  })

  it('shows an inherited glossary with no write controls', async () => {
    conceptRows.mockResolvedValue({ data: rows, meta: meta({ read_only: true }) })
    const { router } = await mountEditor({}, { owner_id: 'other-team' })

    expect(body().textContent).toContain('This glossary belongs to a team, so it is read-only here.')
    expect(body().textContent).not.toContain('Add a concept')
    expect(tableRow('a')?.querySelector('[role="checkbox"]')).toBeNull()

    await openConcept('a', router)
    expect(input('glossary-term-de')).toBeNull()
    expect(body().querySelector('[role="dialog"]')?.textContent).toContain('Bett')
    expect(buttonNamed('Save')).toBeUndefined()
    expect(buttonNamed('Try a text')).toBeDefined()
  })

  it('shows a reader the note and keep exact as plain text', async () => {
    conceptRows.mockResolvedValue({
      data: [rowOf('a', 'Acme', null, { note: 'A brand name.', keep_exact: true })],
      meta: meta({ read_only: true, total: 1 }),
    })
    const { router } = await mountEditor({}, {}, false)
    await openConcept('a', router)

    const sheet = body().querySelector('[role="dialog"]')?.textContent
    expect(sheet).toContain('A brand name.')
    expect(sheet).toContain('Keep exact')
    expect(body().querySelector('#glossary-concept-note')).toBeNull()
  })
})

describe('the concept sheet', () => {
  it('sends only what changed', async () => {
    updateConceptRow.mockResolvedValue({ data: rowOf('a', 'Bett', 'Lit à nuage') })
    const { router } = await mountEditor({ language: 'fr' })
    await openConcept('a', router)

    await setValue(input('glossary-term-fr'), 'Lit à nuage')
    buttonNamed(/^\s*Save\b/)?.click()
    await settle()

    expect(updateConceptRow).toHaveBeenCalledWith('glossary-1', 'a', {
      languages: { fr: { use: 'Lit à nuage' } },
      base: { keep_exact: false, note: null, languages: rows[0].languages },
    })
  })

  it('leaves an older long note alone until it is edited, then asks to shorten it', async () => {
    const long = 'x'.repeat(240)
    conceptRows.mockResolvedValue({ data: [rowOf('a', 'Bett', 'Lit', { note: long })], meta: meta({ total: 1 }) })
    updateConceptRow.mockResolvedValue({ data: rowOf('a', 'Bett', 'Lit à nuage', { note: long }) })
    const { router } = await mountEditor({ language: 'fr' })
    await openConcept('a', router)
    const note = body().querySelector<HTMLTextAreaElement>('#glossary-concept-note')

    expect(note?.value).toBe(long)
    expect(body().textContent).not.toContain('Shorten this note')

    await setValue(note, `${long} more`)
    buttonNamed(/^\s*Save\b/)?.click()
    await settle()
    expect(body().textContent).toContain('Shorten this note to 200 characters')
    expect(updateConceptRow).not.toHaveBeenCalled()

    await setValue(note, long)
    await setValue(input('glossary-term-fr'), 'Lit à nuage')
    buttonNamed(/^\s*Save\b/)?.click()
    await settle()
    expect(updateConceptRow).toHaveBeenCalledWith('glossary-1', 'a', expect.objectContaining({ languages: { fr: { use: 'Lit à nuage' } } }))
  })

  it('keeps typed text after a use_avoided 422 and says so at the field', async () => {
    updateConceptRow.mockRejectedValue(
      Object.assign(new Error('Both'), { status: 422, data: { code: 'use_avoided', locale: 'fr', wording: 'Lit', avoid: 'Lit', message: 'Both' } })
    )
    conceptRows.mockResolvedValue({
      data: [rowOf('a', 'Bett', 'x', { languages: { de: language('Bett'), fr: { ...language('x'), avoid: ['Lit'] } } })],
      meta: meta({ total: 1 }),
    })
    const { router } = await mountEditor({ language: 'fr' })
    await openConcept('a', router)

    await setValue(input('glossary-term-fr'), 'Lit')
    buttonNamed(/^\s*Save\b/)?.click()
    await settle()

    expect(input('glossary-term-fr')?.value).toBe('Lit')
    expect(body().textContent).toContain('The Avoid entry "Lit" also covers "Lit"')
    expect(router.currentRoute.value.query.concept).toBe('a')
  })

  it('keeps typed text after a 409 and links to the concept holding the wording', async () => {
    conceptRow.mockResolvedValue({ data: rows[2] })
    const conflict = Object.assign(new Error('Taken'), {
      status: 409,
      data: { code: 'wording_conflict', locale: 'fr', wording: 'Chaise', concept: { id: 'c', key: null, term: 'Stuhl' } },
    })
    updateConceptRow.mockRejectedValue(conflict)
    const { router } = await mountEditor({ language: 'fr' })
    await openConcept('a', router)

    await setValue(input('glossary-term-fr'), 'Chaise')
    buttonNamed(/^\s*Save\b/)?.click()
    await settle()

    expect(input('glossary-term-fr')?.value).toBe('Chaise')
    expect(router.currentRoute.value.query.concept).toBe('a')
    expect(buttonNamed('open "Stuhl"')).toBeDefined()
  })

  const rowChangedRefusal = () =>
    Object.assign(new Error('Moved'), {
      status: 409,
      data: { code: 'row_changed', message: 'Moved', changes: [{ field: 'note', locale: null }] },
    })

  it('describes the row it reloaded, not the one the refusal named, and bases the next Save on it', async () => {
    // The refusal names the note; by the time of the reload the French Use moved as well.
    const newer = rowOf('a', 'Bettchen', 'Couchette', { note: 'Neu' })
    conceptRow.mockResolvedValue({ data: newer })
    updateConceptRow.mockRejectedValueOnce(rowChangedRefusal())
    updateConceptRow.mockResolvedValueOnce({ data: rowOf('a', 'Bettchen', 'Lit à nuage') })
    const { router } = await mountEditor({ language: 'fr' })
    await openConcept('a', router)

    await setValue(input('glossary-term-fr'), 'Lit à nuage')
    buttonNamed(/^\s*Save\b/)?.click()
    await settle()

    expect(input('glossary-term-fr')?.value).toBe('Lit à nuage')
    expect(body().textContent).toMatch(/differs in: Use \(/)
    expect(body().textContent).not.toContain('Couchette')

    buttonNamed(/^\s*Save\b/)?.click()
    await settle()

    expect(updateConceptRow).toHaveBeenLastCalledWith('glossary-1', 'a', {
      languages: { fr: { use: 'Lit à nuage' } },
      base: { keep_exact: false, note: 'Neu', languages: newer.languages },
    })
  })

  it('keeps the old base and promises no overwrite when the newer row cannot be read', async () => {
    conceptRow.mockRejectedValueOnce(new Error('Offline'))
    updateConceptRow.mockRejectedValue(rowChangedRefusal())
    const { router } = await mountEditor({ language: 'fr' })
    await openConcept('a', router)

    await setValue(input('glossary-term-fr'), 'Lit à nuage')
    buttonNamed(/^\s*Save\b/)?.click()
    await settle()

    expect(input('glossary-term-fr')?.value).toBe('Lit à nuage')
    expect(body().textContent).toContain('could not be loaded')
    expect(body().textContent).not.toContain('replace')

    buttonNamed(/^\s*Save\b/)?.click()
    await settle()

    expect(updateConceptRow).toHaveBeenLastCalledWith('glossary-1', 'a', {
      languages: { fr: { use: 'Lit à nuage' } },
      base: { keep_exact: false, note: null, languages: rows[0].languages },
    })
  })

  it('asks before closing with unsaved changes and stays when told to', async () => {
    const { router } = await mountEditor({ language: 'fr' })
    await openConcept('a', router)
    await setValue(input('glossary-term-fr'), 'Changed')

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }))
    await settle()

    expect(dialog).toHaveBeenCalledOnce()
    expect(router.currentRoute.value.query.concept).toBe('a')
    expect(input('glossary-term-fr')?.value).toBe('Changed')
  })

  it('fills only empty fields with AI and saves nothing', async () => {
    runStart.mockResolvedValue({
      status: 'completed',
      result: { data: { use: 'Table' }, glossary_findings: [] },
      partial: false,
      error: null,
    })
    conceptRows.mockResolvedValue({ data: [rowOf('b', 'Tisch', null)], meta: meta() })
    const { router } = await mountEditor({ language: 'fr' })
    await openConcept('b', router)

    buttonNamed('Suggest French wording')?.click()
    await settle()

    expect(runStart).toHaveBeenCalledWith(
      expect.objectContaining({
        surface: 'translate',
        skill: 'translate-fields',
        context: { source: 'de', target: 'fr', fields: { use: 'Tisch' } },
      })
    )
    expect(input('glossary-term-fr')?.value).toBe('Table')
    expect(body().textContent).toContain('Use suggested by AI, not saved yet')
    expect(updateConceptRow).not.toHaveBeenCalled()
  })

  it('says what a suggestion breaks and what to write instead', async () => {
    runStart.mockResolvedValue({
      status: 'completed',
      result: { data: { use: 'Bureau' }, glossary_findings: [{ type: 'forbidden_variant', term: 'Table', expected: 'Table', found: 'Bureau' }] },
      partial: false,
      error: null,
    })
    conceptRows.mockResolvedValue({ data: [rowOf('b', 'Tisch', null)], meta: meta() })
    const { router } = await mountEditor({ language: 'fr' })
    await openConcept('b', router)

    buttonNamed('Suggest French wording')?.click()
    await settle()

    expect(body().textContent).toContain('"Bureau" is not allowed, use "Table".')

    await setValue(input('glossary-term-fr'), 'Plateau')
    await settle()
    expect(body().textContent).not.toContain('"Bureau" is not allowed')
  })

  it('says what saving reaches, and saves before trying a text with unsaved changes', async () => {
    updateConceptRow.mockResolvedValue({ data: rowOf('a', 'Bett', 'Couche') })
    const { router } = await mountEditor({ language: 'fr' })
    await openConcept('a', router)

    expect(body().textContent).toContain('Saving applies to new AI runs in this space.')

    await setValue(input('glossary-term-fr'), 'Couche')
    await settle()
    buttonNamed('Save and try')?.click()
    await settle()

    expect(updateConceptRow).toHaveBeenCalledOnce()
    expect(body().querySelector<HTMLTextAreaElement>('#glossary-try-source')?.value).toBe('Couche')
  })

  it('names a row with no default wording by its language, and says so', async () => {
    conceptRows.mockResolvedValue({
      data: [{ ...rowOf('e', 'x', 'Lampe'), languages: { fr: language('Lampe') }, coverage: { de: 'missing', fr: 'own' } }],
      meta: meta(),
    })
    const { router } = await mountEditor()

    const row = body().querySelector('[data-row-id="e"]')
    expect(row?.querySelector('td')?.textContent).toContain('Lampe')
    expect(row?.querySelector('td')?.textContent).toContain('French')

    await openConcept('e', router)
    expect(body().textContent).toContain('No German wording yet. Named by its French wording.')

    // The open tab has no wording, so the row's name goes in, as the language it is in.
    buttonNamed('Try a text')?.click()
    await settle()
    expect(body().querySelector<HTMLTextAreaElement>('#glossary-try-source')?.value).toBe('Lampe')
    expect(checkGlossaryText).toHaveBeenCalledWith('glossary-1', expect.objectContaining({ text: 'Lampe', source_locale: 'fr' }))
  })

  it('opens the import dialog above a sheet that is already open', async () => {
    conceptRows.mockResolvedValue({ data: [], meta: meta({ total: 0, facets: { ...meta().facets, total: 0 } }) })
    await mountEditor()

    buttonNamed('Add a term')?.click()
    await settle()
    buttonNamed('Import a sheet')?.click()
    await settle()

    const [sheet, importDialog] = body().querySelectorAll('[role="dialog"]')
    expect(sheet.querySelector('#glossary-term-de')).not.toBeNull()
    expect(importDialog.textContent).toContain('Import')
    expect(importDialog.querySelector('#glossary-term-de')).toBeNull()
  })
})

describe('a Use its own Avoid would flag', () => {
  it('names the entry that covers it inline, also when the two differ', async () => {
    const refusal = Object.assign(new Error('refused'), {
      data: { code: 'use_avoided', locale: 'de', wording: 'Händler', avoid: 'Händler*', message: 'refused' },
    })
    conceptRows.mockResolvedValue({ data: [rowOf('a', 'Händler', 'Marchand', { languages: { de: { use: 'Händler', avoid: ['Händler*'], also: [] }, fr: language('Marchand') } })], meta: meta({ total: 1 }) })
    updateConceptRow.mockRejectedValue(refusal)
    const { router } = await mountEditor({ language: 'de' })
    await openConcept('a', router)

    await setValue(body().querySelector('#glossary-term-de'), 'Händler ')
    await setValue(body().querySelector('textarea[name*="note"], textarea'), 'Geändert')
    buttonNamed('Save')?.click()
    await settle()

    expect(body().textContent).toContain('The Avoid entry "Händler*" also covers "Händler"')
  })
})

describe('trying a text', () => {
  const checkResult = {
    data: {
      terms: [
        {
          term: 'Wolkenbett',
          instruction: '"Wolkenbett": write "Cloud bed" in en',
          required: 'Cloud bed',
          glossary_id: 'glossary-1',
          glossary_name: 'Shop',
          row_id: 'a',
          matches: [
            { start: 3, end: 13, text: 'Wolkenbett' },
            { start: 18, end: 20, text: 'WB' },
          ],
        },
      ],
      findings: [
        { type: 'forbidden_variant', term: 'Wolkenbett', expected: 'Cloud bed', found: 'Daunenbett' },
        { type: 'missing_term', term: 'Wolkenbett', expected: 'Cloud bed' },
      ],
    },
  }

  const openFromMenu = async () => {
    buttonNamed('More actions')?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
    await settle()
    ;[...body().querySelectorAll<HTMLElement>('[role="menuitem"]')].find((item) => item.textContent?.includes('Try a text'))?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await settle()
  }

  it('opens from the page menu, marks spans by their UTF-16 offsets and opens a term', async () => {
    checkGlossaryText.mockResolvedValue(checkResult)
    const { router } = await mountEditor()
    expect(body().querySelector('#glossary-try-source')).toBeNull()

    await openFromMenu()
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    await setValue(body().querySelector('#glossary-try-source'), '😀 Wolkenbett und WB')
    await setValue(body().querySelector('#glossary-try-output'), 'Daunenbett')
    await vi.advanceTimersByTimeAsync(500)
    vi.useRealTimers()
    await settle()

    expect([...body().querySelectorAll('mark')].map((mark) => mark.textContent)).toEqual(['Wolkenbett', 'WB'])
    expect(body().textContent).toContain('Flagged on the draft:')
    expect(body().textContent).toContain('The AI is told: the glossary uses "Cloud bed" for "Wolkenbett".')

    buttonNamed('write "Cloud bed" in en')?.click()
    await settle()
    expect(router.currentRoute.value.query.concept).toBe('a')
    expect(body().querySelector('#glossary-try-source')).toBeNull()
  })

  it('can check what a run in this space uses, and does not open a row of another glossary', async () => {
    checkGlossaryText.mockResolvedValue({
      data: {
        terms: [{ ...checkResult.data.terms[0], row_id: 'x', glossary_id: 'team-glossary', glossary_name: 'Team terms' }],
        findings: null,
      },
    })
    const { router } = await mountEditor()

    await openFromMenu()
    expect(body().querySelector('#glossary-try-scope')).not.toBeNull()
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    await setValue(body().querySelector('#glossary-try-source'), 'Wolkenbett')
    await vi.advanceTimersByTimeAsync(500)
    vi.useRealTimers()
    await settle()

    expect(checkGlossaryText).toHaveBeenCalledWith('glossary-1', expect.objectContaining({ scope: 'glossary' }))
    expect(body().textContent).toContain('From Team terms')
    expect(buttonNamed('write "Cloud bed" in en')).toBeUndefined()
    expect(router.currentRoute.value.query.concept).toBeUndefined()
  })

  it('switches to what a run in this space uses and checks the same text again', async () => {
    checkGlossaryText.mockResolvedValue({ data: { terms: [], findings: null } })
    await mountEditor()

    await openFromMenu()
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    await setValue(body().querySelector('#glossary-try-source'), 'Wolkenbett')
    await vi.advanceTimersByTimeAsync(500)
    vi.useRealTimers()
    await settle()

    checkGlossaryText.mockResolvedValue({
      data: { terms: [{ ...checkResult.data.terms[0], row_id: 'x', glossary_id: 'team-glossary', glossary_name: 'Team terms' }], findings: null },
    })
    body().querySelector<HTMLElement>('#glossary-try-scope')?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
    await settle()
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    ;[...body().querySelectorAll<HTMLElement>('[role="option"]')]
      .find((option) => option.textContent?.includes('Everything'))
      ?.dispatchEvent(new PointerEvent('pointerup', { bubbles: true }))
    await nextTick()
    await vi.advanceTimersByTimeAsync(500)
    vi.useRealTimers()
    await settle()

    expect(checkGlossaryText).toHaveBeenLastCalledWith('glossary-1', expect.objectContaining({ text: 'Wolkenbett', scope: 'space' }))
    expect(body().textContent).toContain('From Team terms')
  })

  it('checks the saved wording again when it opens on the same text, and drops an older answer', async () => {
    const { router } = await mountEditor({ language: 'fr' })
    await openConcept('a', router)

    const asked: { resolve: (value: unknown) => void }[] = []
    checkGlossaryText.mockImplementation(() => new Promise((resolve) => asked.push({ resolve })))
    const wording = (instruction: string) => ({ data: { terms: [{ ...checkResult.data.terms[0], instruction, matches: [] }], findings: null } })

    buttonNamed('Try a text')?.click()
    await settle()
    const firstCheck = asked.length
    expect(firstCheck).toBeGreaterThan(0)
    asked[firstCheck - 1].resolve(wording('before the save'))
    await settle()
    expect(body().textContent).toContain('before the save')

    // Same text and language, reopened after the rule changed.
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await settle()
    buttonNamed('Try a text')?.click()
    await settle()

    expect(asked.length).toBeGreaterThan(firstCheck)
    expect(body().textContent).not.toContain('before the save')

    const second = asked.length
    buttonNamed('Try a text')?.click()
    await settle()
    asked[second - 1]?.resolve(wording('an answer overtaken'))
    asked[asked.length - 1].resolve(wording('after the save'))
    await settle()
    expect(body().textContent).toContain('after the save')
    expect(body().textContent).not.toContain('an answer overtaken')
  })

  it('opens from a concept with its wording in that language', async () => {
    const { router } = await mountEditor({ language: 'fr' })
    await openConcept('a', router)

    buttonNamed('Try a text')?.click()
    await settle()

    expect(body().querySelector<HTMLTextAreaElement>('#glossary-try-source')?.value).toBe('Lit')
    expect(router.currentRoute.value.query.concept).toBe('a')

    // Opening filled the fields, and the debounce past it asks nothing new.
    await new Promise((resolve) => setTimeout(resolve, 450))
    expect(checkGlossaryText).toHaveBeenCalledOnce()

    // One z-index for both, so the order in the page decides. Under the sheet, its results hide.
    const sheet = body().querySelector('[role="dialog"]:not(:has(#glossary-try-source))')
    const tryDialog = body().querySelector('[role="dialog"]:has(#glossary-try-source)')
    expect(sheet?.compareDocumentPosition(tryDialog as Node)).toBe(Node.DOCUMENT_POSITION_FOLLOWING)
  })
})
