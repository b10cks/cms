<script setup lang="ts">
import dayjs from 'dayjs'
import { RadioGroupItem, RadioGroupRoot } from 'reka-ui'
import type { Ref } from 'vue'
import { RouterLink } from 'vue-router'

import { type DiffChange } from '~/components/content/diff/field-diff'
import DiffViewer from '~/components/content/DiffViewer.vue'
import Icon from '~/components/Icon.vue'
import SearchFilter from '~/components/SearchFilter.vue'
import { Avatar } from '~/components/ui/avatar'
import { Badge, SplitBadge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import RenamableTitle from '~/components/ui/RenamableTitle.vue'
import { ResizableHandle, ResizablePanel, ResizablePanelGroup } from '~/components/ui/resizable'
import { ScrollArea } from '~/components/ui/scroll-area'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '~/components/ui/tabs'
import { SimpleTooltip } from '~/components/ui/tooltip'
import { buildPreviewUrl } from '~/lib/preview-url'
import type {
  ContentResource,
  ContentVersionDiffEntry,
  ContentVersionListResource,
  ContentVersionResource,
} from '~/types/contents'

const { $t } = useI18n()

const { formatDateTime, formatRelativeTime, formatCalendarTime, formatDateTimeDynamically } =
  useFormat()
const { user } = useAuth()
const route = useRoute()
const router = useRouter()

const props = defineProps<{
  spaceId: string
  content: ContentResource
}>()

const { settings } = useSpaceSettings(props.spaceId)
const { useSpaceQuery } = useSpaces()
const { data: currentSpace } = useSpaceQuery(props.spaceId) as { data: Ref<SpaceResource | null> }
const { apiToken, openContentJsonInNewTab } = useContentJson(
  props.spaceId,
  computed(() => props.content)
)
const searchQuery = ref('')
const filterOptions = ref({})

const contentId = computed(() => props.content.id)
const selectedVersionId = computed<string | null>(() => {
  const versionId = route.query?.versionId

  if (typeof versionId === 'string') {
    return versionId
  }

  return null
})

const selectedTab = computed({
  get: () => {
    const mode = route.query?.mode
    return typeof mode === 'string' ? mode : settings.value.content.history.mode
  },
  set: (mode) => {
    if (mode) {
      router.replace({ ...route, query: { ...route.query, mode } })
      settings.value.content.history.mode = mode as string
    } else {
      router.replace({ ...route, query: { ...route.query, mode: undefined } })
    }
  },
})

// Get content versions data
const {
  useContentVersionsQuery,
  useContentVersionQuery,
  useUpdateVersionMutation,
  useSetCurrentVersionMutation,
  usePublishVersionMutation,
} = useContentVersions(props.spaceId, contentId)

const { data: versions, isLoading, error } = useContentVersionsQuery()

const { data: selectedVersionData } = useContentVersionQuery(selectedVersionId)

const { mutate: setCurrentVersion, isPending: isSettingCurrent } = useSetCurrentVersionMutation()
const { mutate: publishVersion, isPending: isPublishing } = usePublishVersionMutation()
const { mutate: updateVersion, isPending: isUpdating } = useUpdateVersionMutation()

const { useRemoveVersionsMutation } = useReleases(props.spaceId)
const { mutate: removeVersions, isPending: isRemovingVersion } = useRemoveVersionsMutation()
const { alert } = useAlertDialog()

const handleRemoveFromReleaseClick = async (version: ContentVersionListResource) => {
  if (version.release) {
    await alert.confirm(
      $t('labels.contentVersions.removeFromRelease.message', { name: version.release.name }),
      {
        title: $t('labels.contentVersions.removeFromRelease.title'),
        confirmLabel: $t('labels.contentVersions.removeFromRelease.confirmLabel'),
        variant: 'destructive',
        onConfirm: () => {
          removeVersions({
            releaseId: version.release!.id,
            payload: { version_ids: [version.id] },
          })
        },
      }
    )
  }
}

// Compute date-based groups for the versions
const groupedVersions = computed(() => {
  if (!versions.value) return {}

  const filtered = versions.value.filter((version) => {
    // Apply search query filter if any
    if (!searchQuery.value) return true
    return (
      version.message?.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
      version.author?.name.toLowerCase().includes(searchQuery.value.toLowerCase())
    )
  })

  const groups: Record<string, ContentVersionListResource[]> = {
    today: [],
    yesterday: [],
    thisWeek: [],
    lastWeek: [],
    older: [],
  }

  const now = dayjs()
  const yesterday = now.subtract(1, 'day').startOf('day')
  const startOfWeek = now.startOf('week')
  const startOfLastWeek = startOfWeek.subtract(1, 'week')

  filtered.forEach((version) => {
    const versionDate = dayjs(version.created_at)

    if (versionDate.isAfter(now.startOf('day'))) {
      groups.today.push(version)
    } else if (versionDate.isAfter(yesterday)) {
      groups.yesterday.push(version)
    } else if (versionDate.isAfter(startOfWeek)) {
      groups.thisWeek.push(version)
    } else if (versionDate.isAfter(startOfLastWeek)) {
      groups.lastWeek.push(version)
    } else {
      groups.older.push(version)
    }
  })

  // Remove empty groups
  Object.keys(groups).forEach((key) => {
    if (groups[key].length === 0) {
      delete groups[key]
    }
  })

  return groups
})

// Create a map of parent-child relationships
const versionTreeMap = computed(() => {
  if (!versions.value) return new Map()

  const map = new Map<string, string[]>()

  versions.value.forEach((version) => {
    if (version.parent_id) {
      if (!map.has(version.parent_id)) {
        map.set(version.parent_id, [])
      }
      map.get(version.parent_id)?.push(version.id)
    }
  })

  return map
})

// Calculate indentation level for each version
const getVersionIndentLevel = (versionId: string): number => {
  let level = 0
  let currentId = versionId

  while (true) {
    const version = versions.value?.find((v) => v.id === currentId)
    if (!version?.parent_id) break

    // Check if this is not the first child of its parent
    const siblings = versionTreeMap.value.get(version.parent_id) || []
    if (siblings.indexOf(currentId) > 0) {
      level++
    }

    currentId = version.parent_id
  }

  return level
}

const handleSetAsCurrent = (versionId: string) => {
  setCurrentVersion(versionId)
}

const handlePublishVersion = (versionId: string) => {
  publishVersion(versionId)
}

const handleUpdateMessage = (id: string, message: string | null | undefined) => {
  updateVersion({ id, payload: { message } })
}

// Get group label
const getGroupLabel = (groupKey: string) => {
  switch (groupKey) {
    case 'today':
      return $t('labels.contentVersions.groups.today')
    case 'yesterday':
      return $t('labels.contentVersions.groups.yesterday')
    case 'thisWeek':
      return $t('labels.contentVersions.groups.thisWeek')
    case 'lastWeek':
      return $t('labels.contentVersions.groups.lastWeek')
    case 'older':
      return $t('labels.contentVersions.groups.older')
    default:
      return groupKey
  }
}

onMounted(() => {
  if (!selectedVersionId.value && versions.value && versions.value.length > 0) {
    router.replace({
      ...route,
      query: {
        ...route.query,
        versionId: versions.value[0].id,
      },
    })
  }
})

const versionFilterFields = [
  { id: 'message', label: 'Message' },
  { id: 'author', label: 'Author' },
  { id: 'published', label: 'Published' },
  { id: 'created_at', label: 'Date', datepicker: { max: new Date().toISOString() } },
]

const getIndicatorClass = (version: ContentVersionListResource) => {
  if (wasPublished(version)) {
    return 'border-success bg-success-background'
  }
  if (isScheduled(version)) {
    return 'border-warning bg-warning-background'
  }
  return 'border-border bg-background'
}

const isCurrentDraft = (version: ContentVersionListResource) => {
  return version.id === props.content.current_version_id
}

const wasPublished = (version: ContentVersionListResource) => {
  return !!version.published_at
}

const isScheduled = (version: ContentVersionListResource) => {
  return !!version.scheduled_at
}

const isCurrentlyPublished = (version: ContentVersionListResource) => {
  return version.id === props.content.published_version_id
}

const isMine = (version: ContentVersionListResource) => {
  return version.author?.id === user.value?.id
}

const selectedVersion = computed(() => {
  if (!selectedVersionId.value || !versions.value) return null
  return versions.value.find((v) => v.id === selectedVersionId.value) || null
})

const selectedVersionDetail = computed<ContentVersionResource | null>(() => {
  if (!selectedVersionData.value) return null
  return selectedVersionData.value as ContentVersionResource
})

const effectiveEnvironment = computed<SpaceEnvironment | null>(() => {
  const userEnv = settings.value.content.environment as SpaceEnvironment | null
  if (userEnv) return userEnv

  const defaultName = currentSpace.value?.settings.default_environment
  if (!defaultName) return null

  return currentSpace.value?.settings.environments?.find((e) => e.name === defaultName) ?? null
})

const toDiffChange = (entry: ContentVersionDiffEntry): DiffChange => ({
  path: entry.path,
  type:
    entry.type === 'added' || entry.type === 'removed' || entry.type === 'changed'
      ? entry.type
      : 'changed',
  oldValue: entry.old_value,
  newValue: entry.new_value,
  fieldType: entry.field_type,
  children: entry.children?.map(toDiffChange),
})

const previewSource = computed(() => {
  if (!selectedVersion.value) return null

  const envUrl = effectiveEnvironment.value?.url
  if (!envUrl) return null

  const url = buildPreviewUrl(
    envUrl,
    currentSpace.value?.settings,
    props.content.language_iso,
    props.content.full_slug
  )
  if (!url) return null

  return `${url}?b10cks_rv=${new Date(selectedVersion.value.created_at).getTime()}&b10cks_vid=${selectedVersion.value.id}`
})

const openInTab = () => {
  if (!previewSource.value) return
  window.open(previewSource.value, '_blank')
}

const openVersionJsonInNewTab = (versionId: string) => {
  openContentJsonInNewTab(versionId)
}
</script>

<template>
  <div class="flex h-full w-full flex-col p-6">
    <ResizablePanelGroup
      direction="vertical"
      class="h-full min-h-0"
    >
      <ResizablePanel>
        <div class="flex h-full min-h-0 flex-col gap-4">
          <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold">{{ $t('labels.contentVersions.title') }}</h2>
            <div class="flex w-2/3 gap-2">
              <SearchFilter
                v-model="filterOptions"
                :filterable-fields="versionFilterFields"
                @search="searchQuery = $event"
                @reset="searchQuery = ''"
              />
            </div>
          </div>

          <ScrollArea class="flex-1 rounded-md bg-surface">
            <div
              v-if="isLoading"
              class="flex h-32 items-center justify-center"
            >
              <Icon
                name="lucide:loader"
                class="mr-2 h-6 w-6 animate-spin"
              />
              <span>{{ $t('labels.contentVersions.loading') }}</span>
            </div>

            <div
              v-else-if="error"
              class="p-4 text-destructive"
            >
              {{ error }}
            </div>

            <div
              v-else-if="!versions?.length"
              class="p-4 text-center text-muted"
            >
              {{ $t('labels.contentVersions.noVersions') }}
            </div>

            <RadioGroupRoot
              v-else
              class="relative p-2"
            >
              <div
                v-for="(groupVersions, groupKey) in groupedVersions"
                :key="groupKey"
                class="mb-2"
              >
                <div class="relative z-10 flex items-center px-2">
                  <div
                    class="bg-surface py-1 text-xs font-medium tracking-wider text-muted uppercase"
                  >
                    {{ getGroupLabel(groupKey) }}
                  </div>
                  <div class="ml-2 h-px flex-1 bg-border" />
                </div>

                <div class="grid">
                  <div
                    v-for="version in groupVersions"
                    :key="`line-${version.id}`"
                  >
                    <div
                      v-if="version.parent_id"
                      class="absolute z-0 border-3 border-transparent border-t-border border-l-border"
                      :style="{
                        borderTopLeftRadius: `${getVersionIndentLevel(version.id) ? 12 : 0}px`,
                        top: `calc(anchor(--v${version.id} center) - 1px)`,
                        left: `calc(anchor(--v${version.parent_id} center) - 1.5px)`,
                        bottom: `anchor(--v${version.parent_id} center)`,
                        right: `anchor(--v${version.id} center)`,
                      }"
                    />
                  </div>
                  <RadioGroupItem
                    v-for="version in groupVersions"
                    :key="version.id"
                    :as="RouterLink"
                    :to="{
                      name: 'space-content-contentId-versions',
                      params: { space: spaceId, contentId: contentId },
                      query: { ...route.query, versionId: version.id },
                    }"
                    class="group ring-none relative z-10 flex items-center rounded-md px-3 py-1 transition-colors outline-none"
                    :class="{
                      'bg-secondary/50': selectedVersionId === version.id,
                      'cursor-pointer hover:bg-secondary/20': selectedVersionId !== version.id,
                    }"
                  >
                    <div
                      v-if="getVersionIndentLevel(version.id) > 0"
                      :style="`width: ${getVersionIndentLevel(version.id) * 16}px`"
                      class="shrink-0"
                    />
                    <div
                      class="z-10 mr-3 h-3 w-3 shrink-0 rounded-full border-2"
                      :class="getIndicatorClass(version)"
                      :style="{
                        anchorName: `--v${version.id}`,
                      }"
                    />
                    <div class="flex flex-1 grow items-center">
                      <div class="flex grow items-center justify-start gap-2">
                        <div class="flex gap-1">
                          <Badge
                            v-if="isCurrentDraft(version)"
                            variant="default"
                            size="sm"
                          >
                            {{ $t('labels.contentVersions.badges.draft') }}
                          </Badge>
                          <SimpleTooltip
                            v-if="isScheduled(version) && version.scheduled_at"
                            :tooltip="formatDateTime(version.scheduled_at)"
                          >
                            <Badge
                              v-if="version.published_at"
                              variant="warning"
                              size="sm"
                            >
                              {{ $t('labels.contentVersions.badges.scheduled') }}
                            </Badge>
                            <SplitBadge
                              v-else
                              variant="secondary"
                              label-variant="warning"
                              size="sm"
                              :label="$t('labels.contentVersions.badges.scheduled')"
                            >
                              {{ formatDateTimeDynamically(version.scheduled_at, 7) }}
                            </SplitBadge>
                          </SimpleTooltip>
                          <SimpleTooltip
                            v-if="isCurrentlyPublished(version) && version.published_at"
                            :tooltip="formatDateTime(version.published_at)"
                          >
                            <SplitBadge
                              label-variant="success"
                              variant="secondary"
                              size="sm"
                              :label="$t('labels.contentVersions.badges.published')"
                            >
                              <time>{{ formatDateTimeDynamically(version.published_at, 14) }}</time>
                            </SplitBadge>
                          </SimpleTooltip>
                          <SplitBadge
                            v-if="version.release"
                            size="sm"
                            variant="accent"
                            label-variant="secondary"
                            :label="$t('labels.contentVersions.badges.release')"
                            removable
                            @remove="handleRemoveFromReleaseClick(version)"
                          >
                            <Icon
                              name="lucide:rocket"
                              size="0.75rem"
                            />
                            <span class="truncate">{{ version.release.name }}</span>
                          </SplitBadge>
                        </div>
                        <RenamableTitle
                          :name="version.message || $t('labels.contentVersions.noMessage')"
                          :fallback="$t('labels.contentVersions.noMessage')"
                          class="text-left"
                          :disabled="!isMine(version)"
                          @update="handleUpdateMessage(version.id, $event)"
                        />
                      </div>

                      <div class="ml-auto flex items-center gap-2">
                        <div
                          v-if="version.author"
                          class="flex items-center gap-2"
                        >
                          <Avatar
                            :name="version.author.name"
                            :avatar="version.author.avatar"
                            size="sm"
                          />
                          <span>{{ version.author.name }}</span>
                        </div>
                        <span v-else>System</span>
                        <span>•</span>
                        <SimpleTooltip :tooltip="formatDateTime(version.created_at)">
                          <time class="text-primary">{{
                            formatDateTimeDynamically(version.created_at, 14)
                          }}</time>
                        </SimpleTooltip>
                      </div>
                    </div>
                    <div
                      class="ml-2 flex gap-1 transition-opacity"
                      :class="{
                        'opacity-100': selectedVersionId === version.id,
                        'opacity-0 group-hover:opacity-100': selectedVersionId !== version.id,
                      }"
                    >
                      <SimpleTooltip :tooltip="$t('labels.contentVersions.actions.setCurrent')">
                        <Button
                          variant="ghost"
                          size="icon"
                          class="h-6! w-6!"
                          :disabled="isCurrentDraft(version) || isSettingCurrent"
                          @click.prevent.stop="handleSetAsCurrent(version.id)"
                        >
                          <Icon name="lucide:square-pen" />
                        </Button>
                      </SimpleTooltip>

                      <SimpleTooltip :tooltip="$t('labels.contentVersions.actions.showJson')">
                        <Button
                          variant="ghost"
                          size="icon"
                          class="h-6! w-6!"
                          :disabled="!apiToken"
                          @click.prevent.stop="openVersionJsonInNewTab(version.id)"
                        >
                          <Icon name="lucide:braces" />
                        </Button>
                      </SimpleTooltip>

                      <SimpleTooltip :tooltip="$t('labels.contentVersions.actions.publish')">
                        <Button
                          variant="ghost"
                          size="icon"
                          class="h-6! w-6!"
                          :disabled="isCurrentlyPublished(version) || isPublishing"
                          @click.prevent.stop="handlePublishVersion(version.id)"
                        >
                          <Icon name="lucide:send" />
                        </Button>
                      </SimpleTooltip>
                    </div>
                  </RadioGroupItem>
                </div>
              </div>
            </RadioGroupRoot>
          </ScrollArea>
        </div>
      </ResizablePanel>

      <ResizableHandle
        visible
        with-handle
        class="my-2"
      />

      <ResizablePanel
        :default-size="settings.content.history.panelHeight"
        class="flex h-full flex-col"
        @resize="(size) => (settings.content.history.panelHeight = size)"
      >
        <div
          v-if="!selectedVersion"
          class="flex h-full items-center justify-center text-muted"
        >
          {{ $t('labels.contentVersions.selectVersion') }}
        </div>
        <div
          v-else
          class="flex h-full flex-1 flex-col"
        >
          <Tabs
            v-model="selectedTab"
            class="flex h-full w-full flex-col"
          >
            <div class="my-3 flex items-center justify-between">
              <div>
                <h3 class="text-lg font-medium text-primary">
                  {{ formatCalendarTime(selectedVersion.created_at) }} {{ selectedVersion.message }}
                </h3>
                <div class="flex items-center gap-2 text-sm">
                  <Icon name="lucide:git-commit" />
                  <span>{{ $t('labels.contentVersions.changesFromPrevious') }}</span>
                </div>
              </div>
              <TabsList>
                <TabsTrigger value="changes">{{
                  $t('labels.contentVersions.tabs.changes')
                }}</TabsTrigger>
                <TabsTrigger value="visual">{{
                  $t('labels.contentVersions.tabs.visual')
                }}</TabsTrigger>
              </TabsList>
            </div>
            <TabsContent
              value="changes"
              class="flex h-full flex-col gap-4 overflow-hidden rounded-md"
            >
              <ScrollArea class="h-full flex-1 rounded-lg bg-surface p-4">
                <DiffViewer
                  v-if="selectedVersionDetail?.diff"
                  :changes="selectedVersionDetail.diff.entries.map(toDiffChange)"
                />
              </ScrollArea>
            </TabsContent>
            <TabsContent
              value="visual"
              class="grid grow"
            >
              <div class="flex grow flex-col gap-4">
                <div class="flex items-center justify-between">
                  <div class="mt-2 text-xs text-muted">
                    {{
                      $t('labels.contentVersions.previewingVersionFrom', {
                        date: formatCalendarTime(selectedVersion.created_at),
                      })
                    }}
                  </div>
                  <Button
                    variant="outline"
                    size="sm"
                    @click="openInTab"
                  >
                    <Icon name="lucide:external-link" />
                    {{ $t('actions.open') }}
                  </Button>
                </div>
                <div class="flex grow overflow-hidden rounded-lg bg-surface">
                  <iframe
                    v-if="previewSource"
                    class="flex-1 grow bg-white"
                    :title="$t('labels.contentVersions.previewTitle')"
                    :src="previewSource"
                  />
                </div>
              </div>
            </TabsContent>
          </Tabs>
        </div>
      </ResizablePanel>
    </ResizablePanelGroup>
  </div>
</template>
