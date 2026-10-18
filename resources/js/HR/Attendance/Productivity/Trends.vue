<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Trend = { id: number; metric: string; period_date: string; value: number; trend_direction: string | null; change_pct: number | null }

const props = defineProps<{ trends: Record<string, Trend[] > }>()

function toTitleCase(str: string): string { return str.replace(/_/g, ' ').replace(/\w\S*/g, word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase()) }
function formatDate(dateStr: string): string { const d = new Date(dateStr); const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']; const yy = String(d.getFullYear()).slice(2); return `${months[d.getMonth()]} ${yy}` }
function sortedEntries(items: Trend[]): Trend[] { return [...items].sort((a, b) => a.period_date.localeCompare(b.period_date)) }
function maxValue(items: Trend[]): number { return Math.max(...items.map(t => t.value), 1) }
function lastEntry(items: Trend[]): Trend | null { const sorted = sortedEntries(items); return sorted.length ? sorted[sorted.length - 1] : null }
function changePctColor(pct: number | null): string { if (pct === null) return 'default'; if (pct > 0) return 'success'; if (pct < 0) return 'error'; return 'default' }
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow class="mb-4" align="center">
      <VCol><h4 class="text-h4">{{ t('attendance_productivity.trends_title') }}</h4></VCol>
      <VCol cols="auto"><VBtn :href="'/attendance/productivity'" variant="tonal" prepend-icon="tabler-arrow-left">{{ t('attendance_productivity.back') }}</VBtn></VCol>
    </VRow>

    <VCard v-for="[metric, items] in Object.entries(trends)" :key="metric" elevation="0" border class="mb-4">
      <VCardTitle class="pt-4 px-4">{{ toTitleCase(metric) }}</VCardTitle>
      <VCardText>
        <div class="d-flex align-center justify-space-between mb-3">
          <div style="display: flex; align-items: flex-end; gap: 4px; height: 120px; flex: 1">
            <div v-for="entry in sortedEntries(items)" :key="entry.id" style="display: flex; flex-direction: column; align-items: center; flex: 1; min-width: 24px">
              <div :style="{ height: `${(entry.value / maxValue(items)) * 100}%`, minHeight: '4px', width: '100%', backgroundColor: 'rgb(var(--v-theme-primary))', borderRadius: '4px 4px 0 0', cursor: 'default' }" :title="String(entry.value)" />
              <div class="text-caption text-medium-emphasis mt-1" style="font-size: 10px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 40px; text-align: center">{{ formatDate(entry.period_date) }}</div>
            </div>
          </div>
        </div>

        <div v-if="lastEntry(items) && lastEntry(items)!.change_pct !== null" class="d-flex align-center gap-2">
          <span class="text-caption text-medium-emphasis">{{ t('attendance_productivity.latest_change') }}</span>
          <VChip :color="changePctColor(lastEntry(items)!.change_pct)" size="small" variant="tonal">{{ lastEntry(items)!.change_pct! > 0 ? '+' : '' }}{{ lastEntry(items)!.change_pct!.toFixed(1) }}%</VChip>
        </div>
        <div v-else-if="lastEntry(items)" class="d-flex align-center gap-2">
          <span class="text-caption text-medium-emphasis">{{ t('attendance_productivity.latest_change') }}</span>
          <VChip color="default" size="small" variant="tonal">{{ t('attendance_productivity.na') }}</VChip>
        </div>
      </VCardText>
    </VCard>
  </VContainer>
</template>
