<script setup lang="ts">
import {
  DropdownMenuItem,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
} from '~/components/ui/dropdown-menu'
import IconName from '~/components/ui/IconName.vue'

const emit = defineEmits<{
  (e: 'select', payload: { blockSlug: string; template: BlockTemplate | null }): void
}>()

const props = defineProps<{
  block: BlockResource
  spaceId: string
}>()

const { useBlockTemplatesQuery } = useBlockTemplates(
  () => props.spaceId,
  () => props.block.id
)
const { data: templates } = useBlockTemplatesQuery()

const select = (template: BlockTemplate | null = null) => {
  emit('select', { blockSlug: props.block.slug, template })
}
</script>

<template>
  <DropdownMenuSub>
    <DropdownMenuSubTrigger class="flex items-center gap-2">
      <IconName
        :icon="block.icon"
        :color="block.color"
        :name="block.name"
      />
    </DropdownMenuSubTrigger>
    <DropdownMenuSubContent>
      <DropdownMenuItem @select="select()">
        {{ $t('labels.contents.blankTemplate') }}
      </DropdownMenuItem>
      <DropdownMenuItem
        v-for="template in templates"
        :key="template.id"
        @select="select(template)"
      >
        <IconName
          :icon="template.icon"
          :color="template.color"
          :name="template.name"
        />
      </DropdownMenuItem>
    </DropdownMenuSubContent>
  </DropdownMenuSub>
</template>
