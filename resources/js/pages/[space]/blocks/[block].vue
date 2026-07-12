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
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { Skeleton } from '~/components/ui/skeleton'

const route = useRoute()
const router = useRouter()
const spaceId = computed(() => route.params.space as string)
const blockId = computed(() => route.params.block as string)
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: spaceId.value })))
const canManageBlocks = computed(() => access.hasAbility('blocks.manage'))

const { useBlockQuery, useUpdateBlockMutation } = useBlocks(spaceId)
const { isLoading, data: block } = useBlockQuery(blockId)

const { mutate: updateBlock } = useUpdateBlockMutation()

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

const submit = async (b: BlockResource) => {
  updateBlock({
    id: b.id,
    payload: { ...b },
  })
}

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
              @click="showVersionsSheet = true"
            >
              <Icon name="lucide:history" />
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
