import { useStorage } from '@vueuse/core'

type PlainObject = Record<string, unknown>

const isPlainObject = (value: unknown): value is PlainObject =>
  typeof value === 'object' && value !== null && !Array.isArray(value)

/**
 * `mergeDefaults: true` only merges the top level, so a section stored before a
 * nested key existed keeps overriding the whole section and every later default
 * reads back `undefined`. Merge section by section instead; arrays and non-object
 * values are replaced wholesale by whatever was stored.
 */
const mergeStoredSettings = <T>(defaults: T, stored: unknown): T => {
  if (!isPlainObject(defaults) || !isPlainObject(stored)) {
    return stored === undefined ? defaults : (stored as T)
  }

  const merged: PlainObject = { ...defaults }

  for (const [key, value] of Object.entries(stored)) {
    merged[key] = key in defaults ? mergeStoredSettings(defaults[key], value) : value
  }

  return merged as T
}

// A factory, not a shared literal: `useStorage` keeps the object it is given and
// the first write would otherwise mutate the defaults through the reactive proxy,
// leaving `reset()` with nothing to restore.
const createDefaults = () => ({
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
})

export default function useSpaceSettings(spaceId: string) {
  const settings = useStorage(`space-${unref(spaceId)}-settings`, createDefaults(), undefined, {
    mergeDefaults: (storageValue, defaults) => mergeStoredSettings(defaults, storageValue),
  })

  return {
    // state
    settings,

    // methods
    reset() {
      settings.value = createDefaults()
    },
  }
}
