<script setup lang="ts">
import { SheetDescription, SheetHeader, SheetTitle } from '~/components/ui/sheet/index'

defineProps<{
  title?: string
  description?: string
}>()
</script>

<template>
  <SheetHeader>
    <SheetTitle v-if="title">{{ title }}</SheetTitle>
    <SheetDescription v-if="description">{{ description }}</SheetDescription>
  </SheetHeader>
</template>
