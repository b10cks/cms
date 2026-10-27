import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { defineComponent, nextTick } from 'vue'

import {
  Command,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '~/components/ui/command'

const Palette = defineComponent({
  components: { Command, CommandGroup, CommandInput, CommandItem, CommandList },
  template: `
    <Command>
      <CommandInput />
      <CommandList>
        <CommandItem value="ask" always-visible>Ask anything</CommandItem>
        <CommandGroup heading="Pages">
          <CommandItem value="home">Home</CommandItem>
          <CommandItem value="about">About</CommandItem>
        </CommandGroup>
      </CommandList>
    </Command>
  `,
})

const search = async (wrapper: ReturnType<typeof mount>, text: string) => {
  await wrapper.find('input').setValue(text)
  await nextTick()
}

describe('filtering', () => {
  it('keeps an always-visible item outside any group through every search', async () => {
    const wrapper = mount(Palette, { attachTo: document.body })

    await search(wrapper, 'ab')
    await search(wrapper, 'abo')

    const options = wrapper.findAll('[role="option"]').map((option) => option.text())
    expect(options).toEqual(['Ask anything', 'About'])

    wrapper.unmount()
  })
})
