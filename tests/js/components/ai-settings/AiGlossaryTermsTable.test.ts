import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import type { AiGlossaryTermResource } from '~/api/resources/ai-knowledge'
import AiGlossaryTermsTable from '~/components/ai-settings/AiGlossaryTermsTable.vue'

const term = (overrides: Partial<AiGlossaryTermResource>): AiGlossaryTermResource => ({
  id: 'term',
  source_locale: 'en',
  term: 'Checkout',
  translations: {},
  case_sensitive: false,
  do_not_translate: false,
  forbidden: [],
  note: null,
  ...overrides,
})

describe('AiGlossaryTermsTable', () => {
  it('renders one column per space language and the rule badges', () => {
    const wrapper = mount(AiGlossaryTermsTable, {
      props: {
        languages: ['de', 'fr'],
        canManage: false,
        terms: [
          term({ id: 'checkout', translations: { de: 'Kasse' }, forbidden: ['Bezahlseite'] }),
          term({ id: 'b10cks', term: 'b10cks', do_not_translate: true }),
        ],
      },
    })

    expect(wrapper.findAll('th').map((th) => th.text())).toEqual(['Term', 'de', 'fr', 'Rules'])

    const rows = wrapper.findAll('tbody tr').map((row) => row.findAll('td').map((cell) => cell.text()))
    expect(rows).toEqual([
      ['Checkout', 'Kasse', '', 'Avoid: Bezahlseite'],
      ['b10cks', '', '', 'Keep untranslated'],
    ])
  })
})
