<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import NuxtImg from '~/components/NuxtImg.vue'

import { Button } from '~/components/ui/button'
import { Card, CardContent, CardFooter, CardHeaderCombined } from '~/components/ui/card'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { FormField, InputField, SelectField } from '~/components/ui/form'
import { useFileUpload } from '~/composables/useFileUpload'

const { useUserQuery, useUpdateUserMutation, useUploadAvatarMutation } = useUser()
const { t, locales } = useI18n()
const { data: user } = useUserQuery()
const { mutate: updateUser, isPending: isUpdating } = useUpdateUserMutation()
const { mutate: uploadAvatar, isPending: isUploadingAvatar } = useUploadAvatarMutation()
const { isUpdating: isUpdatingSettings, handleUpdateLanguage } = useUserSettings(user)

useSeoMeta({
  title: computed(() => t('labels.account.profile.title')),
})

const firstname = ref('')
const lastname = ref('')
const avatar = ref<string | null>(null)
const avatarInputRef = ref<HTMLInputElement | null>(null)
const uploadProgress = ref(0)
const { upload, isUploading: fileUploadIsUploading } = useFileUpload()

watch(
  () => user.value,
  (newUser) => {
    if (newUser) {
      firstname.value = newUser.firstname
      lastname.value = newUser.lastname
      avatar.value = newUser.avatar || null
    }
  },
  { immediate: true }
)

const handleSave = async () => {
  await updateUser({
    firstname: firstname.value,
    lastname: lastname.value,
  })
}

const onLanguageChange = async (value: string) => {
  await handleUpdateLanguage(value)
}

const handleAvatarFile = async (file: File) => {
  if (!file) return
  uploadProgress.value = 0
  try {
    const response = await upload(file, {
      url: '/mgmt/v1/users/me/avatar',
      fieldName: 'avatar',
      onProgress: (p) => (uploadProgress.value = p),
    })
    if (response?.data?.avatar) {
      avatar.value = response.data.avatar
    }
  } catch {
    // Error handled by useFileUpload
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

    <Card
      v-if="user"
      variant="outline"
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
              v-if="avatar"
              class="flex h-20 w-20 cursor-pointer items-center justify-center overflow-hidden rounded-full bg-surface"
              @click="handleUploadAvatar"
            >
              <NuxtImg
                :src="avatar"
                alt="Avatar"
                class="h-full w-full object-cover"
              />
            </div>
            <div
              v-else
              class="flex h-20 w-20 cursor-pointer items-center justify-center rounded-full border border-dashed border-muted bg-surface"
              @click="handleUploadAvatar"
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
    <Card variant="outline">
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
          :disabled="isUpdating"
          @click="handleSave"
        >
          <Icon
            v-if="isUpdating"
            name="lucide:loader"
            class="animate-spin"
          />
          {{ $t('actions.saveChanges') }}
        </Button>
      </CardFooter>
    </Card>
    <Card
      v-if="user"
      variant="outline"
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
          :description="$t('labels.account.profile.emailReadonly')"
        />
      </CardContent>
    </Card>
    <Card
      v-if="user"
      variant="outline"
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
          :model-value="getLocale()"
          @update:model-value="onLanguageChange"
          :options="locales.map((locale) => ({ label: locale.name, value: locale.code }))"
        />
      </CardContent>
    </Card>
  </div>
</template>
