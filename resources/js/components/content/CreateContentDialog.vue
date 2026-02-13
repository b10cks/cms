<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { FormField, InputField } from '~/components/ui/form'
import IconName from '~/components/ui/IconName.vue'
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
} from '~/components/ui/select'
import type { CreateContentPayload } from '~/types/contents'

const open = defineModel<boolean>('open')
const props = withDefaults(
  defineProps<{
    spaceId: string
    parentId?: string
  }>(),
  {
    parentId: undefined,
  }
)

const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(props.spaceId)

const { useBlocksQuery } = useBlocks(props.spaceId)
const { data: blocks } = useBlocksQuery({ per_page: 1000 })

const { useCreateContentMutation } = useContent(props.spaceId)
const { mutate: createContent } = useCreateContentMutation()

const content = ref<CreateContentPayload>({
  block_id: null,
  slug: '',
  name: '',
})

const blockId = computed(() => content.value.block_id)
const { useBlockTemplatesQuery } = useBlockTemplates(props.spaceId, blockId)
const selectedTemplate = ref<BlockTemplate | null>(null)

watch(
  space,
  () => {
    if (!content.value.block_id) {
      content.value.block_id = space.value?.settings.default_block
    }
  },
  { immediate: true }
)

const handleCreate = async (editContent: CreateContentPayload) => {
  const payload: CreateContentPayload = {
    ...editContent,
    parent_id: props.parentId,
  }

  if (selectedTemplate.value) {
    payload.content = selectedTemplate.value.content
  }

  await createContent(payload)
  open.value = false
  resetForm()
}

const resetForm = () => {
  content.value = {
    block_id: content.value.block_id,
    slug: '',
    name: '',
  }
  selectedTemplate.value = null
}

const possibleBlocks = computed(() => {
  return (
    blocks.value?.data.filter((b: BlockResource) => {
      return b.type === 'root' || b.type === 'universal' || (!props.parentId && b.type === 'single')
    }) || []
  )
})

const currentBlock = computed(() => {
  return possibleBlocks.value?.find((b: BlockResource) => b.id === content.value?.block_id)
})

const { data: templates } = useBlockTemplatesQuery()

const createSlug = () => {
  if (content.value.slug?.trim()) return
  content.value.slug = content.value.name
    .replace(/[^a-zA-Z0-9]+/g, '-')
    .toLocaleLowerCase()
    .replace(/--/g, '-')
}

const selectTemplate = (template: BlockTemplate | null) => {
  selectedTemplate.value = template
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="open = $event"
  >
    <DialogContent class="sm:max-w-lg">
      <form
        class="grid gap-4"
        @submit.prevent="handleCreate(content)"
      >
        <DialogHeaderCombined :title="$t('labels.contents.createContent')" />
        <div class="grid gap-6">
          <InputField
            v-model="content.name"
            :label="$t('labels.contents.fields.name')"
            name="slug"
            required
            auto-focus
            @blur="createSlug"
          />
          <InputField
            v-model="content.slug"
            :label="$t('labels.contents.fields.slug')"
            name="slug"
            required
          />
          <FormField
            name="block"
            :label="$t('labels.contents.fields.block')"
          >
            <template #default="{ id }">
              <Select
                :id="id"
                v-model="content.block_id"
              >
                <SelectTrigger>
                  <div
                    v-if="currentBlock"
                    class="flex items-center gap-2"
                  >
                    <Icon
                      v-if="currentBlock.icon"
                      :name="`lucide:${currentBlock.icon}`"
                      :style="{ color: currentBlock.color }"
                    />
                    <p>{{ currentBlock?.name }}</p>
                  </div>
                </SelectTrigger>
                <SelectContent>
                  <SelectGroup>
                    <SelectItem
                      v-for="block in possibleBlocks"
                      :key="block.id"
                      :value="block.id"
                    >
                      <div class="flex items-center gap-2">
                        <Icon
                          v-if="block.icon"
                          :name="`lucide:${block.icon}`"
                          :style="{ color: block.color }"
                        />
                        <div>
                          {{ block.name }}
                        </div>
                      </div>
                    </SelectItem>
                  </SelectGroup>
                </SelectContent>
              </Select>
            </template>
          </FormField>

          <div v-if="templates?.length">
            <FormField
              name="template"
              :label="$t('labels.contents.fields.template')"
            >
              <Select>
                <SelectTrigger>
                  <IconName
                    v-if="selectedTemplate"
                    :icon="selectedTemplate.icon"
                    :color="selectedTemplate.color"
                    :name="selectedTemplate.name"
                  />
                  <span v-else>{{ $t('labels.contents.blankTemplate') }}</span>
                </SelectTrigger>
                <SelectContent>
                  <SelectGroup>
                    <SelectItem
                      @select="selectTemplate(null)"
                      :value="null"
                    >
                      {{ $t('labels.contents.blankTemplate') }}
                    </SelectItem>
                  </SelectGroup>
                  <SelectGroup>
                    <SelectLabel>{{ $t('labels.contents.templates') }}</SelectLabel>
                    <SelectItem
                      v-for="template in templates"
                      :key="template.id"
                      :value="template.id"
                      @select="selectTemplate(template)"
                    >
                      <IconName
                        :icon="template.icon"
                        :color="template.color"
                        :name="template.name"
                      />
                    </SelectItem>
                  </SelectGroup>
                </SelectContent>
              </Select>
            </FormField>
          </div>
        </div>
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            @click="open = false"
          >
            {{ $t('actions.cancel') }}
          </Button>
          <Button
            variant="primary"
            type="submit"
          >
            {{ $t('actions.create') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
