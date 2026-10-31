import { aiFocusedField } from '~/lib/aiFocusedField'
import type { AiThreadChip } from '~/lib/aiThreads'

const param = (value: unknown) => (typeof value === 'string' && value ? value : null)

/**
 * The current screen as thread chips: the open entry and the field last
 * focused in it, the data source, the block, or the asset and folder open in
 * the asset manager. Named from the caches the space pages already fill.
 */
export function useAiScreenChips(spaceId: MaybeRefOrGetter<string>) {
  const route = useRoute()
  const { t } = useI18n()
  const id = computed(() => toValue(spaceId))

  const contentId = computed(() => param(route.params.contentId))
  const dataSourceId = computed(() => param(route.params.dataSourceId))
  const blockId = computed(() => (route.name === 'space-block' ? param(route.params.block) : null))
  const onAssets = computed(() => typeof route.name === 'string' && route.name.startsWith('space-assets'))
  const assetId = computed(() => (onAssets.value ? param(route.query.asset) : null))
  const folderId = computed(() => (onAssets.value ? param(route.query.folder) : null))

  const { useContentMenuQuery } = useContentMenu(id)
  const { data: menu } = useContentMenuQuery(computed(() => !!contentId.value))
  const { useDataSourceQuery } = useDataSources(id)
  const { data: dataSource } = useDataSourceQuery(
    () => dataSourceId.value ?? '',
    computed(() => !!dataSourceId.value)
  )
  const { useBlockQuery } = useBlocks(id)
  const { data: block } = useBlockQuery(
    computed(() => blockId.value ?? ''),
    computed(() => !!blockId.value)
  )
  const { useAssetQuery } = useAssets(id)
  const { data: asset } = useAssetQuery(
    computed(() => assetId.value ?? ''),
    computed(() => !!assetId.value)
  )
  const { useAssetFolderQuery } = useAssetFolders(id)
  const { data: folder } = useAssetFolderQuery(
    () => folderId.value ?? '',
    computed(() => !!folderId.value)
  )

  return computed<AiThreadChip[]>(() => {
    if (contentId.value) {
      const label = menu.value?.[contentId.value]?.name ?? t('components.aiThreads.screen.entry')
      const chips: AiThreadChip[] = [{ type: 'content', id: contentId.value, label }]
      const focused = aiFocusedField.value
      if (focused?.contentId === contentId.value) {
        chips.push({ type: 'field', id: contentId.value, label: focused.label, field: focused.path })
      }
      return chips
    }
    if (dataSourceId.value) {
      const label = dataSource.value?.name ?? t('components.aiThreads.screen.dataSource')
      return [{ type: 'data_source', id: dataSourceId.value, label }]
    }
    if (blockId.value) {
      return [{ type: 'block', id: blockId.value, label: block.value?.name ?? t('components.aiThreads.screen.block') }]
    }
    const chips: AiThreadChip[] = []
    if (folderId.value) {
      chips.push({
        type: 'asset_folder',
        id: folderId.value,
        label: folder.value?.name ?? t('components.aiThreads.screen.folder'),
      })
    }
    if (assetId.value) {
      chips.push({
        type: 'asset',
        id: assetId.value,
        label: asset.value?.filename ?? t('components.aiThreads.screen.asset'),
      })
    }
    return chips
  })
}
