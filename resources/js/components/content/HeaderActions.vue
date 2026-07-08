<script setup lang="ts">
import { toast } from 'vue-sonner'

import { ContentModel } from '~/api/resources/content-model'
import LanguageSwitcher from '~/components/content/LanguageSwitcher.vue'
import VersionConflictDialog from '~/components/content/VersionConflictDialog.vue'
import Icon from '~/components/Icon.vue'
import AssignToReleaseDialog from '~/components/releases/AssignToReleaseDialog.vue'
import { AvatarList } from '~/components/ui/avatar'
import { Badge } from '~/components/ui/badge'
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
import {
  SimpleTooltip,
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '~/components/ui/tooltip'
import type {
  CollaborationPresenceUser,
  ContentCommitAction,
} from '~/composables/useContentLiveCollaboration'
import {
  resolveContentRouteName,
  sanitizeContentMutationPayload,
  withContentLanguageQuery,
} from '~/lib/content-i18n'
import type { ContentResource, ContentVersionConflictResponse } from '~/types/contents'

import PublishDialog from './PublishDialog.vue'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const { alert } = useAlertDialog()
const { useAccessControl } = useAuthorization()
const props = defineProps<{
  spaceId: string
  content: ContentResource
  presentUsers?: CollaborationPresenceUser[]
  remoteDraftUsers?: CollaborationPresenceUser[]
  disabled?: boolean
  isDirty?: boolean
}>()
const access = useAccessControl(computed(() => ({ space_id: props.spaceId })))
const canSaveContent = computed(() => access.hasAbility('content.manage'))
const canPublishContent = computed(() => access.hasAbility('content.publish'))
const canManageReleases = computed(() => access.hasAbility('releases.manage'))

const commitPersistedContent = inject<
  ((content: ContentResource, action?: ContentCommitAction) => void) | undefined
>('commitPersistedContent', undefined)
const resetDirtyState = inject<(() => void) | undefined>('resetDirtyState', undefined)
const validateContentForSubmit = inject<((options?: { silent?: boolean }) => boolean) | undefined>(
  'validateContentForSubmit',
  undefined
)
const getClientValidationErrors = inject<(() => Record<string, string[]>) | undefined>(
  'getClientValidationErrors',
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
const getValidationSummary = inject<
  (() => { isValid: boolean; issueCount: number }) | undefined
>('getValidationSummary', undefined)
const getValidationIssueSignature = inject<(() => string) | undefined>(
  'getValidationIssueSignature',
  undefined
)
const revealValidationState = inject<(() => Promise<void>) | undefined>(
  'revealValidationState',
  undefined
)
const editingFromVersionId = inject<Ref<string | null>>('editingFromVersionId')
const serverVersionDrifted = inject<ComputedRef<boolean>>('serverVersionDrifted')
const serverCurrentVersion = inject<ComputedRef<ContentVersionListResource | null | undefined>>(
  'serverCurrentVersion'
)
const reloadServerContent = inject<(() => void) | undefined>('reloadServerContent', undefined)

const conflictDialogOpen = ref(false)
const conflictData = ref<ContentVersionConflictResponse | null>(null)

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

const { mutateAsync: createContent, isPending: isCreating } = useCreateContentMutation()
const { mutateAsync: updateContent, isPending: isUpdating } = useUpdateContentMutation()
const { mutateAsync: publishContent, isPending: isPublishing } = usePublishContentMutation()
const { mutateAsync: scheduleContent, isPending: isScheduling } = useScheduleContentMutation()
const { mutateAsync: unpublishContent, isPending: isUnpublishing } = useUnpublishContentMutation()

const isVersions = computed(() => route.name === 'space-content-contentId-versions')
const publishDialogOpen = ref(false)
const publishType = ref<'now' | 'schedule'>('now')
const assignReleaseDialogOpen = ref(false)
const selectedReleaseForAssign = ref<any>(null)
const lastReviewedValidationSignature = ref<string | null>(null)
const contentModel = computed(() => new ContentModel(props.content))
const isSaving = computed(() => isCreating.value || isUpdating.value)
const isPublishingAction = computed(
  () => isPublishing.value || isScheduling.value || isUnpublishing.value
)
const isAnyActionPending = computed(
  () => isSaving.value || isPublishingAction.value || isAssigning.value
)
const validationSummary = computed(() => getValidationSummary?.() || { isValid: true, issueCount: 0 })
const validationStatusTooltip = computed(() =>
  validationSummary.value.isValid
    ? (t('labels.contents.validation.status.validTooltip') as string)
    : (t('labels.contents.validation.status.invalidTooltip', {
        count: validationSummary.value.issueCount,
      }) as string)
)
const validationStatusAriaLabel = computed(() =>
  validationSummary.value.isValid
    ? (t('labels.contents.validation.status.validAriaLabel') as string)
    : (t('labels.contents.validation.status.invalidAriaLabel', {
        count: validationSummary.value.issueCount,
      }) as string)
)

watch(
  () => validationSummary.value.isValid,
  (isValid) => {
    if (isValid) {
      lastReviewedValidationSignature.value = null
    }
  },
  { immediate: true }
)

const handlePersistedContent = (
  nextContent: ContentResource,
  action: ContentCommitAction = 'save'
) => {
  lastReviewedValidationSignature.value = null
  commitPersistedContent?.(nextContent, action)
  resetDirtyState?.()
}

const mutationPayload = computed(() =>
  sanitizeContentMutationPayload({
    ...props.content,
    content: sanitizeContentForSubmit?.() || props.content.content,
    parent_version_id: editingFromVersionId?.value ?? undefined,
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

const getValidationErrorData = (error: unknown): Record<string, string[]> | null => {
  const errorData =
    (error as { data?: { errors?: Record<string, string[]> } })?.data?.errors ||
    (error as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data
      ?.errors

  return errorData || null
}

const isSoftValidationError = (error: unknown): boolean => {
  return (
    (error as { status?: number })?.status === 422 &&
    !!getValidationErrorData(error) &&
    Object.keys(getValidationErrorData(error) || {}).length > 0
  )
}

const isConflictError = (error: unknown): error is { status: 409; data: ContentVersionConflictResponse } => {
  return (error as { status?: number })?.status === 409 &&
    !!(error as { data?: { conflict?: boolean } })?.data?.conflict
}

const getValidationWarningMessage = (errors: Record<string, string[]>): string => {
  const messages = Object.values(errors).flat().filter(Boolean)

  return [
    t('labels.contents.validationWarning.description') as string,
    ...(messages.length ? ['', ...messages.map((message) => `• ${message}`)] : []),
  ].join('\n')
}

const confirmSoftValidation = async (errors: Record<string, string[]>) => {
  return await alert.confirm(getValidationWarningMessage(errors), {
    title: t('labels.contents.validationWarning.title') as string,
    confirmLabel: t('actions.forceSave') as string,
    cancelLabel: t('actions.cancel') as string,
  })
}

const revealAndFocusValidationIssues = async () => {
  await revealValidationState?.()
  await focusFirstValidationError?.()
}

const remoteDraftUserNames = computed(() =>
  (props.remoteDraftUsers || [])
    .map((user) => [user.firstname, user.lastname].filter(Boolean).join(' ') || user.email)
    .join(', ')
)

// Persisting also commits unsaved live edits from collaborators — make that explicit.
const confirmRemoteDrafts = async () => {
  if (!props.remoteDraftUsers?.length) return true

  return await alert.confirm(
    t('labels.contents.collaboration.persistWithRemoteDrafts', {
      names: remoteDraftUserNames.value,
    }) as string,
    {
      title: t('labels.contents.collaboration.persistWithRemoteDraftsTitle') as string,
      confirmLabel: t('actions.save') as string,
      cancelLabel: t('actions.cancel') as string,
    }
  )
}

const guardSubmit = async (options?: { silent?: boolean; revealOnFail?: boolean }) => {
  if (!options?.silent) {
    clearValidationErrors?.()
  }

  const isValid = validateContentForSubmit?.(options) ?? true

  if (!isValid && options?.revealOnFail) {
    await revealAndFocusValidationIssues()
  }

  return isValid
}

const confirmClientValidationWarnings = async () => {
  const clientErrors = getClientValidationErrors?.() || {}
  const messages = Object.values(clientErrors).flat().filter(Boolean)

  return await alert.confirm(
    [
      t('labels.contents.validationWarning.description') as string,
      ...(messages.length ? ['', ...messages.map((message) => `${message}`)] : []),
    ].join('\n'),
    {
      title: t('labels.contents.validationWarning.title') as string,
      confirmLabel: t('actions.forceSave') as string,
      cancelLabel: t('actions.cancel') as string,
    }
  )
}

const performSave = async (force = false, forceConflict = false) => {
  const payload = {
    ...mutationPayload.value,
    ...(force ? { force: true } : {}),
    ...(forceConflict ? { force_conflict: true } : {}),
  }

  if (props.content.id) {
    try {
      const nextContent = await updateContent({
        id: props.content.id,
        payload,
      })
      handlePersistedContent(nextContent, 'save')
    } catch (error) {
      if (isConflictError(error)) {
        conflictData.value = error.data
        conflictDialogOpen.value = true
        return
      }

      if (!force && isSoftValidationError(error)) {
        const validationErrors = getValidationErrorData(error)
        if (validationErrors && (await confirmSoftValidation(validationErrors))) {
          await performSave(true)
          return
        }
      }

      handleMutationError(error)
    }
    return
  }

  try {
    const nextContent = await createContent(payload)
    handlePersistedContent(nextContent, 'save')
  } catch (error) {
    if (!force && isSoftValidationError(error)) {
      const validationErrors = getValidationErrorData(error)
      if (validationErrors && (await confirmSoftValidation(validationErrors))) {
        await performSave(true)
        return
      }
    }

    handleMutationError(error)
  }
}

const handleSaveBranch = async () => {
  conflictDialogOpen.value = false
  conflictData.value = null
  await performSave(false, true)
}

const handleConflictReload = () => {
  conflictDialogOpen.value = false
  conflictData.value = null
  reloadServerContent?.()
}

const save = async (force = false) => {
  if (!(await confirmRemoteDrafts())) return

  if (force) {
    await performSave(true)
    return
  }

  const isValid = await guardSubmit({ silent: false })

  if (!isValid) {
    const currentValidationSignature = getValidationIssueSignature?.() || null

    if (currentValidationSignature !== lastReviewedValidationSignature.value) {
      lastReviewedValidationSignature.value = currentValidationSignature
      await revealAndFocusValidationIssues()
      return
    }

    const confirmed = await confirmClientValidationWarnings()

    if (!confirmed) {
      await revealAndFocusValidationIssues()
      return
    }

    await performSave(true)
    return
  }

  lastReviewedValidationSignature.value = null
  await performSave(false)
}

const handleSaveClick = async (event?: MouseEvent) => {
  event?.preventDefault()
  event?.stopPropagation()

  if (isAnyActionPending.value) {
    return
  }

  await save()
}

const handleValidationStatusClick = async () => {
  if (validationSummary.value.isValid) {
    lastReviewedValidationSignature.value = null
    return
  }

  lastReviewedValidationSignature.value = getValidationIssueSignature?.() || null
  await revealAndFocusValidationIssues()
}

const publishDirectly = async () => {
  if (!(await confirmRemoteDrafts())) return
  if (!(await guardSubmit({ revealOnFail: true }))) return

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

const publishWithMessage = async () => {
  if (!(await confirmRemoteDrafts())) return
  if (!(await guardSubmit({ revealOnFail: true }))) return

  publishType.value = 'now'
  publishDialogOpen.value = true
}

const schedulePublish = async () => {
  if (!(await confirmRemoteDrafts())) return
  if (!(await guardSubmit({ revealOnFail: true }))) return

  publishType.value = 'schedule'
  publishDialogOpen.value = true
}

const handlePublish = async (payload: { message?: string; published_at?: string | null }) => {
  if (!(await guardSubmit({ revealOnFail: true }))) return

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
  if (!(await guardSubmit({ revealOnFail: true }))) return

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
  try {
    const nextContent = await unpublishContent({
      id: props.content.id,
      payload: mutationPayload.value,
    })
    handlePersistedContent(nextContent, 'unpublish')
  } catch (error) {
    handleMutationError(error)
  }
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
    <SimpleTooltip
      v-if="props.remoteDraftUsers?.length"
      :tooltip="t('labels.contents.collaboration.unsavedFrom', { names: remoteDraftUserNames })"
      side="bottom"
    >
      <Badge
        variant="warning"
        size="indicator"
      />
    </SimpleTooltip>
    <AvatarList
      v-if="props.presentUsers?.length"
      :users="props.presentUsers"
      :max="3"
      tooltip-side="bottom"
    />
    <TooltipProvider :delay-duration="300">
      <Tooltip>
        <TooltipTrigger as-child>
          <Button
            size="icon"
            :variant="validationSummary.isValid ? 'outline' : 'warning'"
            :aria-label="validationStatusAriaLabel"
            :class="
              validationSummary.isValid
                ? 'border-success/30 bg-success-background/10 text-success hover:bg-success-background/20 hover:text-success'
                : undefined
            "
            @click="handleValidationStatusClick"
          >
            <Icon :name="validationSummary.isValid ? 'lucide:badge-check' : 'lucide:circle-alert'" />
          </Button>
        </TooltipTrigger>
        <TooltipContent side="bottom">
          <p>{{ validationStatusTooltip }}</p>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
    <LanguageSwitcher :content="content" />
    <TooltipProvider :delay-duration="300">
      <Tooltip>
        <TooltipTrigger as-child>
          <Button
            :variant="isVersions ? 'primary' : 'default'"
            size="icon"
            class="relative"
            @click="switchVersions"
          >
            <Icon name="lucide:history" />
            <Badge
              v-if="serverVersionDrifted"
              variant="warning"
              size="dot"
              class="absolute -top-1 -right-1"
            />
          </Button>
        </TooltipTrigger>
        <TooltipContent
          v-if="serverVersionDrifted"
          side="bottom"
        >
          <p>{{ $t('content.conflict.driftTooltip') }}</p>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
    <Button
      v-if="canSaveContent"
      :disabled="disabled || isAnyActionPending || (!!content.id && !isDirty)"
      @click="handleSaveClick"
    >
      <Icon
        v-if="isSaving"
        name="lucide:loader"
        class="animate-spin"
      />
      {{ $t('actions.save') }}
    </Button>
    <SplitButton
      v-if="canPublishContent"
      variant="accent"
      :primary-action="publishDirectly"
      :disabled="
        disabled || isAnyActionPending || !content.id || !(contentModel.canPublish || isDirty)
      "
      :loading="isPublishingAction"
    >
      <span>{{ $t('actions.content.publish') }}</span>
      <template #menu>
        <DropdownMenuLabel>{{ $t('actions.content.publish') }}</DropdownMenuLabel>
        <DropdownMenuItem
          :disabled="
            disabled || isAnyActionPending || !content.id || !(contentModel.canPublish || isDirty)
          "
          @select="publishWithMessage"
        >
          <Icon name="lucide:send" />
          <span>{{ $t('actions.content.publishWithMessage') }}</span>
        </DropdownMenuItem>
        <DropdownMenuItem
          :disabled="
            disabled ||
            isAnyActionPending ||
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
          <DropdownMenuSubTrigger
            v-if="canManageReleases"
            :disabled="disabled || !canPublishToRelease"
          >
            <Icon name="lucide:tag" />
            <span>{{ $t('actions.content.addToRelease') }}</span>
          </DropdownMenuSubTrigger>
          <DropdownMenuSubContent v-if="canManageReleases">
            <DropdownMenuItem
              v-if="draftReleases.length === 0"
              disabled
            >
              {{ $t('actions.content.noDraftReleases') }}
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
          <span>{{ $t('actions.content.showDraftJson') }}</span>
        </DropdownMenuItem>
        <DropdownMenuItem
          :disabled="!content.id || !apiToken || !space?.updated_at || !contentModel.isPublished"
          @select="showPublishedJson"
        >
          <Icon name="lucide:braces" />
          <span>{{ $t('actions.content.showPublishedJson') }}</span>
        </DropdownMenuItem>
        <DropdownMenuItem
          :disabled="isPublishingAction || !contentModel.isPublished"
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
    <VersionConflictDialog
      v-if="conflictData"
      :open="conflictDialogOpen"
      :server-version="conflictData.current_version"
      :server-content="conflictData.current_content"
      :my-content="(sanitizeContentForSubmit?.() || props.content.content) as Record<string, unknown>"
      @update:open="conflictDialogOpen = $event"
      @save-branch="handleSaveBranch"
      @reload="handleConflictReload"
    />
  </div>
</template>
