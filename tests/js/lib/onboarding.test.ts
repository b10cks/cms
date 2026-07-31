import { describe, expect, it } from 'vitest'

import {
  buildScaffoldCommand,
  docsUrl,
  ONBOARDING_FRAMEWORKS,
  PACKAGE_MANAGERS,
  sanitizeDirectory,
} from '~/lib/onboarding'

describe('docsUrl', () => {
  it('prefixes the configured docs base URL', () => {
    // tests/js/setup.ts pins window.__APP_CONFIG__.docsUrl.
    expect(docsUrl('/guides/nuxt')).toBe('https://docs.b10cks.test/guides/nuxt')
  })
})

describe('sanitizeDirectory', () => {
  it('keeps a plain name untouched', () => {
    expect(sanitizeDirectory('my-app')).toBe('my-app')
  })

  it('keeps scoped and nested paths', () => {
    expect(sanitizeDirectory('apps/@acme/web_1.0')).toBe('apps/@acme/web_1.0')
  })

  it('collapses runs of unsafe characters into a single dash', () => {
    expect(sanitizeDirectory('my app')).toBe('my-app')
    expect(sanitizeDirectory('my   app')).toBe('my-app')
  })

  it.each([
    ['rm -rf /; echo pwned', 'rm--rf-/-echo-pwned'],
    ['$(whoami)', 'whoami'],
    ['a`b`c', 'a-b-c'],
    ['a && b', 'a-b'],
    ['a\nb', 'a-b'],
  ])('neutralizes shell metacharacters in %s', (input, expected) => {
    expect(sanitizeDirectory(input)).toBe(expected)
  })

  it('trims leading and trailing dashes', () => {
    expect(sanitizeDirectory('  !!app!!  ')).toBe('app')
  })

  it('returns an empty string when nothing usable remains', () => {
    expect(sanitizeDirectory('   ')).toBe('')
    expect(sanitizeDirectory('!!!')).toBe('')
  })
})

describe('buildScaffoldCommand', () => {
  const base = {
    packageManager: 'bun' as const,
    framework: 'nuxt' as const,
    directory: 'my-app',
    spaceId: 'space-1',
  }

  it('builds the tokenless command without --yes', () => {
    expect(buildScaffoldCommand(base)).toBe(
      'bunx @b10cks/cli init my-app --framework nuxt --space space-1 --pm bun'
    )
  })

  it('appends the token and --yes when a token is available', () => {
    expect(buildScaffoldCommand({ ...base, token: 'tok_123' })).toBe(
      'bunx @b10cks/cli init my-app --framework nuxt --space space-1 --pm bun --token tok_123 --yes'
    )
  })

  it('treats an empty or null token as no token', () => {
    expect(buildScaffoldCommand({ ...base, token: '' })).not.toContain('--yes')
    expect(buildScaffoldCommand({ ...base, token: null })).not.toContain('--yes')
  })

  it.each([
    ['bun', 'bunx'],
    ['npm', 'npx'],
    ['pnpm', 'pnpm dlx'],
    ['yarn', 'yarn dlx'],
  ] as const)('uses the %s runner', (packageManager, runner) => {
    const command = buildScaffoldCommand({ ...base, packageManager })

    expect(command.startsWith(`${runner} @b10cks/cli init`)).toBe(true)
    expect(command).toContain(`--pm ${packageManager}`)
  })

  it('falls back to my-app so a cleared directory cannot swallow the next flag', () => {
    expect(buildScaffoldCommand({ ...base, directory: '   ' })).toBe(
      'bunx @b10cks/cli init my-app --framework nuxt --space space-1 --pm bun'
    )
  })

  it('sanitizes the directory it interpolates', () => {
    expect(buildScaffoldCommand({ ...base, directory: 'my app; rm -rf /' })).toBe(
      'bunx @b10cks/cli init my-app-rm--rf-/ --framework nuxt --space space-1 --pm bun'
    )
  })

  it('covers every advertised framework and package manager', () => {
    for (const framework of ONBOARDING_FRAMEWORKS) {
      for (const packageManager of PACKAGE_MANAGERS) {
        expect(buildScaffoldCommand({ ...base, framework: framework.value, packageManager })).toContain(
          `--framework ${framework.value}`
        )
      }
    }
  })
})
