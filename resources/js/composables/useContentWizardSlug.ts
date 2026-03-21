import type { ContentWizardSlugMode } from '~/types/content-wizard'

export function useContentWizardSlug() {
  const slugify = (value: string) => {
    return value
      .normalize('NFKD')
      .replace(/@/g, '-at-')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/[^\p{Letter}\p{Number}\s_-]+/gu, '')
      .replace(/[-\s]+/g, '-')
      .replace(/^-+|-+$/g, '')
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

  const resolveEffectiveSlug = (title: string, slug: string) => slug.trim() || slugify(title)

  return {
    slugify,
    resolveSlugMode,
    resolveEffectiveSlug,
    syncSlugWithTitle,
  }
}
