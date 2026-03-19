<script setup lang="ts">
import { ref } from 'vue'

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
import { Switch } from '~/components/ui/switch'

import IconName from '../ui/IconName.vue'

const { useUpdateSpaceMutation } = useSpaces()
const { mutate: updateSpace } = useUpdateSpaceMutation()

const props = defineProps<{ space: SpaceResource }>()

const { useBlocksQuery } = useBlocks(props.space.id)
const { data: blocks } = useBlocksQuery({ per_page: 1000 })

const defaultBlockId = ref(props.space.settings.default_block)
const filterHiddenBlocks = ref(props.space.settings.filter_hidden_blocks ?? false)

const availableBlocks = computed(
  () => blocks.value?.data?.filter(({ type }) => ['root', 'universal'].includes(type)) || []
)
const defaultBlock = computed(() =>
  availableBlocks.value?.find(({ id }) => id === defaultBlockId.value)
)

const handleSave = () => {
  updateSpace({
    id: props.space.id,
    payload: {
      settings: {
        ...props.space.settings,
        default_block: defaultBlockId.value,
        filter_hidden_blocks: filterHiddenBlocks.value,
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
    </CardContent>
    <CardFooter>
      <Button
        variant="primary"
        @click="handleSave"
        >{{ $t('actions.saveChanges') }}
      </Button>
    </CardFooter>
  </Card>
</template>
