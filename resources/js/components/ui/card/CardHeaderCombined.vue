<script setup lang="ts">
import { CardDescription, CardHeader, CardTitle } from '~/components/ui/card/index';

defineProps<{
  title?: string
  description?: string
}>()
</script>

<template>
  <CardHeader>
    <CardTitle v-if="title">{{ title }}</CardTitle>
    <CardDescription v-if="description">{{ description }}</CardDescription>
  </CardHeader>
</template>
