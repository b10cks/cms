import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import type { AiResolvedSkill } from '~/api/resources/ai'
import AiActions from '~/components/ai/AiActions.vue'

const skill = (name: string, source: AiResolvedSkill['source']): AiResolvedSkill => ({
  name,
  description: `${name} description`,
  surfaces: ['selection'],
  blocks: [],
  output: 'text',
  source,
  owner_id: null,
  skill_id: null,
  shadows: [],
})

const mounted: { unmount: () => void }[] = []
afterEach(() => mounted.splice(0).forEach((wrapper) => wrapper.unmount()))

describe('AiActions', () => {
  it('offers a space skill next to the built-ins and emits the chosen one', async () => {
    const sparkle = skill('sparkle-copy', 'space')
    const wrapper = mount(AiActions, {
      props: { skills: [skill('rewrite', 'system'), sparkle] },
      attachTo: document.body,
    })
    mounted.push(wrapper)

    await wrapper.get('button').trigger('keydown', { key: 'Enter' })
    await new Promise((resolve) => setTimeout(resolve, 0))

    const items = Array.from(document.querySelectorAll('[role="menuitem"]'))
    expect(items.map((item) => item.textContent)).toEqual([
      expect.stringContaining('Rewrite'),
      expect.stringContaining('Sparkle copy'),
    ])

    items[1].dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(wrapper.emitted('select')).toEqual([[sparkle]])
  })
})
