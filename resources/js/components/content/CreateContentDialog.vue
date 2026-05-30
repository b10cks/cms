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
import {
  createContentDefaultsBlockLookup,
  hydrateContentWithSchema,
} from '~/composables/useContentDefaults'
import {
  resolveCreateContentBlocks,
  resolvePreferredCreateContentBlock,
} from '~/lib/content-children'
import type { CreateContentPayload } from '~/types/contents'

const open = defineModel<boolean>('open')
const props = withDefaults(
  defineProps<{
    spaceId: string
    parentId?: string | null
    onSubmit?: (payload: CreateContentPayload) => Promise<void> | void
  }>(),
  {
    parentId: undefined,
  }
)

const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(props.spaceId)

const { useBlocksQuery } = useBlocks(props.spaceId)
const { data: blocks } = useBlocksQuery({ per_page: 1000 })
const { useContentQuery } = useContent(props.spaceId)

const content = ref<CreateContentPayload>({
  block_id: '',
  slug: '',
  name: '',
})

const parentContentId = computed(() => (open.value && props.parentId ? props.parentId : null))
const { data: parentContent, isLoading: isLoadingParentContent } = useContentQuery(parentContentId)
const canonicalParentContentId = computed(() =>
  parentContent.value && parentContent.value.i18n_parent_id
    ? parentContent.value.i18n_parent_id
    : null
)
const { data: canonicalParentContent, isLoading: isLoadingCanonicalParentContent } =
  useContentQuery(canonicalParentContentId)

const blockId = computed(() => content.value.block_id)
const { useBlockTemplatesQuery } = useBlockTemplates(props.spaceId, blockId)
const selectedTemplate = ref<BlockTemplate | null>(null)
const canonicalParentSettings = computed(
  () => canonicalParentContent.value?.settings || parentContent.value?.settings || null
)
const isLoadingParentRestrictions = computed(
  () => !!props.parentId && (isLoadingParentContent.value || isLoadingCanonicalParentContent.value)
)
const blockLookup = computed<Record<string, Pick<BlockResource, 'slug' | 'schema'>>>(() =>
  createContentDefaultsBlockLookup(blocks.value?.data || [])
)

const handleCreate = async (editContent: CreateContentPayload) => {
  if (!editContent.block_id) {
    return
  }

  const payload: CreateContentPayload = {
    ...editContent,
    parent_id: props.parentId,
  }

  if (currentBlock.value?.schema && selectedTemplate.value) {
    payload.content = hydrateContentWithSchema(
      currentBlock.value.schema,
      selectedTemplate.value.content || {},
      blockLookup.value
    )
  }

  await props.onSubmit?.(payload)
  open.value = false
  resetForm()
}

const resetForm = () => {
  content.value = {
    block_id: '',
    slug: '',
    name: '',
  }
  selectedTemplate.value = null
}

const possibleBlocks = computed(() => {
  return resolveCreateContentBlocks({
    blocks: blocks.value?.data,
    parentSettings: canonicalParentSettings.value,
    isChild: !!props.parentId,
  })
})

const currentBlock = computed(() => {
  return possibleBlocks.value?.find((b: BlockResource) => b.id === content.value?.block_id)
})

const preferredBlockId = computed(() => {
  return resolvePreferredCreateContentBlock({
    availableBlocks: possibleBlocks.value,
    parentSettings: props.parentId ? canonicalParentSettings.value : null,
    spaceDefaultBlockId: space.value?.settings.default_block,
  })
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

const syncSelectedBlock = () => {
  const possibleBlockIds = possibleBlocks.value.map((block) => block.id)

  if (possibleBlockIds.length === 0) {
    content.value.block_id = ''
    selectedTemplate.value = null
    return
  }

  if (possibleBlockIds.includes(content.value.block_id)) {
    return
  }

  content.value.block_id = preferredBlockId.value
  selectedTemplate.value = null
}

watch([possibleBlocks, preferredBlockId], syncSelectedBlock, { immediate: true })
watch(blockId, () => {
  selectedTemplate.value = null
})
watch(open, (isOpen, wasOpen) => {
  if (isOpen) {
    syncSelectedBlock()
    return
  }

  if (wasOpen) {
    resetForm()
  }
})
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
          <div
            v-if="props.parentId && isLoadingParentRestrictions"
            class="rounded-lg border border-border bg-surface px-4 py-3 text-sm text-muted"
          >
            {{ $t('labels.contents.create.loadingRestrictions') }}
          </div>
          <div
            v-else-if="!possibleBlocks.length"
            class="rounded-lg border border-border bg-surface px-4 py-3 text-sm text-muted"
          >
            {{ $t('labels.contents.create.noAllowedChildren') }}
          </div>
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
                :disabled="isLoadingParentRestrictions || !possibleBlocks.length"
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
                  <span
                    v-else
                    class="text-muted"
                  >
                    {{ $t('labels.contents.create.selectContentType') }}
                  </span>
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
            :disabled="isLoadingParentRestrictions || !possibleBlocks.length || !content.block_id"
          >
            {{ $t('actions.create') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
