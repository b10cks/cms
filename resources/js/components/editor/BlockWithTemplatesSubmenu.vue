<script setup lang="ts">
import {
  DropdownMenuItem,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
} from '~/components/ui/dropdown-menu'
import IconName from '~/components/ui/IconName.vue'

const emit = defineEmits<(e: 'select', blockSlug: string, templateId?: string | null) => void>()

const props = defineProps<{
  block: BlockResource
  spaceId: string
}>()

const { useBlockTemplatesQuery } = useBlockTemplates(
  () => props.spaceId,
  () => props.block.id
)
const { data: templates } = useBlockTemplatesQuery()

const select = (templateId?: string | null) => {
  emit('select', props.block.slug, templateId)
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
      <DropdownMenuItem @select="select(null)">
        {{ $t('labels.contents.blankTemplate') }}
      </DropdownMenuItem>
      <DropdownMenuItem
        v-for="template in templates"
        :key="template.id"
        @select="select(template.id)"
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
