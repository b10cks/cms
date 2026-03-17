<script setup lang="ts">
import { ContentModel } from '~/api/resources/content-model'
import Icon from '~/components/Icon.vue'
import AssignToReleaseDialog from '~/components/releases/AssignToReleaseDialog.vue'
import { AvatarList } from '~/components/ui/avatar'
import { Button } from '~/components/ui/button'
import SplitButton from '~/components/ui/button/SplitButton.vue'
import {
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
} from '~/components/ui/dropdown-menu'
import { SimpleTooltip } from '~/components/ui/tooltip'
import type {
  CollaborationPresenceUser,
  ContentCommitAction,
} from '~/composables/useContentLiveCollaboration'
import type { ContentVersionListResource } from '~/types/contents'
import type { ContentResource } from '~/types/contents'

import PublishDialog from './PublishDialog.vue'

const route = useRoute()
const router = useRouter()
const props = defineProps<{
  spaceId: string
  content: ContentResource
  presentUsers?: CollaborationPresenceUser[]
  disabled?: boolean
  isDirty?: boolean
}>()


const commitPersistedContent = inject<
  ((content: ContentResource, action?: ContentCommitAction) => void) | undefined
>('commitPersistedContent', undefined)
const resetDirtyState = inject<(() => void) | undefined>('resetDirtyState', undefined)


const {
  useCreateContentMutation,
  useUpdateContentMutation,
  usePublishContentMutation,
  useScheduleContentMutation,
  useUnpublishContentMutation,
} = useContent(props.spaceId)


const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(props.spaceId)
const { apiToken, openContentJsonInNewTab } = useContentJson(props.spaceId)


const { useReleasesQuery, useAssignVersionsMutation, getReleaseState } = useReleases(props.spaceId)
const { data: releases } = useReleasesQuery()


const { mutate: assignVersions, isPending: isAssigning } = useAssignVersionsMutation()


const { mutateAsync: createContent } = useCreateContentMutation()
const { mutateAsync: updateContent } = useUpdateContentMutation()
const { mutateAsync: publishContent, isPending: isPublishing } = usePublishContentMutation()
const { mutateAsync: scheduleContent, isPending: isScheduling } = useScheduleContentMutation()
const { mutateAsync: unpublishContent } = useUnpublishContentMutation()


const isLocalization = computed(() => route.name === 'space-content-contentId-localization')
const isVersions = computed(() => route.name === 'space-content-contentId-versions')
const publishDialogOpen = ref(false)
const publishType = ref<'now' | 'schedule'>('now')
const assignReleaseDialogOpen = ref(false)
const selectedReleaseForAssign = ref<any>(null)
const contentModel = computed(() => new ContentModel(props.content))


const handlePersistedContent = (
  nextContent: ContentResource,
  action: ContentCommitAction = 'save'
) => {
  commitPersistedContent?.(nextContent, action)
  resetDirtyState?.()
}


const save = async () => {
  if (props.content.id) {
    const nextContent = await updateContent({ id: props.content.id, payload: props.content })
    handlePersistedContent(nextContent, 'save')
  } else {
    const nextContent = await createContent(props.content)
    handlePersistedContent(nextContent, 'save')
  }
}


const publishDirectly = async () => {
  const nextContent = await publishContent({ id: props.content.id, payload: props.content })
  handlePersistedContent(nextContent, 'publish')
}


const publishWithMessage = () => {
  publishType.value = 'now'
  publishDialogOpen.value = true
}


const schedulePublish = () => {
  publishType.value = 'schedule'
  publishDialogOpen.value = true
}


const handlePublish = async (payload: { message?: string; published_at?: string | null }) => {
  const publishPayload = {
    ...props.content,
    ...payload,
  }
  const nextContent = await publishContent({ id: props.content.id, payload: publishPayload })
  handlePersistedContent(nextContent, 'publish')
  publishDialogOpen.value = false
}


const handleSchedule = async (payload: { message?: string; scheduled_at?: string | null }) => {
  const schedulePayload = {
    ...props.content,
    message: payload.message,
    scheduled_at: payload.scheduled_at,
  }
  const nextContent = await scheduleContent({ id: props.content.id, payload: schedulePayload })
  handlePersistedContent(nextContent, 'schedule')
  publishDialogOpen.value = false
}


const unpublish = async () => {
  const nextContent = await unpublishContent({ id: props.content.id, payload: props.content })
  handlePersistedContent(nextContent, 'unpublish')
}


const switchLocalization = () => {
  router.push({
    name: isLocalization.value ? 'space-content-contentId' : 'space-content-contentId-localization',
    params: route.params,
  })
}


const switchVersions = () => {
  router.push({
    name: isVersions.value ? 'space-content-contentId' : 'space-content-contentId-versions',
    params: route.params,
  })
}


const assignedRelease = computed(() =>
  (releases.value?.data || []).find((release) => release.id === contentModel.value.releaseId)
)


const isInScheduledRelease = computed(
  () => assignedRelease.value && getReleaseState(assignedRelease.value) !== 'draft'
)


const hasLocalization = computed(() => space.value?.settings?.languages?.length > 0)


const draftReleases = computed(() =>
  (releases.value?.data || []).filter((release) => getReleaseState(release) === 'draft')
)


const canPublishToRelease = computed(
  () => !isInScheduledRelease.value && !contentModel.value.isPublished
)


const handleAssignToRelease = (release: any) => {
  selectedReleaseForAssign.value = release
  assignReleaseDialogOpen.value = true
}


const showDraftJson = () => {
  openContentJsonInNewTab('draft')
}


const showPublishedJson = () => {
  openContentJsonInNewTab('published')
}


const openVersionJsonInNewTab = (version: ContentVersionListResource | null | undefined) => {
  if (!version) return
  openContentJsonInNewTab(version.id)
}


const handleConfirmAssign = (versionIds: string[]) => {
  if (selectedReleaseForAssign.value) {
    assignVersions(
      {
        releaseId: selectedReleaseForAssign.value.id,
        payload: { version_ids: versionIds },
      },
      {
        onSuccess: () => {
          assignReleaseDialogOpen.value = false
          selectedReleaseForAssign.value = null
        },
      }
    )
  }
}
</script>

<template>
  <div class="flex items-center gap-3">
    <AvatarList
      v-if="props.presentUsers?.length"
      :users="props.presentUsers"
      :max="3"
      tooltip-side="bottom"
    />
    <SimpleTooltip
      :tooltip="
        hasLocalization
          ? $t('labels.contents.localization.available')
          : $t('labels.contents.localization.notAvailable')
      "
    >
      <Button
        :variant="isLocalization ? 'primary' : 'default'"
        size="icon"
        :disabled="!hasLocalization"
        @click="switchLocalization"
      >
        <Icon name="lucide:globe" />
      </Button>
    </SimpleTooltip>
    <Button
      :variant="isVersions ? 'primary' : 'default'"
      size="icon"
      @click="switchVersions"
    >
      <Icon name="lucide:history" />
    </Button>
    <Button
      :disabled="disabled || !isDirty"
      @click="save"
      >Save
    </Button>
    <SplitButton
      variant="accent"
      :primary-action="publishDirectly"
      :disabled="disabled || !(contentModel.canPublish || isDirty)"
      :loading="isPublishing || isScheduling"
    >
      <span>{{ $t('actions.content.publish') }}</span>
      <template #menu>
        <DropdownMenuLabel>Publish</DropdownMenuLabel>
        <DropdownMenuItem
          :disabled="disabled || !(contentModel.canPublish || isDirty)"
          @select="publishWithMessage"
        >
          <Icon name="lucide:send" />
          <span>{{ $t('actions.content.publishWithMessage') }}</span>
        </DropdownMenuItem>
        <DropdownMenuItem
          :disabled="disabled || !(contentModel.canPublish || isDirty) || contentModel.isInRelease"
          @select="schedulePublish"
        >
          <Icon name="lucide:clock-fading" />
          <span>{{ $t('actions.content.schedule') }}</span>
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuSub>
          <DropdownMenuSubTrigger :disabled="disabled || !canPublishToRelease">
            <Icon name="lucide:tag" />
            <span>Add to Release</span>
          </DropdownMenuSubTrigger>
          <DropdownMenuSubContent>
            <DropdownMenuItem
              v-if="draftReleases.length === 0"
              disabled
            >
              No draft releases available
            </DropdownMenuItem>
            <template v-else>
              <DropdownMenuItem
                v-for="release in draftReleases"
                :key="release.id"
                :disabled="disabled"
                @select="handleAssignToRelease(release)"
              >
                <Icon
                  name="lucide:plus"
                  class="mr-2 h-4 w-4"
                />
                {{ release.name }}
              </DropdownMenuItem>
            </template>
          </DropdownMenuSubContent>
        </DropdownMenuSub>
        <DropdownMenuSeparator />
        <DropdownMenuItem
          :disabled="!apiToken || !space?.updated_at"
          @select="showDraftJson"
        >
          <Icon name="lucide:braces" />
          <span>Show draft JSON</span>
        </DropdownMenuItem>
        <DropdownMenuItem
          :disabled="!apiToken || !space?.updated_at || !contentModel.isPublished"
          @select="showPublishedJson"
        >
          <Icon name="lucide:braces" />
          <span>Show published JSON</span>
        </DropdownMenuItem>
        <DropdownMenuItem
          :disabled="!contentModel.isPublished"
          @select="unpublish"
        >
          <Icon name="lucide:eye-off" />
          <span>{{ $t('actions.content.unpublish') }}</span>
        </DropdownMenuItem>
      </template>
    </SplitButton>
    <PublishDialog
      :open="publishDialogOpen"
      :content="content"
      :loading="isPublishing || isScheduling"
      :publish-type="publishType"
      @update:open="publishDialogOpen = $event"
      @publish="handlePublish"
      @schedule="handleSchedule"
    />
    <AssignToReleaseDialog
      :open="assignReleaseDialogOpen"
      :release="selectedReleaseForAssign"
      :current-version="content.current_version"
      :loading="isAssigning"
      @update:open="assignReleaseDialogOpen = $event"
      @assign="handleConfirmAssign"
    />
  </div>
</template>
