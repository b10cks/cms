import { buildIlumUrl } from '~/lib/ilum'
import { runtimeConfig } from '~/lib/runtime-config'

interface RenderScope {
  value: unknown
  parent?: RenderScope
  index?: number
  key?: string
  root: Record<string, unknown>
}

interface BlockMatch {
  closeStart: number
  closeEnd: number
  elseStart?: number
  elseEnd?: number
}

const TAG_PATTERN = /\{\{([^}]+)\}\}/g
const EACH_HELPER = 'each'
const IF_HELPER = 'if'
const IMAGE_HELPER = 'image'

class PreviewHandlebars {
  render(template: string, data?: Record<string, unknown> | null): string {
    const root = isRecord(data) ? data : {}

    return renderTemplate(template, {
      value: root,
      root,
    })
  }
}

function renderTemplate(template: string, scope: RenderScope): string {
  let output = ''
  let cursor = 0
  const regex = new RegExp(TAG_PATTERN)
  let match: RegExpExecArray | null

  while ((match = regex.exec(template)) !== null) {
    output += template.slice(cursor, match.index)

    const token = match[1]?.trim() ?? ''

    if (token.startsWith('#')) {
      const helperName = getHelperName(token.slice(1))
      const block = findMatchingBlock(template, regex.lastIndex, helperName)

      if (!block) {
        cursor = match.index + match[0].length
        continue
      }

      const expression = token.slice(helperName.length + 1).trim()
      const truthySection = template.slice(regex.lastIndex, block.elseStart ?? block.closeStart)
      const falsySection =
        block.elseStart !== undefined && block.elseEnd !== undefined
          ? template.slice(block.elseEnd, block.closeStart)
          : ''

      output += renderBlock(helperName, expression, truthySection, falsySection, scope)
      cursor = block.closeEnd
      regex.lastIndex = block.closeEnd
      continue
    }

    if (token === 'else' || token.startsWith('/')) {
      cursor = match.index + match[0].length
      continue
    }

    output += renderInline(token, scope)
    cursor = match.index + match[0].length
  }

  output += template.slice(cursor)

  return output
}

function renderBlock(
  helperName: string,
  expression: string,
  truthySection: string,
  falsySection: string,
  scope: RenderScope
): string {
  if (helperName === EACH_HELPER) {
    const iterable = resolveExpression(expression, scope)
    const entries = normalizeEntries(iterable)

    if (entries.length === 0) {
      return falsySection ? renderTemplate(falsySection, scope) : ''
    }

    return entries
      .map(([key, value], index) =>
        renderTemplate(truthySection, {
          value,
          parent: scope,
          index,
          key,
          root: scope.root,
        })
      )
      .join('')
  }

  if (helperName === IF_HELPER) {
    const value = resolveExpression(expression, scope)

    return isTruthy(value)
      ? renderTemplate(truthySection, scope)
      : renderTemplate(falsySection, scope)
  }

  return ''
}

function renderInline(token: string, scope: RenderScope): string {
  const [helperName, ...rest] = token.split(/\s+/)

  if (helperName === IMAGE_HELPER && rest.length > 0) {
    return renderImage(rest.join(' '), scope)
  }

  return stringifyValue(resolveExpression(token, scope))
}

function renderImage(expression: string, scope: RenderScope): string {
  const value = resolveExpression(expression, scope)
  const source = resolveImageSource(value)

  if (!source) {
    return ''
  }

  if (/^(https?:)?\/\//.test(source) || source.startsWith('data:')) {
    return source
  }

  const baseURL = (runtimeConfig.public.ilum.baseURL || '').replace(/\/$/, '')
  const imgUrl = buildIlumUrl(source, { width: 64, height: 64 }, baseURL)

  return `<img src="${imgUrl}" alt="" />`
}

function findMatchingBlock(
  template: string,
  searchStart: number,
  helperName: string
): BlockMatch | null {
  const regex = new RegExp(TAG_PATTERN)
  regex.lastIndex = searchStart

  const nestedBlocks: string[] = []
  let elseStart: number | undefined
  let elseEnd: number | undefined
  let match: RegExpExecArray | null

  while ((match = regex.exec(template)) !== null) {
    const token = match[1]?.trim() ?? ''
    const closingHelperName = token.startsWith('/') ? token.slice(1).trim() : ''

    if (token.startsWith('#')) {
      const nestedHelperName = getHelperName(token.slice(1))

      if (nestedHelperName) {
        nestedBlocks.push(nestedHelperName)
      }

      continue
    }

    if (token.startsWith('/')) {
      if (nestedBlocks.length > 0 && nestedBlocks[nestedBlocks.length - 1] === closingHelperName) {
        nestedBlocks.pop()
        continue
      }

      if (nestedBlocks.length === 0 && closingHelperName === helperName) {
        return {
          closeStart: match.index,
          closeEnd: regex.lastIndex,
          elseStart,
          elseEnd,
        }
      }

      continue
    }

    if (token === 'else' && nestedBlocks.length === 0 && elseStart === undefined) {
      elseStart = match.index
      elseEnd = regex.lastIndex
    }
  }

  return null
}

function resolveExpression(expression: string, scope: RenderScope): unknown {
  const normalized = expression.trim()

  if (!normalized) {
    return ''
  }

  if (
    (normalized.startsWith('"') && normalized.endsWith('"')) ||
    (normalized.startsWith("'") && normalized.endsWith("'"))
  ) {
    return normalized.slice(1, -1)
  }

  if (normalized === 'true') return true
  if (normalized === 'false') return false
  if (normalized === 'null') return null
  if (normalized === 'undefined') return undefined
  if (/^-?\d+(\.\d+)?$/.test(normalized)) return Number(normalized)

  return resolvePath(normalized, scope)
}

function resolvePath(path: string, scope: RenderScope): unknown {
  let currentScope = scope
  let normalizedPath = path

  while (normalizedPath.startsWith('../')) {
    currentScope = currentScope.parent ?? currentScope
    normalizedPath = normalizedPath.slice(3)
  }

  if (!normalizedPath || normalizedPath === '.' || normalizedPath === 'this') {
    return currentScope.value
  }

  if (normalizedPath === '@index') return currentScope.index
  if (normalizedPath === '@key') return currentScope.key

  if (normalizedPath === '@root') {
    return currentScope.root
  }

  if (normalizedPath.startsWith('@root.')) {
    return readPath(currentScope.root, splitPath(normalizedPath.slice(6)))
  }

  if (normalizedPath.startsWith('this.')) {
    return readPath(currentScope.value, splitPath(normalizedPath.slice(5)))
  }

  const segments = splitPath(normalizedPath)
  const [firstSegment, ...rest] = segments

  if (!firstSegment) {
    return undefined
  }

  const baseValue = lookup(firstSegment, currentScope)

  return rest.length > 0 ? readPath(baseValue, rest) : baseValue
}

function lookup(key: string, scope: RenderScope): unknown {
  if (key === 'this') return scope.value
  if (key === '@index') return scope.index
  if (key === '@key') return scope.key
  if (key === '@root') return scope.root

  if (hasOwn(scope.value, key)) {
    return getProperty(scope.value, key)
  }

  if (scope.parent) {
    return lookup(key, scope.parent)
  }

  return undefined
}

function readPath(value: unknown, segments: string[]): unknown {
  let current = value

  for (const segment of segments) {
    if (current == null) {
      return undefined
    }

    current = getProperty(current, segment)
  }

  return current
}

function normalizeEntries(value: unknown): Array<[string, unknown]> {
  if (Array.isArray(value)) {
    return value.map((entry, index) => [String(index), entry])
  }

  if (isRecord(value)) {
    return Object.entries(value)
  }

  return []
}

function resolveImageSource(value: unknown): string | null {
  if (typeof value === 'string') {
    const trimmed = value.trim()

    return trimmed || null
  }

  if (!isRecord(value)) {
    return null
  }

  console.log(value)

  for (const key of ['full_path', 'url', 'src', 'path']) {
    const candidate = value[key]

    if (typeof candidate === 'string' && candidate.trim()) {
      return candidate.trim()
    }
  }

  return null
}

function stringifyValue(value: unknown): string {
  if (value == null) {
    return ''
  }

  if (typeof value === 'string') {
    return value
  }

  if (typeof value === 'number' || typeof value === 'boolean' || typeof value === 'bigint') {
    return String(value)
  }

  return ''
}

function isTruthy(value: unknown): boolean {
  if (Array.isArray(value)) {
    return value.length > 0
  }

  return Boolean(value)
}

function getHelperName(token: string): string {
  return token.trim().split(/\s+/, 1)[0] || ''
}

function splitPath(path: string): string[] {
  return path
    .split('.')
    .map((segment) => segment.trim())
    .filter(Boolean)
}

function hasOwn(value: unknown, key: string): boolean {
  return (
    typeof value === 'object' && value !== null && Object.prototype.hasOwnProperty.call(value, key)
  )
}

function getProperty(value: unknown, key: string): unknown {
  if (Array.isArray(value) && /^\d+$/.test(key)) {
    return value[Number(key)]
  }

  if (typeof value === 'string' && key === 'length') {
    return value.length
  }

  if (typeof value === 'object' && value !== null && key in value) {
    return (value as Record<string, unknown>)[key]
  }

  return undefined
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

export default function useHandlebars() {
  return new PreviewHandlebars()
}
