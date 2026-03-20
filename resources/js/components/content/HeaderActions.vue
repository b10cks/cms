<script setup lang="ts">
import { ContentModel } from '~/api/resources/content-model'
import LanguageSwitcher from '~/components/content/LanguageSwitcher.vue'
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
import type {
  CollaborationPresenceUser,
  ContentCommitAction,
} from '~/composables/useContentLiveCollaboration'
import {
  resolveContentRouteName,
  sanitizeContentMutationPayload,
  withContentLanguageQuery,
} from '~/lib/content-i18n'
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
const validateContentForSubmit = inject<(() => boolean) | undefined>(
  'validateContentForSubmit',
  undefined
)
const sanitizeContentForSubmit = inject<(() => Record<string, unknown>) | undefined>(
  'sanitizeContentForSubmit',
  undefined
)
const setValidationErrors = inject<((errors: Record<string, string[]>) => void) | undefined>(
  'setValidationErrors',
  undefined
)
const clearValidationErrors = inject<(() => void) | undefined>('clearValidationErrors', undefined)
const focusFirstValidationError = inject<(() => Promise<void>) | undefined>(
  'focusFirstValidationError',
  undefined
)


const {
  useCreateContentMutation,
  useUpdateContentMutation,
  usePublishContentMutation,
  useScheduleContentMutation,
  useUnpublishContentMutation,
} = useContent(props.spaceId)


const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(props.spaceId)
const defaultLanguage = computed(
  () =>
    space.value?.settings.default_language ||
    props.content.language_versions?.find((version) => version.is_default)?.language_iso ||
    props.content.language_iso
)
const resolvedLanguage = computed(() => route.query.lang || defaultLanguage.value)
const { apiToken, openContentJsonInNewTab } = useContentJson(
  props.spaceId,
  computed(() => props.content)
)


const { useReleasesQuery, useAssignVersionsMutation, getReleaseState } = useReleases(props.spaceId)
const { data: releases } = useReleasesQuery()


const { mutate: assignVersions, isPending: isAssigning } = useAssignVersionsMutation()


const { mutateAsync: createContent } = useCreateContentMutation()
const { mutateAsync: updateContent } = useUpdateContentMutation()
const { mutateAsync: publishContent, isPending: isPublishing } = usePublishContentMutation()
const { mutateAsync: scheduleContent, isPending: isScheduling } = useScheduleContentMutation()
const { mutateAsync: unpublishContent } = useUnpublishContentMutation()


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


const mutationPayload = computed(() =>
  sanitizeContentMutationPayload({
    ...props.content,
    content: sanitizeContentForSubmit?.() || props.content.content,
  })
)


const handleMutationError = (error: unknown) => {
  const errorData =
    (error as { data?: { errors?: Record<string, string[]> } })?.data?.errors ||
    (error as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data
      ?.errors
  if (errorData) {
    setValidationErrors?.(errorData)
    focusFirstValidationError?.()
  }
}


const guardSubmit = () => {
  clearValidationErrors?.()
  const isValid = validateContentForSubmit?.() ?? true
  if (!isValid) {
    focusFirstValidationError?.()
  }


  return isValid
}


const save = async () => {
  if (!guardSubmit()) return


  if (props.content.id) {
    try {
      const nextContent = await updateContent({
        id: props.content.id,
        payload: mutationPayload.value,
      })
      handlePersistedContent(nextContent, 'save')
    } catch (error) {
      handleMutationError(error)
    }
    return
  }


  try {
    const nextContent = await createContent(mutationPayload.value)
    handlePersistedContent(nextContent, 'save')
  } catch (error) {
    handleMutationError(error)
  }
}


const publishDirectly = async () => {
  if (!guardSubmit()) return


  try {
    const nextContent = await publishContent({
      id: props.content.id,
      payload: mutationPayload.value,
    })
    handlePersistedContent(nextContent, 'publish')
  } catch (error) {
    handleMutationError(error)
  }
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
  if (!guardSubmit()) return


  const publishPayload = sanitizeContentMutationPayload({
    ...props.content,
    content: sanitizeContentForSubmit?.() || props.content.content,
    ...payload,
  })
  try {
    const nextContent = await publishContent({ id: props.content.id, payload: publishPayload })
    handlePersistedContent(nextContent, 'publish')
    publishDialogOpen.value = false
  } catch (error) {
    handleMutationError(error)
  }
}


const handleSchedule = async (payload: { message?: string; scheduled_at?: string | null }) => {
  if (!guardSubmit()) return


  const schedulePayload = sanitizeContentMutationPayload({
    ...props.content,
    content: sanitizeContentForSubmit?.() || props.content.content,
    message: payload.message,
    scheduled_at: payload.scheduled_at,
  })
  try {
    const nextContent = await scheduleContent({ id: props.content.id, payload: schedulePayload })
    handlePersistedContent(nextContent, 'schedule')
    publishDialogOpen.value = false
  } catch (error) {
    handleMutationError(error)
  }
}


const unpublish = async () => {
  const nextContent = await unpublishContent({
    id: props.content.id,
    payload: mutationPayload.value,
  })
  handlePersistedContent(nextContent, 'unpublish')
}


const switchVersions = () => {
  const targetRouteName = isVersions.value
    ? resolveContentRouteName(
        'space-content-contentId',
        props.content.effective_i18n_mode,
        resolvedLanguage.value as string,
        defaultLanguage.value
      )
    : 'space-content-contentId-versions'


  router.push({
    name: targetRouteName,
    params: {
      ...route.params,
      contentId: props.content.i18n_canonical_id,
    },
    query: withContentLanguageQuery(
      route.query,
      resolvedLanguage.value as string,
      defaultLanguage.value
    ),
  })
}


const assignedRelease = computed(() =>
  (releases.value?.data || []).find((release) => release.id === contentModel.value.releaseId)
)


const isInScheduledRelease = computed(
  () => assignedRelease.value && getReleaseState(assignedRelease.value) !== 'draft'
)


const draftReleases = computed(() =>
  (releases.value?.data || []).filter((release) => getReleaseState(release) === 'draft')
)


const canPublishToRelease = computed(
  () => !!props.content.id && !isInScheduledRelease.value && !contentModel.value.isPublished
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


const handleConfirmAssign = (versionIds: string[]) => {
  if (!selectedReleaseForAssign.value) {
    return
  }


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
</script>

<template>
  <div class="flex items-center gap-3">
    <AvatarList
      v-if="props.presentUsers?.length"
      :users="props.presentUsers"
      :max="3"
      tooltip-side="bottom"
    />
    <LanguageSwitcher :content="content" />
    <Button
      :variant="isVersions ? 'primary' : 'default'"
      size="icon"
      @click="switchVersions"
    >
      <Icon name="lucide:history" />
    </Button>
    <Button
      :disabled="disabled || (!!content.id && !isDirty)"
      @click="save"
      >Save
    </Button>
    <SplitButton
      variant="accent"
      :primary-action="publishDirectly"
      :disabled="disabled || !content.id || !(contentModel.canPublish || isDirty)"
      :loading="isPublishing || isScheduling"
    >
      <span>{{ $t('actions.content.publish') }}</span>
      <template #menu>
        <DropdownMenuLabel>Publish</DropdownMenuLabel>
        <DropdownMenuItem
          :disabled="disabled || !content.id || !(contentModel.canPublish || isDirty)"
          @select="publishWithMessage"
        >
          <Icon name="lucide:send" />
          <span>{{ $t('actions.content.publishWithMessage') }}</span>
        </DropdownMenuItem>
        <DropdownMenuItem
          :disabled="
            disabled ||
            !content.id ||
            !(contentModel.canPublish || isDirty) ||
            contentModel.isInRelease
          "
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
          :disabled="!content.id || !apiToken || !space?.updated_at"
          @select="showDraftJson"
        >
          <Icon name="lucide:braces" />
          <span>Show draft JSON</span>
        </DropdownMenuItem>
        <DropdownMenuItem
          :disabled="!content.id || !apiToken || !space?.updated_at || !contentModel.isPublished"
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
