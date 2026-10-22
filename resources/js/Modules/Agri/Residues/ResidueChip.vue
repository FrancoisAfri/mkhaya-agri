<script setup lang="ts">
/** A lot's residue status (worst over its markets), with each market's status on hover. */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{ status: string; markets?: Record<string, string> }>()
const { t } = useI18n()
const COLOR: Record<string, string> = { pass: 'success', not_tested: 'warning', exceeds: 'error', banned: 'error' }
const detail = computed(() => Object.entries(props.markets ?? {}).map(([m, s]) => `${m}: ${t(`agri.residues.status.${s}`)}`).join(' · '))
</script>

<template>
  <VChip size="x-small" :color="COLOR[status] ?? 'default'" prepend-icon="tabler-flask" :title="detail">{{ t(`agri.residues.status.${status}`) }}</VChip>
  <span v-if="detail" class="text-caption text-medium-emphasis ms-1">{{ detail }}</span>
</template>
