<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type TrendEntry = {
  id: number
  metric: string
  value: number
  period_date: string
}

const props = defineProps<{
  trends: Record<string, TrendEntry[]>
}>()

const metricLabels: Record<string, string> = {
  avg_productivity_score: 'Avg Productivity Score',
  avg_attendance_rate:    'Avg Attendance Rate (%)',
  avg_overtime_hours:     'Avg Overtime (hrs)',
  late_rate:              'Late Rate (%)',
}

const metricList = computed(() => Object.keys(props.trends))
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center gap-2 mb-6">
      <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.productivity.index'))" />
      <div>
        <h4 class="text-h4">Productivity Trends</h4>
        <p class="text-medium-emphasis mb-0">Company-wide attendance productivity over time</p>
      </div>
    </div>

    <VRow>
      <VCol v-for="metric in metricList" :key="metric" cols="12" lg="6">
        <VCard elevation="0" border class="mb-5">
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ metricLabels[metric] ?? metric }}</VCardTitle>
          <VDivider />
          <VCardText class="pa-4">
            <table style="width:100%; border-collapse: collapse;">
              <thead>
                <tr>
                  <th class="text-left text-caption text-medium-emphasis pa-2" style="border-bottom:1px solid rgba(0,0,0,.08);">Date</th>
                  <th class="text-right text-caption text-medium-emphasis pa-2" style="border-bottom:1px solid rgba(0,0,0,.08);">Value</th>
                  <th class="text-right text-caption text-medium-emphasis pa-2" style="border-bottom:1px solid rgba(0,0,0,.08);">Trend</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(entry, i) in (trends[metric] ?? [])" :key="entry.id" style="border-bottom:1px solid rgba(0,0,0,.04);">
                  <td class="pa-2 text-body-2 text-medium-emphasis">{{ entry.period_date }}</td>
                  <td class="pa-2 text-body-2 text-right font-weight-semibold">{{ entry.value }}</td>
                  <td class="pa-2 text-right">
                    <VIcon
                      v-if="i > 0"
                      :icon="entry.value > (trends[metric][i-1]?.value ?? 0) ? 'tabler-trending-up' : 'tabler-trending-down'"
                      :color="entry.value > (trends[metric][i-1]?.value ?? 0) ? 'success' : 'error'"
                      size="14"
                    />
                    <span v-else class="text-caption text-medium-emphasis">—</span>
                  </td>
                </tr>
                <tr v-if="!(trends[metric]?.length)">
                  <td colspan="3" class="pa-4 text-center text-body-2 text-medium-emphasis">No trend data.</td>
                </tr>
              </tbody>
            </table>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>
