import type { ContentWizardSlugMode } from '~/types/content-wizard'

export function useContentWizardSlug() {
  const slugify = (value: string) => {
    return (
      value
        .normalize('NFKD')
        .replace(/@/g, '-at-')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        // NFKD plus combining-mark stripping folds every other umlaut but cannot
        // reach \u00df, so it needs its own rule to keep the output ASCII.
        .replace(/\u00df/g, 'ss')
        // Path and pattern punctuation separates words \u2014 stripping it silently
        // glued '{lang}/about' into 'langabout'.
        .replace(/[{}[\]()/\\|]+/g, '-')
        .replace(/[^\p{Letter}\p{Number}\s_-]+/gu, '')
        .replace(/[-\s]+/g, '-')
        .replace(/^-+|-+$/g, '')
    )
  }

  const resolveSlugMode = (title: string, slug: string): ContentWizardSlugMode => {
    if (!slug.trim()) {
      return 'auto'
    }

    return slugify(title) === slugify(slug) ? 'auto' : 'manual'
  }

  const syncSlugWithTitle = (
    title: string,
    currentSlug: string,
    mode: ContentWizardSlugMode
  ): { slug: string; slugMode: ContentWizardSlugMode } => {
    if (mode === 'manual') {
      return {
        slug: currentSlug,
        slugMode: currentSlug.trim() ? 'manual' : 'auto',
      }
    }

    return {
      slug: slugify(title),
      slugMode: 'auto',
    }
  }

  // The explicit slug is normalized too: it ends up in the create/update payload
  // verbatim, so whatever the user pastes must go through the same hygiene as an
  // auto slug. A slug that normalizes to nothing stays empty and fails validation
  // rather than quietly falling back to the title.
  const resolveEffectiveSlug = (title: string, slug: string) =>
    slug.trim() ? slugify(slug) : slugify(title)

  return {
    slugify,
    resolveSlugMode,
    resolveEffectiveSlug,
    syncSlugWithTitle,
  }
}
