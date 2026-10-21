import { computed, useId } from 'vue'

interface FieldA11yProps {
  id?: string
  name: string
  error?: string | null
  description?: string
}

/**
 * Stable field id plus the aria attributes that link a control to its
 * description and error (rendered by FieldMessages under `${id}-…` ids).
 */
export function useFieldA11y(props: FieldA11yProps) {
  const generatedId = useId()
  const fieldId = computed(() => props.id || `${props.name}-${generatedId}`)
  const hasError = computed(() => !!props.error)
  const describedBy = computed(
    () =>
      [
        hasError.value && `${fieldId.value}-error`,
        props.description && `${fieldId.value}-description`,
      ]
        .filter(Boolean)
        .join(' ') || undefined
  )
  const invalid = computed(() => hasError.value || undefined)

  return { fieldId, hasError, describedBy, invalid }
}
