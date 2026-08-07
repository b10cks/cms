import { slugifyContent } from '~/lib/slug'
import type { ContentWizardSlugMode } from '~/types/content-wizard'

/**
 * Content slugs, in the language the entry is written in.
 *
 * The rules themselves live in `~/lib/slug` alongside their PHP twin — this
 * composable only adds the wizard's auto/manual bookkeeping. It imports from
 * `~/lib/slug` rather than the `useSlug` composable because a sibling import
 * would drop `useSlug` out of the auto-import map.
 */
export function useContentWizardSlug(
  defaultLanguage?: MaybeRefOrGetter<string | null | undefined>
) {
  const slugify = (value: string, language?: string | null) =>
    slugifyContent(value, language ?? toValue(defaultLanguage) ?? null)

  const resolveSlugMode = (
    title: string,
    slug: string,
    language?: string | null
  ): ContentWizardSlugMode => {
    if (!slug.trim()) {
      return 'auto'
    }

    return slugify(title, language) === slugify(slug, language) ? 'auto' : 'manual'
  }

  const syncSlugWithTitle = (
    title: string,
    currentSlug: string,
    mode: ContentWizardSlugMode,
    language?: string | null
  ): { slug: string; slugMode: ContentWizardSlugMode } => {
    if (mode === 'manual') {
      return {
        slug: currentSlug,
        slugMode: currentSlug.trim() ? 'manual' : 'auto',
      }
    }

    return {
      slug: slugify(title, language),
      slugMode: 'auto',
    }
  }

  // The explicit slug is normalized too: it ends up in the create/update payload
  // verbatim, so whatever the user pastes must go through the same hygiene as an
  // auto slug. A slug that normalizes to nothing stays empty and fails validation
  // rather than quietly falling back to the title.
  const resolveEffectiveSlug = (title: string, slug: string, language?: string | null) =>
    slug.trim() ? slugify(slug, language) : slugify(title, language)

  return {
    slugify,
    resolveSlugMode,
    resolveEffectiveSlug,
    syncSlugWithTitle,
  }
}
