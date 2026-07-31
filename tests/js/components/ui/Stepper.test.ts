import type { VueWrapper } from '@vue/test-utils'
import type { ComponentPublicInstance } from 'vue'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import Stepper from '~/components/ui/stepper/Stepper.vue'
import StepperDescription from '~/components/ui/stepper/StepperDescription.vue'
import StepperIndicator from '~/components/ui/stepper/StepperIndicator.vue'
import StepperItem from '~/components/ui/stepper/StepperItem.vue'
import StepperSeparator from '~/components/ui/stepper/StepperSeparator.vue'
import StepperTitle from '~/components/ui/stepper/StepperTitle.vue'
import StepperTrigger from '~/components/ui/stepper/StepperTrigger.vue'

const steps = [
  { step: 1, title: 'Details', description: 'Name your space' },
  { step: 2, title: 'Plan', description: 'Pick a plan' },
  { step: 3, title: 'Done', description: 'Review' },
]

// The state a caller cares about (active/completed/disabled) is decided by the
// root and read back by the parts, so the whole assembly is the unit under test.
const Host = {
  components: {
    Stepper,
    StepperDescription,
    StepperIndicator,
    StepperItem,
    StepperSeparator,
    StepperTitle,
    StepperTrigger,
  },
  props: {
    modelValue: { type: Number, default: 1 },
    linear: { type: Boolean, default: true },
    disabledStep: { type: Number, default: 0 },
    orientation: { type: String, default: 'horizontal' },
    steps: { type: Array, default: () => steps },
  },
  emits: ['update:modelValue'],
  template: `
    <Stepper
      :model-value="modelValue"
      :linear="linear"
      :orientation="orientation"
      @update:model-value="$emit('update:modelValue', $event)"
    >
      <StepperItem
        v-for="item in steps"
        :key="item.step"
        :step="item.step"
        :disabled="item.step === disabledStep"
      >
        <StepperTrigger>
          <StepperIndicator>{{ item.step }}</StepperIndicator>
          <StepperTitle>{{ item.title }}</StepperTitle>
          <StepperDescription>{{ item.description }}</StepperDescription>
        </StepperTrigger>
        <StepperSeparator v-if="item.step < 3" />
      </StepperItem>
    </Stepper>
  `,
}

// `Host` is a plain options object, so `InstanceType` does not apply to it.
type HostWrapper = VueWrapper<ComponentPublicInstance>

const mountStepper = (props: Record<string, unknown> = {}) => mount(Host, { props })

const itemNodes = (wrapper: HostWrapper) => wrapper.findAllComponents(StepperItem)
const triggers = (wrapper: HostWrapper) => wrapper.findAll('button')
const states = (wrapper: HostWrapper) =>
  itemNodes(wrapper).map((item) => item.attributes('data-state'))

// The trigger acts on mousedown, not click — a plain `click` does nothing.
const activate = (wrapper: HostWrapper, index: number) =>
  triggers(wrapper)[index].trigger('mousedown')

describe('rendering', () => {
  it('renders a labelled group with one item per step', () => {
    const wrapper = mountStepper()

    expect(wrapper.attributes('role')).toBe('group')
    expect(wrapper.attributes('aria-label')).toBe('progress')
    expect(itemNodes(wrapper)).toHaveLength(3)
    expect(wrapper.text()).toContain('Details')
    expect(wrapper.text()).toContain('Name your space')
  })

  it('lays itself out horizontally by default and reports the orientation', () => {
    expect(mountStepper().attributes('data-orientation')).toBe('horizontal')
    expect(mountStepper({ orientation: 'vertical' }).attributes('data-orientation')).toBe('vertical')
  })

  it('marks itself linear unless told otherwise', () => {
    expect(mountStepper().attributes('data-linear')).toBe('')
    expect(mountStepper({ linear: false }).attributes('data-linear')).toBeUndefined()
  })

  it('labels each trigger by its title and describes it by its description', () => {
    const wrapper = mountStepper()
    const trigger = triggers(wrapper)[0]

    expect(trigger.attributes('type')).toBe('button')
    expect(trigger.attributes('aria-labelledby')).toBe(wrapper.find('h4').attributes('id'))
    expect(trigger.attributes('aria-describedby')).toBeTruthy()
  })

  it('renders a separator between steps but not after the last one', () => {
    expect(mountStepper().findAllComponents(StepperSeparator)).toHaveLength(2)
  })

  it('announces the position in a live region, but counts zero steps on first paint', async () => {
    // totalStepperItems fills up in onMounted, so the very first render
    // announces "Step 1 of 0".
    const wrapper = mountStepper({ modelValue: 2 })

    expect(wrapper.find('[role="status"]').text()).toBe('Step 2 of 0')

    await nextTick()

    expect(wrapper.find('[role="status"]').text()).toBe('Step 2 of 3')
  })

  it('appends caller classes rather than replacing the base ones', () => {
    const wrapper = mount({
      components: { Stepper, StepperItem, StepperIndicator, StepperTrigger, StepperTitle },
      template: `
        <Stepper :model-value="1" class="root-extra">
          <StepperItem :step="1" class="item-extra">
            <StepperTrigger class="trigger-extra">
              <StepperIndicator class="indicator-extra">1</StepperIndicator>
              <StepperTitle class="title-extra">One</StepperTitle>
            </StepperTrigger>
          </StepperItem>
        </Stepper>
      `,
    })

    expect(wrapper.classes()).toEqual(expect.arrayContaining(['gap-2', 'root-extra']))
    expect(wrapper.findComponent(StepperItem).classes()).toEqual(
      expect.arrayContaining(['group', 'item-extra'])
    )
    expect(wrapper.find('button').classes()).toEqual(
      expect.arrayContaining(['flex-col', 'trigger-extra'])
    )
    expect(wrapper.findComponent(StepperIndicator).classes()).toEqual(
      expect.arrayContaining(['rounded-full', 'indicator-extra'])
    )
    expect(wrapper.find('h4').classes()).toEqual(
      expect.arrayContaining(['font-semibold', 'title-extra'])
    )
  })
})

describe('step state', () => {
  it('marks the current step active and the rest inactive', () => {
    expect(states(mountStepper({ modelValue: 1 }))).toEqual(['active', 'inactive', 'inactive'])
  })

  it('marks earlier steps completed', () => {
    expect(states(mountStepper({ modelValue: 3 }))).toEqual(['completed', 'completed', 'active'])
  })

  it('reports only the active step as aria-current', () => {
    const wrapper = mountStepper({ modelValue: 2 })

    expect(itemNodes(wrapper).map((item) => item.attributes('aria-current'))).toEqual([
      undefined,
      'true',
      undefined,
    ])
  })

  it('mirrors the item state onto the trigger and the separator', () => {
    const wrapper = mountStepper({ modelValue: 2 })

    expect(triggers(wrapper).map((trigger) => trigger.attributes('data-state'))).toEqual([
      'completed',
      'active',
      'inactive',
    ])
    expect(
      wrapper.findAllComponents(StepperSeparator).map((sep) => sep.attributes('data-state'))
    ).toEqual(['completed', 'active'])
  })

  it('keeps steps beyond the next one unreachable while linear', () => {
    const wrapper = mountStepper({ modelValue: 1 })

    expect(triggers(wrapper).map((trigger) => trigger.attributes('tabindex'))).toEqual([
      '0',
      '0',
      '-1',
    ])
    expect(triggers(wrapper)[2].attributes('disabled')).toBe('')
  })

  it('makes every step focusable once linear is off', () => {
    const wrapper = mountStepper({ modelValue: 1, linear: false })

    expect(triggers(wrapper).map((trigger) => trigger.attributes('tabindex'))).toEqual([
      '0',
      '0',
      '0',
    ])
  })

  it('disables a step on request', () => {
    const wrapper = mountStepper({ modelValue: 2, disabledStep: 2 })

    expect(itemNodes(wrapper)[1].attributes('data-disabled')).toBe('')
    expect(triggers(wrapper)[1].attributes('disabled')).toBe('')
    expect(triggers(wrapper)[1].attributes('tabindex')).toBe('-1')
  })
})

describe('navigation guards', () => {
  it('ignores a plain click — the trigger listens on mousedown', async () => {
    const wrapper = mountStepper({ modelValue: 1 })

    await triggers(wrapper)[1].trigger('click')

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('lets the user step back to a completed step', async () => {
    const wrapper = mountStepper({ modelValue: 3 })

    await activate(wrapper, 0)

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([1])
  })

  it('allows the immediately next step while linear', async () => {
    const wrapper = mountStepper({ modelValue: 1 })

    await activate(wrapper, 1)

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([2])
  })

  it('refuses a jump past the next step while linear', async () => {
    const wrapper = mountStepper({ modelValue: 1 })

    await activate(wrapper, 2)

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('allows any jump once linear is off', async () => {
    const wrapper = mountStepper({ modelValue: 1, linear: false })

    await activate(wrapper, 2)

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([3])
  })

  it('ignores an activation on a disabled step', async () => {
    const wrapper = mountStepper({ modelValue: 1, linear: false, disabledStep: 3 })

    await activate(wrapper, 2)

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('ignores a ctrl-modified activation', async () => {
    const wrapper = mountStepper({ modelValue: 1 })

    await triggers(wrapper)[1].trigger('mousedown', { ctrlKey: true })

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('re-emits the same step when the active one is activated again', async () => {
    // No equality guard: activating the current step still notifies the parent.
    const wrapper = mountStepper({ modelValue: 2 })

    await activate(wrapper, 1)

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([2])
  })

  it('moves on Enter and Space from the keyboard', async () => {
    const wrapper = mountStepper({ modelValue: 1 })

    await triggers(wrapper)[1].trigger('keydown', { key: 'Enter' })
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([2])

    await triggers(wrapper)[1].trigger('keydown', { key: ' ' })
    expect(wrapper.emitted('update:modelValue')).toHaveLength(2)
  })

  it('ignores other keys', async () => {
    const wrapper = mountStepper({ modelValue: 1 })

    await triggers(wrapper)[1].trigger('keydown', { key: 'a' })

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('follows a step pushed in from the parent', async () => {
    const wrapper = mountStepper({ modelValue: 1 })

    await wrapper.setProps({ modelValue: 2 })

    expect(states(wrapper)).toEqual(['completed', 'active', 'inactive'])
  })
})
