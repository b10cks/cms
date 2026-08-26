<script setup lang="ts">
import { RouterLink } from 'vue-router'

import { Badge } from '@/components/ui/badge'
import BlockEdit from '~/components/BlockEdit.vue'
import BlockMenu from '~/components/BlockMenu.vue'
import BlockTemplatesSheet from '~/components/blocks/BlockTemplatesSheet.vue'
import BlockUsedIn from '~/components/blocks/BlockUsedIn.vue'
import BlockVersionsSheet from '~/components/blocks/BlockVersionsSheet.vue'
import CreateBlockDialog from '~/components/blocks/CreateBlockDialog.vue'
import Icon from '~/components/Icon.vue'
import { HistoryIcon } from '~/components/icons'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { Skeleton } from '~/components/ui/skeleton'
import { createSnapshotDirtyTracker } from '~/lib/contentEditorState'
import { useSaveShortcut } from '~/lib/editorShortcuts'
import { useUnsavedChangesGuard } from '~/lib/unsavedChangesGuard'

const route = useRoute()
const router = useRouter()
const spaceId = computed(() => route.params.space as string)
const blockId = computed(() => route.params.block as string)
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: spaceId.value })))
const canManageBlocks = computed(() => access.hasAbility('blocks.manage'))

const { useBlockQuery, useUpdateBlockMutation } = useBlocks(spaceId)
const { isLoading, data: block } = useBlockQuery(blockId)

const { mutateAsync: updateBlock, isPending: isSaving } = useUpdateBlockMutation()

const overviewRoute = computed(() => ({
  name: 'space-blocks-index' as const,
  params: { space: spaceId.value },
  query: route.query,
}))

useSeoMeta({
  title: computed(() => block.value?.name),
})

const showTemplatesSheet = ref(false)
const showVersionsSheet = ref(false)
const showDuplicateBlockDialog = ref(false)

const blockEdit = useTemplateRef<InstanceType<typeof BlockEdit>>('blockEdit')

/**
 * Resolves whether the block reached the server. The editor is re-seeded from
 * the response because the backend rewrites `schema` and `settings` on write —
 * comparing against what was sent would leave the page dirty forever.
 */
const submit = async (b: BlockResource): Promise<boolean> => {
  try {
    const saved = await updateBlock({ id: b.id, payload: { ...b } })
    blockEdit.value?.reset(saved)
    return true
  } catch {
    // The mutation already surfaced the failure as a toast.
    return false
  }
}

/**
 * Only the fields the block editor actually writes, normalised the same way
 * `BlockEdit` seeds them — so a freshly loaded block is never reported dirty
 * and server-side bookkeeping (`updated_at`, counters) never is either.
 */
const editableSnapshot = (source: BlockResource | null | undefined) =>
  source
    ? {
        name: source.name,
        slug: source.slug,
        description: source.description,
        type: source.type || 'nestable',
        icon: source.icon || 'block',
        color: source.color,
        tags: source.tags,
        preview_template: source.preview_template,
        preview_file: source.preview_file,
        settings: source.settings,
        schema: source.schema,
        editor: source.editor,
      }
    : null

const editedBlock = computed(() => editableSnapshot(blockEdit.value?.editableBlock))
const savedBlock = computed(() => editableSnapshot(block.value))

const { isDirty } = createSnapshotDirtyTracker(editedBlock, savedBlock)

const saveEditedBlock = async (): Promise<boolean> => {
  const edited = blockEdit.value?.editableBlock
  return edited ? submit(edited) : false
}

useUnsavedChangesGuard({ isDirty, onSave: saveEditedBlock })

useSaveShortcut({
  canSave: () => canManageBlocks.value && isDirty.value && !isSaving.value,
  save: saveEditedBlock,
})

const handleDuplicateCreated = (createdBlock: BlockResource) => {
  showDuplicateBlockDialog.value = false
  void router.push({
    name: 'space-block',
    params: {
      space: spaceId.value,
      block: createdBlock.id,
    },
    query: route.query,
  })
}
</script>

<template>
  <BlockMenu :space-id="spaceId" />
  <div class="flex grow bg-background">
    <div
      v-if="isLoading"
      aria-hidden="true"
      class="mx-auto flex w-full max-w-5xl flex-col gap-6 pb-6"
    >
      <div class="grid gap-2 pt-8">
        <Skeleton class="size-9" />
        <div class="flex items-center justify-between">
          <Skeleton class="h-8 w-56" />
          <div class="flex items-center gap-2">
            <Skeleton class="h-8 w-28" />
            <Skeleton class="h-8 w-9" />
            <Skeleton class="h-8 w-32" />
          </div>
        </div>
      </div>
      <div
        class="mx-auto flex w-full max-w-4xl flex-col gap-6 [mask-image:linear-gradient(to_bottom,black_20%,transparent)]"
      >
        <div
          v-for="(width, index) in ['w-32', 'w-24', 'w-40', 'w-28', 'w-36', 'w-24']"
          :key="index"
          class="flex flex-col gap-2"
        >
          <Skeleton :class="['h-4', width]" />
          <Skeleton class="h-10 w-full" />
        </div>
      </div>
    </div>
    <div
      v-else-if="block"
      class="mx-auto flex w-full max-w-6xl flex-col gap-6 pb-6"
    >
      <ContentHeader
        class="sticky top-14 z-10 bg-background pb-2"
        :header="block.name"
      >
        <template #before-header>
          <Button
            :as="RouterLink"
            :to="overviewRoute"
            variant="ghost"
            size="icon"
          >
            <Icon name="lucide:arrow-left" />
            <span class="sr-only">{{ $t('actions.back') }}</span>
          </Button>
        </template>
        <template #actions>
          <div class="flex items-center gap-2">
            <Button
              v-if="canManageBlocks"
              variant="outline"
              size="sm"
              @click="showDuplicateBlockDialog = true"
            >
              <Icon name="lucide:copy" />
              {{ $t('actions.blocks.duplicate') }}
            </Button>
            <Button
              variant="outline"
              size="sm"
              class="icon-anim"
              @click="showVersionsSheet = true"
            >
              <HistoryIcon :size="16" />
            </Button>
            <Button
              variant="outline"
              size="sm"
              @click="showTemplatesSheet = true"
            >
              <Icon name="lucide:notepad-text-dashed" />
              {{ $t('actions.blocks.templates') }}
              <Badge
                v-if="block.templates_count"
                variant="secondary"
                size="sm"
                class="ml-1"
              >
                {{ block.templates_count }}
              </Badge>
            </Button>
          </div>
        </template>
      </ContentHeader>

      <div class="mx-auto max-w-4xl">
        <BlockEdit
          ref="blockEdit"
          v-slot="{ editBlock }"
          :block="block"
          :space-id="spaceId"
          :readonly="!canManageBlocks"
          show-schema
          @submit="submit"
        >
          <div class="flex">
            <Button
              v-if="canManageBlocks"
              type="button"
              variant="primary"
              class="ml-auto"
              :loading="isSaving"
              @click="submit(editBlock)"
            >
              {{ $t('actions.blocks.save') }}
            </Button>
          </div>
        </BlockEdit>

        <BlockUsedIn
          class="mt-6"
          :space-id="spaceId"
          :block="block"
        />
      </div>
    </div>
  </div>

  <BlockVersionsSheet
    v-if="block"
    v-model:open="showVersionsSheet"
    :space-id="spaceId"
    :block="block"
  />

  <BlockTemplatesSheet
    v-if="block"
    v-model:open="showTemplatesSheet"
    :space-id="spaceId"
    :block-id="blockId"
  />

  <CreateBlockDialog
    v-if="block && canManageBlocks"
    v-model:open="showDuplicateBlockDialog"
    :space-id="spaceId"
    :initial-block="block"
    :title="$t('actions.blocks.duplicate')"
    @created="handleDuplicateCreated"
  />
</template>
