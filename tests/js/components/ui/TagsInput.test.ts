import type { VueWrapper } from '@vue/test-utils'
import type { ComponentPublicInstance } from 'vue'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import TagsInput from '~/components/ui/tags-input/TagsInput.vue'
import TagsInputInput from '~/components/ui/tags-input/TagsInputInput.vue'
import TagsInputItem from '~/components/ui/tags-input/TagsInputItem.vue'
import TagsInputItemDelete from '~/components/ui/tags-input/TagsInputItemDelete.vue'
import TagsInputItemText from '~/components/ui/tags-input/TagsInputItemText.vue'

const stubs = { Icon: { template: '<i :data-name="name" />', props: ['name'] } }

// Each wrapper is a thin reka-ui pass-through, so what is worth testing is the
// composed widget — root + item + delete + input — exactly how callers build it.
const Host = {
  components: { TagsInput, TagsInputInput, TagsInputItem, TagsInputItemDelete, TagsInputItemText },
  props: {
    modelValue: { type: Array, default: () => [] },
    max: { type: Number, default: undefined },
    duplicate: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    delimiter: { type: String, default: undefined },
    addOnPaste: { type: Boolean, default: false },
  },
  emits: ['update:modelValue', 'invalid'],
  template: `
    <TagsInput
      :model-value="modelValue"
      :max="max"
      :duplicate="duplicate"
      :disabled="disabled"
      :delimiter="delimiter"
      :add-on-paste="addOnPaste"
      @update:model-value="$emit('update:modelValue', $event)"
      @invalid="$emit('invalid', $event)"
    >
      <TagsInputItem v-for="tag in modelValue" :key="String(tag)" :value="tag">
        <TagsInputItemText />
        <TagsInputItemDelete />
      </TagsInputItem>
      <TagsInputInput placeholder="Add a tag" />
    </TagsInput>
  `,
}

// `Host` is a plain options object, so `InstanceType` does not apply to it.
type HostWrapper = VueWrapper<ComponentPublicInstance>

const mountTags = (props: Record<string, unknown> = {}) =>
  mount(Host, { props, global: { stubs }, attachTo: document.body })

const root = (wrapper: HostWrapper) => wrapper.findComponent(TagsInput)
const field = (wrapper: HostWrapper) => wrapper.find('input')
const items = (wrapper: HostWrapper) => wrapper.findAll('[data-reka-collection-item]')
const wrapperDelete = (wrapper: HostWrapper) => wrapper.find('[data-reka-collection-item] button')

const addTag = async (wrapper: HostWrapper, value: string) => {
  await field(wrapper).setValue(value)
  await field(wrapper).trigger('keydown', { key: 'Enter' })
}

// The delimiter split runs off the InputEvent's `data`, which setValue omits.
const typeDelimiter = async (wrapper: HostWrapper, value: string, delimiter: string) => {
  field(wrapper).element.value = value
  await field(wrapper).trigger('input', { data: delimiter })
}

describe('rendering', () => {
  it('renders one item per tag with its text and a delete control', () => {
    const wrapper = mountTags({ modelValue: ['vue', 'laravel'] })

    expect(items(wrapper)).toHaveLength(2)
    expect(wrapper.text()).toContain('vue')
    expect(wrapper.text()).toContain('laravel')
    expect(wrapper.findAll('[data-reka-collection-item] i')).toHaveLength(2)
  })

  it('labels each tag and its delete button by the tag text', async () => {
    const wrapper = mountTags({ modelValue: ['vue'] })
    await nextTick()

    const textId = wrapper.find('[data-reka-collection-item] span').attributes('id')
    expect(textId).toBeTruthy()
    expect(items(wrapper)[0].attributes('aria-labelledby')).toBe(textId)
    expect(wrapper.find('[data-reka-collection-item] button').attributes('aria-labelledby')).toBe(
      textId
    )
  })

  it('keeps the delete buttons out of the tab order', () => {
    expect(wrapperDelete(mountTags({ modelValue: ['vue'] })).attributes('tabindex')).toBe('-1')
  })

  it('renders every tag as selected on the very first paint', async () => {
    // reka-ui compares selectedElement against a template ref that is still
    // undefined during the initial render, so every item briefly reports
    // aria-current="true" — and our item styling rings all of them.
    const wrapper = mountTags({ modelValue: ['vue', 'laravel'] })

    expect(items(wrapper).map((item) => item.attributes('aria-current'))).toEqual(['true', 'true'])

    await nextTick()

    expect(items(wrapper).map((item) => item.attributes('aria-current'))).toEqual(['false', 'false'])
  })

  it('appends caller classes to every wrapper rather than replacing the base ones', () => {
    const wrapper = mount(
      {
        components: { TagsInput, TagsInputItem, TagsInputItemText, TagsInputInput },
        template: `
          <TagsInput :model-value="['vue']" class="root-extra">
            <TagsInputItem value="vue" class="item-extra">
              <TagsInputItemText class="text-extra" />
            </TagsInputItem>
            <TagsInputInput class="input-extra" />
          </TagsInput>
        `,
      },
      { global: { stubs } }
    )

    expect(wrapper.findComponent(TagsInput).classes()).toEqual(
      expect.arrayContaining(['flex-wrap', 'root-extra'])
    )
    expect(wrapper.find('[data-reka-collection-item]').classes()).toEqual(
      expect.arrayContaining(['bg-secondary', 'item-extra'])
    )
    expect(wrapper.find('span').classes()).toEqual(expect.arrayContaining(['px-2', 'text-extra']))
    expect(wrapper.find('input').classes()).toEqual(
      expect.arrayContaining(['flex-1', 'input-extra'])
    )
  })

  it('forwards attributes to the text input', () => {
    expect(field(mountTags()).attributes('placeholder')).toBe('Add a tag')
  })
})

describe('adding tags', () => {
  it('adds the typed value on Enter and clears the field', async () => {
    const wrapper = mountTags()

    await addTag(wrapper, 'vue')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([['vue']])
    expect(field(wrapper).element.value).toBe('')
  })

  it('appends to the existing tags', async () => {
    const wrapper = mountTags({ modelValue: ['vue'] })

    await addTag(wrapper, 'laravel')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([['vue', 'laravel']])
  })

  it('ignores Enter on an empty field', async () => {
    const wrapper = mountTags()

    await addTag(wrapper, '')

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('adds whitespace verbatim — nothing trims or rejects a blank tag', async () => {
    const wrapper = mountTags()

    await addTag(wrapper, '  vue  ')
    await addTag(wrapper, '   ')

    // The host never feeds the value back, so the root's own passive copy is
    // what accumulates here.
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([['  vue  ']])
    expect(wrapper.emitted('update:modelValue')?.[1]).toEqual([['  vue  ', '   ']])
  })

  it('splits on a comma by default', async () => {
    const wrapper = mountTags()

    await typeDelimiter(wrapper, 'vue,', ',')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([['vue']])
    expect(field(wrapper).element.value).toBe('')
  })

  it('honours a custom delimiter and ignores the comma then', async () => {
    const wrapper = mountTags({ delimiter: ';' })

    await typeDelimiter(wrapper, 'vue,laravel,', ',')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()

    await typeDelimiter(wrapper, 'vue;', ';')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([['vue']])
  })

  it('drops a delimiter typed on its own instead of adding an empty tag', async () => {
    const wrapper = mountTags()

    await typeDelimiter(wrapper, ',', ',')

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    expect(field(wrapper).element.value).toBe('')
  })
})

describe('pasting', () => {
  it('ignores a paste unless addOnPaste is set', async () => {
    const wrapper = mountTags()

    await field(wrapper).trigger('paste', { clipboardData: { getData: () => 'vue,laravel' } })

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('splits a pasted list on the delimiter', async () => {
    const wrapper = mountTags({ addOnPaste: true })

    await field(wrapper).trigger('paste', { clipboardData: { getData: () => 'vue,laravel' } })

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([['vue', 'laravel']])
  })
})

describe('duplicates and limits', () => {
  it('rejects a duplicate and reports it as invalid', async () => {
    const wrapper = mountTags({ modelValue: ['vue'] })

    await addTag(wrapper, 'vue')

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    expect(wrapper.emitted('invalid')?.at(-1)).toEqual(['vue'])
  })

  it('flags both the field and the input as invalid after a duplicate', async () => {
    const wrapper = mountTags({ modelValue: ['vue'] })

    await addTag(wrapper, 'vue')

    expect(root(wrapper).attributes('data-invalid')).toBe('')
    expect(field(wrapper).attributes('data-invalid')).toBe('')
  })

  it('allows duplicates when asked to', async () => {
    const wrapper = mountTags({ modelValue: ['vue'], duplicate: true })

    await addTag(wrapper, 'vue')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([['vue', 'vue']])
    expect(wrapper.emitted('invalid')).toBeUndefined()
  })

  it('stops accepting tags at max and reports the rejected value', async () => {
    const wrapper = mountTags({ modelValue: ['vue'], max: 1 })

    await addTag(wrapper, 'laravel')

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    expect(wrapper.emitted('invalid')?.at(-1)).toEqual(['laravel'])
  })

  it('gives no visual invalid marker when the max is what rejected the tag', async () => {
    // Only the duplicate branch sets isInvalidInput, so a full field silently
    // swallows the entry — the text even stays behind in the input.
    const wrapper = mountTags({ modelValue: ['vue'], max: 1 })

    await addTag(wrapper, 'laravel')

    expect(root(wrapper).attributes('data-invalid')).toBeUndefined()
    expect(field(wrapper).attributes('data-invalid')).toBeUndefined()
    expect(field(wrapper).element.value).toBe('laravel')
  })

  it('treats max 0 as no limit at all', async () => {
    // `array.length >= max && !!max` — a falsy max short-circuits the check.
    const wrapper = mountTags({ max: 0 })

    await addTag(wrapper, 'vue')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([['vue']])
  })
})

describe('removing tags', () => {
  it('removes a tag through its delete control', async () => {
    const wrapper = mountTags({ modelValue: ['vue', 'laravel'] })
    // The item collection registers on the tick after mount; deleting before
    // that throws inside reka-ui's removeTag emit.
    await nextTick()

    await items(wrapper)[0].find('button').trigger('click')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([['laravel']])
  })

  it('removes only the clicked tag', async () => {
    const wrapper = mountTags({ modelValue: ['vue', 'laravel', 'nuxt'] })
    await nextTick()

    await items(wrapper)[1].find('button').trigger('click')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([['vue', 'nuxt']])
  })

  it('leaves the tags alone when Backspace is pressed with text in the field', async () => {
    const wrapper = mountTags({ modelValue: ['vue'] })

    await field(wrapper).setValue('lar')
    await field(wrapper).trigger('keydown', { key: 'Backspace' })

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('does nothing on Backspace when there are no tags', async () => {
    const wrapper = mountTags()

    await field(wrapper).trigger('keydown', { key: 'Backspace' })

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })
})

describe('disabled', () => {
  it('marks itself disabled and accepts nothing', async () => {
    const wrapper = mountTags({ modelValue: ['vue'], disabled: true })

    expect(root(wrapper).attributes('data-disabled')).toBe('')
    expect(field(wrapper).attributes('disabled')).toBeDefined()

    await addTag(wrapper, 'laravel')

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('still renders the delete buttons while disabled, but they no longer remove', async () => {
    const wrapper = mountTags({ modelValue: ['vue'], disabled: true })
    await nextTick()

    await wrapperDelete(wrapper).trigger('click')

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })
})
