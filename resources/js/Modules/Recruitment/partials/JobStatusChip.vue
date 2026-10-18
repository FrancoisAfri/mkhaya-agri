<script setup lang="ts">
const props = defineProps<{
  status: string
}>()

const colorMap: Record<string, string> = {
  active: 'success',
  draft:  'warning',
  closed: 'error',
  filled: 'info',
}
</script>

<template>
  <VChip
    :color="colorMap[props.status] ?? 'default'"
    size="small"
    label
    class="font-weight-medium text-capitalize"
  >
    {{ props.status }}
  </VChip>
</template>
