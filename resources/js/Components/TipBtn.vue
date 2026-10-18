<script lang="ts" setup>
/**
 * Icon button with a built-in hover tooltip — so every action icon explains
 * itself. Drop-in for `<VBtn icon="..." />`: pass the glyph via `icon` and the
 * hover text via `tip`. All other VBtn props/listeners fall through.
 *
 *   <TipBtn icon="tabler-trash" tip="Delete" size="x-small" variant="text" color="error" @click="..." />
 */
withDefaults(defineProps<{ tip: string; icon?: string; location?: string }>(), { location: 'top' })
</script>

<template>
  <VBtn v-bind="$attrs" :icon="icon ? true : undefined">
    <VIcon v-if="icon" :icon="icon" />
    <slot />
    <VTooltip activator="parent" :location="location" open-delay="200">{{ tip }}</VTooltip>
  </VBtn>
</template>
