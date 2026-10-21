import { ref, watch, type Ref } from 'vue'

/**
 * Turns true the first time `isOpen` is true and stays true. Gate a dialog with it
 * so its queries and setup wait for the first open while close animations still play.
 */
export const useMountOnFirstOpen = (isOpen: () => boolean): Ref<boolean> => {
  const mounted = ref(isOpen())
  watch(isOpen, (open) => {
    if (open) mounted.value = true
  })
  return mounted
}
