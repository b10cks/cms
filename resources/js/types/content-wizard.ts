export const CONTENT_WIZARD_ROOT_ID = '__root__'
export const CONTENT_WIZARD_CARD_WIDTH = 256
export const CONTENT_WIZARD_CARD_HEIGHT = 64
export const CONTENT_WIZARD_HORIZONTAL_GAP = 32
export const CONTENT_WIZARD_VERTICAL_GAP = 32

export type ContentWizardBlockType = BlockResource['type']
export type ContentWizardSlugMode = 'auto' | 'manual'
export type ContentWizardDeletedReason = 'self' | 'ancestor'
export type ContentWizardAddPosition = 'sibling' | 'child'
export type ContentWizardEditableField = 'title' | 'slug'
export type ContentWizardValidationField = 'title' | 'slug' | 'placement' | 'block' | 'general'

export interface ContentWizardPosition {
  x: number
  y: number
}

export interface ContentWizardBounds {
  minX: number
  maxX: number
  minY: number
  maxY: number
  width: number
  height: number
}

export interface ContentWizardOriginalNodeState {
  parentId: string | null
  title: string
  slug: string
  blockId: string
  blockType: ContentWizardBlockType
  position: number
}

export interface ContentWizardValidationError {
  nodeId: string
  field: ContentWizardValidationField
  message: string
}

export interface ContentWizardNodeValidationState {
  hasErrors: boolean
  errors: ContentWizardValidationError[]
}

export interface ContentWizardNodeChangeState {
  created: boolean
  updated: boolean
  moved: boolean
  deleted: boolean
}

export interface ContentWizardDraftNode {
  id: string
  backendId: string | null
  parentId: string | null
  childrenIds: string[]
  blockId: string
  blockType: ContentWizardBlockType
  blockName: string
  title: string
  slug: string
  slugMode: ContentWizardSlugMode
  icon: string | null
  color: string | null
  depth: number
  position: number
  layout: ContentWizardPosition
  isRootVirtual: boolean
  canHaveChildren: boolean
  isAiAltered: boolean
  isDeletedSelf: boolean
  deletedReason?: ContentWizardDeletedReason
  changes: ContentWizardNodeChangeState
  validationState: ContentWizardNodeValidationState
  original: ContentWizardOriginalNodeState | null
}

export interface ContentWizardDraftTree {
  rootId: string
  nodes: Record<string, ContentWizardDraftNode>
}

export interface ContentWizardLayoutResult {
  positions: Record<string, ContentWizardPosition>
  bounds: ContentWizardBounds
}

export interface ContentWizardCreateOperation {
  type: 'create'
  nodeId: string
  parentId: string | null
  depth: number
}

export interface ContentWizardUpdateOperation {
  type: 'update'
  nodeId: string
  depth: number
  fromBlockType: ContentWizardBlockType
  toBlockType: ContentWizardBlockType
  requiresMoveBeforeUpdate: boolean
  requiresUpdateBeforeMove: boolean
}

export interface ContentWizardMoveOperation {
  type: 'move'
  nodeId: string
  parentId: string | null
  depth: number
  position: number
}

export interface ContentWizardDeleteOperation {
  type: 'delete'
  nodeId: string
  depth: number
}

export type ContentWizardOperation =
  | ContentWizardCreateOperation
  | ContentWizardUpdateOperation
  | ContentWizardMoveOperation
  | ContentWizardDeleteOperation

export interface ContentWizardApplyResult {
  success: boolean
  operations: ContentWizardOperation[]
  error?: string
}

export interface ContentWizardViewportState {
  x: number
  y: number
  scale: number
}
