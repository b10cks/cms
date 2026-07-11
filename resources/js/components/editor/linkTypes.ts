export type LinkKind = 'url' | 'internal'

/** The link the cursor currently sits on, used to prefill the dialog for editing. */
export interface LinkInitial {
  kind: LinkKind
  url?: string
  content?: string
  anchor?: string
  target?: string | null
  rel?: string | null
}

/** Result of the dialog, applied to the editor by the host component. */
export interface LinkApplyPayload {
  kind: LinkKind
  target: string | null
  rel: string | null
  /** Text to insert when creating a link over an empty selection. */
  text?: string
  url?: string
  content?: string
  anchor?: string
}
