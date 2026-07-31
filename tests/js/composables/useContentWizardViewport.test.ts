import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref, type Ref } from 'vue'

import type { ContentWizardBounds } from '~/types/content-wizard'

import { useContentWizardViewport } from '~/composables/useContentWizardViewport'

import { withSetup, type Harness } from '../support/harness'

const MIN_SCALE = 0.3
const MAX_SCALE = 3
const CANVAS_MIN_OVERSCROLL_X = 2400
const CANVAS_MIN_OVERSCROLL_Y = 2000
const AI_DOCK_SAFE_AREA = 120

type Viewport = ReturnType<typeof useContentWizardViewport>

// Explicit, not ReturnType<typeof setup> — that is circular and TS would widen
// the whole composable surface to `any`.
let harness: Harness<Viewport> | undefined

const boundsOf = (overrides: Partial<ContentWizardBounds> = {}): ContentWizardBounds => ({
  minX: 0,
  maxX: 0,
  minY: 0,
  maxY: 0,
  width: 0,
  height: 0,
  ...overrides,
})

/**
 * jsdom has no layout: clientWidth/clientHeight are 0 and scrollLeft/scrollTop
 * assignments are dropped. Redefining them gives the pan/zoom math a surface
 * that behaves like a real scroll container.
 */
const scrollContainer = (width: number, height: number, rectLeft = 0, rectTop = 0) => {
  const element = document.createElement('div')

  Object.defineProperties(element, {
    clientWidth: { value: width, configurable: true },
    clientHeight: { value: height, configurable: true },
    scrollLeft: { value: 0, writable: true, configurable: true },
    scrollTop: { value: 0, writable: true, configurable: true },
  })

  element.getBoundingClientRect = () =>
    ({
      left: rectLeft,
      top: rectTop,
      width,
      height,
      right: rectLeft + width,
      bottom: rectTop + height,
      x: rectLeft,
      y: rectTop,
    }) as DOMRect
  element.setPointerCapture = vi.fn()
  element.releasePointerCapture = vi.fn()

  return element
}

const setup = (bounds: Ref<ContentWizardBounds> = ref(boundsOf())) => {
  harness = withSetup(() => useContentWizardViewport(bounds))

  return harness.result
}

/**
 * Attach a container and pin the observed size. useElementSize resets its refs
 * on the tick after the target appears, so the size has to be written after it.
 */
const attach = async (viewport: Viewport, width = 800, height = 600, rectLeft = 0, rectTop = 0) => {
  const element = scrollContainer(width, height, rectLeft, rectTop)
  viewport.containerRef.value = element
  await nextTick()
  ;(viewport.containerWidth as Ref<number>).value = width
  ;(viewport.containerHeight as Ref<number>).value = height

  return element
}

const pointer = (overrides: Partial<PointerEvent> & { target?: HTMLElement } = {}) =>
  ({
    button: 0,
    pointerId: 1,
    clientX: 0,
    clientY: 0,
    target: document.createElement('div'),
    ...overrides,
  }) as unknown as PointerEvent

const gestureEvent = (type: string, detail: Record<string, number> = {}) =>
  Object.assign(new Event(type, { cancelable: true }), detail)

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('initial state', () => {
  it('starts unscrolled at 100%', () => {
    const viewport = setup()

    expect(viewport.viewport).toEqual({ x: 0, y: 0, scale: 1 })
    expect(viewport.zoomPercent.value).toBe(100)
  })

  it('starts with no container', () => {
    expect(setup().containerRef.value).toBeNull()
  })
})

describe('zoomPercent', () => {
  it('rounds the scale to whole percent', () => {
    const viewport = setup()

    viewport.viewport.scale = 0.6451612903225806

    expect(viewport.zoomPercent.value).toBe(65)
  })
})

describe('scale clamping', () => {
  // Without a container setScaleAroundPoint short-circuits to a bare clamp,
  // which is exactly the clamp under test.
  it('stops zooming in at 300%', () => {
    const viewport = setup()

    for (let step = 0; step < 40; step += 1) {
      viewport.zoomIn()
    }

    expect(viewport.viewport.scale).toBe(MAX_SCALE)
  })

  it('stops zooming out at 30%', () => {
    const viewport = setup()

    for (let step = 0; step < 40; step += 1) {
      viewport.zoomOut()
    }

    expect(viewport.viewport.scale).toBe(MIN_SCALE)
  })

  it('steps by 10 percentage points', () => {
    const viewport = setup()

    viewport.zoomIn()
    expect(viewport.viewport.scale).toBeCloseTo(1.1, 10)

    viewport.zoomOut()
    expect(viewport.viewport.scale).toBeCloseTo(1, 10)
  })

  it('snaps back to 100%', () => {
    const viewport = setup()

    viewport.zoomIn()
    viewport.setZoom100()

    expect(viewport.viewport.scale).toBe(1)
  })
})

describe('overscroll padding, origin and canvas size', () => {
  it('pads by the minimum overscroll for a small container', async () => {
    const viewport = setup(ref(boundsOf({ width: 400, height: 300 })))
    await attach(viewport, 800, 600)

    expect(viewport.canvasOrigin.value).toEqual({
      x: CANVAS_MIN_OVERSCROLL_X,
      y: CANVAS_MIN_OVERSCROLL_Y,
    })
  })

  it('pads by twice the container size once that exceeds the minimum', async () => {
    const viewport = setup()
    await attach(viewport, 2000, 1500)

    expect(viewport.canvasOrigin.value).toEqual({ x: 4000, y: 3000 })
  })

  it('shifts the origin so negative content coordinates stay on canvas', async () => {
    const viewport = setup(ref(boundsOf({ minX: -500, minY: -200, width: 800, height: 400 })))
    await attach(viewport, 800, 600)

    expect(viewport.canvasOrigin.value).toEqual({
      x: CANVAS_MIN_OVERSCROLL_X + 500,
      y: CANVAS_MIN_OVERSCROLL_Y + 200,
    })
  })

  it('falls back to the minimum canvas width for a small tree in a small container', async () => {
    const viewport = setup(ref(boundsOf({ width: 100, height: 100 })))
    await attach(viewport, 100, 100)

    expect(viewport.canvasSize.value.width).toBe(5200)
  })

  // Pinned actual behaviour: padding alone contributes 2*2000 + 220 = 4220, so
  // the 4200px CANVAS_MIN_HEIGHT floor can never be the maximum — dead constant.
  it('is always taller than the minimum canvas height, whatever the content', async () => {
    const viewport = setup()
    await attach(viewport, 0, 0)

    expect(viewport.canvasSize.value.height).toBe(4220)
  })

  it('grows the canvas with the content plus padding on both sides', async () => {
    const viewport = setup(ref(boundsOf({ width: 4000, height: 3000 })))
    await attach(viewport, 800, 600)

    expect(viewport.canvasSize.value).toEqual({
      width: 4000 + CANVAS_MIN_OVERSCROLL_X * 2,
      // The extra 220 leaves room for the AI dock below the content.
      height: 3000 + CANVAS_MIN_OVERSCROLL_Y * 2 + 220,
    })
  })

  it('never lets the canvas be narrower than the container plus padding', async () => {
    const viewport = setup()
    await attach(viewport, 3000, 600)

    // padX becomes 6000, so the container itself sets the floor.
    expect(viewport.canvasSize.value.width).toBe(3000 + 6000 * 2)
  })
})

describe('sceneViewport', () => {
  it('converts the scrolled pixel window into scene coordinates', async () => {
    const viewport = setup()
    await attach(viewport, 800, 600)

    viewport.viewport.x = 100
    viewport.viewport.y = 50
    viewport.viewport.scale = 0.5

    expect(viewport.sceneViewport.value).toEqual({
      left: 200,
      top: 100,
      right: 1800,
      bottom: 1300,
    })
  })

  it('is the identity window at 100%', async () => {
    const viewport = setup()
    await attach(viewport, 800, 600)

    expect(viewport.sceneViewport.value).toEqual({ left: 0, top: 0, right: 800, bottom: 600 })
  })
})

describe('fitToView', () => {
  it('picks the tighter of the two axes and centres the content', async () => {
    const viewport = setup(ref(boundsOf({ width: 1000, height: 500 })))
    const element = await attach(viewport, 800, 600)

    viewport.fitToView()

    // width: 800 / (1000 + 2*120) = 0.6452 — tighter than
    // height: (600 - 120) / (500 + 2*120) = 0.6486
    const scale = 800 / 1240
    expect(viewport.viewport.scale).toBeCloseTo(scale, 10)

    await nextTick()

    expect(element.scrollLeft).toBeCloseTo((CANVAS_MIN_OVERSCROLL_X + 500) * scale - 400, 6)
    expect(element.scrollTop).toBeCloseTo(
      (CANVAS_MIN_OVERSCROLL_Y + 250) * scale - (600 - AI_DOCK_SAFE_AREA) / 2,
      6
    )
    expect(viewport.viewport.x).toBeCloseTo(element.scrollLeft, 10)
  })

  it('never zooms past 100% for content smaller than the viewport', async () => {
    const viewport = setup(ref(boundsOf({ width: 200, height: 100 })))
    await attach(viewport, 1600, 1200)

    viewport.fitToView()

    expect(viewport.viewport.scale).toBe(1)
  })

  it('clamps to the minimum scale for content far too large to fit', async () => {
    const viewport = setup(ref(boundsOf({ width: 40000, height: 30000 })))
    await attach(viewport, 800, 600)

    viewport.fitToView()

    expect(viewport.viewport.scale).toBe(MIN_SCALE)
  })

  it('treats tiny content as a 320px box, so padding cannot blow the scale up', async () => {
    const viewport = setup()
    await attach(viewport, 320, 600)

    viewport.fitToView(0)

    expect(viewport.viewport.scale).toBe(1)
  })

  it('honours a custom padding', async () => {
    const viewport = setup(ref(boundsOf({ width: 1000, height: 500 })))
    await attach(viewport, 800, 600)

    viewport.fitToView(0)

    expect(viewport.viewport.scale).toBeCloseTo(0.8, 10)
  })

  // The dock reserve only applies where there is room for it: on a container
  // shorter than the reserve the usable height is the container itself, so the
  // fitted content still fits on screen.
  it('never assumes more usable height than the container has', async () => {
    const viewport = setup(ref(boundsOf({ width: 100, height: 600 })))
    await attach(viewport, 800, 200)

    viewport.fitToView(0)

    // 200 / 600 — the whole container, not 240 and not (200 - 120).
    expect(viewport.viewport.scale).toBeCloseTo(1 / 3, 10)
  })

  it('does nothing without a container', () => {
    const viewport = setup(ref(boundsOf({ width: 1000, height: 500 })))

    viewport.fitToView()

    expect(viewport.viewport.scale).toBe(1)
  })
})

describe('resetView', () => {
  it('returns to 100% and centres', async () => {
    const viewport = setup(ref(boundsOf({ width: 1000, height: 500 })))
    const element = await attach(viewport, 800, 600)

    viewport.viewport.scale = 2
    viewport.resetView()

    expect(viewport.viewport.scale).toBe(1)

    await nextTick()

    expect(element.scrollLeft).toBeCloseTo(CANVAS_MIN_OVERSCROLL_X + 500 - 400, 6)
    expect(element.scrollTop).toBeCloseTo(CANVAS_MIN_OVERSCROLL_Y + 250 - 240, 6)
  })

  // The padding uses the ResizeObserver-measured size while the centring uses
  // element.clientWidth; before the first observation those disagree, which is
  // the only way the max(0, …) clamp in centerContent is reachable.
  it('clamps to zero when the measured size lags the real element', async () => {
    const viewport = setup()
    const element = await attach(viewport, 9000, 600)
    ;(viewport.containerWidth as Ref<number>).value = 0
    element.scrollLeft = 999

    viewport.resetView()
    await nextTick()

    expect(element.scrollLeft).toBe(0)
  })
})

describe('setScaleAroundPoint via zoom controls', () => {
  it('keeps the container centre fixed when no point is given', async () => {
    const viewport = setup()
    const element = await attach(viewport, 800, 600)
    element.scrollLeft = 1000
    element.scrollTop = 500

    viewport.zoomIn()
    await nextTick()

    // scene point under the centre: (1000 + 400) / 1 = 1400
    expect(element.scrollLeft).toBeCloseTo(1400 * 1.1 - 400, 6)
    expect(element.scrollTop).toBeCloseTo(800 * 1.1 - 300, 6)
    expect(viewport.viewport.x).toBeCloseTo(element.scrollLeft, 10)
  })

  it('is a no-op on scroll offsets once clamped at the maximum', async () => {
    const viewport = setup()
    const element = await attach(viewport, 800, 600)

    viewport.viewport.scale = MAX_SCALE
    element.scrollLeft = 600
    viewport.zoomIn()
    await nextTick()

    expect(viewport.viewport.scale).toBe(MAX_SCALE)
    expect(element.scrollLeft).toBeCloseTo(600, 6)
  })

  it('accounts for the container offset within the page', async () => {
    const viewport = setup()
    const element = await attach(viewport, 800, 600, 100, 50)
    element.scrollLeft = 0
    element.scrollTop = 0

    viewport.zoomIn()
    await nextTick()

    // rect centre is (500, 350) in client space, i.e. (400, 300) relative.
    expect(element.scrollLeft).toBeCloseTo(400 * 1.1 - 400, 6)
    expect(element.scrollTop).toBeCloseTo(300 * 1.1 - 300, 6)
  })
})

describe('wheel handling', () => {
  const wheel = (element: HTMLElement, init: WheelEventInit) =>
    element.dispatchEvent(new WheelEvent('wheel', { cancelable: true, ...init }))

  it('pans by the raw pixel delta', async () => {
    const viewport = setup()
    const element = await attach(viewport, 800, 600)

    wheel(element, { deltaX: 30, deltaY: 60 })

    expect(element.scrollLeft).toBe(30)
    expect(element.scrollTop).toBe(60)
    expect(viewport.viewport).toMatchObject({ x: 30, y: 60 })
  })

  it('scales a line-mode delta by 16px', async () => {
    const viewport = setup()
    const element = await attach(viewport, 800, 600)

    wheel(element, { deltaY: 3, deltaMode: WheelEvent.DOM_DELTA_LINE })

    expect(element.scrollTop).toBe(48)
    expect(viewport.viewport.y).toBe(48)
  })

  it('scales a page-mode delta by the container height', async () => {
    const viewport = setup()
    const element = await attach(viewport, 800, 600)

    wheel(element, { deltaY: 2, deltaMode: WheelEvent.DOM_DELTA_PAGE })

    expect(element.scrollTop).toBe(1200)
  })

  it('zooms exponentially when Ctrl is held', async () => {
    const viewport = setup()
    const element = await attach(viewport, 800, 600)

    wheel(element, { deltaY: -100, ctrlKey: true, clientX: 400, clientY: 300 })

    expect(viewport.viewport.scale).toBeCloseTo(Math.exp(0.5), 10)
  })

  it('zooms out on a positive Ctrl delta and clamps at the minimum', async () => {
    const viewport = setup()
    const element = await attach(viewport, 800, 600)

    wheel(element, { deltaY: 100000, ctrlKey: true })

    expect(viewport.viewport.scale).toBe(MIN_SCALE)
  })

  it('treats Meta like Ctrl, for macOS pinch', async () => {
    const viewport = setup()
    const element = await attach(viewport, 800, 600)

    wheel(element, { deltaY: -10, metaKey: true })

    expect(viewport.viewport.scale).toBeGreaterThan(1)
  })

  it('anchors a Ctrl zoom on the pointer', async () => {
    const viewport = setup()
    const element = await attach(viewport, 800, 600)
    element.scrollLeft = 200

    wheel(element, { deltaY: -100, ctrlKey: true, clientX: 100, clientY: 0 })
    await nextTick()

    const scale = Math.exp(0.5)
    expect(element.scrollLeft).toBeCloseTo(300 * scale - 100, 6)
  })

  it('cancels the browser default so the page never scrolls', async () => {
    const viewport = setup()
    const element = await attach(viewport, 800, 600)

    expect(wheel(element, { deltaY: 10 })).toBe(false)
  })

  it('stops handling wheel events after unmount', async () => {
    const viewport = setup()
    const element = await attach(viewport, 800, 600)

    harness?.unmount()
    harness = undefined

    wheel(element, { deltaY: 60 })

    expect(element.scrollTop).toBe(0)
    expect(viewport.viewport.y).toBe(0)
  })
})

describe('trackpad gestures', () => {
  it('scales from the scale at gesture start', async () => {
    const viewport = setup()
    const element = await attach(viewport, 800, 600)

    viewport.viewport.scale = 1.5
    element.dispatchEvent(gestureEvent('gesturestart', { scale: 1 }))
    element.dispatchEvent(gestureEvent('gesturechange', { scale: 2, clientX: 400, clientY: 300 }))

    expect(viewport.viewport.scale).toBe(3)
  })

  it('ignores a gesturechange with no gesturestart', async () => {
    const viewport = setup()
    const element = await attach(viewport, 800, 600)

    element.dispatchEvent(gestureEvent('gesturechange', { scale: 2 }))

    expect(viewport.viewport.scale).toBe(1)
  })

  it('ignores further changes after gestureend', async () => {
    const viewport = setup()
    const element = await attach(viewport, 800, 600)

    element.dispatchEvent(gestureEvent('gesturestart', { scale: 1 }))
    element.dispatchEvent(gestureEvent('gestureend'))
    element.dispatchEvent(gestureEvent('gesturechange', { scale: 2 }))

    expect(viewport.viewport.scale).toBe(1)
  })

  it('clamps a gesture zoom', async () => {
    const viewport = setup()
    const element = await attach(viewport, 800, 600)

    element.dispatchEvent(gestureEvent('gesturestart', { scale: 1 }))
    element.dispatchEvent(gestureEvent('gesturechange', { scale: 20 }))

    expect(viewport.viewport.scale).toBe(MAX_SCALE)
  })
})

describe('scroll syncing', () => {
  it('mirrors an external scroll into the viewport state', async () => {
    const viewport = setup()
    const element = await attach(viewport, 800, 600)

    element.scrollLeft = 120
    element.scrollTop = 240
    element.dispatchEvent(new Event('scroll'))

    expect(viewport.viewport).toMatchObject({ x: 120, y: 240 })
  })
})

describe('drag to pan', () => {
  it('scrolls opposite the pointer movement', async () => {
    const viewport = setup()
    const element = await attach(viewport, 800, 600)
    element.scrollLeft = 500
    element.scrollTop = 400

    viewport.handlePointerDown(pointer({ clientX: 200, clientY: 200 }))
    viewport.handlePointerMove(pointer({ clientX: 250, clientY: 150 }))

    expect(element.scrollLeft).toBe(450)
    expect(element.scrollTop).toBe(450)
    expect(viewport.viewport).toMatchObject({ x: 450, y: 450 })
  })

  it('measures every move against the drag start, not the previous move', async () => {
    const viewport = setup()
    const element = await attach(viewport, 800, 600)
    element.scrollLeft = 500

    viewport.handlePointerDown(pointer({ clientX: 200 }))
    viewport.handlePointerMove(pointer({ clientX: 210 }))
    viewport.handlePointerMove(pointer({ clientX: 220 }))

    expect(element.scrollLeft).toBe(480)
  })

  it('captures the pointer and marks the drag active', async () => {
    const viewport = setup()
    const element = await attach(viewport, 800, 600)

    viewport.handlePointerDown(pointer({ pointerId: 7 }))

    expect(viewport.dragState.active).toBe(true)
    expect(viewport.dragState.pointerId).toBe(7)
    expect(element.setPointerCapture).toHaveBeenCalledWith(7)
  })

  it('releases the capture and clears the drag on pointer up', async () => {
    const viewport = setup()
    const element = await attach(viewport, 800, 600)

    viewport.handlePointerDown(pointer({ pointerId: 7 }))
    viewport.handlePointerUp()

    expect(viewport.dragState.active).toBe(false)
    expect(viewport.dragState.pointerId).toBe(-1)
    expect(element.releasePointerCapture).toHaveBeenCalledWith(7)
  })

  it('does not release a capture it never took', async () => {
    const viewport = setup()
    const element = await attach(viewport, 800, 600)

    viewport.handlePointerLeave()

    expect(element.releasePointerCapture).not.toHaveBeenCalled()
  })

  it('ignores a non-primary button', async () => {
    const viewport = setup()
    await attach(viewport, 800, 600)

    viewport.handlePointerDown(pointer({ button: 2 }))

    expect(viewport.dragState.active).toBe(false)
  })

  it('ignores a pointer down without a container', () => {
    const viewport = setup()

    viewport.handlePointerDown(pointer())

    expect(viewport.dragState.active).toBe(false)
  })

  it.each([
    'data-node-card',
    'data-add-menu',
    'data-block-select',
    'data-shared-add-controls',
  ])('does not start a pan from inside [%s]', async (attribute) => {
    const viewport = setup()
    await attach(viewport, 800, 600)

    const host = document.createElement('div')
    host.innerHTML = `<div ${attribute}><button>x</button></div>`

    viewport.handlePointerDown(
      pointer({ target: host.querySelector('button') as unknown as HTMLElement })
    )

    expect(viewport.dragState.active).toBe(false)
  })

  it('ignores moves from a second pointer mid-drag', async () => {
    const viewport = setup()
    const element = await attach(viewport, 800, 600)
    element.scrollLeft = 500

    viewport.handlePointerDown(pointer({ pointerId: 1, clientX: 200 }))
    viewport.handlePointerMove(pointer({ pointerId: 2, clientX: 300 }))

    expect(element.scrollLeft).toBe(500)
  })

  it('ignores moves when no drag is active', async () => {
    const viewport = setup()
    const element = await attach(viewport, 800, 600)
    element.scrollLeft = 500

    viewport.handlePointerMove(pointer({ clientX: 300 }))

    expect(element.scrollLeft).toBe(500)
  })

  // Pinned actual behaviour: the pan writes raw client-pixel deltas to
  // scrollLeft/scrollTop, which is already scaled space — so panning tracks the
  // cursor 1:1 at any zoom level rather than being divided by the scale.
  it('pans by pixels, not scene units, when zoomed', async () => {
    const viewport = setup()
    const element = await attach(viewport, 800, 600)
    element.scrollLeft = 500
    viewport.viewport.scale = 2

    viewport.handlePointerDown(pointer({ clientX: 200 }))
    viewport.handlePointerMove(pointer({ clientX: 250 }))

    expect(element.scrollLeft).toBe(450)
  })
})
