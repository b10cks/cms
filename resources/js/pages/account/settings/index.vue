<script setup lang="ts">
import { toast } from 'vue-sonner'

import Icon from '~/components/Icon.vue'
import NuxtImg from '~/components/NuxtImg.vue'
import { Button } from '~/components/ui/button'
import { Card, CardContent, CardFooter, CardHeaderCombined } from '~/components/ui/card'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { ComboboxField, FormField, InputField, SelectField } from '~/components/ui/form'
import { useFileUpload } from '~/composables/useFileUpload'
import type { Clock, DateOrder } from '~/lib/display-formats'
import {
  browserTimeZone,
  CLOCKS,
  DATE_ORDERS,
  displayFormats,
  supportedTimeZones,
} from '~/lib/display-formats'
import type { User } from '~/types/users'

const { useUserQuery, useUpdateUserMutation, cacheUser } = useUser()
const { t, locales } = useI18n()
const { data: user } = useUserQuery()
const { mutate: updateUser, isPending: isUpdating } = useUpdateUserMutation()
const { settings } = useUserSettings()
const { formatDate, formatTime, formatDateTime } = useFormat()

useSeoMeta({
  title: computed(() => t('labels.account.profile.title')),
})

const firstname = ref('')
const lastname = ref('')
const avatarInputRef = ref<HTMLInputElement | null>(null)
const uploadProgress = ref(0)
const { upload, isUploading: fileUploadIsUploading } = useFileUpload()

// Keyed on the names alone, so an avatar upload refreshing the user keeps unsaved name edits
watch(
  [() => user.value?.firstname, () => user.value?.lastname],
  ([first, last]) => {
    if (first !== undefined) firstname.value = first
    if (last !== undefined) lastname.value = last
  },
  { immediate: true }
)

const handleSave = async () => {
  await updateUser({
    firstname: firstname.value,
    lastname: lastname.value,
  })
}

const onLanguageChange = (value: string) => {
  if (value === settings.languageIso) return
  settings.languageIso = value
}

// Each option previews today as it would read with that one choice applied.
const now = Date.now()
const followLanguage = (example: string) =>
  t('labels.account.settings.followLanguage', { example })

const dateOrderOptions = computed((): Array<{ value: DateOrder; label: string }> => [
  {
    value: 'auto',
    label: followLanguage(formatDate(now, { ...displayFormats, dateOrder: 'auto' })),
  },
  ...DATE_ORDERS.map((dateOrder) => ({
    value: dateOrder,
    label: t(`labels.account.settings.dateOrders.${dateOrder}`, {
      example: formatDate(now, { ...displayFormats, dateOrder }),
    }),
  })),
])

const CLOCK_LABELS = { '24h': 'h24', '12h': 'h12' } as const

const clockOptions = computed((): Array<{ value: Clock; label: string }> => [
  {
    value: 'auto',
    label: followLanguage(formatTime(now, { ...displayFormats, clock: 'auto' })),
  },
  ...CLOCKS.map((clock) => ({
    value: clock,
    label: t(`labels.account.settings.clocks.${CLOCK_LABELS[clock]}`, {
      example: formatTime(now, { ...displayFormats, clock }),
    }),
  })),
])

const timeZoneOptions = computed(() => [
  {
    value: 'auto',
    label: t('labels.account.settings.browserTimeZone', { zone: browserTimeZone() }),
  },
  ...supportedTimeZones().map((zone) => ({ value: zone, label: zone.replaceAll('_', ' ') })),
])

const onTimeZoneChange = (value: string | string[]) => {
  if (typeof value === 'string') settings.timeZone = value
}

const handleAvatarFile = async (file: File) => {
  if (!file) return
  uploadProgress.value = 0
  try {
    const response = await upload<ApiResponse<User>>(file, {
      url: '/mgmt/v1/users/me/avatar',
      fieldName: 'avatar',
      onProgress: (p) => (uploadProgress.value = p),
    })
    // The endpoint returns the full user, so the header and menus update without a refetch
    cacheUser(response.data)
    toast.success(t('labels.account.profile.toast.avatarUploaded'))
  } catch (e) {
    toast.error(
      t('labels.account.profile.toast.avatarUploadFailed', {
        error: e instanceof Error ? e.message : 'Unknown error',
      })
    )
  }
}

const handleUploadAvatar = () => {
  avatarInputRef.value?.click()
}

const onAvatarInputChange = (e: Event) => {
  const files = (e.target as HTMLInputElement).files
  if (files && files[0]) {
    handleAvatarFile(files[0])
  }
}

const onDropAvatar = (e: DragEvent) => {
  e.preventDefault()
  if (e.dataTransfer?.files && e.dataTransfer.files[0]) {
    handleAvatarFile(e.dataTransfer.files[0])
  }
}

const onDragOverAvatar = (e: DragEvent) => {
  e.preventDefault()
}
</script>

<template>
  <div class="content-grid gap-6 pb-6">
    <ContentHeader
      :header="$t('labels.account.profile.title')"
      :description="$t('labels.account.profile.description')"
    />

    <div class="pt-6 space-y-12 divide-y divide-border">
      <Card
        v-if="user"
        variant="none"
      >
        <CardHeaderCombined :title="$t('labels.account.profile.avatar')" />
        <CardContent class="grid gap-6">
          <FormField
            name="avatar"
            :label="$t('labels.account.profile.avatar')"
            :description="$t('labels.account.profile.avatarDescription')"
          >
            <div
              class="flex items-center gap-4"
              @drop="onDropAvatar"
              @dragover="onDragOverAvatar"
            >
              <div
                v-if="user.avatar"
                role="button"
                tabindex="0"
                :aria-label="$t('labels.account.profile.uploadAvatar')"
                class="flex h-20 w-20 cursor-pointer items-center justify-center overflow-hidden rounded-full bg-surface"
                @click="handleUploadAvatar"
                @keydown.enter.prevent="handleUploadAvatar"
                @keydown.space.prevent="handleUploadAvatar"
              >
                <NuxtImg
                  :src="user.avatar"
                  alt="Avatar"
                  class="h-full w-full object-cover"
                />
              </div>
              <div
                v-else
                role="button"
                tabindex="0"
                :aria-label="$t('labels.account.profile.uploadAvatar')"
                class="flex h-20 w-20 cursor-pointer items-center justify-center rounded-full border border-dashed border-muted bg-surface"
                @click="handleUploadAvatar"
                @keydown.enter.prevent="handleUploadAvatar"
                @keydown.space.prevent="handleUploadAvatar"
              >
                <Icon
                  name="lucide:user"
                  class="h-10 w-10 text-muted"
                />
              </div>
              <input
                ref="avatarInputRef"
                type="file"
                accept="image/*"
                class="hidden"
                @change="onAvatarInputChange"
              />
              <span
                v-if="fileUploadIsUploading"
                class="ml-2 text-xs text-muted"
                >{{ uploadProgress }}%</span
              >
            </div>
          </FormField>
          <InputField
            :label="$t('labels.account.profile.userId')"
            name="user-id"
            :model-value="user.id"
            readonly
            :actions="['copy']"
          />
        </CardContent>
      </Card>
      <Card variant="none">
        <CardHeaderCombined
          :title="$t('labels.account.profile.personalInfo')"
          :description="$t('labels.account.profile.personalInfoDescription')"
        />
        <CardContent class="grid gap-6">
          <InputField
            v-model="firstname"
            :label="$t('labels.account.profile.firstname')"
            :placeholder="$t('labels.account.profile.firstnamePlaceholder')"
            name="firstname"
            required
          />

          <InputField
            v-model="lastname"
            :label="$t('labels.account.profile.lastname')"
            :placeholder="$t('labels.account.profile.lastnamePlaceholder')"
            name="lastname"
            required
          />
        </CardContent>
        <CardFooter>
          <Button
            variant="primary"
            :loading="isUpdating"
            @click="handleSave"
          >
            {{ $t('actions.saveChanges') }}
          </Button>
        </CardFooter>
      </Card>
      <Card
        v-if="user"
        variant="none"
      >
        <CardHeaderCombined
          :title="$t('labels.account.profile.email')"
          :description="$t('labels.account.profile.emailReadonly')"
        />
        <CardContent>
          <InputField
            :label="$t('labels.account.profile.email')"
            name="email"
            :model-value="user.email"
            readonly
          >
            <template #append>
              <span
                v-if="user.email_verified_at"
                class="flex items-center gap-1 text-success text-sm pr-2"
              >
                <Icon name="lucide:circle-check" />
                <span>verified</span>
              </span>
            </template>
          </InputField>
        </CardContent>
      </Card>
      <Card
        v-if="user"
        variant="none"
      >
        <CardHeaderCombined
          :title="$t('labels.account.settings.localization')"
          :description="$t('labels.account.settings.localizationDescription')"
        />
        <CardContent class="grid gap-6">
          <SelectField
            :label="$t('labels.account.settings.language')"
            name="language"
            :description="$t('labels.account.settings.languageDescription')"
            :placeholder="$t('labels.account.settings.selectLanguage')"
            :model-value="settings.languageIso"
            @update:model-value="onLanguageChange"
            :options="locales.map((locale) => ({ label: locale.name, value: locale.code }))"
          />
        </CardContent>
      </Card>
      <Card
        v-if="user"
        variant="none"
      >
        <CardHeaderCombined
          :title="$t('labels.account.settings.displayFormats')"
          :description="
            $t('labels.account.settings.displayFormatsDescription', {
              example: formatDateTime(now),
            })
          "
        />
        <CardContent class="grid gap-6">
          <SelectField
            :label="$t('labels.account.settings.dateOrder')"
            name="date-order"
            :description="$t('labels.account.settings.dateOrderDescription')"
            :model-value="settings.dateOrder"
            :options="dateOrderOptions"
            @update:model-value="(value) => (settings.dateOrder = value)"
          />
          <SelectField
            :label="$t('labels.account.settings.clock')"
            name="clock"
            :description="$t('labels.account.settings.clockDescription')"
            :model-value="settings.clock"
            :options="clockOptions"
            @update:model-value="(value) => (settings.clock = value)"
          />
          <ComboboxField
            :label="$t('labels.account.settings.timeZone')"
            name="time-zone"
            :description="$t('labels.account.settings.timeZoneDescription')"
            placeholder="labels.account.settings.searchTimeZone"
            :model-value="settings.timeZone"
            :options="timeZoneOptions"
            searchable
            @update:model-value="onTimeZoneChange"
          />
        </CardContent>
      </Card>
    </div>
  </div>
</template>
