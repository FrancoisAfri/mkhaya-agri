<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type DeviceLog = {
  id: number; device_id: string; device_type: string; device_name: string | null
  employee: string; status: 'pending' | 'processed' | 'failed'
  captured_at: string; error: string | null
}
type Paginated<T> = { data: T[]; current_page: number; last_page: number; total: number }

defineProps<{ logs: Paginated<DeviceLog> }>()

const subNav      = ref('devices')
const statusColor: Record<string, string> = { pending: 'warning', processed: 'success', failed: 'error' }
const typeIcon: Record<string, string> = {
  biometric: 'fas fa-fingerprint', facial_recognition: 'fas fa-face-smile',
  qr_scanner: 'fas fa-qrcode', mobile: 'fas fa-mobile-alt',
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12" class="pb-0">
        <VCard variant="outlined">
          <VTabs v-model="subNav" color="primary" density="compact">
            <VTab value="audit" href="/attendance/audit">
              <VIcon start size="16" icon="tabler-clipboard-list" />{{ t('attendance_device_logs.tab_audit_logs') }}
            </VTab>
            <VTab value="devices" href="/attendance/clocking/device-logs">
              <VIcon start size="16" icon="tabler-devices" />{{ t('attendance_device_logs.tab_device_logs') }}
            </VTab>
          </VTabs>
        </VCard>
      </VCol>
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('attendance_device_logs.breadcrumb_module') }}</div>
              <h4 class="text-h4 mb-1">{{ t('attendance_device_logs.title') }}</h4>
              <p class="mb-0 text-medium-emphasis">{{ t('attendance_device_logs.subtitle') }}</p>
            </div>
            <VChip color="primary" variant="tonal" label>
              {{ t('attendance_device_logs.total_records', { count: logs.total }) }}
            </VChip>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>{{ t('attendance_device_logs.table_device') }}</th>
                <th>{{ t('attendance_device_logs.table_type') }}</th>
                <th>{{ t('attendance_device_logs.table_employee') }}</th>
                <th>{{ t('attendance_device_logs.table_status') }}</th>
                <th>{{ t('attendance_device_logs.table_captured_at') }}</th>
                <th>{{ t('attendance_device_logs.table_error') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!logs.data.length">
                <td colspan="6" class="text-center py-8 text-medium-emphasis">{{ t('attendance_device_logs.no_logs') }}</td>
              </tr>
              <tr v-for="log in logs.data" :key="log.id">
                <td>
                  <div class="font-weight-medium font-monospace" style="font-size:0.85rem">{{ log.device_id }}</div>
                  <div v-if="log.device_name" class="text-caption text-medium-emphasis">{{ log.device_name }}</div>
                </td>
                <td>
                  <VChip size="small" variant="outlined">
                    <i :class="typeIcon[log.device_type] ?? 'fas fa-microchip'" class="me-1"></i>
                    {{ log.device_type.replace('_', ' ') }}
                  </VChip>
                </td>
                <td>{{ log.employee }}</td>
                <td>
                  <VChip :color="statusColor[log.status]" size="small" variant="tonal">{{ log.status }}</VChip>
                </td>
                <td class="font-monospace text-caption">{{ log.captured_at }}</td>
                <td class="text-caption text-error">{{ log.error ?? '—' }}</td>
              </tr>
            </tbody>
          </VTable>

          <VCardText v-if="logs.last_page > 1" class="d-flex justify-center py-4">
            <VPagination
              :model-value="logs.current_page"
              :length="logs.last_page"
              @update:model-value="page => router.get('/attendance/clocking/device-logs', { page }, { preserveState: true })"
            />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>
