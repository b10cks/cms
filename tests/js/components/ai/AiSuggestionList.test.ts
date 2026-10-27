import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import type { AiGlossaryResource } from '~/api/resources/ai-knowledge'
import AiSuggestionList from '~/components/ai/AiSuggestionList.vue'
import type { AiGlossarySuggestion } from '~/lib/aiChangesets'

const glossary = (id: string, name: string, scope: AiGlossaryResource['scope']): AiGlossaryResource => ({
  id,
  name,
  scope,
  enabled: true,
  updated_at: null,
})

const suggestion: AiGlossarySuggestion = {
  id: 'suggestion-1',
  source_locale: 'en',
  term: 'basket',
  translations: {},
  forbidden: ['cart'],
  note: null,
  evidence: { ai: 'Go to the cart', final: 'Go to the basket', label: 'Home' },
  actor: null,
  created_at: '2026-10-27T10:00:00Z',
}

const mounted: { unmount: () => void }[] = []
afterEach(() => mounted.splice(0).forEach((wrapper) => wrapper.unmount()))

describe('AiSuggestionList', () => {
  it('adds a suggestion to the chosen glossary or dismisses it', async () => {
    const wrapper = mount(AiSuggestionList, {
      props: {
        spaceId: 'space-1',
        suggestions: [suggestion],
        glossaries: [glossary('space-glossary', 'Shop', 'space'), glossary('team-glossary', 'Brand', 'team')],
      },
      global: { stubs: { Icon: true } },
      attachTo: document.body,
    })
    mounted.push(wrapper)

    expect(wrapper.text()).toContain('Never: cart')
    expect(wrapper.text()).toContain('kept "Go to the basket"')

    await wrapper.get('button').trigger('keydown', { key: 'Enter' })
    await new Promise((resolve) => setTimeout(resolve, 0))

    const items = Array.from(document.querySelectorAll('[role="menuitem"]'))
    expect(items.map((item) => item.textContent)).toEqual([
      expect.stringContaining('Shop'),
      expect.stringContaining('Brand'),
    ])

    items[1].dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(wrapper.emitted('accept')).toEqual([['suggestion-1', 'team-glossary']])

    const dismiss = wrapper.findAll('button').find((button) => button.text() === 'Dismiss')
    await dismiss?.trigger('click')
    expect(wrapper.emitted('dismiss')).toEqual([['suggestion-1']])
  })
})
