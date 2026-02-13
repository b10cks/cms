<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import BlockEdit from '~/components/BlockEdit.vue'
import BlockMenu from '~/components/BlockMenu.vue'
import BlockTemplatesSheet from '~/components/blocks/BlockTemplatesSheet.vue'
import BlockVersionsSheet from '~/components/blocks/BlockVersionsSheet.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { ScrollArea } from '~/components/ui/scroll-area'

const route = useRoute()
const spaceId = computed(() => route.params.space as string)
const blockId = computed(() => route.params.block as string)

const { useBlockQuery, useUpdateBlockMutation } = useBlocks(spaceId)
const { isLoading, data: block } = useBlockQuery(blockId)

const { mutate: updateBlock } = useUpdateBlockMutation()

useSeoMeta({
  title: computed(() => block.value?.name),
})

const activeTab = ref('editor')
const showTemplatesSheet = ref(false)
const showVersionsSheet = ref(false)

const submit = async (b: BlockResource) => {
  updateBlock({
    id: b.id,
    payload: { ...b },
  })
}
</script>

<template>
  <BlockMenu :space-id="spaceId" />
  <ScrollArea class="flex grow bg-background">
    <div v-if="isLoading">Loading ...</div>
    <div
      v-else-if="block"
      class="mx-auto flex w-full max-w-5xl flex-col gap-6 pb-6"
    >
      <ContentHeader :header="block.name">
        <template #actions>
          <div class="flex items-center gap-2">
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

      <div class="mx-auto max-w-xl">
        <BlockEdit
          v-slot="{ editBlock }"
          :block="block"
          :space-id="spaceId"
          show-schema
          @submit="submit"
        >
          <div class="flex">
            <Button
              type="button"
              variant="primary"
              class="ml-auto"
              @click="submit(editBlock)"
            >
              {{ $t('actions.blocks.save') }}
            </Button>
          </div>
        </BlockEdit>
      </div>
    </div>
  </ScrollArea>

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
</template>
