<script setup lang="ts">
import type { CleanTranslation } from 'nuxt-i18n-micro-types/src'
import { CardDescription, CardHeader, CardTitle } from '~/components/ui/card/index'

defineProps<{
  title?: string | CleanTranslation
  description?: string | CleanTranslation
}>()
</script>

<template>
  <CardHeader>
    <CardTitle v-if="title">{{ title }}</CardTitle>
    <CardDescription v-if="description">{{ description }}</CardDescription>
  </CardHeader>
</template>
