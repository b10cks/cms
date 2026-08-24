import type { VueWrapper } from '@vue/test-utils'
import type { ComponentPublicInstance } from 'vue'
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { nextTick, ref } from 'vue'

import InputOTP from '~/components/ui/input-otp/InputOTP.vue'
import InputOTPGroup from '~/components/ui/input-otp/InputOTPGroup.vue'
import InputOTPSeparator from '~/components/ui/input-otp/InputOTPSeparator.vue'
import InputOTPSlot from '~/components/ui/input-otp/InputOTPSlot.vue'

const stubs = { Icon: { template: '<i :data-name="name" />', props: ['name'] } }

// vue-input-otp polls for a password-manager badge on a timer that outlives the
// test; jsdom has no elementFromPoint, and the rejection surfaces as an
// unhandled error long after the assertion that caused it.
document.elementFromPoint ??= () => null

// The pieces only make sense composed: the slots read their character out of
// the vue-input-otp context that the root provides.
const Host = {
  components: { InputOTP, InputOTPGroup, InputOTPSeparator, InputOTPSlot },
  props: {
    maxlength: { type: Number, default: 4 },
    initial: { type: String, default: '' },
    pattern: { type: String, default: undefined },
    disabled: { type: Boolean, default: false },
    separator: { type: Boolean, default: false },
    ariaInvalid: { type: Boolean, default: false },
  },
  emits: ['complete'],
  setup(props: { initial: string }) {
    return { value: ref(props.initial) }
  },
  template: `
    <InputOTP
      v-model="value"
      :maxlength="maxlength"
      :pattern="pattern"
      :disabled="disabled"
      @complete="$emit('complete', $event)"
    >
      <InputOTPGroup>
        <InputOTPSlot
          v-for="i in maxlength"
          :key="i"
          :index="i - 1"
          :aria-invalid="ariaInvalid || undefined"
        />
      </InputOTPGroup>
      <InputOTPSeparator v-if="separator" />
    </InputOTP>
  `,
}

// `Host` is a plain options object, so `InstanceType` does not apply to it.
type HostWrapper = VueWrapper<ComponentPublicInstance>

// Every mount is tracked and torn down: vue-input-otp keeps a timer that
// dispatches into its input, and a wrapper left attached lets that fire during a
// *later* test file, where it surfaces as an unhandled error.
let mounted: HostWrapper[] = []

const mountOtp = (props: Record<string, unknown> = {}) => {
  const wrapper = mount(Host, { props, global: { stubs }, attachTo: document.body })
  mounted.push(wrapper)
  return wrapper
}

afterEach(() => {
  mounted.forEach((wrapper) => wrapper.unmount())
  mounted = []
})

const field = (wrapper: HostWrapper) => wrapper.find('input')
const slots = (wrapper: HostWrapper) => wrapper.findAll('[data-slot="input-otp-slot"]')
const chars = (wrapper: HostWrapper) => slots(wrapper).map((slot) => slot.text())

const type = async (wrapper: HostWrapper, value: string) => {
  await field(wrapper).setValue(value)
  await nextTick()
}

describe('rendering', () => {
  it('renders one slot per digit plus a single real input', () => {
    const wrapper = mountOtp({ maxlength: 6 })

    expect(slots(wrapper)).toHaveLength(6)
    expect(wrapper.findAll('input')).toHaveLength(1)
  })

  it('caps the input at maxlength and asks for a one-time code', () => {
    const wrapper = mountOtp({ maxlength: 6 })

    expect(field(wrapper).attributes('maxlength')).toBe('6')
    expect(field(wrapper).attributes('autocomplete')).toBe('one-time-code')
    expect(field(wrapper).attributes('inputmode')).toBe('numeric')
  })

  it('tags the container, group, slots and separator for styling hooks', () => {
    const wrapper = mountOtp({ separator: true })

    expect(wrapper.find('[data-input-otp-container]').classes()).toContain('items-center')
    expect(wrapper.find('[data-slot="input-otp-group"]').exists()).toBe(true)
    expect(wrapper.find('[data-slot="input-otp-separator"]').attributes('role')).toBe('separator')
    expect(field(wrapper).attributes('data-slot')).toBe('input-otp')
  })

  it('gives the separator a default glyph that a slot can replace', () => {
    expect(mountOtp({ separator: true }).find('[role="separator"] i').attributes('data-name')).toBe(
      'lucide:minus'
    )

    const custom = mount(InputOTPSeparator, { slots: { default: '—' }, global: { stubs } })
    expect(custom.text()).toBe('—')
    expect(custom.find('i').exists()).toBe(false)
  })

  it('leaks the slot index onto the DOM as a stray attribute', () => {
    // `index` is forwarded with the rest of the props, so it lands on the div.
    expect(slots(mountOtp())[2].attributes('index')).toBe('2')
  })

  it('appends caller classes rather than replacing the base ones', () => {
    const wrapper = mount(
      {
        components: { InputOTP, InputOTPGroup, InputOTPSlot },
        template: `
          <InputOTP :maxlength="1" model-value="" class="container-extra">
            <InputOTPGroup class="group-extra"><InputOTPSlot :index="0" class="slot-extra" /></InputOTPGroup>
          </InputOTP>
        `,
      },
      { global: { stubs } }
    )

    expect(wrapper.find('[data-input-otp-container]').classes()).toEqual(
      expect.arrayContaining(['items-center', 'container-extra'])
    )
    expect(wrapper.find('[data-slot="input-otp-group"]').classes()).toEqual(
      expect.arrayContaining(['flex', 'group-extra'])
    )
    expect(wrapper.find('[data-slot="input-otp-slot"]').classes()).toEqual(
      expect.arrayContaining(['size-12', 'slot-extra'])
    )
  })

  it('passes aria-invalid through to each slot so the error styling can hook it', () => {
    expect(slots(mountOtp({ ariaInvalid: true }))[0].attributes('aria-invalid')).toBe('true')
    expect(slots(mountOtp())[0].attributes('aria-invalid')).toBeUndefined()
  })
})

describe('entering a code', () => {
  it('spreads the value one character per slot', async () => {
    const wrapper = mountOtp()

    await type(wrapper, '12')

    expect(chars(wrapper)).toEqual(['1', '2', '', ''])
  })

  it('fills every slot once the code is complete', async () => {
    const wrapper = mountOtp()

    await type(wrapper, '1234')

    expect(chars(wrapper)).toEqual(['1', '2', '3', '4'])
  })

  it('renders an initial value without any interaction', async () => {
    const wrapper = mountOtp({ initial: '99' })
    await nextTick()

    expect(chars(wrapper)).toEqual(['9', '9', '', ''])
  })

  it('clears the slots when the code is deleted', async () => {
    const wrapper = mountOtp({ initial: '1234' })
    await nextTick()

    await type(wrapper, '')

    expect(chars(wrapper)).toEqual(['', '', '', ''])
  })

  it('reports the code to the parent through v-model', async () => {
    const wrapper = mountOtp()

    await type(wrapper, '1234')

    expect((wrapper.vm as unknown as { value: string }).value).toBe('1234')
  })

  it('accepts letters unless a pattern rejects them', async () => {
    // The input is only inputmode="numeric" — nothing filters non-digits.
    const wrapper = mountOtp()

    await type(wrapper, '12ab')

    expect(chars(wrapper)).toEqual(['1', '2', 'a', 'b'])
  })

  it('refuses a value that does not match the pattern outright', async () => {
    const wrapper = mountOtp({ pattern: '^[0-9]+$' })

    await type(wrapper, '12ab')

    expect(chars(wrapper)).toEqual(['', '', '', ''])
  })

  it('keeps a pattern-matching value', async () => {
    const wrapper = mountOtp({ pattern: '^[0-9]+$' })

    await type(wrapper, '1234')

    expect(chars(wrapper)).toEqual(['1', '2', '3', '4'])
  })

  it('drops anything typed past maxlength', async () => {
    const wrapper = mountOtp({ maxlength: 4 })

    await type(wrapper, '123456')

    expect(chars(wrapper)).toEqual(['1', '2', '3', '4'])
    expect((wrapper.vm as unknown as { value: string }).value).toBe('1234')
  })
})

describe('focus and caret', () => {
  it('marks the slot at the caret active and shows a fake caret there', async () => {
    const wrapper = mountOtp()

    await field(wrapper).trigger('focus')
    await nextTick()

    expect(slots(wrapper).map((slot) => slot.attributes('data-active'))).toEqual([
      'true',
      'false',
      'false',
      'false',
    ])
    expect(slots(wrapper)[0].find('.animate-caret-blink').exists()).toBe(true)
    expect(slots(wrapper)[1].find('.animate-caret-blink').exists()).toBe(false)
  })

  it('has no active slot while unfocused', () => {
    expect(slots(mountOtp()).map((slot) => slot.attributes('data-active'))).toEqual([
      'false',
      'false',
      'false',
      'false',
    ])
  })

  it('flags the placeholder state until something is entered', async () => {
    const wrapper = mountOtp()

    expect(field(wrapper).attributes('data-input-otp-placeholder-shown')).toBe('true')

    await type(wrapper, '1')

    expect(field(wrapper).attributes('data-input-otp-placeholder-shown')).toBeUndefined()
  })
})

describe('disabled', () => {
  it('disables the underlying input', () => {
    expect(field(mountOtp({ disabled: true })).attributes('disabled')).toBeDefined()
    expect(field(mountOtp()).attributes('disabled')).toBeUndefined()
  })
})

describe('completion event', () => {
  // OtpField forwards this so the 2FA dialog can verify a password-manager fill
  // without a second click. See TwoFactorVerifyDialog.
  it('reaches the parent with the full code once the last slot is filled', async () => {
    const wrapper = mountOtp()

    await type(wrapper, '123')

    expect(wrapper.emitted('complete')).toBeUndefined()

    await type(wrapper, '1234')

    expect(wrapper.emitted('complete')).toEqual([['1234']])
  })

  it('emits again after the code is corrected', async () => {
    const wrapper = mountOtp()

    await type(wrapper, '1234')
    await type(wrapper, '123')
    await type(wrapper, '1235')

    expect(wrapper.emitted('complete')).toEqual([['1234'], ['1235']])
  })
})
