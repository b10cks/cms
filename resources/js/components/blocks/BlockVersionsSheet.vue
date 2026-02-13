<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import dayjs from 'dayjs'
import { RadioGroupItem, RadioGroupRoot } from 'reka-ui'
import BlockEdit from '~/components/BlockEdit.vue'
import { Avatar } from '~/components/ui/avatar'
import { Button } from '~/components/ui/button'
import RenamableTitle from '~/components/ui/RenamableTitle.vue'

import { ResizableHandle, ResizablePanel, ResizablePanelGroup } from '~/components/ui/resizable'
import { ScrollArea } from '~/components/ui/scroll-area'
import { Sheet, SheetContent, SheetHeaderCombined } from '~/components/ui/sheet'
import { SimpleTooltip } from '~/components/ui/tooltip'

const open = defineModel<boolean>('open')

const props = defineProps<{
  spaceId: string
  block: BlockResource
}>()

const { formatDateTime, formatDateTimeDynamically } = useFormat()
const { user } = useAuth()
const { $t } = useI18n()

const selectedVersionId = ref<string | null>(null)
const selectedTab = ref('preview')

const {
  useBlockVersionsQuery,
  useBlockVersionQuery,
  useUpdateBlockVersionMutation,
  useRestoreBlockVersionMutation,
  useDeleteBlockVersionMutation,
} = useBlockVersions(
  () => props.spaceId,
  () => props.block.id
)

const { data: versions, isLoading } = useBlockVersionsQuery()
const { data: selectedVersionData } = useBlockVersionQuery(selectedVersionId)

const { mutate: updateVersion } = useUpdateBlockVersionMutation()
const { mutate: restoreVersion, isPending: isRestoring } = useRestoreBlockVersionMutation()
const { mutate: deleteVersion } = useDeleteBlockVersionMutation()

const { alert } = useAlertDialog()

const groupedVersions = computed(() => {
  if (!versions.value) return {}

  const groups: Record<string, BlockVersion[]> = {
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

  versions.value.forEach((version) => {
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

  Object.keys(groups).forEach((key) => {
    if (groups[key].length === 0) {
      delete groups[key]
    }
  })

  return groups
})

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

const getVersionIndentLevel = (versionId: string): number => {
  let level = 0
  let currentId = versionId

  while (true) {
    const version = versions.value?.find((v) => v.id === currentId)
    if (!version?.parent_id) break

    const siblings = versionTreeMap.value.get(version.parent_id) || []
    if (siblings.indexOf(currentId) > 0) {
      level++
    }

    currentId = version.parent_id
  }

  return level
}

const selectVersion = (version: BlockVersion) => {
  selectedVersionId.value = version.id
}

const handleRestore = async (versionId: string) => {
  await alert.confirm($t('labels.blockVersions.restore.message'), {
    title: $t('labels.blockVersions.restore.title'),
    confirmLabel: $t('labels.blockVersions.restore.confirmLabel'),
    onConfirm: () => {
      restoreVersion(versionId)
    },
  })
}

const handleDelete = async (version: BlockVersion) => {
  await alert.confirm(
    $t('labels.blockVersions.delete.message', { date: formatDateTime(version.created_at) }),
    {
      title: $t('labels.blockVersions.delete.title'),
      confirmLabel: $t('labels.blockVersions.delete.confirmLabel'),
      variant: 'destructive',
      onConfirm: () => {
        deleteVersion(version.id)
      },
    }
  )
}

const handleUpdateMessage = (id: string, message: string | null | undefined) => {
  updateVersion({ id, payload: { commit_message: message } })
}

const getGroupLabel = (groupKey: string) => {
  switch (groupKey) {
    case 'today':
      return $t('labels.blockVersions.groups.today')
    case 'yesterday':
      return $t('labels.blockVersions.groups.yesterday')
    case 'thisWeek':
      return $t('labels.blockVersions.groups.thisWeek')
    case 'lastWeek':
      return $t('labels.blockVersions.groups.lastWeek')
    case 'older':
      return $t('labels.blockVersions.groups.older')
    default:
      return groupKey
  }
}

const isMine = (version: BlockVersion) => {
  return version.created_by?.id === user.value?.id
}

const selectedVersion = computed(() => {
  if (!selectedVersionId.value || !versions.value) return null
  return versions.value.find((v) => v.id === selectedVersionId.value) || null
})

onMounted(() => {
  if (versions.value && versions.value.length > 0) {
    selectedVersionId.value = versions.value[0].id
  }
})

watch(
  versions,
  (newVersions) => {
    if (newVersions?.length && !selectedVersionId.value) {
      selectedVersionId.value = newVersions[0].id
    }
  },
  { immediate: true }
)
</script>

<template>
  <Sheet
    :open="open"
    @update:open="open = $event"
  >
    <SheetContent class="sm:max-w-2xl">
      <SheetHeaderCombined :title="$t('labels.blockTemplates.manageTitle')" />
      <ResizablePanelGroup
        direction="vertical"
        class="h-full min-h-0"
      >
        <ResizablePanel>
          <div class="flex h-full min-h-0 flex-col gap-4">
            <div class="flex items-center justify-between">
              <h2 class="text-lg font-semibold">{{ $t('labels.blockVersions.title') }}</h2>
            </div>
            <ScrollArea class="flex-1 rounded-md bg-surface">
              <div
                v-if="isLoading"
                class="flex h-32 items-center justify-center"
              >
                <Icon
                  name="lucide:loader"
                  class="animate-spin"
                />
                <span>{{ $t('labels.blockVersions.loading') }}</span>
              </div>

              <div
                v-else-if="!versions?.length"
                class="p-4 text-center text-muted"
              >
                {{ $t('labels.blockVersions.noVersions') }}
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
                      class="group ring-none relative z-10 flex items-center rounded-md px-3 py-1 transition-colors outline-none"
                      :class="{
                        'bg-secondary/50': selectedVersionId === version.id,
                        'cursor-pointer hover:bg-secondary/20': selectedVersionId !== version.id,
                      }"
                      @select="selectVersion(version)"
                    >
                      <div
                        v-if="getVersionIndentLevel(version.id) > 0"
                        :style="`width: ${getVersionIndentLevel(version.id) * 16}px`"
                        class="shrink-0"
                      />
                      <div
                        class="z-10 mr-3 h-3 w-3 shrink-0 rounded-full border-2 border-border bg-background"
                        :style="{
                          anchorName: `--v${version.id}`,
                        }"
                      />
                      <div class="flex flex-1 grow items-center">
                        <div class="flex grow items-center justify-start gap-2">
                          <RenamableTitle
                            :name="version.commit_message"
                            :fallback="$t('labels.blockVersions.noCommitMessage')"
                            class="text-left"
                            :disabled="!isMine(version)"
                            @update="handleUpdateMessage(version.id, $event)"
                          />
                        </div>

                        <div class="ml-auto flex items-center gap-2">
                          <SimpleTooltip
                            v-if="version.created_by"
                            :tooltip="version.created_by.email"
                            class="flex items-center gap-2"
                          >
                            <Avatar
                              :name="version.created_by.name"
                              :avatar="version.created_by.avatar"
                              size="sm"
                            />
                            <span>{{ version.created_by.name }}</span>
                          </SimpleTooltip>
                          <span v-else>{{ $t('labels.system') }}</span>
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
                        <SimpleTooltip :tooltip="$t('labels.blockVersions.restoreTooltip')">
                          <Button
                            variant="ghost"
                            size="icon"
                            class="h-6! w-6!"
                            :disabled="isRestoring"
                            @click.stop="handleRestore(version.id)"
                          >
                            <Icon name="lucide:rotate-ccw" />
                          </Button>
                        </SimpleTooltip>

                        <SimpleTooltip :tooltip="$t('labels.blockVersions.deleteTooltip')">
                          <Button
                            variant="ghost"
                            size="icon"
                            class="h-6! w-6!"
                            @click.stop="handleDelete(version)"
                          >
                            <Icon name="lucide:trash-2" />
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
          :default-size="50"
          class="flex h-full flex-col"
        >
          <ScrollArea>
            <div
              v-if="!selectedVersion"
              class="flex h-full items-center justify-center text-muted"
            >
              {{ $t('labels.blockVersions.selectVersion') }}
            </div>
            <div
              v-else
              class="flex h-full flex-1 flex-col"
            >
              <BlockEdit
                :readonly="true"
                :space-id="spaceId"
                :block="selectedVersion.data"
                show-schema
              />
            </div>
          </ScrollArea>
        </ResizablePanel>
      </ResizablePanelGroup>
    </SheetContent>
  </Sheet>
</template>
