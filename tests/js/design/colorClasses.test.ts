import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * Tailwind v4 drops a class whose color it doesn't know, with typecheck, lint and tests all green.
 * These names came with shadcn and mean something else here, or nothing.
 */
const BANNED = /(?<![\w-])(text-muted-foreground|text-danger|border-input(?![-\w])|bg-muted(?![-\w]))/

const sources = (dir: string): string[] =>
  readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    if (statSync(path).isDirectory()) return sources(path)
    return /\.(vue|ts)$/.test(path) ? [path] : []
  })

describe('color classes', () => {
  it('uses none of the color names shadcn left behind', () => {
    const offenders = sources('resources/js').flatMap((path) => {
      const match = readFileSync(path, 'utf8').match(BANNED)
      return match ? [`${path}: ${match[1]}`] : []
    })

    expect(offenders).toEqual([])
  })
})
