<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

type Override = {
  id: number; request_number: string | null; employee: string
  leave_type: string; override_reason: string | null
  actioned_by: string; actioned_at: string; level_name: string | null
}
type Stats = {
  total: number; this_month: number; by_leave_type: Record<string, number>
}

const props = defineProps<{
  overrides: Override[]
  stats:     Stats
}>()

const byTypeEntries = computed(() =>
  Object.entries(props.stats.by_leave_type).sort((a, b) => b[1] - a[1])
)

const { t } = useI18n()
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <!-- Header -->
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div class="d-flex align-center gap-3">
              <VBtn icon variant="text" @click="router.visit('/leave-management/policy-violations')">
                <VIcon icon="tabler-arrow-left" />
              </VBtn>
              <div>
                <div class="text-overline text-medium-emphasis">{{ t('leave.title') }}</div>
                <h4 class="text-h4">{{ t('leave.policy_override_register') }}</h4>
                <p class="mb-0 text-body-2 text-medium-emphasis">
                  All HR policy overrides applied to leave requests — fully audit-logged
                </p>
              </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
              <VChip color="warning" size="large" label prepend-icon="tabler-shield-check">
                {{ stats.total }} Total Overrides
              </VChip>
              <VChip color="primary" size="large" label prepend-icon="tabler-calendar-month">
                {{ stats.this_month }} This Month
              </VChip>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Stats by leave type -->
      <VCol v-if="byTypeEntries.length" cols="12" md="4">
        <VCard>
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">
            <VIcon icon="tabler-chart-bar" class="me-2" />{{ t('leave.overrides_by_type') }}
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-4">
            <div v-for="[type, count] in byTypeEntries" :key="type" class="d-flex justify-space-between align-center mb-2">
              <span class="text-body-2">{{ type }}</span>
              <VChip size="x-small" color="warning" label>{{ count }}</VChip>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Override policy notice -->
      <VCol cols="12" :md="byTypeEntries.length ? 8 : 12">
        <VAlert type="warning" variant="tonal" density="compact" icon="tabler-shield-alert" class="mb-4">
          <strong>Compliance Notice:</strong> Each override below bypassed one or more policy rules and skipped
          remaining approval levels. All overrides are retained permanently for audit purposes and cannot be deleted.
        </VAlert>

        <VCard>
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">
            <VIcon icon="tabler-shield-check" class="me-2" />{{ t('leave.override_history') }}
          </VCardTitle>
          <VDivider />

          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>{{ t('leave.request') }}</th>
                <th>{{ t('leave.employee_label') }}</th>
                <th>{{ t('leave.leave_type') }}</th>
                <th>{{ t('leave.approval_level') }}</th>
                <th>{{ t('leave.override_reason') }}</th>
                <th>{{ t('leave.actioned_by') }}</th>
                <th>{{ t('leave.date') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!overrides.length">
                <td colspan="7" class="text-center py-10 text-medium-emphasis">
                  <VIcon icon="tabler-shield-check" size="40" class="d-block mb-2" color="success" />
                  No policy overrides on record.
                </td>
              </tr>
              <tr v-for="o in overrides" :key="o.id">
                <td>
                  <VBtn
                    v-if="o.request_number"
                    size="x-small"
                    variant="text"
                    color="primary"
                    @click="router.visit(`/leave-management/my-applications/${o.id}`)"
                  >
                    {{ o.request_number }}
                  </VBtn>
                  <span v-else class="text-medium-emphasis text-caption">—</span>
                </td>
                <td class="font-weight-medium text-caption">{{ o.employee || '—' }}</td>
                <td class="text-caption">{{ o.leave_type }}</td>
                <td>
                  <VChip v-if="o.level_name" size="x-small" color="primary" label>{{ o.level_name }}</VChip>
                  <span v-else class="text-medium-emphasis text-caption">—</span>
                </td>
                <td class="text-caption" style="max-width:240px">
                  <span :title="o.override_reason ?? ''">
                    {{ o.override_reason ? o.override_reason.substring(0, 80) + (o.override_reason.length > 80 ? '…' : '') : '—' }}
                  </span>
                </td>
                <td class="text-caption">{{ o.actioned_by }}</td>
                <td class="text-caption text-medium-emphasis text-no-wrap">{{ o.actioned_at }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>
