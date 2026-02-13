<script setup lang="ts">
import { DialogDescription, DialogHeader, DialogTitle } from '~/components/ui/dialog/index'
import type { CleanTranslation } from 'nuxt-i18n-micro-types/src'

defineProps<{
  title?: string | CleanTranslation
  description?: string | CleanTranslation
}>()
</script>

<template>
  <DialogHeader>
    <DialogTitle v-if="title">{{ title }}</DialogTitle>
    <DialogDescription v-if="description">{{ description }}</DialogDescription>
  </DialogHeader>
</template>
