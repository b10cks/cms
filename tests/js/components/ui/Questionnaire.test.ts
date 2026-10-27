import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { h, nextTick } from 'vue'

import {
  Questionnaire,
  QuestionnaireChoice,
  QuestionnaireError,
  QuestionnaireInput,
  QuestionnaireItem,
  QuestionnaireNext,
  QuestionnaireSkip,
  QuestionnaireTitle,
} from '~/components/ui/questionnaire'

const mounted: { unmount: () => void }[] = []

afterEach(() => mounted.splice(0).forEach((wrapper) => wrapper.unmount()))

function mountQuestionnaire() {
  const wrapper = mount(Questionnaire, {
    props: { shortcuts: 'letters', onSubmit: (event: Event) => event.preventDefault() },
    attachTo: document.body,
    slots: {
      default: () => [
        h(QuestionnaireItem, { name: 'tone', required: true }, () => [
          h(QuestionnaireTitle, {}, () => 'Tone'),
          h(QuestionnaireChoice, { value: 'friendly' }, () => 'Friendly'),
          h(QuestionnaireChoice, { value: 'formal' }, () => 'Formal'),
          h(QuestionnaireError),
        ]),
        h(QuestionnaireItem, { name: 'detail' }, () => [
          h(QuestionnaireTitle, {}, () => 'Detail'),
          h(QuestionnaireInput),
        ]),
        h(QuestionnaireNext),
        h(QuestionnaireSkip),
      ],
    },
  })
  mounted.push(wrapper)
  return wrapper
}

describe('Questionnaire', () => {
  it('requires an answer before continuing and preserves it in form data', async () => {
    const wrapper = mountQuestionnaire()
    await nextTick()

    await wrapper.get('[data-slot="questionnaire-next"]').trigger('click')
    expect(wrapper.findAll('fieldset')[0].attributes('aria-invalid')).toBe('true')
    expect(wrapper.findAll('fieldset')[1].attributes('hidden')).toBeDefined()

    await wrapper.get('input[value="formal"]').setValue(true)
    await wrapper.get('[data-slot="questionnaire-next"]').trigger('click')

    expect(wrapper.findAll('fieldset')[1].attributes('hidden')).toBeUndefined()
    expect(new FormData(wrapper.element).get('tone')).toBe('formal')
  })

  it('selects choices with keyboard shortcuts and skips optional answers', async () => {
    const wrapper = mountQuestionnaire()
    await nextTick()

    await wrapper.trigger('keydown', { key: 'B' })
    expect(wrapper.get<HTMLInputElement>('input[value="formal"]').element.checked).toBe(true)
    await wrapper.get('[data-slot="questionnaire-next"]').trigger('click')
    await wrapper.get('[data-slot="questionnaire-input"]').setValue('Extra context')
    await wrapper.get('[data-slot="questionnaire-skip"]').trigger('click')

    expect(wrapper.emitted('submit')).toHaveLength(1)
    expect(new FormData(wrapper.element).get('detail')).toBeNull()
  })

  it('restores default answers and the first question when the form resets', async () => {
    const wrapper = mountQuestionnaire()
    await nextTick()

    await wrapper.get('input[value="friendly"]').setValue(true)
    await wrapper.get('[data-slot="questionnaire-next"]').trigger('click')
    await wrapper.get('[data-slot="questionnaire-input"]').setValue('Extra context')
    await wrapper.trigger('reset')

    expect(wrapper.findAll('fieldset')[0].attributes('hidden')).toBeUndefined()
    expect(wrapper.get<HTMLInputElement>('input[value="friendly"]').element.checked).toBe(false)
    expect(wrapper.get<HTMLInputElement>('[data-slot="questionnaire-input"]').element.value).toBe(
      ''
    )
  })
})
