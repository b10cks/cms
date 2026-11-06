import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { AiGlossarySheetImportResult } from '~/api/resources/ai-knowledge'
import type { ImportProgress } from '~/types/import-export'

const importTerms = vi.fn()
const exportUrl = vi.fn((_id: string, format: string, { template = false } = {}) => `/export?format=${format}&template=${template ? 1 : 0}`)
const downloadBlob = vi.fn()

vi.mock('~/api', () => ({ api: { aiKnowledge: () => ({ importTerms, exportUrl }) } }))
vi.mock('~/lib/import-export', () => ({ downloadBlob }))

const GlossarySheetImportDialog = (await import('~/components/ai-settings/glossary-import/GlossarySheetImportDialog.vue')).default
const { queryKeys } = await import('~/composables/useQueryClient')

const owner = { kind: 'space' as const, id: 'space-1' }

const sheetResult = (overrides: Partial<AiGlossarySheetImportResult['summary']>, rest: Partial<AiGlossarySheetImportResult> = {}): AiGlossarySheetImportResult => ({
  changes: [],
  errors: [],
  ignored_fields: [],
  warnings: [],
  summary: { total_success: 0, total_changes: 0, total_errors: 0, added: 0, updated: 0, unchanged: 0, ...overrides },
  ...rest,
})

const mountDialog = async () => {
  const queryClient = new QueryClient()
  const invalidate = vi.spyOn(queryClient, 'invalidateQueries')
  const wrapper = mount(GlossarySheetImportDialog, {
    attachTo: document.body,
    props: { owner, glossaryId: 'glossary-1', open: false },
    global: { plugins: [[VueQueryPlugin, { queryClient }]], stubs: { Icon: true } },
  })
  await wrapper.setProps({ open: true })
  await flushPromises()
  return { wrapper, invalidate }
}

const chooseFile = async (name = 'glossary.csv') => {
  const input = document.querySelector<HTMLInputElement>('input[type="file"]')!
  Object.defineProperty(input, 'files', { value: [new File(['x'], name)], configurable: true })
  input.dispatchEvent(new Event('change'))
  await flushPromises()
}

const runImport = async () => {
  await chooseFile()
  const button = [...document.querySelectorAll('button')].find((item) => item.textContent?.trim() === 'Import')
  button!.click()
  await flushPromises()
}

const figures = () =>
  Object.fromEntries(
    [...document.querySelectorAll('dl > div')].map((item) => [item.querySelector('dt')!.textContent!.trim(), item.querySelector('dd')!.textContent!.trim()])
  )

beforeEach(() => {
  vi.clearAllMocks()
})

afterEach(() => {
  document.body.innerHTML = ''
})

describe('GlossarySheetImportDialog', () => {
  it('offers both template downloads in the empty state and accepts sheets and TBX', async () => {
    await mountDialog()

    const links = [...document.querySelectorAll<HTMLAnchorElement>('a[download]')]
    expect(links.map((link) => link.getAttribute('href'))).toEqual(['/export?format=csv&template=1', '/export?format=xlsx&template=1'])
    expect(document.querySelector('input[type="file"]')!.getAttribute('accept')).toBe('.csv,.xlsx,.tbx,.xml')
  })

  it('reports added, updated, unchanged and warnings, refreshes the glossary and emits imported', async () => {
    const result = sheetResult(
      { added: 2, updated: 3, unchanged: 4, total_success: 9, total_changes: 5 },
      { warnings: [{ code: 'unused_locales', locales: ['fr', 'it'], message: '' }], ignored_fields: ['owner'] }
    )
    importTerms.mockResolvedValue(result)
    const { wrapper, invalidate } = await mountDialog()

    await runImport()

    expect(figures()).toEqual({ Added: '2', Updated: '3', Unchanged: '4', 'Row errors': '0' })
    expect(document.body.textContent).toContain('fr, it')
    expect(document.body.textContent).toContain('owner')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.aiKnowledge(owner).glossaries() })
    expect(wrapper.emitted('imported')?.[0]).toEqual([result])
  })

  it('shows live progress while it streams', async () => {
    let report: (progress: ImportProgress) => void = () => {}
    importTerms.mockImplementation((_id: string, _file: File, onProgress: typeof report) => {
      report = onProgress
      return new Promise(() => {})
    })
    await mountDialog()

    await runImport()
    report({ processed: 50, total: 200 })
    await flushPromises()

    expect(document.body.textContent).toContain('50')
    expect(document.body.textContent).toContain('200')
  })

  it('shows no success when every row failed, and hands the row errors over as CSV', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
    importTerms.mockResolvedValue(
      sheetResult(
        { total_errors: 2 },
        {
          errors: [
            { row: 2, column: 'keep_exact', message: 'Use yes or no.' },
            { row: 7, column: 'term:de', message: 'The wording "=Bett, Alt" belongs to another concept: held.' },
          ],
        }
      )
    )
    await mountDialog()

    await runImport()

    expect(figures()).toEqual({ Added: '0', Updated: '0', Unchanged: '0', 'Row errors': '2' })
    expect(document.body.textContent).toContain('No row applied')
    expect(document.body.textContent).toContain('Row 7, term:de')

    const buttons = [...document.querySelectorAll('button')]
    buttons.find((item) => item.textContent?.trim() === 'Copy')!.click()
    await flushPromises()
    expect(writeText).toHaveBeenCalledWith(
      'row,column,message\n2,keep_exact,Use yes or no.\n7,term:de,"The wording ""=Bett, Alt"" belongs to another concept: held."'
    )

    buttons.find((item) => item.textContent?.trim() === 'Download as CSV')!.click()
    expect(downloadBlob).toHaveBeenCalledWith(expect.any(Blob), 'glossary-import-errors.csv')
  })

  it('says when a file changed nothing', async () => {
    importTerms.mockResolvedValue(sheetResult({ unchanged: 5, total_success: 5 }))
    await mountDialog()

    await runImport()

    expect(document.body.textContent).toContain('Nothing changed')
    expect(figures().Unchanged).toBe('5')
  })

  it('counts an older format or TBX file as imported', async () => {
    importTerms.mockResolvedValue({ changes: [], errors: [], summary: { total_success: 3, total_changes: 0, total_errors: 0 } })
    await mountDialog()

    await runImport()

    expect(figures()).toEqual({ Imported: '3', 'Row errors': '0' })
  })

  it('shows a refused file on the upload step', async () => {
    importTerms.mockRejectedValue(new Error('The first row must name the columns.'))
    await mountDialog()

    await runImport()

    expect(document.body.textContent).toContain('The first row must name the columns.')
    expect(document.querySelector('dl')).toBeNull()
  })
})
