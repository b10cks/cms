<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import CreateReleaseDialog from '~/components/releases/CreateReleaseDialog.vue'
import ReleaseList from '~/components/releases/ReleaseList.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import type { CreateReleaseRequest, Release } from '~/types/releases'

const route = useRoute()
const { t } = useI18n()
const spaceId = computed(() => route.params.space as string)

useSeoMeta({
  title: computed(() => t('labels.releases.title')),
})

const {
  useReleasesQuery,
  useCreateReleaseMutation,
  useUpdateReleaseMutation,
  useCommitReleaseMutation,
  useCancelReleaseMutation,
  useDeleteReleaseMutation,
  usePublishReleaseMutation,
} = useReleases(spaceId)

const { data: releases, isLoading: isLoadingReleases } = useReleasesQuery()
const { mutate: createRelease, isPending: isCreating } = useCreateReleaseMutation()
const { mutate: updateRelease, isPending: isUpdating } = useUpdateReleaseMutation()
const { mutate: commitRelease, isPending: isCommitting } = useCommitReleaseMutation()
const { mutate: cancelRelease, isPending: isCancelling } = useCancelReleaseMutation()
const { mutate: deleteRelease, isPending: isDeleting } = useDeleteReleaseMutation()
const { mutate: publishRelease, isPending: isPublishing } = usePublishReleaseMutation()

const { alert } = useAlertDialog()

const createDialogOpen = ref(false)
const editDialogOpen = ref(false)
const releaseToEdit = ref<Release | null>(null)
const isUpdatingRelease = ref(false)

const handleCreateRelease = (payload: CreateReleaseRequest) => {
  createRelease(payload, {
    onSuccess: () => {
      createDialogOpen.value = false
    },
  })
}

const handleUpdateRelease = (id: string, payload: CreateReleaseRequest) => {
  isUpdatingRelease.value = true
  updateRelease(
    { id, payload },
    {
      onSuccess: () => {
        isUpdatingRelease.value = false
        editDialogOpen.value = false
        releaseToEdit.value = null
      },
      onError: () => {
        isUpdatingRelease.value = false
      },
    }
  )
}

const handleCommit = (releaseId: string) => {
  commitRelease(releaseId)
}

const handleCancelClick = async (release: Release) => {
  const confirmed = await alert.confirm(
    `Are you sure you want to cancel the release "${release.name}"?`,
    {
      title: 'Cancel Release',
      confirmLabel: 'Cancel Release',
      variant: 'destructive',
      onConfirm: () => {
        cancelRelease(release.id)
      },
    }
  )
}

const handleDeleteClick = async (release: Release) => {
  const confirmed = await alert.confirm(
    `Are you sure you want to delete the release "${release.name}"? This action cannot be undone.`,
    {
      title: 'Delete Release',
      confirmLabel: 'Delete Release',
      variant: 'destructive',
      onConfirm: () => {
        deleteRelease(release.id)
      },
    }
  )
}

const handlePublish = (releaseId: string) => {
  publishRelease(releaseId)
}

const isLoading = computed(
  () =>
    isLoadingReleases.value ||
    isCreating.value ||
    isUpdating.value ||
    isCommitting.value ||
    isCancelling.value ||
    isDeleting.value ||
    isPublishing.value
)
</script>

<template>
  <div class="w-full bg-background">
    <div class="content-grid">
      <ContentHeader
        :header="$t('labels.releases.title')"
        :description="$t('labels.releases.description')"
      >
        <template #actions>
          <Button
            variant="primary"
            @click="createDialogOpen = true"
            :disabled="isLoading"
          >
            <Icon name="lucide:plus" />
            {{ $t('labels.releases.createRelease') }}
          </Button>
        </template>
      </ContentHeader>

      <ReleaseList
        :is-loading="isLoading"
        @commit="(release) => handleCommit(release.id)"
        @cancel="handleCancelClick"
        @delete="handleDeleteClick"
        @edit="
          (release) => {
            releaseToEdit.value = release
            editDialogOpen.value = true
          }
        "
      />
    </div>
  </div>

  <CreateReleaseDialog
    :open="createDialogOpen"
    :loading="isCreating"
    @update:open="createDialogOpen = $event"
    @create="handleCreateRelease"
  />

  <CreateReleaseDialog
    :open="editDialogOpen"
    :loading="isUpdatingRelease"
    :release-to-edit="releaseToEdit"
    @update:open="
      (newOpen) => {
        editDialogOpen.value = newOpen
        if (!newOpen) {
          releaseToEdit.value = null
        }
      }
    "
    @update="handleUpdateRelease"
  />
</template>
