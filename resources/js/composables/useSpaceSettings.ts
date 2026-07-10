import { useStorage } from '@vueuse/core'

export default function useSpaceSettings(spaceId: string) {
  const defaults = {
    content: {
      environment: null,
      siteLocale: null as string | null,
      treeWidth: 20,
      showPreview: true,
      history: {
        mode: 'changes',
        panelHeight: 60,
      },
      expanded: [] as string[],
    },
    blocks: {
      pageSize: 25,
    },
    assets: {
      gridSize: 'md' as 'sm' | 'md' | 'lg',
      pageSize: 24,
      gridFolders: true,
      expanded: [] as string[],
      visibleFields: [] as string[],
      visibleLanguages: [] as string[],
      lastDialogFolderId: undefined as string | null | undefined,
      autoSave: true,
    },
    dataEntries: {
      mode: 'single',
      autoSave: true,
    },
  }

  const settings = useStorage(`space-${unref(spaceId)}-settings`, defaults, undefined, {
    mergeDefaults: true,
  })

  return {
    // state
    settings,

    // methods
    reset() {
      settings.value = defaults
    },
  }
}
