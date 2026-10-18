<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()

type Stats = {
  openBreaches: number
  overdueBreaches: number
  phiAccessLast7d: number
  activeConsents: number
}

type Breach = {
  uuid: string
  title: string
  severity: string
  status: string
  detected_at: string
  hours_until_deadline: number
  is_overdue: boolean
  reported_by: string | null
  assigned_to: string | null
}

type PhiEntry = {
  id: number
  subject_type: string
  subject_id: number
  field: string
  accessed_by: string
  ip_address: string | null
  accessed_at: string
}

const props = defineProps<{
  stats: Stats
  openBreaches: Breach[]
  recentPhiLog: PhiEntry[]
}>()

const statCards = computed(() => [
  {
    title: t('compliance_dashboard.stats.open_breaches'),
    value: props.stats.openBreaches,
    icon: 'tabler-alert-triangle',
    color: props.stats.openBreaches > 0 ? 'error' : 'success',
  },
  {
    title: t('compliance_dashboard.stats.overdue_breaches'),
    value: props.stats.overdueBreaches,
    icon: 'tabler-clock-exclamation',
    color: props.stats.overdueBreaches > 0 ? 'error' : 'success',
  },
  {
    title: t('compliance_dashboard.stats.phi_accesses_7d'),
    value: props.stats.phiAccessLast7d,
    icon: 'tabler-eye',
    color: 'info',
  },
  {
    title: t('compliance_dashboard.stats.active_consents'),
    value: props.stats.activeConsents,
    icon: 'tabler-clipboard-check',
    color: 'success',
  },
])

function severityColor(s: string) {
  return { low: 'info', medium: 'warning', high: 'orange', critical: 'error' }[s] ?? 'default'
}

function statusColor(s: string) {
  return { open: 'error', notified: 'warning', contained: 'info', closed: 'success' }[s] ?? 'default'
}

function fmt(iso: string) {
  return new Date(iso).toLocaleString('en-ZA', { dateStyle: 'medium', timeStyle: 'short' })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4">{{ t('compliance_dashboard.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('compliance_dashboard.subtitle') }}</p>
      </div>
      <VBtn color="error" prepend-icon="tabler-alert-triangle" :href="route('compliance.breaches.index')">
        {{ t('compliance_dashboard.report_breach') }}
      </VBtn>
    </div>

    <VRow class="mb-6">
      <VCol v-for="card in statCards" :key="card.title" cols="12" sm="6" lg="3">
        <VCard>
          <VCardText class="d-flex align-center gap-4 pa-4">
            <VAvatar :color="card.color" variant="tonal" size="52" rounded>
              <VIcon :icon="card.icon" size="28" />
            </VAvatar>
            <div>
              <p class="text-medium-emphasis text-body-2 mb-1">{{ card.title }}</p>
              <h5 class="text-h5">{{ card.value.toLocaleString() }}</h5>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <VCol cols="12" lg="7">
        <VCard>
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between">
            <span class="text-subtitle-1">{{ t('compliance_dashboard.open_incidents.title') }}</span>
            <VBtn variant="text" size="small" :href="route('compliance.breaches.index')">{{ t('compliance_dashboard.open_incidents.view_all') }}</VBtn>
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-0">
            <VTable density="compact" class="text-no-wrap">
              <thead>
                <tr>
                  <th>{{ t('compliance_dashboard.open_incidents.table.title') }}</th>
                  <th>{{ t('compliance_dashboard.open_incidents.table.severity') }}</th>
                  <th>{{ t('compliance_dashboard.open_incidents.table.status') }}</th>
                  <th>{{ t('compliance_dashboard.open_incidents.table.deadline') }}</th>
                  <th>{{ t('compliance_dashboard.open_incidents.table.detected') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="openBreaches.length === 0">
                  <td colspan="5" class="text-center text-medium-emphasis pa-4">{{ t('compliance_dashboard.open_incidents.no_incidents') }}</td>
                </tr>
                <tr v-for="b in openBreaches" :key="b.uuid" class="cursor-pointer" @click="router.visit(route('compliance.breaches.show', b.uuid))">
                  <td class="py-2"><span class="font-weight-medium">{{ b.title }}</span></td>
                  <td><VChip :color="severityColor(b.severity)" size="x-small" label>{{ t(`compliance_dashboard.open_incidents.severities.${b.severity}`) }}</VChip></td>
                  <td><VChip :color="statusColor(b.status)" size="x-small" label>{{ t(`compliance_dashboard.open_incidents.statuses.${b.status}`) }}</VChip></td>
                  <td>
                    <VChip :color="b.is_overdue ? 'error' : b.hours_until_deadline < 12 ? 'warning' : 'success'" size="x-small" label>
                      {{ b.is_overdue ? t('compliance_dashboard.open_incidents.table.overdue') : `${Math.round(b.hours_until_deadline)}h` }}
                    </VChip>
                  </td>
                  <td class="text-medium-emphasis text-caption">{{ fmt(b.detected_at) }}</td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" lg="5">
        <VCard>
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between">
            <span class="text-subtitle-1">{{ t('compliance_dashboard.recent_phi.title') }}</span>
            <VBtn variant="text" size="small" :href="route('compliance.phi-log.index')">{{ t('compliance_dashboard.recent_phi.view_all') }}</VBtn>
          </VCardTitle>
          <VDivider />
          <VList lines="two" class="py-0">
            <template v-if="recentPhiLog.length === 0">
              <VListItem>
                <VListItemTitle class="text-medium-emphasis text-center">{{ t('compliance_dashboard.recent_phi.no_access') }}</VListItemTitle>
              </VListItem>
            </template>
            <VListItem v-for="entry in recentPhiLog" :key="entry.id" class="px-4 py-2">
              <template #prepend>
                <VAvatar color="secondary" variant="tonal" size="36" class="me-3">
                  <VIcon icon="tabler-eye" size="18" />
                </VAvatar>
              </template>
              <VListItemTitle class="text-body-2 font-weight-medium">
                {{ entry.accessed_by }}
                <span class="text-medium-emphasis font-weight-regular">{{ t('compliance_dashboard.recent_phi.accessed') }}</span>
                <code class="text-caption">{{ entry.field }}</code>
                <span class="text-medium-emphasis font-weight-regular">{{ t('compliance_dashboard.recent_phi.on') }} {{ entry.subject_type }}</span>
              </VListItemTitle>
              <VListItemSubtitle class="text-caption">
                {{ fmt(entry.accessed_at) }}
                <span v-if="entry.ip_address"> · {{ entry.ip_address }}</span>
              </VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mt-4">
      <VCol cols="12">
        <VCard>
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('compliance_dashboard.quick_links.title') }}</VCardTitle>
          <VDivider />
          <VCardText class="d-flex flex-wrap gap-3 pa-4">
            <VBtn variant="tonal" prepend-icon="tabler-alert-triangle" :href="route('compliance.breaches.index')">
              {{ t('compliance_dashboard.quick_links.breach_register') }}
            </VBtn>
            <VBtn variant="tonal" prepend-icon="tabler-clipboard-check" :href="route('compliance.consents.index')">
              {{ t('compliance_dashboard.quick_links.consent_register') }}
            </VBtn>
            <VBtn variant="tonal" prepend-icon="tabler-eye" :href="route('compliance.phi-log.index')">
              {{ t('compliance_dashboard.quick_links.phi_access_log') }}
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>
