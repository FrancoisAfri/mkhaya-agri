<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface EscalationEntry {
  id: number; event: string; description: string
  properties: Record<string, any>; created_at: string
}
interface Stats {
  overdue_investigations: number; overdue_hearings: number
  overdue_notices: number; escalations_today: number
}

const props = defineProps<{
  recentEscalations: EscalationEntry[]
  recentNotifications: EscalationEntry[]
  stats: Stats
}>()

const runForm = useForm({})
const tab = ref('escalations')

function runEscalations() {
  runForm.post(route('disciplinary.notifications-escalations.run'), {
    onSuccess: () => {},
  })
}

const eventLabel = (event: string) => t(`disciplinary_notifications.event_labels.${event}`) ?? event

const eventColor = (event: string) => {
  if (event.startsWith('escalated_')) return 'error'
  if (event === 'escalation_run')      return 'warning'
  return 'primary'
}

const thresholds = [
  { label: t('disciplinary_notifications.thresholds.overdue_investigation'), threshold: t('disciplinary_notifications.thresholds.overdue_investigation_threshold'), color: 'error' },
  { label: t('disciplinary_notifications.thresholds.overdue_hearing'), threshold: t('disciplinary_notifications.thresholds.overdue_hearing_threshold'), color: 'deep-orange' },
  { label: t('disciplinary_notifications.thresholds.notice_not_issued'), threshold: t('disciplinary_notifications.thresholds.notice_not_issued_threshold'), color: 'orange' },
  { label: t('disciplinary_notifications.thresholds.policy_violation'), threshold: t('disciplinary_notifications.thresholds.policy_violation_threshold'), color: 'warning' },
]
</script>

<template>
  <div class="pa-6">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('disciplinary_notifications.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('disciplinary_notifications.subtitle') }}</p>
      </div>
      <VBtn color="warning" prepend-icon="tabler-bell-ringing" :loading="runForm.processing" @click="runEscalations">
        {{ t('disciplinary_notifications.run_escalation') }}
      </VBtn>
    </div>

    <!-- Stats -->
    <VRow class="mb-6" dense>
      <VCol cols="6" sm="3">
        <VCard variant="tonal" color="error" class="text-center pa-4">
          <div class="text-h4 font-weight-bold">{{ stats.overdue_investigations }}</div>
          <div class="text-caption">{{ t('disciplinary_notifications.stats.overdue_investigations') }}</div>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard variant="tonal" color="deep-orange" class="text-center pa-4">
          <div class="text-h4 font-weight-bold">{{ stats.overdue_hearings }}</div>
          <div class="text-caption">{{ t('disciplinary_notifications.stats.overdue_hearings') }}</div>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard variant="tonal" color="orange" class="text-center pa-4">
          <div class="text-h4 font-weight-bold">{{ stats.overdue_notices }}</div>
          <div class="text-caption">{{ t('disciplinary_notifications.stats.notices_not_issued') }}</div>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard variant="tonal" color="primary" class="text-center pa-4">
          <div class="text-h4 font-weight-bold">{{ stats.escalations_today }}</div>
          <div class="text-caption">{{ t('disciplinary_notifications.stats.escalations_today') }}</div>
        </VCard>
      </VCol>
    </VRow>

    <!-- Tabs -->
    <VCard>
      <VTabs v-model="tab" color="primary">
        <VTab value="escalations">
          <VIcon start>tabler-arrow-up-circle</VIcon>
          {{ t('disciplinary_notifications.tabs.escalation_history') }}
          <VChip class="ml-2" size="x-small" color="error" variant="tonal">{{ recentEscalations.length }}</VChip>
        </VTab>
        <VTab value="notifications">
          <VIcon start>tabler-bell</VIcon>
          {{ t('disciplinary_notifications.tabs.notification_history') }}
          <VChip class="ml-2" size="x-small" color="primary" variant="tonal">{{ recentNotifications.length }}</VChip>
        </VTab>
      </VTabs>
      <VDivider />

      <VWindow v-model="tab">
        <!-- Escalation History -->
        <VWindowItem value="escalations">
          <VDataTable
            :items="recentEscalations"
            :headers="[
              { title: t('disciplinary_notifications.table.time'), key: 'created_at', width: 160 },
              { title: t('disciplinary_notifications.table.type'), key: 'event' },
              { title: t('disciplinary_notifications.table.detail'), key: 'description' },
            ]"
            :items-per-page="20"
          >
            <template #item.event="{ item }">
              <VChip :color="eventColor(item.event)" size="small" variant="tonal">
                {{ eventLabel(item.event) }}
              </VChip>
            </template>
            <template #item.description="{ item }">
              <span class="text-body-2">{{ item.description }}</span>
              <div v-if="item.properties.case_number" class="text-caption text-medium-emphasis">
                {{ t('disciplinary_notifications.table.case') }} {{ item.properties.case_number }}
              </div>
            </template>
          </VDataTable>
        </VWindowItem>

        <!-- Notification History -->
        <VWindowItem value="notifications">
          <VDataTable
            :items="recentNotifications"
            :headers="[
              { title: t('disciplinary_notifications.table.time'), key: 'created_at', width: 160 },
              { title: t('disciplinary_notifications.table.type'), key: 'event' },
              { title: t('disciplinary_notifications.table.detail'), key: 'description' },
            ]"
            :items-per-page="20"
          >
            <template #item.event="{ item }">
              <VChip color="primary" size="small" variant="tonal">
                {{ eventLabel(item.event) }}
              </VChip>
            </template>
            <template #item.description="{ item }">
              <span class="text-body-2">{{ item.description }}</span>
              <div v-if="item.properties.case_number" class="text-caption text-medium-emphasis">
                {{ t('disciplinary_notifications.table.case') }} {{ item.properties.case_number }}
              </div>
            </template>
          </VDataTable>
        </VWindowItem>
      </VWindow>
    </VCard>

    <!-- Escalation thresholds legend -->
    <VCard class="mt-4" variant="outlined">
      <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('disciplinary_notifications.thresholds.title') }}</VCardTitle>
      <VDivider />
      <VCardText class="pa-4">
        <VRow dense>
          <VCol cols="12" sm="6" md="3" v-for="item in thresholds" :key="item.label">
            <div class="d-flex align-center ga-2">
              <VIcon :color="item.color" size="16">tabler-clock-exclamation</VIcon>
              <div>
                <div class="text-body-2 font-weight-medium">{{ item.label }}</div>
                <div class="text-caption text-medium-emphasis">{{ item.threshold }}</div>
              </div>
            </div>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>
  </div>
</template>
