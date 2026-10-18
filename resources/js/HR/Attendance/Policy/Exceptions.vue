<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type PolicyException = {
  id: number; employee: string; policy_rule: string
  reason: string; granted_by: string | null
  approved_at: string; expires_at: string | null
  is_expired: boolean; notes: string | null
}
type Paginated<T> = { data: T[]; current_page: number; last_page: number; total: number }

const props = defineProps<{ exceptions: Paginated<PolicyException> }>()
const subNav = ref('exceptions')
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12" class="pb-0">
        <VCard variant="outlined">
          <VTabs v-model="subNav" color="primary" density="compact">
            <VTab value="engine" href="/attendance/policy"><VIcon start size="16" icon="tabler-cog-outline" />{{ t('attendance_policy_pages.tab_policy_engine') }}</VTab>
            <VTab value="violations" href="/attendance/policy/violations"><VIcon start size="16" icon="tabler-alert" />{{ t('attendance_policy_pages.tab_violations') }}</VTab>
            <VTab value="compliance" href="/attendance/policy/compliance"><VIcon start size="16" icon="tabler-shield-check" />{{ t('attendance_policy_pages.tab_compliance') }}</VTab>
            <VTab value="exceptions" href="/attendance/policy/exceptions"><VIcon start size="16" icon="tabler-shield-off-outline" />{{ t('attendance_policy_pages.tab_exceptions') }}</VTab>
          </VTabs>
        </VCard>
      </VCol>
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('attendance_policy_pages.exceptions_header') }}</div>
              <h4 class="text-h4 mb-1">{{ t('attendance_policy_pages.exceptions_title') }}</h4>
              <p class="mb-0 text-medium-emphasis">{{ t('attendance_policy_pages.exceptions_subtitle') }}</p>
            </div>
            <VBtn variant="text" color="primary" href="/attendance/policy/violations">
              <i class="fas fa-arrow-left me-2"></i>{{ t('attendance_policy_pages.violations_back') }}
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>{{ t('attendance_policy_pages.table_employee') }}</th>
                <th>{{ t('attendance_policy_pages.table_policy_rule') }}</th>
                <th>{{ t('attendance_policy_pages.field_exception_reason') }}</th>
                <th>{{ t('attendance_policy_pages.table_granted_by') }}</th>
                <th>{{ t('attendance_policy_pages.table_approved') }}</th>
                <th>{{ t('attendance_policy_pages.table_expires') }}</th>
                <th>{{ t('attendance_policy_pages.table_status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!exceptions.data.length">
                <td colspan="7" class="text-center py-8 text-medium-emphasis">{{ t('attendance_policy_pages.no_exceptions') }}</td>
              </tr>
              <tr v-for="e in exceptions.data" :key="e.id">
                <td class="font-weight-medium">{{ e.employee }}</td>
                <td class="text-caption">{{ e.policy_rule }}</td>
                <td class="text-caption" style="max-width:220px;white-space:normal">{{ e.reason }}</td>
                <td class="text-caption">{{ e.granted_by ?? '—' }}</td>
                <td class="text-caption">{{ e.approved_at }}</td>
                <td class="text-caption">{{ e.expires_at ?? t('attendance_policy_pages.no_expiry') }}</td>
                <td>
                  <VChip :color="e.is_expired ? 'secondary' : 'success'" size="x-small" variant="tonal">
                    {{ e.is_expired ? t('attendance_policy_pages.expired') : t('attendance_policy_pages.active') }}
                  </VChip>
                </td>
              </tr>
            </tbody>
          </VTable>
          <VCardText v-if="exceptions.last_page > 1" class="d-flex justify-center py-4">
            <VPagination :model-value="exceptions.current_page" :length="exceptions.last_page" @update:model-value="page => router.get('/attendance/policy/exceptions', { page }, { preserveState: true })" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>
