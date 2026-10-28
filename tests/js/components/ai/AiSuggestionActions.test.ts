import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import type { AiGlossaryResource } from '~/api/resources/ai-knowledge'
import AiSuggestionActions from '~/components/ai/AiSuggestionActions.vue'

const glossary = (id: string, name: string, scope: AiGlossaryResource['scope']): AiGlossaryResource => ({
  id,
  name,
  scope,
  owner_id: `${scope}-1`,
  enabled: true,
  updated_at: null,
})

const mounted: { unmount: () => void }[] = []
afterEach(() => mounted.splice(0).forEach((wrapper) => wrapper.unmount()))

describe('AiSuggestionActions', () => {
  it('adds a suggestion to the chosen glossary or dismisses it', async () => {
    const wrapper = mount(AiSuggestionActions, {
      props: {
        spaceId: 'space-1',
        term: 'basket',
        glossaries: [glossary('space-glossary', 'Shop', 'space'), glossary('team-glossary', 'Brand', 'team')],
      },
      global: { stubs: { Icon: true } },
      attachTo: document.body,
    })
    mounted.push(wrapper)

    await wrapper.get('button').trigger('keydown', { key: 'Enter' })
    await new Promise((resolve) => setTimeout(resolve, 0))

    const items = Array.from(document.querySelectorAll('[role="menuitem"]'))
    expect(items.map((item) => item.textContent)).toEqual([
      expect.stringContaining('Shop'),
      expect.stringContaining('Brand'),
    ])

    items[1].dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(wrapper.emitted('accept')).toEqual([['team-glossary']])

    const dismiss = wrapper.findAll('button').find((button) => button.text() === 'Dismiss')
    await dismiss?.trigger('click')
    expect(wrapper.emitted('dismiss')).toEqual([[]])
  })
})
