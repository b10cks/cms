/**
 * Reads and writes the form fields of a `SKILL.md`: `name` and `description`
 * in the frontmatter, `b10cks.surfaces`, and the instructions below it.
 * Every other frontmatter line (tools, output, schema, budget) is kept as
 * written, so the form never drops what a power user added in the raw view.
 */

export interface SkillFields {
  name: string
  description: string
  surfaces: string[]
  instructions: string
}

/** Surfaces the app renders skills on; `learning` and `field:*` stay raw-only. */
export const SKILL_SURFACES = [
  'selection',
  'cmdk',
  'canvas',
  'content-tree',
  'meta-tags',
  'translate',
  'asset',
  'bulk:content',
  'bulk:data_entries',
  'bulk:assets',
] as const

const FRONTMATTER = /^---\r?\n([\s\S]*?)\r?\n?---[ \t]*(?:\r?\n|$)/
const TOP_KEY = /^([A-Za-z0-9_-]+):[ \t]*(.*)$/

function split(source: string): { lines: string[]; body: string } {
  const match = FRONTMATTER.exec(source)
  if (!match) return { lines: [], body: source }

  return { lines: match[1] === '' ? [] : match[1].split(/\r?\n/), body: source.slice(match[0].length) }
}

/** Index range [start, end) of a top-level key and its indented continuation lines. */
function keyRange(lines: readonly string[], key: string): [number, number] | null {
  const start = lines.findIndex((line) => TOP_KEY.exec(line)?.[1] === key)
  if (start === -1) return null

  let end = start + 1
  while (end < lines.length && (lines[end].trim() === '' || /^\s/.test(lines[end]))) end++

  return [start, end]
}

function unquote(value: string): string {
  const trimmed = value.trim()
  if (trimmed.startsWith('"') && trimmed.endsWith('"') && trimmed.length > 1) {
    try {
      return JSON.parse(trimmed) as string
    } catch {
      return trimmed.slice(1, -1)
    }
  }
  if (trimmed.startsWith("'") && trimmed.endsWith("'") && trimmed.length > 1) {
    return trimmed.slice(1, -1).replace(/''/g, "'")
  }

  return trimmed
}

function readScalar(lines: readonly string[], key: string): string {
  const range = keyRange(lines, key)
  if (!range) return ''

  const inline = TOP_KEY.exec(lines[range[0]])?.[2] ?? ''
  const continuation = lines.slice(range[0] + 1, range[1]).map((line) => line.trim())

  if (/^[>|][+-]?$/.test(inline.trim())) {
    return continuation.join(inline.trim().startsWith('|') ? '\n' : ' ').trim()
  }

  return unquote([inline, ...continuation].filter(Boolean).join(' '))
}

function readSurfaces(lines: readonly string[]): string[] {
  const range = keyRange(lines, 'b10cks')
  if (!range) return []

  const block = lines.slice(range[0] + 1, range[1])
  const index = block.findIndex((line) => /^\s+surfaces:/.test(line))
  if (index === -1) return []

  const inline = block[index].replace(/^\s+surfaces:\s*/, '').trim()
  if (inline.startsWith('[')) {
    return inline
      .replace(/^\[|\]$/g, '')
      .split(',')
      .map(unquote)
      .filter(Boolean)
  }

  const indent = /^\s*/.exec(block[index])?.[0].length ?? 0
  const items: string[] = []
  for (const line of block.slice(index + 1)) {
    const lineIndent = /^\s*/.exec(line)?.[0].length ?? 0
    if (line.trim() === '' || lineIndent <= indent) break
    items.push(unquote(line.trim().replace(/^-\s*/, '')))
  }

  return items.filter(Boolean)
}

export function readSkillFields(source: string): SkillFields {
  const { lines, body } = split(source)

  return {
    name: readScalar(lines, 'name'),
    description: readScalar(lines, 'description'),
    surfaces: readSurfaces(lines),
    instructions: body.replace(/^\s*\n/, '').trimEnd(),
  }
}

/** A YAML scalar: plain when that is unambiguous, otherwise double-quoted (JSON strings are valid YAML). */
function scalar(value: string): string {
  const plain = /^[A-Za-z0-9(][^\n]*$/.test(value) && !/:\s|\s#|:$|\s$/.test(value)

  return plain ? value : JSON.stringify(value)
}

function writeScalar(lines: string[], key: string, value: string): void {
  const line = `${key}: ${scalar(value)}`
  const range = keyRange(lines, key)
  if (range) {
    lines.splice(range[0], range[1] - range[0], line)
  } else {
    lines.splice(key === 'name' ? 0 : Math.min(1, lines.length), 0, line)
  }
}

function writeSurfaces(lines: string[], surfaces: readonly string[]): void {
  const range = keyRange(lines, 'b10cks')
  if (!range) {
    lines.push('b10cks:', `  surfaces: [${surfaces.join(', ')}]`)
    return
  }

  const blockStart = range[0] + 1
  const block = lines.slice(blockStart, range[1])
  const index = block.findIndex((line) => /^\s+surfaces:/.test(line))
  const indent = index === -1 ? '  ' : (/^\s*/.exec(block[index])?.[0] ?? '  ')
  const line = `${indent}surfaces: [${surfaces.join(', ')}]`

  if (index === -1) {
    lines.splice(blockStart, 0, line)
    return
  }

  let end = index + 1
  while (
    end < block.length &&
    block[end].trim() !== '' &&
    (/^\s*/.exec(block[end])?.[0].length ?? 0) > indent.length
  ) {
    end++
  }
  lines.splice(blockStart + index, end - index, line)
}

export function writeSkillFields(source: string, fields: SkillFields): string {
  const { lines } = split(source)

  writeScalar(lines, 'name', fields.name)
  writeScalar(lines, 'description', fields.description)
  writeSurfaces(lines, fields.surfaces)

  return `---\n${lines.join('\n')}\n---\n\n${fields.instructions.trim()}\n`
}
