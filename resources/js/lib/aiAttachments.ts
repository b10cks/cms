/**
 * Files attached to a thread message. Images go to the model as image parts,
 * documents as extracted text. The server validates again; this check only
 * spares the user an upload that would fail.
 */
export type AiAttachmentKind = 'image' | 'document'

export interface AiAttachment {
  id: string
  name: string
  mime_type: string
  size: number
  kind: AiAttachmentKind
  url: string
}

export const MAX_ATTACHMENTS = 10

const MB = 1024 * 1024

export const ATTACHMENT_LIMITS = {
  image: 5 * MB,
  document: 20 * MB,
} as const satisfies Record<AiAttachmentKind, number>

const EXTENSIONS = {
  png: 'image',
  jpg: 'image',
  jpeg: 'image',
  webp: 'image',
  gif: 'image',
  pdf: 'document',
  docx: 'document',
  md: 'document',
  markdown: 'document',
  txt: 'document',
  csv: 'document',
} as const satisfies Record<string, AiAttachmentKind>

/** What the file picker offers. */
export const ATTACHMENT_ACCEPT = Object.keys(EXTENSIONS)
  .map((extension) => `.${extension}`)
  .join(',')

const isKnownExtension = (extension: string): extension is keyof typeof EXTENSIONS =>
  Object.hasOwn(EXTENSIONS, extension)

/** The kind by extension; browsers report no or odd MIME types for md and csv. */
export function attachmentKind(file: Pick<File, 'name'>): AiAttachmentKind | null {
  const extension = file.name.split('.').pop()?.toLowerCase() ?? ''
  return isKnownExtension(extension) ? EXTENSIONS[extension] : null
}

export type AttachmentCheck =
  | { ok: true; kind: AiAttachmentKind }
  | { ok: false; reason: 'type' }
  | { ok: false; reason: 'size'; kind: AiAttachmentKind }

export function checkAttachment(file: Pick<File, 'name' | 'size'>): AttachmentCheck {
  const kind = attachmentKind(file)
  if (!kind) return { ok: false, reason: 'type' }
  if (file.size > ATTACHMENT_LIMITS[kind]) return { ok: false, reason: 'size', kind }
  return { ok: true, kind }
}

export const formatMegabytes = (bytes: number) => `${Math.round(bytes / MB)} MB`
