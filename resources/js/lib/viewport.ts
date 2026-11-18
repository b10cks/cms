import { createSharedComposable, useMediaQuery } from '@vueuse/core'
import { ref } from 'vue'

/** Same conditions as the `phone` and `touch` variants in main.css. */
export const useIsPhone = createSharedComposable(() =>
  useMediaQuery('(max-width: 767px), (pointer: coarse) and (max-height: 500px)')
)
export const useIsTouch = createSharedComposable(() =>
  useMediaQuery('(hover: none) and (pointer: coarse)')
)

/** On a phone the preview replaces the form on request, and the saved preference stays as it is. */
export const phonePreviewOpen = ref(false)

export const closePhonePreview = () => {
  phonePreviewOpen.value = false
}
