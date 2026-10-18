<script setup lang="ts">
const props = defineProps<{
  status: { value: string; label: string }
  colorMap?: Record<string, string>
}>()

const defaultColorMap: Record<string, string> = {
  applied:    'info',
  screening:  'default',
  assessment: 'secondary',
  interview:  'warning',
  shortlisted:'primary',
  offer_sent: 'secondary',
  hired:      'success',
  rejected:   'error',
  withdrawn:  'default',
  pending:    'warning',
  approved:   'success',
  declined:   'error',
}

const resolvedColor = props.colorMap?.[props.status.value]
  ?? defaultColorMap[props.status.value]
  ?? 'default'
</script>

<template>
  <VChip
    :color="resolvedColor"
    size="small"
    label
    class="font-weight-medium"
  >
    {{ props.status.label }}
  </VChip>
</template>
