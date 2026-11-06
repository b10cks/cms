import type { AiOperationScope } from '~/lib/aiOperations'
import { languageName } from '~/lib/languageNames'

export interface OperationOption {
  key: string
  label: string
  /** The field's own name, when `label` also names its block */
  field?: string
}

/**
 * The name of a field an output part points at. A nested meta field's path holds
 * its block ID, not its slug, so it reads as the field's name and where it sits.
 */
export function fieldLabelOf(options: readonly OperationOption[], path: string): string {
  const exact = options.find((option) => option.key === path)
  if (exact) return exact.label

  const segments = path.split('.')
  if (segments.length === 1) return path
  const key = segments.at(-1) as string
  const option = options.find((candidate) => candidate.key === key || candidate.key.endsWith(`.${key}`))
  return `${option?.field ?? option?.label ?? key} (${segments.slice(0, -1).join(' ')})`
}

/** The fields an asset plan may name: those the space and the scope's folders enable, as the server validates them. */
export function useAssetFieldOptions(spaceId: string, scope: () => AiOperationScope) {
  const { folders, getEffectiveFields } = useAssetRequirements(spaceId)

  /** The scope's folders as the server walks them: the root and, when recursive, every folder below it. */
  const scopeFolders = computed<Array<string | null>>(() => {
    const all = folders.value ?? []
    const { root_id: root, recursive } = scope()
    if (!root) return [null, ...all.map((folder) => folder.id)]
    if (!recursive) return [null, root]

    const children = Map.groupBy(all, (folder) => folder.parent_id)
    const ids: string[] = []
    const queue = [root]
    while (queue.length > 0) {
      const id = queue.shift() as string
      ids.push(id)
      queue.push(...(children.get(id) ?? []).map((folder) => folder.id))
    }
    return [null, ...ids]
  })

  return computed<OperationOption[]>(() => {
    const byKey = new Map<string, string>()
    for (const folderId of scopeFolders.value) {
      for (const field of getEffectiveFields({ folderId })) if (!byKey.has(field.key)) byKey.set(field.key, field.label || field.key)
    }
    return [...byKey].map(([key, label]) => ({ key, label }))
  })
}

/**
 * The meta fields of the blocks in the scope, named as their schemas name them,
 * and those of blocks that nest in an entry, as `<block slug>.<field key>`.
 * Anything the list can't show still works: no choice means every field.
 */
export function useMetaFieldOptions(spaceId: string, groups: () => readonly string[], enabled: () => boolean) {
  const { useBlocksQuery } = useBlocks(spaceId)
  const { data } = useBlocksQuery({ per_page: 100 }, computed(enabled))

  return computed<OperationOption[]>(() => {
    const slugs = new Set(groups())
    const byKey = new Map<string, OperationOption>()
    for (const block of data.value?.data ?? []) {
      const nested = block.type === 'nestable'
      if (!nested && !slugs.has(block.slug)) continue
      for (const [key, field] of Object.entries(block.schema ?? {})) {
        if (field.type !== 'meta') continue
        const name = field.name || key
        const option = nested ? { key: `${block.slug}.${key}`, label: `${block.name}: ${name}`, field: name } : { key, label: name }
        if (!byKey.has(option.key)) byKey.set(option.key, option)
      }
    }
    return [...byKey.values()]
  })
}

/** Language options of a space; asset data stores its default language as `_default`, content by its code. */
export function useOperationLanguages(spaceId: string, defaultLabel: (name: string) => string) {
  const { locale } = useI18n()
  const { useSpaceQuery } = useSpaces()
  const { data: space } = useSpaceQuery(() => spaceId)

  const named = (code: string) => {
    const settings = space.value?.settings
    return code === '_default'
      ? defaultLabel(languageName(settings?.default_language ?? 'en', settings?.languages, locale.value))
      : languageName(code, settings?.languages, locale.value)
  }

  /** Every language of the space, the default first, keyed by content language code. */
  const contentLanguages = computed<OperationOption[]>(() => {
    const settings = space.value?.settings
    const fallback = settings?.default_language ?? 'en'
    return [
      { key: fallback, label: defaultLabel(languageName(fallback, settings?.languages, locale.value)) },
      ...(settings?.languages ?? []).map((language) => ({ key: language.code, label: named(language.code) })),
    ]
  })

  return { named, contentLanguages }
}
