<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { deepClone } from '@vue/devtools-shared'
import { AccordionContent, AccordionHeader, AccordionItem, AccordionTrigger } from 'reka-ui'
import AssetBlock from '~/components/blocks/AssetBlock.vue'
import BlocksBlock from '~/components/blocks/BlocksBlock.vue'
import BooleanBlock from '~/components/blocks/BooleanBlock.vue'
import DateBlock from '~/components/blocks/DateBlock.vue'
import LinkBlock from '~/components/blocks/LinkBlock.vue'
import MetaBlock from '~/components/blocks/MetaBlock.vue'
import MultiAssetBlock from '~/components/blocks/MultiAssetBlock.vue'
import NumberBlock from '~/components/blocks/NumberBlock.vue'
import OptionBlock from '~/components/blocks/OptionBlock.vue'
import ReferencesBlock from '~/components/blocks/ReferencesBlock.vue'
import RichTextBlock from '~/components/blocks/RichTextBlock.vue'
import TextareaBlock from '~/components/blocks/TextareaBlock.vue'
import TextBlock from '~/components/blocks/TextBlock.vue'
import BlockType from '~/components/ui/BlockType.vue'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import { CheckboxField, InputField, TextField } from '~/components/ui/form'

const emit = defineEmits(['delete', 'to-page', 'update:name', 'update:item'])

const props = defineProps<{
  item: SchemaType
  pages: EditorPage[]
  currentPage: number
  isOpen: boolean
  readonly: boolean
  name: string
}>()

const localItem = ref({ ...props.item })
const translatable = ['text', 'textarea', 'markdown', 'richtext', 'number', 'link', 'meta']

const schemas = {
  asset: AssetBlock,
  multiAsset: MultiAssetBlock,
  blocks: BlocksBlock,
  boolean: BooleanBlock,
  link: LinkBlock,
  number: NumberBlock,
  option: OptionBlock,
  reference: ReferencesBlock,
  text: TextBlock,
  textarea: TextareaBlock,
  meta: MetaBlock,
  date: DateBlock,
  richtext: RichTextBlock,
}

watch(
  props.item as SchemaType,
  (newItem) => {
    localItem.value = { ...newItem }
  },
  { deep: true }
)

const updateValue = (key: string, value: unknown) => {
  emit('update:item', {
    ...deepClone(props.item),
    [key]: value,
  })
}
</script>

<template>
  <AccordionItem :value="name">
    <AccordionHeader class="group flex items-center gap-3">
      <div
        class="flex h-full cursor-ns-resize items-center"
        draggable
      >
        <Icon name="lucide:grip-vertical" />
      </div>
      <BlockType :type="item?.type" />
      <AccordionTrigger class="flex grow cursor-pointer flex-col text-left">
        <h4 class="font-bold">{{ item?.name || name }}</h4>
        <div class="text-sm">{{ $t(`labels.blocks.fieldTypes.${item?.type}.label`) }}</div>
      </AccordionTrigger>
      <div
        class="ml-auto flex items-center gap-3 opacity-0 transition-opacity duration-200 ease-in-out group-hover:opacity-100"
      >
        <DropdownMenu>
          <DropdownMenuTrigger
            class="cursor-pointer hover:text-primary focus:text-primary"
            v-if="!readonly"
            :disabled="pages.length <= 1"
          >
            <Icon name="lucide:folder-input" />
          </DropdownMenuTrigger>
          <DropdownMenuContent>
            <DropdownMenuRadioGroup :model-value="`${currentPage}`">
              <DropdownMenuRadioItem
                v-for="(page, i) in pages"
                :key="i"
                :value="`${i}`"
                @click="$emit('to-page', i)"
              >
                {{ page.header }}
              </DropdownMenuRadioItem>
            </DropdownMenuRadioGroup>
          </DropdownMenuContent>
        </DropdownMenu>
        <button
          v-if="!readonly"
          class="cursor-pointer hover:text-destructive focus:text-destructive"
          type="button"
          @click="$emit('delete', name)"
        >
          <Icon name="lucide:trash-2" />
        </button>
        <AccordionTrigger class="cursor-pointer">
          <Icon
            name="lucide:chevron-down"
            :class="['transition-transform duration-200 ease-in-out', isOpen && 'rotate-180']"
          />
        </AccordionTrigger>
      </div>
    </AccordionHeader>
    <AccordionContent class="flex flex-col gap-6 p-2 pt-4">
      <InputField
        :model-value="name"
        :label="$t('labels.blocks.fields.slug')"
        name="key"
        disabled
      />
      <InputField
        :model-value="localItem.name"
        :label="$t('labels.blocks.fields.name')"
        name="name"
        :disabled="readonly"
        @update:model-value="(v) => updateValue('name', v)"
      />
      <TextField
        :model-value="localItem.description"
        :label="$t('labels.blocks.fields.description')"
        name="description"
        :disabled="readonly"
        @update:model-value="(v) => updateValue('description', v)"
      />
      <CheckboxField
        :model-value="localItem.required"
        name="required"
        :label="$t('labels.blocks.fields.required')"
        :tooltip="$t('labels.blocks.fields.requiredTooltip')"
        :disabled="readonly"
        @update:model-value="(v) => updateValue('required', v)"
      />
      <CheckboxField
        v-if="translatable.includes(item.type)"
        :model-value="localItem.translatable"
        name="translatable"
        :label="$t('labels.blocks.fields.translatable')"
        :tooltip="$t('labels.blocks.fields.translatableTooltip')"
        :disabled="readonly"
        @update:model-value="(v) => updateValue('translatable', v)"
      />
      <component
        :is="schemas[localItem.type]"
        :name="name"
        :value="item as SchemaType"
        :readonly="readonly"
        @update:item-value="(key, value) => updateValue(key, value)"
      />
    </AccordionContent>
  </AccordionItem>
</template>
