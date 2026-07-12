<script setup lang="ts">
import type { HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'

const props = defineProps<{
  class?: HTMLAttributes['class']
}>()

// SMIL begin-chains reference ids document-wide, so each instance needs unique ones
const uid = useId().replace(/[^a-zA-Z0-9]/g, '')
const id = (name: string) => `sp_${uid}_${name}`
const ref_ = (name: string) => `${id(name)}.end`
</script>

<template>
  <svg
    fill="currentColor"
    viewBox="0 0 24 24"
    xmlns="http://www.w3.org/2000/svg"
    :class="cn('size-4', props.class)"
    aria-hidden="true"
  >
    <rect x="1" y="1" rx="1" width="10" height="10">
      <animate :id="id('a')" :begin="`0;${ref_('l')}`" attributeName="x" dur="0.2s" values="1;13" fill="freeze" />
      <animate :id="id('b')" :begin="ref_('i')" attributeName="y" dur="0.2s" values="1;13" fill="freeze" />
      <animate :id="id('c')" :begin="ref_('j')" attributeName="x" dur="0.2s" values="13;1" fill="freeze" />
      <animate :id="id('d')" :begin="ref_('k')" attributeName="y" dur="0.2s" values="13;1" fill="freeze" />
    </rect>
    <rect x="1" y="13" rx="1" width="10" height="10">
      <animate :id="id('e')" :begin="ref_('a')" attributeName="y" dur="0.2s" values="13;1" fill="freeze" />
      <animate :id="id('f')" :begin="ref_('b')" attributeName="x" dur="0.2s" values="1;13" fill="freeze" />
      <animate :id="id('g')" :begin="ref_('c')" attributeName="y" dur="0.2s" values="1;13" fill="freeze" />
      <animate :id="id('h')" :begin="ref_('d')" attributeName="x" dur="0.2s" values="13;1" fill="freeze" />
    </rect>
    <rect x="13" y="13" rx="1" width="10" height="10">
      <animate :id="id('i')" :begin="ref_('e')" attributeName="x" dur="0.2s" values="13;1" fill="freeze" />
      <animate :id="id('j')" :begin="ref_('f')" attributeName="y" dur="0.2s" values="13;1" fill="freeze" />
      <animate :id="id('k')" :begin="ref_('g')" attributeName="x" dur="0.2s" values="1;13" fill="freeze" />
      <animate :id="id('l')" :begin="ref_('h')" attributeName="y" dur="0.2s" values="1;13" fill="freeze" />
    </rect>
  </svg>
</template>
