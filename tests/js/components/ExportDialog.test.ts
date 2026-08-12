import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import ExportDialog from '~/components/import-export/ExportDialog.vue'
import type { ExportDialogLabels } from '~/types/import-export'

const stubs = { Icon: { template: '<i :data-name="name" />', props: ['name'] } }

const labels: ExportDialogLabels = {
  title: 'Export things',
  description: 'Pick a format',
  formatLabel: 'Export format',
  submit: 'Export',
  fallbackError: 'Export failed',
}

const formats = [
  { value: 'csv' as const, label: 'CSV' },
  { value: 'json' as const, label: 'JSON' },
]

const text = () => document.body.textContent ?? ''
const button = (label: string) =>
  [...document.body.querySelectorAll('button')].find((el) => el.textContent?.includes(label))

const mountDialog = async (props: Record<string, unknown> = {}) => {
  const wrapper = mount(ExportDialog, {
    props: {
      open: true,
      labels,
      formats,
      filenamePrefix: 'things-export',
      submit: vi.fn().mockResolvedValue(new Blob(['a,b'])),
      ...props,
    },
    global: { stubs },
  })

  await flushPromises()

  return wrapper
}

const submitForm = async () => {
  document.body.querySelector('form')?.dispatchEvent(new Event('submit', { bubbles: true }))
  await flushPromises()
}

beforeEach(() => {
  // jsdom implements neither half of the object-URL API that `downloadBlob` uses.
  URL.createObjectURL = vi.fn(() => 'blob:things')
  URL.revokeObjectURL = vi.fn()
})

afterEach(() => {
  document.body.innerHTML = ''
})

describe('ExportDialog', () => {
  it('renders the configured copy', async () => {
    await mountDialog()

    expect(text()).toContain('Export things')
    expect(text()).toContain('Pick a format')
    expect(text()).toContain('Export format')
  })

  it('exports the first format and downloads a timestamped file', async () => {
    const submit = vi.fn().mockResolvedValue(new Blob(['a,b']))
    let downloaded = ''
    const click = vi
      .spyOn(HTMLAnchorElement.prototype, 'click')
      .mockImplementation(function (this: HTMLAnchorElement) {
        downloaded = this.download
      })
    const wrapper = await mountDialog({ submit })

    await submitForm()

    expect(submit).toHaveBeenCalledWith('csv')
    expect(downloaded).toMatch(/^things-export-\d{4}-\d{2}-\d{2}\.csv$/)
    expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false])

    click.mockRestore()
  })

  it('keeps the dialog open and shows the rejection message', async () => {
    const wrapper = await mountDialog({
      submit: vi.fn().mockRejectedValue(new Error('Server said no')),
    })

    await submitForm()

    expect(text()).toContain('Server said no')
    expect(wrapper.emitted('update:open')).toBeUndefined()
  })

  it('falls back to the configured copy for non-Error rejections', async () => {
    await mountDialog({ submit: vi.fn().mockRejectedValue('boom') })

    await submitForm()

    expect(text()).toContain('Export failed')
  })

  it('re-enables the submit button once the export settles', async () => {
    await mountDialog({ submit: vi.fn().mockRejectedValue('boom') })

    await submitForm()

    expect(button('Export')?.hasAttribute('disabled')).toBe(false)
  })
})
