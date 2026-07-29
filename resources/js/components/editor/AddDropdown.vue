<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import NuxtImg from '~/components/NuxtImg.vue'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '~/components/ui/command'
import IconName from '~/components/ui/IconName.vue'
import { Popover, PopoverContent, PopoverTrigger } from '~/components/ui/popover'

const emit = defineEmits<{
  (e: 'select', payload: { blockSlug: string; template: BlockTemplate | null }): void
  (e: 'paste'): void
}>()

const props = defineProps<{
  item: BlocksSchema
  hasClipboardItem: boolean
  spaceId: string
  canMutate?: boolean
}>()

const isOpen = ref(false)
const templatesBlock = ref<BlockResource | null>(null)

const { useBlocksQuery } = useBlocks(props.spaceId)
const { data: blocks } = useBlocksQuery({ per_page: 1000 })

const { useBlockTemplatesQuery } = useBlockTemplates(
  computed(() => props.spaceId),
  computed(() => templatesBlock.value?.id || '')
)
const { data: templates } = useBlockTemplatesQuery()

const activeBlockWhitelist = computed(() =>
  (props.item.block_whitelist || []).filter((slug): slug is string => Boolean(slug))
)

const activeTagWhitelist = computed(() =>
  (props.item.tag_whitelist || []).filter((tag): tag is string => Boolean(tag))
)

const possibleBlocks = computed(() => {
  return (
    blocks.value?.data.filter((block: BlockResource) => {
      const isValidType = ['nestable', 'universal'].includes(block.type)
      const blockAllowlistActive = activeBlockWhitelist.value.length > 0
      const tagAllowlistActive = activeTagWhitelist.value.length > 0
      const hasExplicitAllowlists = blockAllowlistActive || tagAllowlistActive
      const restrictionEnabled =
        props.item.restrict_blocks || props.item.restrict_tags || hasExplicitAllowlists
      const matchesBlockWhitelist = activeBlockWhitelist.value.includes(block.slug)
      const matchesTagWhitelist = Boolean(
        block.tags?.some((tag) => activeTagWhitelist.value.includes(tag))
      )

      if (!isValidType) {
        return false
      }

      if (!restrictionEnabled || !hasExplicitAllowlists) {
        return true
      }

      return matchesBlockWhitelist || matchesTagWhitelist
    }) || []
  )
})

const select = (payload: { blockSlug: string; template: BlockTemplate | null }) => {
  emit('select', payload)
  isOpen.value = false
}

const pickBlock = (block: BlockResource) => {
  if (block.templates_count && block.templates_count > 0) {
    templatesBlock.value = block
    return
  }

  select({ blockSlug: block.slug, template: null })
}

const handleOpen = (newIsOpen: boolean) => {
  templatesBlock.value = null

  if (newIsOpen && possibleBlocks.value.length === 1) {
    select({ blockSlug: possibleBlocks.value[0].slug, template: null })
  }
}

const handleInputKeydown = (event: KeyboardEvent) => {
  const input = event.target as HTMLInputElement

  if (event.key === 'Backspace' && !input.value && templatesBlock.value) {
    templatesBlock.value = null
  }
}

interface PreviewDetails {
  name: string
  description?: string | null
  preview_file?: string | null
}

const highlightedItem = ref<PreviewDetails | null>(null)

const previewItem = computed(() => {
  const item = highlightedItem.value
  return item && (item.preview_file || item.description) ? item : null
})

const handleHighlight = (item?: { value?: unknown }) => {
  const value = item?.value

  if (typeof value !== 'string') {
    highlightedItem.value = null
    return
  }

  highlightedItem.value = templatesBlock.value
    ? (templates.value?.find((template) => template.id === value) ?? null)
    : (possibleBlocks.value.find((block) => block.slug === value) ?? null)
}

watch([isOpen, templatesBlock], () => {
  highlightedItem.value = null
})
</script>

<template>
  <Popover
    v-if="props.canMutate !== false"
    v-model:open="isOpen"
    @update:open="handleOpen"
  >
    <div class="flex opacity-0 transition-opacity hover:opacity-100">
      <div class="absolute inset-x-0 -mt-3 border-t-2 border-accent pt-4" />
      <div class="absolute inset-x-0 z-10 mx-auto -mt-6 flex transform justify-center gap-2">
        <PopoverTrigger as-child>
          <button
            class="flex size-6 cursor-pointer items-center justify-center rounded-full bg-accent text-accent-foreground"
            :aria-label="$t('actions.blocks.add')"
          >
            <Icon name="lucide:plus" />
          </button>
        </PopoverTrigger>
        <button
          v-if="hasClipboardItem"
          class="flex size-6 cursor-pointer items-center justify-center rounded-full bg-accent text-accent-foreground"
          :aria-label="$t('actions.blocks.tooltips.paste')"
          @click="emit('paste')"
        >
          <Icon name="lucide:clipboard-paste" />
        </button>
      </div>
    </div>
    <PopoverContent
      align="center"
      class="relative w-64 p-0!"
    >
      <Command
        highlight-on-hover
        @highlight="handleHighlight"
      >
        <CommandInput
          :placeholder="$t('labels.contents.canvas.searchBlocks')"
          @keydown="handleInputKeydown"
        />
        <CommandList
          class="max-h-[min(18rem,calc(var(--reka-popover-content-available-height)-4rem))]"
        >
          <CommandEmpty>{{ $t('labels.search.noResultsFound') }}</CommandEmpty>
          <CommandGroup v-if="!templatesBlock">
            <CommandItem
              v-for="block in possibleBlocks"
              :key="block.slug"
              :value="block.slug"
              class="cursor-pointer"
              @select="pickBlock(block)"
            >
              <IconName
                :icon="block.icon || null"
                :color="block.color"
                :name="block.name"
              />
              <Icon
                v-if="block.templates_count && block.templates_count > 0"
                name="lucide:chevron-right"
                class="ml-auto opacity-50"
              />
            </CommandItem>
          </CommandGroup>
          <CommandGroup v-else>
            <CommandItem
              value="__back"
              class="cursor-pointer"
              @select="templatesBlock = null"
            >
              <Icon
                name="lucide:chevron-left"
                class="opacity-50"
              />
              <IconName
                :icon="templatesBlock.icon || null"
                :color="templatesBlock.color"
                :name="templatesBlock.name"
              />
            </CommandItem>
            <CommandItem
              value="__blank"
              class="cursor-pointer"
              @select="select({ blockSlug: templatesBlock!.slug, template: null })"
            >
              {{ $t('labels.contents.blankTemplate') }}
            </CommandItem>
            <CommandItem
              v-for="template in templates"
              :key="template.id"
              :value="template.id"
              class="cursor-pointer"
              @select="select({ blockSlug: templatesBlock!.slug, template })"
            >
              <IconName
                :icon="template.icon"
                :color="template.color"
                :name="template.name"
              />
            </CommandItem>
          </CommandGroup>
        </CommandList>
      </Command>
      <div
        v-if="previewItem"
        class="absolute top-0 right-full mr-3 w-96 overflow-hidden rounded-md border border-border bg-popover shadow-md"
      >
        <NuxtImg
          v-if="previewItem.preview_file"
          :src="previewItem.preview_file"
          :alt="previewItem.name"
          :width="384"
          :height="288"
          :modifiers="{ crop: 'fit' }"
          class="max-h-72 w-full border-b border-border bg-surface/50 object-contain"
        />
        <div class="grid gap-1 p-3">
          <p class="text-sm font-semibold text-primary">{{ previewItem.name }}</p>
          <p
            v-if="previewItem.description"
            class="text-sm text-muted"
          >
            {{ previewItem.description }}
          </p>
        </div>
      </div>
    </PopoverContent>
  </Popover>
</template>
