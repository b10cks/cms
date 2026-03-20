import {
  useClipboard,
  useIntervalFn,
  useLocalStorage,
  usePermission,
  useWindowFocus,
} from '@vueuse/core'
import { onMounted, readonly, ref, watch } from 'vue'

export interface ClipboardSingleItem {
  type: 'blocks-editor-clipboard-item'
  data: Record<string, unknown>
  timestamp: number
  spaceId: string
  blockType?: string
  _isCut?: boolean
}

export interface ClipboardMultipleItems {
  type: 'blocks-editor-clipboard-items'
  data: Record<string, unknown>[]
  timestamp: number
  spaceId: string
  blockType?: string
  _isCut?: boolean
}

export type ClipboardItem = ClipboardSingleItem | ClipboardMultipleItems

const { copy: copyToClipboard, text: clipboardText, isSupported } = useClipboard({ read: true })
const localStorageClipboard = useLocalStorage<string | null>('blocks-clipboard', null)
const ulid = useUlid()
const focused = useWindowFocus()
const clipboardPermission = usePermission('clipboard-read')
const hasClipboardItem = ref(false)

// Function to check clipboard and validate format
const checkClipboard = async () => {
  try {
    let clipboardData = null

    // Try to read from system clipboard if supported and permitted
    if (isSupported.value && clipboardPermission.value === 'granted' && clipboardText.value) {
      try {
        const parsed = JSON.parse(clipboardText.value)
        if (isValidClipboardItem(parsed)) {
          clipboardData = parsed
        }
      } catch (error) {
        // Invalid JSON or format, continue to fallback
      }
    }

    // Fallback to localStorage
    if (!clipboardData && localStorageClipboard.value) {
      try {
        const parsed = JSON.parse(localStorageClipboard.value)
        if (isValidClipboardItem(parsed)) {
          clipboardData = parsed
        }
      } catch (error) {
        // Invalid localStorage data
      }
    }

    hasClipboardItem.value = !!clipboardData
  } catch (error) {
    hasClipboardItem.value = false
  }
}

// Helper function to validate clipboard item format
const isRecord = (value: unknown): value is Record<string, unknown> =>
  !!value && typeof value === 'object' && !Array.isArray(value)

const isValidClipboardItem = (item: unknown): item is ClipboardItem => {
  if (!isRecord(item)) {
    return false
  }

  if (typeof item.timestamp !== 'number' || typeof item.spaceId !== 'string') {
    return false
  }

  if (item.type === 'blocks-editor-clipboard-item') {
    return isRecord(item.data) && (item._isCut === undefined || typeof item._isCut === 'boolean')
  }

  if (item.type === 'blocks-editor-clipboard-items') {
    return Array.isArray(item.data) && item.data.every(isRecord)
  }

  return false
}

// Periodic clipboard checking when window is focused
const { pause: pauseInterval, resume: resumeInterval } = useIntervalFn(async () => {
  if (focused.value && clipboardPermission.value === 'granted') {
    await checkClipboard()
  }
}, 1000) // Check every second

// Watch for focus changes to start/stop checking
watch(focused, async (isFocused) => {
  if (isFocused && clipboardPermission.value === 'granted') {
    resumeInterval()
    await checkClipboard() // Check immediately when focused
  } else {
    pauseInterval()
  }
})

// Watch for permission changes
watch(clipboardPermission, async (permission) => {
  if (permission === 'granted' && focused.value) {
    resumeInterval()
    await checkClipboard()
  } else {
    pauseInterval()
  }
})

// Initialize on mount
onMounted(async () => {
  // Try to read clipboard to trigger permission request if needed
  if (clipboardPermission.value === 'prompt' && isSupported.value) {
    try {
      await navigator.clipboard.readText()
    } catch (error) {
      // Permission denied or not supported, continue with localStorage fallback
    }
  }

  // Initial check
  await checkClipboard()
})

export const useGlobalClipboard = () => {
  const replaceIds = (obj: unknown): unknown => {
    if (Array.isArray(obj)) {
      return obj.map(replaceIds)
    } else if (obj && typeof obj === 'object') {
      const newObj: Record<string, unknown> = {}
      for (const key in obj as Record<string, unknown>) {
        if (Object.prototype.hasOwnProperty.call(obj, key)) {
          if (key === 'id') {
            newObj[key] = ulid()
          } else {
            newObj[key] = replaceIds((obj as Record<string, unknown>)[key])
          }
        }
      }
      return newObj
    }
    return obj
  }

  const writeClipboardItem = async (clipboardItem: ClipboardItem) => {
    const serializedItem = JSON.stringify(clipboardItem)

    try {
      await copyToClipboard(serializedItem)
      localStorageClipboard.value = serializedItem
      hasClipboardItem.value = true
    } catch (error) {
      localStorageClipboard.value = serializedItem
      hasClipboardItem.value = true
      // eslint-disable-next-line no-console
      console.warn('Failed to copy to system clipboard, using localStorage:', error)
    }
  }

  const copyItem = async (
    item: Record<string, unknown> | Record<string, unknown>[],
    spaceId: string,
    blockType?: string
  ) => {
    const clipboardItem: ClipboardItem = Array.isArray(item)
      ? {
          type: 'blocks-editor-clipboard-items',
          data: item.map((entry) => ({ ...entry })),
          timestamp: Date.now(),
          spaceId,
          blockType,
        }
      : {
          type: 'blocks-editor-clipboard-item',
          data: { ...item },
          timestamp: Date.now(),
          spaceId,
          blockType,
        }

    await writeClipboardItem(clipboardItem)
  }

  const cutItem = async (
    item: Record<string, unknown> | Record<string, unknown>[],
    spaceId: string,
    blockType?: string
  ) => {
    const clipboardItem: ClipboardItem = Array.isArray(item)
      ? {
          type: 'blocks-editor-clipboard-items',
          data: item.map((entry) => ({ ...entry })),
          timestamp: Date.now(),
          spaceId,
          blockType,
          _isCut: true,
        }
      : {
          type: 'blocks-editor-clipboard-item',
          data: { ...item },
          timestamp: Date.now(),
          spaceId,
          blockType,
          _isCut: true,
        }

    await writeClipboardItem(clipboardItem)
  }

  const getClipboardItem = async (): Promise<ClipboardItem | null> => {
    try {
      // First try to read from system clipboard
      if (clipboardText.value) {
        const parsed = JSON.parse(clipboardText.value)
        if (isValidClipboardItem(parsed)) {
          return parsed
        }
      }
    } catch (error) {
      // eslint-disable-next-line no-console
      console.warn('Failed to parse clipboard text:', error)
    }

    // Fallback to localStorage
    if (localStorageClipboard.value) {
      try {
        const parsed = JSON.parse(localStorageClipboard.value)
        if (isValidClipboardItem(parsed)) {
          return parsed
        }
      } catch (error) {
        // eslint-disable-next-line no-console
        console.warn('Failed to parse localStorage clipboard:', error)
      }
    }

    return null
  }

  const pasteItem = async (): Promise<
    Record<string, unknown> | Record<string, unknown>[] | null
  > => {
    const clipboardItem = await getClipboardItem()

    if (!clipboardItem) {
      return null
    }

    if (clipboardItem.type === 'blocks-editor-clipboard-items') {
      return clipboardItem.data.map((item) => replaceIds(item) as Record<string, unknown>)
    }

    return replaceIds(clipboardItem.data) as Record<string, unknown>
  }

  const clearClipboard = async () => {
    localStorageClipboard.value = null
    hasClipboardItem.value = false
  }

  return {
    copyItem,
    cutItem,
    pasteItem,
    hasClipboardItem: readonly(hasClipboardItem),
    clearClipboard,
    getClipboardItem,
  }
}
