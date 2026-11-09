<script setup lang="ts">
import type { AiOwner } from '~/api/resources/ai-knowledge'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import { aiSettingsRoutes } from '~/lib/aiSettingsRoutes'

/** "New" menu of the knowledge list: a style guide or reference document opens its editor. */
const props = withDefaults(
  defineProps<{
    owner: AiOwner
    size?: 'default' | 'sm'
  }>(),
  { size: 'default' }
)

const router = useRouter()
const routes = computed(() => aiSettingsRoutes(props.owner))

const newDocument = (type: 'style' | 'reference') =>
  void router.push(routes.value.document('new', { type }))
</script>
<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button
        variant="primary"
        :size="size"
      >
        <Icon name="lucide:plus" />
        {{ $t('components.aiSettings.knowledge.new') }}
        <Icon name="lucide:chevron-down" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end">
      <DropdownMenuItem @select="newDocument('style')">
        <Icon name="lucide:pen-line" />
        {{ $t('components.aiSettings.knowledge.types.style') }}
      </DropdownMenuItem>
      <DropdownMenuItem @select="newDocument('reference')">
        <Icon name="lucide:file-text" />
        {{ $t('components.aiSettings.knowledge.types.reference') }}
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
