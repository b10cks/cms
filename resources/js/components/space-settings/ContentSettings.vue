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
import { Input } from '~/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'
import SettingsTable, {
  type ColumnDefinition,
  type TableItem,
} from '~/components/ui/settings-table.vue'
import { Switch } from '~/components/ui/switch'

import IconName from '../ui/IconName.vue'

const { useUpdateSpaceMutation } = useSpaces()
const { mutate: updateSpace } = useUpdateSpaceMutation()
const { useAccessControl } = useAuthorization()

const props = defineProps<{ space: SpaceResource }>()
const access = useAccessControl(computed(() => ({ space_id: props.space.id })))
const canUpdateSpace = computed(() => access.hasAbility('space.update'))

const { useBlocksQuery } = useBlocks(props.space.id)
const { data: blocks } = useBlocksQuery({ per_page: 1000 })
const { $t } = useI18n()

const defaultBlockId = ref(props.space.settings.default_block)
const filterHiddenBlocks = ref(props.space.settings.filter_hidden_blocks ?? false)
const contentSorting = ref(props.space.settings.content_sorting ?? false)
const serialGaps = ref<'preserve' | 'reuse'>(props.space.settings.serial_gaps ?? 'preserve')

const serialGapOptions = computed(() => [
  {
    value: 'preserve',
    label: $t('labels.settings.content.serialGapsPreserve'),
    description: $t('labels.settings.content.serialGapsPreserveDescription'),
  },
  {
    value: 'reuse',
    label: $t('labels.settings.content.serialGapsReuse'),
    description: $t('labels.settings.content.serialGapsReuseDescription'),
  },
])
const sitemapTypes = ref<SpaceSitemapType[]>(deepClone(props.space.settings.sitemap?.types || []))

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

const sitemapNewItemTemplate: SpaceSitemapType = {
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

const namedSitemaps = ref<SpaceNamedSitemap[]>(deepClone(props.space.settings.sitemaps || []))

const namedSitemapColumns = (sitemap: SpaceNamedSitemap): ColumnDefinition<SpaceSitemapType>[] => [
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
        disabled: sitemap.types.some(
          (type) => type.block === block.slug && type.block !== (item.block || '')
        ),
      })),
  },
  {
    key: 'path',
    label: $t('labels.settings.content.sitemap.path'),
    type: 'text',
    placeholder: $t('labels.settings.content.sitemap.pathPlaceholder'),
    required: true,
  },
]

const addNamedSitemap = (): void => {
  namedSitemaps.value.push({ slug: '', types: [] })
}

const removeNamedSitemap = (index: number): void => {
  namedSitemaps.value.splice(index, 1)
}

const addNamedSitemapType = (sitemap: SpaceNamedSitemap, item: SpaceSitemapType): void => {
  sitemap.types.push({
    block: String(item.block || ''),
    path: String(item.path || ''),
  })
}

const cleanedNamedSitemaps = (): SpaceNamedSitemap[] =>
  namedSitemaps.value
    .map((sitemap) => ({
      slug: sitemap.slug.trim().toLowerCase(),
      types: sitemap.types
        .map((type) => ({ block: type.block.trim(), path: type.path.trim() }))
        .filter((type) => type.block && type.path),
    }))
    .filter((sitemap) => sitemap.slug && sitemap.types.length)

const handleSave = () => {
  updateSpace({
    id: props.space.id,
    payload: {
      settings: {
        ...props.space.settings,
        default_block: defaultBlockId.value,
        filter_hidden_blocks: filterHiddenBlocks.value,
        content_sorting: contentSorting.value,
        serial_gaps: serialGaps.value,
        sitemap: {
          types: sitemapTypes.value
            .map((type) => ({
              block: type.block.trim(),
              path: type.path.trim(),
            }))
            .filter((type) => type.block && type.path),
        },
        sitemaps: cleanedNamedSitemaps(),
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
      <div class="space-y-2">
        <div class="flex items-center space-x-2">
          <Switch
            id="content-sorting"
            v-model="contentSorting"
            aria-label="Enable manual content sorting"
          />
          <Label
            for="content-sorting"
            class="text-sm font-medium"
            :label="$t('labels.settings.content.contentSorting')"
          />
        </div>
        <p class="text-xs text-muted">
          {{ $t('labels.settings.content.contentSortingDescription') }}
        </p>
      </div>
      <div class="space-y-2">
        <Label
          class="text-sm font-medium"
          :label="$t('labels.settings.content.serialGaps')"
        />
        <p class="text-xs text-muted">
          {{ $t('labels.settings.content.serialGapsDescription') }}
        </p>
        <div class="space-y-2 pt-1">
          <label
            v-for="option in serialGapOptions"
            :key="option.value"
            class="flex cursor-pointer items-start gap-3 rounded-lg border border-border p-3"
            :class="serialGaps === option.value ? 'border-accent bg-surface' : ''"
          >
            <input
              v-model="serialGaps"
              type="radio"
              name="serial-gaps"
              :value="option.value"
              class="mt-1"
            />
            <span class="space-y-1">
              <span class="block text-sm font-medium">{{ option.label }}</span>
              <span class="block text-xs text-muted">{{ option.description }}</span>
            </span>
          </label>
        </div>
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
          @add="addSitemapType"
          @remove="removeSitemapType"
          @update:items="(items) => (sitemapTypes = items as unknown as SpaceSitemapType[])"
        />
      </div>
      <div class="space-y-4">
        <div class="space-y-1">
          <h3 class="text-sm font-semibold">
            {{ $t('labels.settings.content.sitemaps.title') }}
          </h3>
          <p class="text-xs text-muted">
            {{ $t('labels.settings.content.sitemaps.description') }}
          </p>
        </div>
        <div
          v-for="(sitemap, index) in namedSitemaps"
          :key="index"
          class="space-y-4 rounded-lg border border-border p-4"
        >
          <div class="flex items-end gap-2">
            <FormField
              :name="`sitemap-slug-${index}`"
              class="grow"
              :label="$t('labels.settings.content.sitemaps.slug')"
              :description="$t('labels.settings.content.sitemaps.slugDescription')"
            >
              <Input
                :id="`sitemap-slug-${index}`"
                v-model="sitemap.slug"
                :placeholder="$t('labels.settings.content.sitemaps.slugPlaceholder')"
              />
            </FormField>
            <Button
              variant="ghost"
              @click="removeNamedSitemap(index)"
            >
              {{ $t('labels.settings.content.sitemaps.remove') }}
            </Button>
          </div>
          <SettingsTable
            v-model:items="sitemap.types"
            :columns="namedSitemapColumns(sitemap)"
            :new-item-template="sitemapNewItemTemplate"
            @add="(item) => addNamedSitemapType(sitemap, item)"
            @remove="(typeIndex) => sitemap.types.splice(typeIndex, 1)"
            @update:items="(items) => (sitemap.types = items as unknown as SpaceSitemapType[])"
          />
        </div>
        <Button
          variant="outline"
          @click="addNamedSitemap"
        >
          {{ $t('labels.settings.content.sitemaps.add') }}
        </Button>
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
