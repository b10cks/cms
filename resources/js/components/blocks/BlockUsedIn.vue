<script setup lang="ts">
import { RouterLink } from 'vue-router'

import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import IconName from '~/components/ui/IconName.vue'

const props = defineProps<{
  spaceId: string
  block: BlockResource
}>()

interface UsageEntry {
  block: BlockResource
  fields: Array<{ key: string; name: string }>
}

const { useBlocksQuery } = useBlocks(props.spaceId)
const { data: blocks } = useBlocksQuery({ per_page: 1000 })

const isNestable = computed(() => ['nestable', 'universal'].includes(props.block.type))
const isRootable = computed(() => ['root', 'universal'].includes(props.block.type))

const fieldAllowsBlock = (field: BlocksSchema) => {
  const blockWhitelist = (field.block_whitelist || []).filter(Boolean)
  const tagWhitelist = (field.tag_whitelist || []).filter(Boolean)
  const hasExplicitAllowlists = blockWhitelist.length > 0 || tagWhitelist.length > 0
  const restrictionEnabled = field.restrict_blocks || field.restrict_tags || hasExplicitAllowlists

  if (!restrictionEnabled || !hasExplicitAllowlists) {
    return true
  }

  return (
    blockWhitelist.includes(props.block.slug) ||
    Boolean(props.block.tags?.some((tag) => tagWhitelist.includes(tag)))
  )
}

const referenceAllowsBlock = (field: ReferencesSchema) => {
  const blockWhitelist = (field.block_whitelist || []).filter(Boolean)

  if (blockWhitelist.length === 0) {
    return isRootable.value
  }

  return blockWhitelist.includes(props.block.slug)
}

const collectUsages = (matches: (field: SchemaType) => boolean): UsageEntry[] => {
  return (blocks.value?.data || [])
    .map((candidate: BlockResource) => ({
      block: candidate,
      fields: Object.entries(candidate.schema || {})
        .filter(([, field]) => matches(field))
        .map(([key, field]) => ({ key, name: field.name || key })),
    }))
    .filter((entry: UsageEntry) => entry.fields.length > 0)
}

const nestedIn = computed<UsageEntry[]>(() => {
  if (!isNestable.value) return []

  return collectUsages(
    (field) => ['blocks', 'block'].includes(field.type) && fieldAllowsBlock(field as BlocksSchema)
  )
})

const referencedBy = computed<UsageEntry[]>(() =>
  collectUsages(
    (field) =>
      ['references', 'reference'].includes(field.type) &&
      referenceAllowsBlock(field as ReferencesSchema)
  )
)

const blockRoute = (blockId: string) => ({
  name: 'space-block' as const,
  params: { space: props.spaceId, block: blockId },
})
</script>

<template>
  <section class="flex flex-col gap-3 rounded-lg border border-input p-4">
    <div>
      <h3 class="font-semibold text-primary">{{ $t('labels.blocks.usedIn.title') }}</h3>
      <p class="text-sm text-muted-foreground">
        {{ $t('labels.blocks.usedIn.description') }}
      </p>
    </div>

    <div class="flex flex-col gap-2">
      <h4 class="text-xs font-medium tracking-wider text-muted uppercase">
        {{ $t('labels.blocks.usedIn.nestedIn') }}
      </h4>
      <p
        v-if="!isNestable"
        class="text-sm text-muted-foreground"
      >
        {{ $t('labels.blocks.usedIn.notNestable', { type: $t(`labels.blocks.types.${block.type}.label`) }) }}
      </p>
      <p
        v-else-if="nestedIn.length === 0"
        class="text-sm text-muted-foreground"
      >
        {{ $t('labels.blocks.usedIn.emptyNested') }}
      </p>
      <ul
        v-else
        class="flex flex-col"
      >
        <li
          v-for="entry in nestedIn"
          :key="entry.block.id"
        >
          <RouterLink
            :to="blockRoute(entry.block.id)"
            class="flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-secondary/20"
          >
            <IconName
              :icon="entry.block.icon || null"
              :color="entry.block.color"
              :name="entry.block.name"
            />
            <Badge
              v-for="field in entry.fields"
              :key="field.key"
              variant="secondary"
              size="sm"
            >
              {{ field.name }}
            </Badge>
            <Icon
              name="lucide:arrow-right"
              class="ml-auto text-muted"
            />
          </RouterLink>
        </li>
      </ul>
    </div>

    <div
      v-if="referencedBy.length > 0"
      class="flex flex-col gap-2"
    >
      <h4 class="text-xs font-medium tracking-wider text-muted uppercase">
        {{ $t('labels.blocks.usedIn.referencedBy') }}
      </h4>
      <ul class="flex flex-col">
        <li
          v-for="entry in referencedBy"
          :key="entry.block.id"
        >
          <RouterLink
            :to="blockRoute(entry.block.id)"
            class="flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-secondary/20"
          >
            <IconName
              :icon="entry.block.icon || null"
              :color="entry.block.color"
              :name="entry.block.name"
            />
            <Badge
              v-for="field in entry.fields"
              :key="field.key"
              variant="secondary"
              size="sm"
            >
              {{ field.name }}
            </Badge>
            <Icon
              name="lucide:arrow-right"
              class="ml-auto text-muted"
            />
          </RouterLink>
        </li>
      </ul>
    </div>
  </section>
</template>
