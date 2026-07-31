import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import FileDropZone from '~/components/ui/FileDropZone.vue'

// Icon resolves names against the iconify collections at runtime; the drop
// zone's behaviour does not depend on which glyph comes back.
const stubs = { Icon: { template: '<i :data-name="name" />', props: ['name'] } }

const mountZone = (props: Record<string, unknown> = {}) =>
  mount(FileDropZone, { props, global: { stubs } })

const file = (name: string) => new File(['x'], name, { type: 'application/zip' })

const fileList = (...files: File[]) =>
  ({ length: files.length, item: (index: number) => files[index], 0: files[0] }) as unknown as FileList

describe('rendering', () => {
  it('prompts for a file by default', () => {
    const wrapper = mountZone()

    expect(wrapper.text()).toContain('Choose a file')
    expect(wrapper.text()).toContain('or drag and drop')
  })

  it('exposes an accessible name and keyboard-reachable role', () => {
    const wrapper = mountZone()

    expect(wrapper.attributes('role')).toBe('button')
    expect(wrapper.attributes('tabindex')).toBe('0')
    expect(wrapper.attributes('aria-label')).toBe('Choose a file')
  })

  it('forwards accept to the file input', () => {
    expect(mountZone({ accept: '.zip,.tgz' }).find('input').attributes('accept')).toBe('.zip,.tgz')
  })

  it('renders a hint only when given one', () => {
    expect(mountZone().text()).not.toContain('Max 10MB')
    expect(mountZone({ hint: 'Max 10MB' }).text()).toContain('Max 10MB')
  })

  it('shows the selected file name instead of the prompt', async () => {
    const wrapper = mountZone({ modelValue: file('backup.zip') })

    await wrapper.vm.$nextTick()

    expect(wrapper.text()).toContain('backup.zip')
    expect(wrapper.text()).not.toContain('or drag and drop')
  })
})

describe('dragging', () => {
  it('switches to the drop prompt while dragging over', async () => {
    const wrapper = mountZone()

    await wrapper.trigger('dragover')

    expect(wrapper.text()).toContain('Drop file here')
    expect(wrapper.find('i').attributes('data-name')).toBe('lucide:download')
  })

  it('keeps the dragging state when the pointer moves onto a child', async () => {
    const wrapper = mountZone()

    await wrapper.trigger('dragover')
    await wrapper.trigger('dragleave', { relatedTarget: wrapper.find('div').element })

    expect(wrapper.text()).toContain('Drop file here')
  })

  it('clears the dragging state when the pointer leaves the zone', async () => {
    const wrapper = mountZone()

    await wrapper.trigger('dragover')
    await wrapper.trigger('dragleave', { relatedTarget: document.body })

    expect(wrapper.text()).not.toContain('Drop file here')
  })
})

describe('selecting a file', () => {
  it('emits the dropped file and stops dragging', async () => {
    const wrapper = mountZone()
    const dropped = file('backup.zip')

    await wrapper.trigger('dragover')
    await wrapper.trigger('drop', { dataTransfer: { files: fileList(dropped) } })

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([dropped])
    expect(wrapper.text()).not.toContain('Drop file here')
  })

  it('emits the file chosen through the input', async () => {
    const wrapper = mountZone()
    const input = wrapper.find('input')

    Object.defineProperty(input.element, 'files', { value: fileList(file('backup.zip')) })
    await input.trigger('change')

    expect(wrapper.emitted('update:modelValue')).toHaveLength(1)
  })

  it('ignores a drop that carries no file', async () => {
    const wrapper = mountZone()

    await wrapper.trigger('drop', { dataTransfer: { files: fileList() } })
    await wrapper.trigger('drop', {})

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })
})

describe('accept filtering', () => {
  it('accepts a matching extension, case-insensitively', async () => {
    const wrapper = mountZone({ accept: '.ZIP' })

    await wrapper.trigger('drop', { dataTransfer: { files: fileList(file('Backup.zip')) } })

    expect(wrapper.emitted('update:modelValue')).toHaveLength(1)
    expect(wrapper.emitted('error')).toBeUndefined()
  })

  it('rejects a non-matching extension with an error instead of a value', async () => {
    const wrapper = mountZone({ accept: '.zip' })

    await wrapper.trigger('drop', { dataTransfer: { files: fileList(file('notes.txt')) } })

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    expect(wrapper.emitted('error')?.[0]).toEqual([
      'Unsupported file type.',
    ])
  })

  it('accepts any file when accept is unset or blank', async () => {
    for (const accept of [undefined, '', ' , ']) {
      const wrapper = mountZone({ accept })

      await wrapper.trigger('drop', { dataTransfer: { files: fileList(file('notes.txt')) } })

      expect(wrapper.emitted('update:modelValue')).toHaveLength(1)
    }
  })

  it('matches any one of several accepted extensions', async () => {
    const wrapper = mountZone({ accept: '.zip, .tgz' })

    await wrapper.trigger('drop', { dataTransfer: { files: fileList(file('backup.tgz')) } })

    expect(wrapper.emitted('update:modelValue')).toHaveLength(1)
  })
})
