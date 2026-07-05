<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { Input } from '~/components/ui/input'
import { Spinner } from '~/components/ui/spinner'

const props = defineProps<{
  spaceId: string
  assetIds: string[]
}>()

const open = defineModel<boolean>('open', { default: false })

const emit = defineEmits<{
  added: [collectionId: string]
}>()

const { $t } = useI18n()
const {
  useAssetCollectionsQuery,
  useCreateAssetCollectionMutation,
  useAddAssetsToCollectionMutation,
} = useAssetCollections(props.spaceId)

const { data: collectionsResponse } = useAssetCollectionsQuery({ per_page: 500, sort: '+name' })
const { mutateAsync: createCollection, isPending: isCreating } =
  useCreateAssetCollectionMutation()
const { mutateAsync: addAssets, isPending: isAdding } = useAddAssetsToCollectionMutation()

const search = ref('')
const newCollectionName = ref('')
const showInlineCreate = ref(false)

const manualCollections = computed(() =>
  (collectionsResponse.value?.data ?? []).filter((collection) => collection.type === 'manual')
)

const filteredCollections = computed(() => {
  const query = search.value.trim().toLowerCase()

  if (!query) {
    return manualCollections.value
  }

  return manualCollections.value.filter((collection) =>
    collection.name.toLowerCase().includes(query)
  )
})

watch(open, (isOpen) => {
  if (isOpen) {
    search.value = ''
    newCollectionName.value = ''
    showInlineCreate.value = false
  }
})

const handlePick = async (collectionId: string) => {
  if (!props.assetIds.length || isAdding.value) {
    return
  }

  await addAssets({ collectionId, assetIds: props.assetIds })
  emit('added', collectionId)
  open.value = false
}

const handleInlineCreate = async () => {
  const name = newCollectionName.value.trim()

  if (!name || isCreating.value) {
    return
  }

  const created = await createCollection({ name, type: 'manual' })
  await handlePick(created.id)
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-md">
      <DialogHeaderCombined
        :title="$t('labels.assetCollections.addToCollection.title', { count: assetIds.length })"
        :description="$t('labels.assetCollections.addToCollection.description')"
      />

      <div class="flex flex-col gap-3">
        <Input
          v-model="search"
          :placeholder="String($t('labels.assetCollections.addToCollection.searchPlaceholder'))"
        />

        <div class="flex max-h-64 flex-col gap-1 overflow-y-auto">
          <button
            v-for="collection in filteredCollections"
            :key="collection.id"
            type="button"
            class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-2 text-left font-semibold transition-colors hover:bg-input disabled:cursor-not-allowed disabled:opacity-60"
            :disabled="isAdding"
            @click="handlePick(collection.id)"
          >
            <Icon
              :name="collection.icon ? `lucide:${collection.icon}` : 'lucide:layers'"
              :style="{ color: collection.color || undefined }"
              class="shrink-0"
            />
            <span class="min-w-0 flex-1 truncate">{{ collection.name }}</span>
            <Badge
              v-if="typeof collection.assets_count === 'number'"
              size="sm"
              type="outline"
            >
              {{ collection.assets_count }}
            </Badge>
          </button>

          <p
            v-if="!filteredCollections.length"
            class="px-2 py-4 text-center text-sm text-muted"
          >
            {{ $t('labels.assetCollections.addToCollection.empty') }}
          </p>
        </div>

        <div
          v-if="showInlineCreate"
          class="flex items-center gap-2"
        >
          <Input
            v-model="newCollectionName"
            :placeholder="String($t('labels.assetCollections.fieldLabels.namePlaceholder'))"
            autofocus
            @keydown.enter="handleInlineCreate"
          />
          <Button
            variant="primary"
            :disabled="!newCollectionName.trim() || isCreating || isAdding"
            @click="handleInlineCreate"
          >
            <Spinner v-if="isCreating || isAdding" />
            {{ $t('actions.create') }}
          </Button>
        </div>
        <Button
          v-else
          variant="ghost"
          class="self-start"
          @click="showInlineCreate = true"
        >
          <Icon name="lucide:plus" />
          {{ $t('labels.assetCollections.addToCollection.createNew') }}
        </Button>
      </div>

      <DialogFooter>
        <Button @click="open = false">
          {{ $t('actions.cancel') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
