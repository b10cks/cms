import { useEventListener } from '@vueuse/core'

import type { ContentWizardBounds, ContentWizardViewportState } from '~/types/content-wizard'

const MIN_SCALE = 0.45
const MAX_SCALE = 1.8
const ZOOM_STEP = 0.12
const CANVAS_PADDING = 240
const CANVAS_MIN_WIDTH = 2200
const CANVAS_MIN_HEIGHT = 1600

export function useContentWizardViewport(bounds: Ref<ContentWizardBounds>) {
  const containerRef = ref<HTMLElement | null>(null)
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

  const canvasOrigin = computed(() => ({
    x: CANVAS_PADDING - bounds.value.minX,
    y: CANVAS_PADDING - bounds.value.minY,
  }))

  const canvasSize = computed(() => ({
    width: Math.max(bounds.value.width + CANVAS_PADDING * 2, CANVAS_MIN_WIDTH),
    height: Math.max(bounds.value.height + CANVAS_PADDING * 2 + 220, CANVAS_MIN_HEIGHT),
  }))

  const zoomPercent = computed(() => Math.round(viewport.scale * 100))
  const clampScale = (scale: number) => Math.min(MAX_SCALE, Math.max(MIN_SCALE, scale))

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

    const contentCenterX = (canvasOrigin.value.x + bounds.value.minX + bounds.value.width / 2) * scale
    const contentCenterY = (canvasOrigin.value.y + bounds.value.minY + bounds.value.height / 2) * scale

    element.scrollLeft = Math.max(0, contentCenterX - element.clientWidth / 2)
    element.scrollTop = Math.max(0, contentCenterY - element.clientHeight / 2)
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

    const targetScale = clampScale(
      Math.min(
        element.clientWidth / Math.max(bounds.value.width + padding * 2, 320),
        element.clientHeight / Math.max(bounds.value.height + padding * 2, 320),
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

  const zoomIn = () => setScaleAroundPoint(viewport.scale + ZOOM_STEP)
  const zoomOut = () => setScaleAroundPoint(viewport.scale - ZOOM_STEP)

  const handleWheel = (event: WheelEvent) => {
    if (!(event.ctrlKey || event.metaKey)) {
      return
    }

    event.preventDefault()
    const delta = event.deltaY > 0 ? -ZOOM_STEP : ZOOM_STEP
    setScaleAroundPoint(viewport.scale + delta, event.clientX, event.clientY)
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
  useEventListener(containerRef, 'scroll', handleScroll, { passive: true })

  return {
    containerRef,
    viewport,
    dragState,
    canvasOrigin,
    canvasSize,
    zoomPercent,
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
