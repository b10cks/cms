<script setup lang="ts">
import { deepClone } from '@vue/devtools-shared'

import { Button } from '~/components/ui/button'
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from '~/components/ui/card'
import { FormField, Label } from '~/components/ui/form'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'
import SettingsTable, { type ColumnDefinition, type TableItem } from '~/components/ui/settings-table.vue'
import { Switch } from '~/components/ui/switch'

import IconName from '../ui/IconName.vue'

interface SitemapTypeMapping extends TableItem {
  block: string
  path: string
}

interface ContentSettingsSpace {
  id: string
  settings: {
    default_block?: string
    filter_hidden_blocks?: boolean
    sitemap?: {
      types?: SitemapTypeMapping[]
    }
  }
}

const { useUpdateSpaceMutation } = useSpaces()
const { mutate: updateSpace } = useUpdateSpaceMutation()
const { useAccessControl } = useAuthorization()

const props = defineProps<{ space: ContentSettingsSpace }>()
const access = useAccessControl(computed(() => ({ space_id: props.space.id })))
const canUpdateSpace = computed(() => access.hasAbility('space.update'))

const { useBlocksQuery } = useBlocks(props.space.id)
const { data: blocks } = useBlocksQuery({ per_page: 1000 })
const { $t } = useI18n()

const defaultBlockId = ref(props.space.settings.default_block)
const filterHiddenBlocks = ref(props.space.settings.filter_hidden_blocks ?? false)
const sitemapTypes = ref<SitemapTypeMapping[]>(deepClone(props.space.settings.sitemap?.types || []))

const availableBlocks = computed(
  () => blocks.value?.data?.filter(({ type }) => ['root', 'universal'].includes(type)) || []
)
const sitemapBlocks = computed(
  () => blocks.value?.data?.filter(({ type }) => ['root', 'universal'].includes(type)) || []
)
const defaultBlock = computed(() =>
  availableBlocks.value?.find(({ id }) => id === defaultBlockId.value)
)

const isBlockTaken = (blockSlug: string, currentBlock?: string): boolean => {
  return sitemapTypes.value.some(
    (type) => type.block === blockSlug && type.block !== (currentBlock || '')
  )
}

const sitemapColumns = computed<ColumnDefinition[]>(() => [
  {
    key: 'block',
    label: $t('labels.settings.content.sitemap.block'),
    type: 'select',
    placeholder: $t('labels.settings.content.sitemap.selectBlock'),
    required: true,
    options: (item) =>
      sitemapBlocks.value.map((block) => ({
        value: block.slug,
        label: block.name,
        disabled: isBlockTaken(block.slug, typeof item.block === 'string' ? item.block : undefined),
      })),
  },
  {
    key: 'path',
    label: $t('labels.settings.content.sitemap.path'),
    type: 'text',
    placeholder: $t('labels.settings.content.sitemap.pathPlaceholder'),
    required: true,
  },
])

const sitemapNewItemTemplate: SitemapTypeMapping = {
  block: '',
  path: '',
}

const addSitemapType = (item: TableItem): void => {
  sitemapTypes.value.push({
    block: String(item.block || ''),
    path: String(item.path || ''),
  })
}

const removeSitemapType = (index: number): void => {
  sitemapTypes.value.splice(index, 1)
}

const handleSave = () => {
  updateSpace({
    id: props.space.id,
    payload: {
      settings: {
        ...props.space.settings,
        default_block: defaultBlockId.value,
        filter_hidden_blocks: filterHiddenBlocks.value,
        sitemap: {
          types: sitemapTypes.value
            .map((type) => ({
              block: type.block.trim(),
              path: type.path.trim(),
            }))
            .filter((type) => type.block && type.path),
        },
      },
    },
  })
}
</script>

<template>
  <Card variant="none">
    <CardHeader>
      <CardTitle>{{ $t('labels.settings.content.title') }}</CardTitle>
      <CardDescription>{{ $t('labels.settings.content.description') }}</CardDescription>
    </CardHeader>
    <CardContent class="space-y-6">
      <FormField
        name="default-block"
        :label="$t('labels.settings.content.defaultBlock')"
        :description="$t('labels.settings.content.defaultBlockDescription')"
      >
        <Select v-model="defaultBlockId">
          <SelectTrigger id="default-block">
            <SelectValue>
              <IconName
                v-if="defaultBlock"
                :icon="defaultBlock?.icon"
                :color="defaultBlock?.color"
                :name="defaultBlock?.name"
              />
              <span v-else>{{ $t('labels.settings.content.selectDefaultBlock') }}</span>
            </SelectValue>
          </SelectTrigger>
          <SelectContent>
            <SelectItem
              v-for="block in availableBlocks"
              :key="block.id"
              :value="block.id"
            >
              <IconName
                :icon="block.icon"
                :color="block.color"
                :name="block.name"
              />
            </SelectItem>
            <div v-if="!availableBlocks.length">{{ $t('labels.settings.content.noBlocks') }}</div>
          </SelectContent>
        </Select>
      </FormField>
      <div class="space-y-2">
        <div class="flex items-center space-x-2">
          <Switch
            id="filter-hidden-blocks"
            v-model="filterHiddenBlocks"
            aria-label="Filter hidden blocks from Data API"
          />
          <Label
            for="filter-hidden-blocks"
            class="text-sm font-medium"
            :label="$t('labels.settings.content.filterHiddenBlocks')"
          />
        </div>
        <p class="text-xs text-muted">
          {{ $t('labels.settings.content.filterHiddenBlocksDescription') }}
        </p>
      </div>
      <div class="space-y-4">
        <div class="space-y-1">
          <h3 class="text-sm font-semibold">
            {{ $t('labels.settings.content.sitemap.title') }}
          </h3>
          <p class="text-xs text-muted">
            {{ $t('labels.settings.content.sitemap.description') }}
          </p>
        </div>
        <SettingsTable
          v-model:items="sitemapTypes"
          :columns="sitemapColumns"
          :new-item-template="sitemapNewItemTemplate"
          :add-button-label="'labels.settings.content.sitemap.addType'"
          @add="addSitemapType"
          @remove="removeSitemapType"
          @update:items="(items) => (sitemapTypes = items as unknown as SitemapTypeMapping[])"
        />
      </div>
    </CardContent>
    <CardFooter>
      <Button
        v-if="canUpdateSpace"
        variant="primary"
        @click="handleSave"
        >{{ $t('actions.saveChanges') }}
      </Button>
    </CardFooter>
  </Card>
</template>
