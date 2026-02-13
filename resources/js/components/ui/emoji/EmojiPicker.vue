<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { Button } from '~/components/ui/button'
import { InputField } from '~/components/ui/form'
import { Popover, PopoverContent, PopoverTrigger } from '~/components/ui/popover'
import { ScrollArea } from '~/components/ui/scroll-area'
import { ToneSelect } from '.'
import {
  type Emoji,
  emojiGroups,
  emojiToChar,
  emojiToValue,
  filterEmojis,
  injectFavorites,
} from './utils'

const emit = defineEmits<{
  (e: 'select', emoji: string): void
}>()

const { tone } = useTone()
const search = ref('')
const isOpen = ref(false)
const props = defineProps<{
  class?: string
}>()
const selected = ref<Emoji | null>(null)

function onSelect(emoji: Emoji): void {
  isOpen.value = false
  emit('select', emojiToValue(emoji))
}

const filteredEmojis = computed(() => {
  return injectFavorites(filterEmojis(emojiGroups, search.value), [
    { n: ['heavy_plus_sign'], u: '2795' },
    {
      n: ['+1'],
      u: '1f44d',
      v: ['1f44d-1f3fb', '1f44d-1f3fc', '1f44d-1f3fd', '1f44d-1f3fe', '1f44d-1f3ff'],
    },
    {
      n: ['-1'],
      u: '1f44e',
      v: ['1f44e-1f3fb', '1f44e-1f3fc', '1f44e-1f3fd', '1f44e-1f3fe', '1f44e-1f3ff'],
    },
    { n: ['eyes'], u: '1f440' },
    { n: ['heart_eyes'], u: '1f60d' },
    { n: ['joy'], u: '1f602' },
    { n: ['fire'], u: '1f525' },
    { n: ['grinning'], u: '1f600' },
    { n: ['sweat_smile'], u: '1f605' },
  ])
})
</script>
<template>
  <Popover v-model:open="isOpen">
    <PopoverTrigger>
      <Button
        variant="ghost"
        size="xs"
        :class="props.class"
      >
        <Icon name="lucide:smile-plus" />
      </Button>
    </PopoverTrigger>
    <PopoverContent>
      <div
        class="-m-3 flex h-80 flex-col"
        @mouseleave="selected = null"
      >
        <div class="p-2">
          <InputField
            name="filter"
            autofocus
            v-model="search"
            placeholder="Search emoji"
            class="mb-2 w-full"
          />
        </div>
        <ScrollArea class="h-full w-xs flex-1">
          <div class="flex h-full w-full flex-1 flex-col gap-2 px-2">
            <div
              v-for="(emojis, name) in filteredEmojis"
              :key="name"
            >
              <h2 class="sticky mb-2 bg-background text-sm font-semibold">
                {{ $t(`labels.emojis.groups.${name}`) }}
              </h2>
              <div class="grid grid-cols-9 gap-1">
                <button
                  v-for="emoji in emojis"
                  :key="emoji.u"
                  class="rounded-md text-xl hover:bg-input"
                  @click="() => onSelect(emoji)"
                  @mouseover="selected = emoji"
                >
                  {{ emojiToChar(emoji, tone) }}
                </button>
              </div>
            </div>
          </div>
        </ScrollArea>
        <div class="flex h-10 items-center justify-between border-t border-border p-2">
          <div v-if="selected">
            {{ emojiToChar(selected, tone) }}
            <span class="text-sm">{{ emojiToValue(selected) }}</span>
          </div>
          <ToneSelect class="ml-auto" />
        </div>
      </div>
    </PopoverContent>
  </Popover>
</template>
