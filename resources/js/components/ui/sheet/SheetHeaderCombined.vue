<script setup lang="ts">
import { SheetDescription, SheetHeader, SheetTitle } from '~/components/ui/sheet/index'
import type { CleanTranslation } from 'nuxt-i18n-micro-types/src'

defineProps<{
  title?: string | CleanTranslation
  description?: string | CleanTranslation
}>()
</script>

<template>
  <SheetHeader>
    <SheetTitle v-if="title">{{ title }}</SheetTitle>
    <SheetDescription v-if="description">{{ description }}</SheetDescription>
  </SheetHeader>
</template>
