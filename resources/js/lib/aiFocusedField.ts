/** A field of an entry, as the content editor last saw it focused. */
export interface AiFocusedField {
  contentId: string
  /** Path inside the entry's content, e.g. `body.0.title` */
  path: string
  label: string
}

/**
 * The last field focused in the content editor. It outlives the blur on
 * purpose: clicking into the AI dock moves focus away, and that is exactly
 * when the dock offers the field as context.
 */
export const aiFocusedField = shallowRef<AiFocusedField | null>(null)
