<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type MissedRecord = {
  id: number; employee: string; type: string; clocked_at: string
  notes: string | null; is_corrected: boolean
}

const props = defineProps<{ missed: MissedRecord[]; selectedDate: string }>()
const subNav       = ref('missed')
const selectedDate = ref(props.selectedDate)
const detecting    = ref(false)

function changeDate() { router.get('/attendance/clocking/missed', { date: selectedDate.value }, { preserveState: true }) }

function detectMissed() {
  detecting.value = true
  useForm({ date: selectedDate.value }).post('/attendance/clocking/detect-missed', {
    onFinish: () => { detecting.value = false },
  })
}

const typeColor: Record<string, string> = { clock_in: 'success', clock_out: 'error' }
const typeLabel: Record<string, string> = {
  clock_in: t('attendance_clocking_missed.type_missing_clock_in'),
  clock_out: t('attendance_clocking_missed.type_missing_clock_out'),
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12" class="pb-0">
        <VCard variant="outlined">
          <VTabs v-model="subNav" color="primary" density="compact">
            <VTab value="records" href="/attendance/records"><VIcon start size="16" icon="tabler-list-check" />{{ t('attendance_clocking_missed.tab_records') }}</VTab>
            <VTab value="missed" href="/attendance/clocking/missed"><VIcon start size="16" icon="tabler-alert-circle-outline" />{{ t('attendance_clocking_missed.tab_missed') }}</VTab>
          </VTabs>
        </VCard>
      </VCol>
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('attendance_clocking_missed.breadcrumb_module') }}</div>
              <h4 class="text-h4 mb-1">{{ t('attendance_clocking_missed.title') }}</h4>
              <p class="mb-0 text-medium-emphasis">{{ t('attendance_clocking_missed.subtitle') }}</p>
            </div>
            <div class="d-flex gap-3 align-center">
              <VTextField v-model="selectedDate" type="date" density="compact" hide-details style="max-width:180px" @change="changeDate" />
              <VBtn color="warning" :loading="detecting" @click="detectMissed">
                <i class="fas fa-search me-2"></i>{{ t('attendance_clocking_missed.detect_missed') }}
              </VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VAlert v-if="!missed.length" type="success" variant="tonal" class="mb-0">
          <i class="fas fa-check-circle me-2"></i>
          {{ t('attendance_clocking_missed.no_missed', { date: selectedDate }) }}
        </VAlert>

        <VCard v-else>
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>{{ t('attendance_clocking_missed.table_employee') }}</th>
                <th>{{ t('attendance_clocking_missed.table_type') }}</th>
                <th>{{ t('attendance_clocking_missed.table_auto_recorded') }}</th>
                <th>{{ t('attendance_clocking_missed.table_notes') }}</th>
                <th>{{ t('attendance_clocking_missed.table_status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in missed" :key="r.id">
                <td class="font-weight-medium">{{ r.employee }}</td>
                <td>
                  <VChip :color="typeColor[r.type] ?? 'grey'" size="small" variant="tonal">
                    {{ typeLabel[r.type] ?? r.type }}
                  </VChip>
                </td>
                <td class="font-monospace">{{ r.clocked_at }}</td>
                <td class="text-caption text-medium-emphasis">{{ r.notes ?? '—' }}</td>
                <td>
                  <VChip :color="r.is_corrected ? 'success' : 'warning'" size="small" variant="tonal">
                    {{ r.is_corrected ? t('attendance_clocking_missed.corrected') : t('attendance_clocking_missed.pending_review') }}
                  </VChip>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>
