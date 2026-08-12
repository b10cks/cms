import { toast } from 'vue-sonner'

/** `t()` narrowed to the interpolating overload the error toasts use. */
export type Translate = (key: string, named?: Record<string, unknown>) => string

/**
 * The message an error toast should show. Callers hand this whatever landed in
 * a rejected promise, which is not always an `Error`.
 */
export const errorMessage = (error: unknown): string => {
  if (typeof error === 'string') return error || 'Unknown error'
  const message = (error as { message?: unknown } | null | undefined)?.message
  return (typeof message === 'string' && message) || 'Unknown error'
}

/** `toast.error(t(key, { error: … }))` — the block repeated across the composables. */
export const toastError = (t: Translate, key: string, error: unknown): void => {
  toast.error(t(key, { error: errorMessage(error) }))
}
