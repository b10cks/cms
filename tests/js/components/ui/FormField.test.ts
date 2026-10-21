import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import FormField from '~/components/ui/form/FormField.vue'

const mountField = (props: Record<string, unknown> = {}) =>
  mount(FormField, {
    props: { name: 'email', id: 'email', label: 'Email', ...props },
    slots: {
      default: `<template #default="{ id, 'aria-describedby': describedBy, 'aria-invalid': invalid }">
        <input :id="id" :aria-describedby="describedBy" :aria-invalid="invalid" />
      </template>`,
    },
  })

describe('FormField accessibility', () => {
  it('links the control to its error first, then its description', () => {
    const wrapper = mountField({ error: 'Invalid', description: 'Work address' })
    const input = wrapper.get('input')

    expect(input.attributes('aria-describedby')).toBe('email-error email-description')
    expect(input.attributes('aria-invalid')).toBe('true')
    expect(wrapper.get('#email-error').text()).toBe('Invalid')
    expect(wrapper.get('#email-description').text()).toBe('Work address')
  })

  it('sets no aria-invalid and only the description id without an error', () => {
    const input = mountField({ description: 'Work address' }).get('input')

    expect(input.attributes('aria-describedby')).toBe('email-description')
    expect(input.attributes('aria-invalid')).toBeUndefined()
  })
})
