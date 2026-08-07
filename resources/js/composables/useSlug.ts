import { CONTENT_SLUG_LENGTH, slugify, slugifyContent, slugifyIdentifier } from '~/lib/slug'
import type { SlugOptions } from '~/lib/slug'

/**
 * Auto-imported access to the shared slug rules.
 *
 * The implementation lives in `~/lib/slug` rather than here on purpose: a
 * composable that imports a sibling composable knocks that sibling out of the
 * auto-import map, and every component still calling it bare becomes a
 * ReferenceError at runtime. Other composables must import from `~/lib/slug`
 * directly; components can use either.
 */
export function useSlug() {
  return {
    slugify,
    slugifyContent,
    slugifyIdentifier,
    CONTENT_SLUG_LENGTH,
  }
}

export type { SlugOptions }
