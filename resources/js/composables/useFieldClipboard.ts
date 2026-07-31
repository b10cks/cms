import { useClipboard, useLocalStorage } from '@vueuse/core'
import { computed, readonly } from 'vue'

export interface SchemaFieldClipboardItem {
  type: 'b10cks-schema-field'
  key: string
  field: SchemaType
  timestamp: number
}

const storage = useLocalStorage<string | null>('schema-field-clipboard', null)
const { copy: copyToSystemClipboard } = useClipboard()

const clipboardItem = computed<SchemaFieldClipboardItem | null>(() => {
  if (!storage.value) return null

  try {
    const parsed = JSON.parse(storage.value)
    if (
      parsed &&
      typeof parsed === 'object' &&
      parsed.type === 'b10cks-schema-field' &&
      typeof parsed.key === 'string' &&
      parsed.field &&
      typeof parsed.field === 'object' &&
      !Array.isArray(parsed.field)
    ) {
      return parsed as SchemaFieldClipboardItem
    }
  } catch {
    // Ignore malformed clipboard payloads
  }

  return null
})

export function useFieldClipboard() {
  const copyField = async (key: string, field: SchemaType) => {
    const item: SchemaFieldClipboardItem = {
      type: 'b10cks-schema-field',
      key,
      field: JSON.parse(JSON.stringify(field)),
      timestamp: Date.now(),
    }

    storage.value = JSON.stringify(item)

    try {
      await copyToSystemClipboard(storage.value)
    } catch {
      // System clipboard is best-effort; localStorage is the source of truth
    }
  }

  const clearField = () => {
    storage.value = null
  }

  return {
    copyField,
    clearField,
    clipboardField: readonly(clipboardItem),
    hasField: computed(() => clipboardItem.value !== null),
  }
}
