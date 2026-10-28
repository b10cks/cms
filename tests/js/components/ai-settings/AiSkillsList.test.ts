import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import type { AiResolvedSkill } from '~/api/resources/ai'
import type { AiSkillResource } from '~/api/resources/ai-knowledge'

const rewrite: AiResolvedSkill = {
  name: 'rewrite',
  description: 'Rewrites the selected text.',
  surfaces: ['selection'],
  blocks: [],
  output: 'text',
  source: 'system',
  owner_id: null,
  skill_id: null,
  shadows: [],
}

const customized: AiSkillResource = {
  id: 'skill-1',
  scope: 'space',
  name: 'rewrite',
  description: 'Rewrites the selected text.',
  enabled: true,
  files: [{ path: 'SKILL.md', content: '---\nname: rewrite\ndescription: Rewrites.\n---\n\nRewrite it.' }],
  published_revision: null,
  has_unpublished_changes: false,
  updated_at: null,
}

const skills = vi.fn().mockResolvedValue({ data: [], resolved: [rewrite] })
const customizeSkill = vi.fn().mockResolvedValue({ data: customized })
const aiKnowledge = vi.fn(() => ({ skills, customizeSkill }))

vi.mock('~/api', () => ({ api: { aiKnowledge } }))

const AiSkillsList = (await import('~/components/ai-settings/AiSkillsList.vue')).default

describe('AiSkillsList', () => {
  it('customizing a built-in skill creates a space skill and opens its editor', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', component: { template: '<div />' } },
        { path: '/:space/settings/ai-skills/:skillId', name: 'space-settings-ai-skill', component: { template: '<div />' } },
      ],
    })
    await router.push('/')

    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    const wrapper = mount(AiSkillsList, {
      props: { owner: { kind: 'space', id: 'space-1' }, canManage: true },
      global: { plugins: [router, [VueQueryPlugin, { queryClient }]], stubs: { Icon: true } },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('Built-in')
    await wrapper.findAll('button').find((button) => button.text() === 'Customize')?.trigger('click')
    await flushPromises()

    expect(aiKnowledge).toHaveBeenCalledWith({ kind: 'space', id: 'space-1' })
    expect(customizeSkill).toHaveBeenCalledWith('rewrite')
    expect(router.currentRoute.value.name).toBe('space-settings-ai-skill')
    expect(router.currentRoute.value.params).toEqual({ space: 'space-1', skillId: 'skill-1' })
  })
})
