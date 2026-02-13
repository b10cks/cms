<script setup lang="ts">
import Icon from '~/components/Icon.vue'

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
import { FormField } from '~/components/ui/form'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'

const { useUpdateSpaceMutation } = useSpaces()
const { mutate: updateSpace } = useUpdateSpaceMutation()

const props = defineProps<{ space: SpaceResource }>()

const { useBlocksQuery } = useBlocks(props.space.id)
const { data: blocks } = useBlocksQuery({ per_page: 1000 })

const defaultBlock = ref(props.space.settings.default_block)

const handleSave = () => {
  updateSpace({
    id: props.space.id,
    payload: {
      settings: {
        ...props.space.settings,
        default_block: defaultBlock.value,
      },
    },
  })
}
</script>

<template>
  <Card variant="outline">
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
        <Select v-model="defaultBlock">
          <SelectTrigger id="default-block">
            <SelectValue :placeholder="$t('labels.settings.content.selectDefaultBlock')" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem
              v-for="block in blocks"
              :key="block.id"
              :value="block.id"
            >
              <div class="flex items-center gap-2">
                <Icon
                  :name="`lucide:${block.icon}`"
                  :style="{ color: block.color }"
                />
                <span>{{ block.name }}</span>
              </div>
            </SelectItem>
          </SelectContent>
        </Select>
      </FormField>
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
