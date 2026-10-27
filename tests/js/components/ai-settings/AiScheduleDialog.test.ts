import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import type { AiSchedule } from '~/api/resources/ai'
import AiScheduleDialog from '~/components/ai-settings/AiScheduleDialog.vue'

const schedule: AiSchedule = {
  id: 'schedule-1',
  name: 'Weekly refresh',
  skill: 'refresh-summaries',
  scope: { type: 'content', filters: { block: 'page', data_source: 'stale' } },
  cron: '0 6 * * 1',
  max_usd: 0.5,
  enabled: true,
  paused_reason: null,
  creator: null,
  last_run_at: null,
  last_run: null,
  next_run_at: null,
}

afterEach(() => {
  document.body.innerHTML = ''
})

describe('AiScheduleDialog', () => {
  it('saves an edited schedule with only the filters its scope understands', async () => {
    const wrapper = mount(AiScheduleDialog, {
      props: {
        open: false,
        schedule,
        skills: { content: [{ name: 'refresh-summaries', description: 'Refreshes summaries.' }], data_entry: [], asset: [] },
        saving: false,
      },
      global: { stubs: { Icon: true } },
      attachTo: document.body,
    })

    await wrapper.setProps({ open: true })
    await flushPromises()

    const save = [...document.body.querySelectorAll('button')].find((button) => button.textContent?.includes('Save'))
    save?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    expect(wrapper.emitted('save')).toEqual([
      [
        {
          name: 'Weekly refresh',
          skill: 'refresh-summaries',
          scope: { type: 'content', filters: { block: 'page' } },
          cron: '0 6 * * 1',
          max_usd: 0.5,
          enabled: true,
        },
      ],
    ])
    wrapper.unmount()
  })
})
