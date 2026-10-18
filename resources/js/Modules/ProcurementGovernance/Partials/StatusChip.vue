<script setup lang="ts">
import { computed } from 'vue'
import { humanise, statusColour, statusIcon } from './format'

/**
 * A status, rendered as colour + icon + label together.
 *
 * Colour alone would fail WCAG 1.4.1, and in this module the states are consequential — approved
 * versus rejected, compliant versus not — so a reader who cannot distinguish the colours must not
 * have to guess. The label is always present; the icon carries the same meaning a second way.
 */
const props = withDefaults(defineProps<{
  status: string | null | undefined
  /** Override the label when the raw enum is not the right wording for the context. */
  label?: string
  size?: 'x-small' | 'small' | 'default'
  /** Set for risk levels, which have their own scale. */
  colour?: string
}>(), { size: 'small' })

const colour = computed(() => props.colour ?? statusColour(props.status))
const icon = computed(() => statusIcon(props.status))
const text = computed(() => props.label ?? humanise(props.status))
</script>

<template>
  <VChip :color="colour" :size="size" variant="tonal" label class="font-weight-medium">
    <VIcon :icon="icon" start size="14" />
    {{ text }}
  </VChip>
</template>
