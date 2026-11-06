import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import AiRunFindings from '~/components/ai/AiRunFindings.vue'
import type { AiGlossaryFinding } from '~/lib/aiRun'

const banned = (found: string): AiGlossaryFinding => ({ type: 'forbidden_variant', term: 'Fachhändler', expected: 'Fachhändler', found })

const mountFindings = (findings: AiGlossaryFinding[]) =>
  mount(AiRunFindings, { props: { findings }, global: { mocks: { $t: (key: string, params?: Record<string, string>) => `${key}:${params?.found ?? ''}` } } })

describe('AiRunFindings', () => {
  it('shows nothing without findings', () => {
    expect(mountFindings([]).find('[data-ai-findings]').exists()).toBe(false)
  })

  it('lists the findings, can be dismissed and returns for the next ones', async () => {
    const wrapper = mountFindings([banned('Händler')])

    expect(wrapper.findAll('[data-ai-findings] li')).toHaveLength(1)

    await wrapper.get('[data-dismiss-findings]').trigger('click')
    expect(wrapper.find('[data-ai-findings]').exists()).toBe(false)

    await wrapper.setProps({ findings: [banned('Verkäufer')] })
    expect(wrapper.find('[data-ai-findings]').exists()).toBe(true)
  })
})
