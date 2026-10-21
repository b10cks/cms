import { ref } from 'vue'

/** `loading` shows the bar, `finishing` completes and fades it, then back to `idle`. */
export type NavigationProgressState = 'idle' | 'loading' | 'finishing'

const SHOW_DELAY_MS = 150
const FINISH_MS = 400

export const navigationProgress = ref<NavigationProgressState>('idle')

let showTimer: ReturnType<typeof setTimeout> | undefined
let hideTimer: ReturnType<typeof setTimeout> | undefined

/** Shows the bar only if the navigation is still running after SHOW_DELAY_MS. */
export function startNavigationProgress() {
  if (showTimer !== undefined || navigationProgress.value === 'loading') return

  showTimer = setTimeout(() => {
    showTimer = undefined
    clearTimeout(hideTimer)
    navigationProgress.value = 'loading'
  }, SHOW_DELAY_MS)
}

export function finishNavigationProgress() {
  clearTimeout(showTimer)
  showTimer = undefined

  if (navigationProgress.value !== 'loading') return

  navigationProgress.value = 'finishing'
  hideTimer = setTimeout(() => {
    navigationProgress.value = 'idle'
  }, FINISH_MS)
}
