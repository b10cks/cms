import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

import ImportDialog from '~/components/import-export/ImportDialog.vue'
import type { ImportDialogLabels, ImportDialogMode } from '~/types/import-export'

// Icon resolves names against the iconify collections at runtime; none of the
// dialog's behaviour depends on which glyph comes back.
const stubs = { Icon: { template: '<i :data-name="name" />', props: ['name'] } }

interface Item {
  id: string
  key: string
  changes: { field: string }[]
}

interface Deleted {
  id: string
  key: string
}

const labels: ImportDialogLabels = {
  title: 'Import things',
  description: 'Upload a file',
  formats: 'Supported: JSON',
  selectFileError: 'Pick a file first',
  fallbackError: 'Import failed',
  submit: 'Import',
  modeLabel: 'Import mode',
  summaryTitle: 'Import summary',
  summaryDescription: 'Here is what happened',
  summarySuccess: 'Successful',
  summaryChanges: 'Changes',
  summaryDeleted: 'Deleted',
  summaryErrors: 'Errors',
  changesTitle: 'Modified things',
  deletedTitle: 'Deleted things',
  ignoredFieldsTitle: 'Ignored fields',
  errorsTitle: 'Import errors',
}

const modes: ImportDialogMode<'addition' | 'replacement'>[] = [
  {
    value: 'addition',
    icon: 'lucide:plus-circle',
    label: 'Add / Update',
    description: 'Keeps everything already there',
  },
  {
    value: 'replacement',
    icon: 'lucide:replace',
    label: 'Replace all',
    description: 'Wipes what is missing',
    warning: 'Everything missing will be deleted',
  },
]

const result = {
  changes: [
    { id: 'a', key: 'alpha', changes: [{ field: 'name' }, { field: 'slug' }] },
    { id: 'b', key: 'beta', changes: [{ field: 'name' }] },
  ],
  errors: [{ row: 7, message: 'Broken row' }],
  ignored_fields: ['legacy_field'],
  deleted: [{ id: 'c', key: 'gamma' }],
  summary: { total_success: 2, total_changes: 2, total_errors: 1, total_deleted: 1 },
}

// The dialog content is portalled, so everything is asserted against the body
// rather than the wrapper's own subtree.
const text = () => document.body.textContent ?? ''
const buttons = () => [...document.body.querySelectorAll('button')]
const button = (label: string) => buttons().find((el) => el.textContent?.includes(label))

const click = async (label: string) => {
  button(label)?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  await flushPromises()
}

const chooseFile = async (name = 'entries.json') => {
  const input = document.body.querySelector('input[type="file"]') as HTMLInputElement

  Object.defineProperty(input, 'files', {
    configurable: true,
    value: { length: 1, item: () => new File(['{}'], name), 0: new File(['{}'], name) },
  })
  input.dispatchEvent(new Event('change'))
  await flushPromises()
}

// The dialog portals its content, which reka only does after the mount tick —
// so every mount is awaited before the body is queried.
// Mounted through an untyped mock `submit`, so the dialog's item generic
// erases to `unknown` here and the fixtures narrow it back at the use site.
const asItem = (item: unknown) => item as Item

const defaultSlots = {
  label: (params: { item: unknown }) => h('span', asItem(params.item).key),
}

const mountDialog = async (
  props: Record<string, unknown> = {},
  slots: Record<string, unknown> = {}
) => {
  const wrapper = mount(ImportDialog, {
    props: {
      open: true,
      accept: '.json',
      labels,
      modes,
      pending: false,
      submit: vi.fn().mockResolvedValue(result),
      itemKey: (item: unknown) => asItem(item).id,
      changeCount: (item: unknown) => `${asItem(item).changes.length} change(s)`,
      deletedLabel: (item: unknown) => (item as Deleted).key,
      ...props,
    },
    slots: { ...defaultSlots, ...slots },
    global: { stubs },
  })

  await flushPromises()

  return wrapper
}

afterEach(() => {
  document.body.innerHTML = ''
})

describe('mode selection', () => {
  it('renders every mode and preselects the first', async () => {
    await mountDialog()

    expect(text()).toContain('Import mode')
    expect(text()).toContain('Add / Update')
    expect(text()).toContain('Replace all')
    expect(button('Add / Update')?.className).toContain('border-primary')
    expect(button('Replace all')?.className).not.toContain('border-primary')
  })

  it('shows the warning of the selected mode only', async () => {
    await mountDialog()

    expect(text()).not.toContain('Everything missing will be deleted')

    await click('Replace all')

    expect(text()).toContain('Everything missing will be deleted')
    expect(button('Replace all')?.className).toContain('border-primary')
  })

  it('submits the file with the selected mode', async () => {
    const submit = vi.fn().mockResolvedValue(result)
    await mountDialog({ submit })

    await chooseFile()
    await click('Replace all')
    await click('Import')

    expect(submit).toHaveBeenCalledTimes(1)
    expect(submit.mock.calls[0][0]).toBeInstanceOf(File)
    expect(submit.mock.calls[0][1]).toBe('replacement')
  })

  it('omits the mode picker when no modes are given', async () => {
    await mountDialog({ modes: undefined })

    expect(text()).not.toContain('Import mode')
    expect(text()).not.toContain('Add / Update')
  })
})

describe('errors', () => {
  it('refuses to submit without a file', async () => {
    const submit = vi.fn()
    await mountDialog({ submit })

    // The button is disabled without a file, so drive the handler the way a
    // stale-state submit would: through the click the component still binds.
    button('Import')?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    expect(submit).not.toHaveBeenCalled()
  })

  it('surfaces the rejection message', async () => {
    await mountDialog({ submit: vi.fn().mockRejectedValue(new Error('Server said no')) })

    await chooseFile()
    await click('Import')

    expect(text()).toContain('Server said no')
    expect(text()).not.toContain('Import summary')
  })

  it('falls back to the configured copy for non-Error rejections', async () => {
    await mountDialog({ submit: vi.fn().mockRejectedValue('boom') })

    await chooseFile()
    await click('Import')

    expect(text()).toContain('Import failed')
  })

  it('reports an unsupported file instead of selecting it', async () => {
    await mountDialog()

    await chooseFile('notes.txt')

    expect(text()).toContain('Unsupported file type.')
  })
})

describe('summary', () => {
  const openSummary = async (
    props: Record<string, unknown> = {},
    slots: Record<string, unknown> = {}
  ) => {
    const wrapper = await mountDialog(props, slots)

    await chooseFile()
    await click('Import')

    return wrapper
  }

  it('replaces the form with the summary tiles', async () => {
    await openSummary()

    expect(text()).toContain('Import summary')
    expect(text()).toContain('Successful')
    expect(text()).toContain('Deleted')
    expect(text()).not.toContain('Upload a file')
  })

  it('lists changed, deleted, ignored and errored rows', async () => {
    await openSummary()

    expect(text()).toContain('alpha')
    expect(text()).toContain('2 change(s)')
    expect(text()).toContain('gamma')
    expect(text()).toContain('legacy_field')
    expect(text()).toContain('Broken row')
  })

  it('expands one changed row at a time and collapses it again', async () => {
    await openSummary({}, { details: () => h('span', { 'data-details': '' }, 'expanded rows') })

    expect(text()).not.toContain('expanded rows')

    await click('alpha')

    expect(document.body.querySelectorAll('[data-details]')).toHaveLength(1)

    await click('beta')

    expect(document.body.querySelectorAll('[data-details]')).toHaveLength(2)

    await click('alpha')

    expect(document.body.querySelectorAll('[data-details]')).toHaveLength(1)
  })

  it('hides the deleted and ignored sections when unconfigured', async () => {
    await openSummary({
      deletedLabel: undefined,
      labels: { ...labels, ignoredFieldsTitle: undefined },
    })

    expect(text()).not.toContain('Deleted things')
    expect(text()).not.toContain('Ignored fields')
    expect(text()).not.toContain('legacy_field')
  })

  it('clears itself and asks the wrapper to reset when closed', async () => {
    const wrapper = await openSummary()

    await click('Close')

    expect(wrapper.emitted('reset')).toHaveLength(1)
    expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false])
  })

  it('keeps the result while a pending import is closed', async () => {
    const wrapper = await openSummary()

    await wrapper.setProps({ pending: true })
    await click('Close')

    expect(wrapper.emitted('reset')).toBeUndefined()
  })
})
