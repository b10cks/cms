import type { AiThreadChip } from '~/lib/aiThreads'

/**
 * The current screen as thread chips: the open entry or data source, named
 * from the caches the space pages already fill.
 */
export function useAiScreenChips(spaceId: MaybeRefOrGetter<string>) {
  const route = useRoute()
  const { t } = useI18n()
  const id = computed(() => toValue(spaceId))

  const contentId = computed(() => (typeof route.params.contentId === 'string' ? route.params.contentId : null))
  const dataSourceId = computed(() =>
    typeof route.params.dataSourceId === 'string' ? route.params.dataSourceId : null
  )

  const { useContentMenuQuery } = useContentMenu(id)
  const { data: menu } = useContentMenuQuery(computed(() => !!contentId.value))
  const { useDataSourceQuery } = useDataSources(id)
  const { data: dataSource } = useDataSourceQuery(
    () => dataSourceId.value ?? '',
    computed(() => !!dataSourceId.value)
  )

  return computed<AiThreadChip[]>(() => {
    if (contentId.value) {
      const label = menu.value?.[contentId.value]?.name ?? t('components.aiThreads.screen.entry')
      return [{ type: 'content', id: contentId.value, label }]
    }
    if (dataSourceId.value) {
      const label = dataSource.value?.name ?? t('components.aiThreads.screen.dataSource')
      return [{ type: 'data_source', id: dataSourceId.value, label }]
    }
    return []
  })
}
