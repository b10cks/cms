import { runtimeConfig } from '~/lib/runtime-config'

/**
 * The frameworks `@b10cks/cli init` can scaffold and wire, mirroring the CLI's
 * own registry (sdk/packages/cli/src/utils/project.ts). Keep `value` in sync
 * with the CLI's `Framework` union — it is passed verbatim as `--framework`.
 */
export const ONBOARDING_FRAMEWORKS = [
  { value: 'nuxt', label: 'Nuxt', icon: 'brand:nuxt', docs: '/guides/nuxt' },
  { value: 'next', label: 'Next.js', icon: 'brand:next', docs: '/guides/nextjs' },
  { value: 'react', label: 'React', icon: 'brand:react', docs: '/guides/react' },
  { value: 'vue', label: 'Vue', icon: 'brand:vue', docs: '/guides/vue' },
  { value: 'svelte', label: 'Svelte', icon: 'brand:svelte', docs: '/guides/svelte' },
] as const

export type OnboardingFramework = (typeof ONBOARDING_FRAMEWORKS)[number]['value']

export const PACKAGE_MANAGERS = ['bun', 'npm', 'pnpm', 'yarn'] as const

export type PackageManager = (typeof PACKAGE_MANAGERS)[number]

/** Absolute URL for a documentation path like `/guides/nuxt`. */
export function docsUrl(path: string): string {
  return `${runtimeConfig.public.docsUrl}${path}`
}

const RUNNERS: Record<PackageManager, string> = {
  bun: 'bunx',
  npm: 'npx',
  pnpm: 'pnpm dlx',
  yarn: 'yarn dlx',
}

/**
 * The command is meant to be pasted into a shell, and the directory is the one
 * free-text part of it. Keep it to characters that need no quoting so a stray
 * space or shell metacharacter can't turn the line into something else.
 */
export function sanitizeDirectory(directory: string): string {
  return directory
    .trim()
    .replace(/[^a-zA-Z0-9._@/-]+/g, '-')
    .replace(/^-+|-+$/g, '')
}

export interface ScaffoldCommandOptions {
  packageManager: PackageManager
  framework: OnboardingFramework
  directory: string
  spaceId: string
  token?: string | null
}

/**
 * Builds the `@b10cks/cli init` line shown in step 3. `--yes` keeps it
 * copy-pasteable: without a token the CLI would drop into an interactive
 * prompt, so it is only added once we actually have one.
 */
export function buildScaffoldCommand({
  packageManager,
  framework,
  directory,
  spaceId,
  token,
}: ScaffoldCommandOptions): string {
  // Shell-safe fallback: a cleared field must not produce `init --framework …`,
  // where the next flag would be swallowed as the directory argument.
  const dir = sanitizeDirectory(directory) || 'my-app'
  const parts = [
    RUNNERS[packageManager],
    '@b10cks/cli',
    'init',
    dir,
    `--framework ${framework}`,
    `--space ${spaceId}`,
    // Always explicit: the CLI otherwise detects the package manager from
    // whatever lockfile happens to sit in the working directory.
    `--pm ${packageManager}`,
  ]

  if (token) {
    parts.push(`--token ${token}`, '--yes')
  }

  return parts.join(' ')
}
