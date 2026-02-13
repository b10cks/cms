import { useStorage } from '@vueuse/core'

export default function useSpaceSettings(spaceId: string) {
  const defaults = {
    content: {
      environment: null,
      treeWidth: 20,
      showPreview: true,
      history: {
        mode: 'changes',
        panelHeight: 60,
      },
      expanded: [],
    },
    assets: {
      gridSize: 'md',
      gridFolders: true,
      expanded: [],
      visibleFields: [],
      visibleLanguages: [],
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
