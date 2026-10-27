import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'

import {
  MessageScroller,
  MessageScrollerContent,
  MessageScrollerItem,
  MessageScrollerProvider,
  MessageScrollerViewport,
} from '~/components/ui/message-scroller'
import { useMessageScrollerContext } from '~/components/ui/message-scroller/useMessageScroller'

const mounted: { unmount: () => void }[] = []

afterEach(() => {
  mounted.splice(0).forEach((wrapper) => wrapper.unmount())
  vi.restoreAllMocks()
})

describe('MessageScroller', () => {
  it('scrolls to registered messages and conversation edges', async () => {
    let context: ReturnType<typeof useMessageScrollerContext> | undefined
    const probe = defineComponent({
      setup() {
        context = useMessageScrollerContext()
        return () => null
      },
    })
    const wrapper = mount(MessageScrollerProvider, {
      props: { defaultScrollPosition: 'start' },
      slots: {
        default: () =>
          h(MessageScroller, {}, () => [
            h(probe),
            h(MessageScrollerViewport, {}, () =>
              h(MessageScrollerContent, {}, () => [
                h(MessageScrollerItem, { messageId: 'first' }, () => 'First'),
                h(MessageScrollerItem, { messageId: 'second' }, () => 'Second'),
              ])
            ),
          ]),
      },
    })
    mounted.push(wrapper)
    await nextTick()

    const viewport = wrapper.get<HTMLElement>('[data-slot="message-scroller-viewport"]').element
    Object.defineProperties(viewport, {
      clientHeight: { value: 100, configurable: true },
      scrollHeight: { value: 400, configurable: true },
    })
    vi.spyOn(viewport, 'getBoundingClientRect').mockImplementation(
      () => new DOMRect(0, 0, 300, 100)
    )
    for (const [index, item] of wrapper
      .findAll<HTMLElement>('[data-slot="message-scroller-item"]')
      .entries()) {
      vi.spyOn(item.element, 'getBoundingClientRect').mockImplementation(
        () => new DOMRect(0, index * 200 - viewport.scrollTop, 300, 200)
      )
    }
    Object.defineProperty(viewport, 'scrollTo', {
      configurable: true,
      value: ({ top }: ScrollToOptions) => {
        viewport.scrollTop = top ?? 0
      },
    })

    expect(context!.scrollToMessage('second', { align: 'start' })).toBe(true)
    expect(viewport.scrollTop).toBe(200)
    expect(context!.scrollToEnd()).toBe(true)
    expect(viewport.scrollTop).toBe(300)
    expect(context!.scrollToStart()).toBe(true)
    expect(viewport.scrollTop).toBe(0)
  })
})
