<script setup lang="ts">
import { toast } from 'vue-sonner'

import Icon from '~/components/Icon.vue'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import IconName from '~/components/ui/IconName.vue'
import type { ItemBlock } from '~/lib/blockItemTitle'

/** The "more actions" menu of one item in a blocks field. */
const props = defineProps<{
  spaceId: string
  itemId?: string | null
  block: ItemBlock
  /** Blocks the item may become; the current one is left out here. */
  allowedBlocks: BlockResource[]
  /** Preview URL of the edited content, without a hash. */
  pageUrl?: string | null
  /** Site-relative path of the edited content, without a hash. */
  pagePath?: string | null
  canManageBlock?: boolean
  readOnly?: boolean
}>()

const emit = defineEmits<{
  switchType: [blockSlug: string]
  createTemplate: []
}>()

const { $t } = useI18n()
const router = useRouter()

const switchTargets = computed(() =>
  props.readOnly ? [] : props.allowedBlocks.filter((block) => block.slug !== props.block.slug)
)
const canCreateTemplate = computed(
  () => !props.readOnly && Boolean(props.itemId) && Boolean(props.block.id)
)
const canManage = computed(() => Boolean(props.canManageBlock) && Boolean(props.block.id))
const hasBlockActions = computed(() => canCreateTemplate.value || canManage.value)

const copy = (text: string, message: string) => {
  navigator.clipboard.writeText(text).then(() => toast.success(message))
}

const copyLink = (base: string) => {
  copy(`${base}#${props.itemId}`, String($t('notifications.preview.copied')))
}

const manageBlock = () => {
  router.push({ name: 'space-block', params: { space: props.spaceId, block: props.block.id } })
}
</script>

<template>
  <DropdownMenu v-if="itemId || switchTargets.length > 0 || hasBlockActions">
    <DropdownMenuTrigger as-child>
      <button
        type="button"
        :aria-label="$t('actions.moreActions')"
        :title="$t('actions.moreActions')"
        class="flex transform cursor-pointer items-center hover:text-primary"
        @click.stop
        @keydown.stop
      >
        <Icon name="lucide:ellipsis-vertical" />
      </button>
    </DropdownMenuTrigger>
    <DropdownMenuContent
      align="end"
      class="min-w-56!"
    >
      <template v-if="itemId">
        <DropdownMenuLabel class="select-text">{{ itemId }}</DropdownMenuLabel>
        <DropdownMenuSeparator />
      </template>
      <template v-if="switchTargets.length > 0">
        <DropdownMenuSub>
          <DropdownMenuSubTrigger>
            <Icon name="lucide:replace" />
            <span>{{ $t('actions.blocks.item.switchType') }}</span>
          </DropdownMenuSubTrigger>
          <DropdownMenuSubContent class="max-h-72 overflow-y-auto!">
            <DropdownMenuItem
              v-for="target in switchTargets"
              :key="target.slug"
              @select="emit('switchType', target.slug)"
            >
              <IconName
                :icon="target.icon || null"
                :color="target.color"
                :name="target.name"
                show-placeholder
              />
            </DropdownMenuItem>
          </DropdownMenuSubContent>
        </DropdownMenuSub>
        <DropdownMenuSeparator v-if="itemId || hasBlockActions" />
      </template>
      <template v-if="itemId">
        <DropdownMenuItem @select="copy(itemId, String($t('actions.copied')))">
          <Icon name="lucide:copy" />
          <span>{{ $t('actions.blocks.item.copyId') }}</span>
        </DropdownMenuItem>
        <DropdownMenuItem
          v-if="pageUrl"
          @select="copyLink(pageUrl)"
        >
          <Icon name="lucide:link" />
          <span>{{ $t('actions.blocks.item.copyAbsoluteLink') }}</span>
        </DropdownMenuItem>
        <DropdownMenuItem
          v-if="pagePath"
          @select="copyLink(pagePath)"
        >
          <Icon name="lucide:link-2" />
          <span>{{ $t('actions.blocks.item.copyRelativeLink') }}</span>
        </DropdownMenuItem>
        <DropdownMenuSeparator v-if="hasBlockActions" />
      </template>
      <DropdownMenuItem
        v-if="canCreateTemplate"
        @select="emit('createTemplate')"
      >
        <Icon name="lucide:notepad-text-dashed" />
        <span>{{ $t('actions.blocks.item.createTemplate') }}</span>
      </DropdownMenuItem>
      <DropdownMenuItem
        v-if="canManage"
        @select="manageBlock"
      >
        <Icon name="lucide:settings-2" />
        <span>{{ $t('actions.blocks.item.manageBlock') }}</span>
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
