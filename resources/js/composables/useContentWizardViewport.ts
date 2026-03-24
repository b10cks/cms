import { useElementSize, useEventListener } from '@vueuse/core'

import type { ContentWizardBounds, ContentWizardViewportState } from '~/types/content-wizard'

const MIN_SCALE = 0.1
const MAX_SCALE = 3
const ZOOM_STEP = 0.1
const CANVAS_PADDING = 100
const CANVAS_MIN_OVERSCROLL_X = 2400
const CANVAS_MIN_OVERSCROLL_Y = 2000
const CANVAS_MIN_WIDTH = 5200
const CANVAS_MIN_HEIGHT = 4200
const GESTURE_ZOOM_SENSITIVITY = 0.005
const AI_DOCK_SAFE_AREA = 120

interface WebKitGestureEvent extends Event {
  scale: number
  clientX: number
  clientY: number
}

export function useContentWizardViewport(bounds: Ref<ContentWizardBounds>) {
  const containerRef = ref<HTMLElement | null>(null)
  const { width: containerWidth, height: containerHeight } = useElementSize(containerRef)
  const viewport = reactive<ContentWizardViewportState>({
    x: 0,
    y: 0,
    scale: 1,
  })

  const dragState = reactive({
    pointerId: -1,
    startX: 0,
    startY: 0,
    startScrollLeft: 0,
    startScrollTop: 0,
    active: false,
  })

  const gestureState = reactive({
    active: false,
    startScale: 1,
  })

  const overscrollPadding = computed(() => ({
    x: Math.max(CANVAS_PADDING, containerWidth.value * 2, CANVAS_MIN_OVERSCROLL_X),
    y: Math.max(CANVAS_PADDING, containerHeight.value * 2, CANVAS_MIN_OVERSCROLL_Y),
  }))

  const canvasOrigin = computed(() => ({
    x: overscrollPadding.value.x - bounds.value.minX,
    y: overscrollPadding.value.y - bounds.value.minY,
  }))

  const canvasSize = computed(() => ({
    width: Math.max(
      bounds.value.width + overscrollPadding.value.x * 2,
      containerWidth.value + overscrollPadding.value.x * 2,
      CANVAS_MIN_WIDTH
    ),
    height: Math.max(
      bounds.value.height + overscrollPadding.value.y * 2 + 220,
      containerHeight.value + overscrollPadding.value.y * 2 + 220,
      CANVAS_MIN_HEIGHT
    ),
  }))

  const zoomPercent = computed(() => Math.round(viewport.scale * 100))
  const clampScale = (scale: number) => Math.min(MAX_SCALE, Math.max(MIN_SCALE, scale))
  const getViewportCenterOffset = (element: HTMLElement) => ({
    x: element.clientWidth / 2,
    y: Math.max((element.clientHeight - AI_DOCK_SAFE_AREA) / 2, 120),
  })

  const syncViewport = () => {
    const element = containerRef.value
    if (!element) {
      return
    }

    viewport.x = element.scrollLeft
    viewport.y = element.scrollTop
  }

  const centerContent = (scale: number) => {
    const element = containerRef.value
    if (!element) {
      return
    }

    const contentCenterX =
      (canvasOrigin.value.x + bounds.value.minX + bounds.value.width / 2) * scale
    const contentCenterY =
      (canvasOrigin.value.y + bounds.value.minY + bounds.value.height / 2) * scale
    const viewportCenter = getViewportCenterOffset(element)

    element.scrollLeft = Math.max(0, contentCenterX - viewportCenter.x)
    element.scrollTop = Math.max(0, contentCenterY - viewportCenter.y)
    syncViewport()
  }

  const setScaleAroundPoint = (nextScale: number, clientX?: number, clientY?: number) => {
    const element = containerRef.value
    const scale = clampScale(nextScale)

    if (!element) {
      viewport.scale = scale
      return
    }

    const rect = element.getBoundingClientRect()
    const relativeX = (clientX ?? rect.left + rect.width / 2) - rect.left
    const relativeY = (clientY ?? rect.top + rect.height / 2) - rect.top
    const sceneX = (element.scrollLeft + relativeX) / viewport.scale
    const sceneY = (element.scrollTop + relativeY) / viewport.scale

    viewport.scale = scale

    nextTick(() => {
      if (!containerRef.value) {
        return
      }

      containerRef.value.scrollLeft = sceneX * scale - relativeX
      containerRef.value.scrollTop = sceneY * scale - relativeY
      syncViewport()
    })
  }

  const fitToView = (padding = 120) => {
    const element = containerRef.value
    if (!element) {
      return
    }

    const availableHeight = Math.max(element.clientHeight - AI_DOCK_SAFE_AREA, 240)
    const targetScale = clampScale(
      Math.min(
        element.clientWidth / Math.max(bounds.value.width + padding * 2, 320),
        availableHeight / Math.max(bounds.value.height + padding * 2, 320),
        1
      )
    )

    viewport.scale = targetScale
    nextTick(() => {
      centerContent(targetScale)
    })
  }

  const resetView = () => {
    viewport.scale = 1
    nextTick(() => {
      centerContent(1)
    })
  }

  const setZoom100 = () => setScaleAroundPoint(1)
  const zoomIn = () => setScaleAroundPoint(viewport.scale + ZOOM_STEP)
  const zoomOut = () => setScaleAroundPoint(viewport.scale - ZOOM_STEP)

  const resolveWheelDelta = (event: WheelEvent) => {
    const element = containerRef.value
    const deltaMultiplier =
      event.deltaMode === WheelEvent.DOM_DELTA_LINE
        ? 16
        : event.deltaMode === WheelEvent.DOM_DELTA_PAGE
          ? element?.clientHeight || 1
          : 1

    return {
      x: event.deltaX * deltaMultiplier,
      y: event.deltaY * deltaMultiplier,
    }
  }

  const handleWheel = (event: WheelEvent) => {
    const element = containerRef.value
    if (!element) {
      return
    }

    event.preventDefault()

    if (event.ctrlKey || event.metaKey) {
      const { y } = resolveWheelDelta(event)
      const nextScale = viewport.scale * Math.exp(-y * GESTURE_ZOOM_SENSITIVITY)
      setScaleAroundPoint(nextScale, event.clientX, event.clientY)
      return
    }

    const { x, y } = resolveWheelDelta(event)
    element.scrollLeft += x
    element.scrollTop += y
    syncViewport()
  }

  const handleGestureStart = (event: Event) => {
    const gestureEvent = event as WebKitGestureEvent
    gestureEvent.preventDefault()
    gestureState.active = true
    gestureState.startScale = viewport.scale
  }

  const handleGestureChange = (event: Event) => {
    if (!gestureState.active) {
      return
    }

    const gestureEvent = event as WebKitGestureEvent
    gestureEvent.preventDefault()
    setScaleAroundPoint(
      gestureState.startScale * gestureEvent.scale,
      gestureEvent.clientX,
      gestureEvent.clientY
    )
  }

  const handleGestureEnd = () => {
    gestureState.active = false
  }

  const handleScroll = () => {
    syncViewport()
  }

  const handlePointerDown = (event: PointerEvent) => {
    if (event.button !== 0 || !containerRef.value) {
      return
    }

    const target = event.target as HTMLElement | null
    if (target?.closest('[data-node-card], [data-add-menu], [data-block-select]')) {
      return
    }

    dragState.pointerId = event.pointerId
    dragState.startX = event.clientX
    dragState.startY = event.clientY
    dragState.startScrollLeft = containerRef.value.scrollLeft
    dragState.startScrollTop = containerRef.value.scrollTop
    dragState.active = true
    containerRef.value.setPointerCapture?.(event.pointerId)
  }

  const handlePointerMove = (event: PointerEvent) => {
    if (!dragState.active || event.pointerId !== dragState.pointerId || !containerRef.value) {
      return
    }

    containerRef.value.scrollLeft = dragState.startScrollLeft - (event.clientX - dragState.startX)
    containerRef.value.scrollTop = dragState.startScrollTop - (event.clientY - dragState.startY)
    syncViewport()
  }

  const finishPointer = () => {
    if (dragState.pointerId !== -1) {
      containerRef.value?.releasePointerCapture?.(dragState.pointerId)
    }

    dragState.pointerId = -1
    dragState.active = false
  }

  useEventListener(containerRef, 'wheel', handleWheel, { passive: false })
  useEventListener(containerRef, 'gesturestart', handleGestureStart as EventListener, {
    passive: false,
  })
  useEventListener(containerRef, 'gesturechange', handleGestureChange as EventListener, {
    passive: false,
  })
  useEventListener(containerRef, 'gestureend', handleGestureEnd as EventListener, { passive: true })
  useEventListener(containerRef, 'scroll', handleScroll, { passive: true })

  return {
    containerRef,
    containerHeight,
    containerWidth,
    viewport,
    dragState,
    canvasOrigin,
    canvasSize,
    zoomPercent,
    setZoom100,
    zoomIn,
    zoomOut,
    fitToView,
    resetView,
    handlePointerDown,
    handlePointerMove,
    handlePointerUp: finishPointer,
    handlePointerLeave: finishPointer,
  }
}
