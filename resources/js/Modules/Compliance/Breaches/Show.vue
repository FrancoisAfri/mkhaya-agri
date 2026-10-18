<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t, te: te2 } = useI18n()

/*
 * Translate where somebody has written a term for this value, and otherwise show the value itself.
 * The data behind these keys is open-ended, so no set of entries can ever cover it — the fallback
 * is what stops the page printing a dotted key at a reader.
 */
function label(key: string, fallback: string | null | undefined): string {
  return te2(key) ? t(key) : (fallback ?? '')
}


type Agent = { id: number; name: string }

type Incident = {
  uuid: string
  title: string
  description: string | null
  severity: string
  status: string
  detected_at: string
  notified_regulator_at: string | null
  notified_subjects_at: string | null
  contained_at: string | null
  closed_at: string | null
  affected_record_count: number | null
  affected_subject_types: string[]
  remediation_notes: string | null
  regulator_reference: string | null
  hours_until_deadline: number
  is_overdue: boolean
  reported_by: { id: number; name: string } | null
  assigned_to: { id: number; name: string } | null
  created_at: string
  updated_at: string
}

const props = defineProps<{
  incident: Incident
  agents: Agent[]
}>()

const showStatusDialog = ref(false)

const statusForm = useForm({
  status:              props.incident.status,
  remediation_notes:   props.incident.remediation_notes ?? '',
  regulator_reference: props.incident.regulator_reference ?? '',
  assigned_to:         props.incident.assigned_to?.id ?? null as number | null,
})

function submitStatus() {
  statusForm.patch(route('compliance.breaches.update-status', props.incident.uuid), {
    onSuccess: () => { showStatusDialog.value = false },
  })
}

function severityColor(s: string) {
  return { low: 'info', medium: 'warning', high: 'orange', critical: 'error' }[s] ?? 'default'
}

function statusColor(s: string) {
  return { open: 'error', notified: 'warning', contained: 'info', closed: 'success' }[s] ?? 'default'
}

function fmt(iso: string | null) {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('en-ZA', { dateStyle: 'long', timeStyle: 'short' })
}

const STATUS_FLOW = [
  { key: 'open',      label: t('compliance_breaches.show.timeline_steps.open'),      icon: 'tabler-alert-triangle' },
  { key: 'notified',  label: t('compliance_breaches.show.timeline_steps.notified'),  icon: 'tabler-send' },
  { key: 'contained', label: t('compliance_breaches.show.timeline_steps.contained'), icon: 'tabler-shield-check' },
  { key: 'closed',    label: t('compliance_breaches.show.timeline_steps.closed'),    icon: 'tabler-circle-check' },
]

const STATUS_ORDER = ['open', 'notified', 'contained', 'closed']

function stepColor(stepKey: string) {
  const current = STATUS_ORDER.indexOf(props.incident.status)
  const step    = STATUS_ORDER.indexOf(stepKey)
  if (step < current)  return 'success'
  if (step === current) return statusColor(stepKey)
  return 'grey-lighten-1'
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center gap-2 mb-1">
      <VBtn variant="text" prepend-icon="tabler-arrow-left" size="small" @click="router.visit(route('compliance.breaches.index'))">
        {{ t('compliance_breaches.show.back') }}
      </VBtn>
    </div>

    <div class="d-flex align-start justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <div class="d-flex align-center gap-2 mb-1">
          <h4 class="text-h4">{{ incident.title }}</h4>
          <VChip :color="severityColor(incident.severity)" size="small" label class="text-capitalize">
            {{ t(`compliance_breaches.index.severities.${incident.severity}`) }}
          </VChip>
          <VChip :color="statusColor(incident.status)" size="small" label class="text-capitalize">
            {{ t(`compliance_breaches.index.statuses.${incident.status}`) }}
          </VChip>
        </div>
        <p class="text-medium-emphasis mb-0 text-body-2">
          {{ t('compliance_breaches.show.detected', { date: fmt(incident.detected_at) }) }}
          <span v-if="incident.reported_by"> · {{ t('compliance_breaches.show.reported_by', { name: incident.reported_by.name }) }}</span>
        </p>
      </div>
      <div class="d-flex gap-2">
        <VChip
          v-if="incident.status === 'open'"
          :color="incident.is_overdue ? 'error' : incident.hours_until_deadline < 12 ? 'warning' : 'success'"
          prepend-icon="tabler-clock"
        >
          {{ incident.is_overdue ? t('compliance_breaches.show.deadline_overdue') : t('compliance_breaches.show.deadline_hours', { hours: Math.round(incident.hours_until_deadline) }) }}
        </VChip>
        <VBtn color="primary" prepend-icon="tabler-edit" @click="showStatusDialog = true">
          {{ t('compliance_breaches.show.update_status') }}
        </VBtn>
      </div>
    </div>

    <VRow>
      <VCol cols="12" lg="8">
        <VCard v-if="incident.description" class="mb-4">
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('compliance_breaches.show.description') }}</VCardTitle>
          <VDivider />
          <VCardText class="pa-4 text-body-2">{{ incident.description }}</VCardText>
        </VCard>

        <VCard v-if="incident.remediation_notes" class="mb-4">
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('compliance_breaches.show.remediation_notes') }}</VCardTitle>
          <VDivider />
          <VCardText class="pa-4 text-body-2" style="white-space: pre-wrap">{{ incident.remediation_notes }}</VCardText>
        </VCard>

        <VCard>
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('compliance_breaches.show.timeline') }}</VCardTitle>
          <VDivider />
          <VCardText class="pa-4">
            <VTimeline side="end" density="compact" truncate-line="both">
              <VTimelineItem
                v-for="step in STATUS_FLOW"
                :key="step.key"
                :dot-color="stepColor(step.key)"
                size="small"
              >
                <template #icon>
                  <VIcon :icon="step.icon" size="16" />
                </template>
                <div class="d-flex align-center justify-space-between">
                  <span class="text-body-2 font-weight-medium">{{ step.label }}</span>
                  <span class="text-caption text-medium-emphasis">
                    <template v-if="step.key === 'open'">{{ fmt(incident.detected_at) }}</template>
                    <template v-else-if="step.key === 'notified'">{{ fmt(incident.notified_regulator_at) }}</template>
                    <template v-else-if="step.key === 'contained'">{{ fmt(incident.contained_at) }}</template>
                    <template v-else-if="step.key === 'closed'">{{ fmt(incident.closed_at) }}</template>
                  </span>
                </div>
              </VTimelineItem>
            </VTimeline>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" lg="4">
        <VCard>
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('compliance_breaches.show.details') }}</VCardTitle>
          <VDivider />
          <VList density="compact" class="py-2">
            <VListItem>
              <VListItemTitle class="text-caption text-medium-emphasis">{{ t('compliance_breaches.show.severity') }}</VListItemTitle>
              <VListItemSubtitle>
                <VChip :color="severityColor(incident.severity)" size="x-small" label class="text-capitalize">
                  {{ t(`compliance_breaches.index.severities.${incident.severity}`) }}
                </VChip>
              </VListItemSubtitle>
            </VListItem>
            <VListItem>
              <VListItemTitle class="text-caption text-medium-emphasis">{{ t('compliance_breaches.show.status') }}</VListItemTitle>
              <VListItemSubtitle>
                <VChip :color="statusColor(incident.status)" size="x-small" label class="text-capitalize">
                  {{ t(`compliance_breaches.index.statuses.${incident.status}`) }}
                </VChip>
              </VListItemSubtitle>
            </VListItem>
            <VListItem>
              <VListItemTitle class="text-caption text-medium-emphasis">{{ t('compliance_breaches.show.records_affected') }}</VListItemTitle>
              <VListItemSubtitle>{{ incident.affected_record_count?.toLocaleString() ?? t('compliance_breaches.show.unknown') }}</VListItemSubtitle>
            </VListItem>
            <VListItem v-if="incident.affected_subject_types.length">
              <VListItemTitle class="text-caption text-medium-emphasis">{{ t('compliance_breaches.show.subject_types') }}</VListItemTitle>
              <VListItemSubtitle>
                <VChip v-for="te in incident.affected_subject_types" :key="te" size="x-small" class="me-1">{{ label(`compliance_breaches.index.subject_types.${te}`, te) }}</VChip>
              </VListItemSubtitle>
            </VListItem>
            <VListItem>
              <VListItemTitle class="text-caption text-medium-emphasis">{{ t('compliance_breaches.show.assigned_to') }}</VListItemTitle>
              <VListItemSubtitle>{{ incident.assigned_to?.name ?? t('compliance_breaches.show.unassigned') }}</VListItemSubtitle>
            </VListItem>
            <VListItem v-if="incident.regulator_reference">
              <VListItemTitle class="text-caption text-medium-emphasis">{{ t('compliance_breaches.show.regulator_reference') }}</VListItemTitle>
              <VListItemSubtitle>{{ incident.regulator_reference }}</VListItemSubtitle>
            </VListItem>
            <VDivider class="my-1" />
            <VListItem>
              <VListItemTitle class="text-caption text-medium-emphasis">{{ t('compliance_breaches.show.detected_at') }}</VListItemTitle>
              <VListItemSubtitle>{{ fmt(incident.detected_at) }}</VListItemSubtitle>
            </VListItem>
            <VListItem v-if="incident.notified_regulator_at">
              <VListItemTitle class="text-caption text-medium-emphasis">{{ t('compliance_breaches.show.regulator_notified') }}</VListItemTitle>
              <VListItemSubtitle>{{ fmt(incident.notified_regulator_at) }}</VListItemSubtitle>
            </VListItem>
            <VListItem v-if="incident.notified_subjects_at">
              <VListItemTitle class="text-caption text-medium-emphasis">{{ t('compliance_breaches.show.subjects_notified') }}</VListItemTitle>
              <VListItemSubtitle>{{ fmt(incident.notified_subjects_at) }}</VListItemSubtitle>
            </VListItem>
            <VListItem>
              <VListItemTitle class="text-caption text-medium-emphasis">{{ t('compliance_breaches.show.last_updated') }}</VListItemTitle>
              <VListItemSubtitle>{{ fmt(incident.updated_at) }}</VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>

        <VAlert
          v-if="incident.status === 'open'"
          :type="incident.is_overdue ? 'error' : 'warning'"
          class="mt-4"
          density="compact"
          prominent
        >
          <strong>{{ t('compliance_breaches.show.popia_obligation.title') }}</strong><br>
          {{ t('compliance_breaches.show.popia_obligation.description') }}
          {{ incident.is_overdue ? t('compliance_breaches.show.popia_obligation.deadline_passed') : t('compliance_breaches.show.popia_obligation.deadline_remaining', { hours: Math.round(incident.hours_until_deadline) }) }}
          <div class="mt-1 text-caption">
            {{ t('compliance_breaches.show.popia_obligation.email') }}
          </div>
        </VAlert>
      </VCol>
    </VRow>

    <VDialog v-model="showStatusDialog" max-width="540" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-subtitle-1 d-flex align-center justify-space-between">
          {{ t('compliance_breaches.show.dialog.title') }}
          <VBtn icon="tabler-x" variant="text" size="small" @click="showStatusDialog = false" />
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow>
            <VCol cols="12">
              <VSelect
                v-model="statusForm.status"
                :items="[
                  { title: t('compliance_breaches.show.statuses.open'), value: 'open' },
                  { title: t('compliance_breaches.show.statuses.notified'), value: 'notified' },
                  { title: t('compliance_breaches.show.statuses.contained'), value: 'contained' },
                  { title: t('compliance_breaches.show.statuses.closed'), value: 'closed' },
                ]"
                item-title="title"
                item-value="value"
                :label="t('compliance_breaches.show.dialog.status_label')"
                :error-messages="statusForm.errors.status"
                density="compact"
              />
            </VCol>
            <VCol cols="12">
              <VSelect
                v-model="statusForm.assigned_to"
                :items="agents"
                item-title="name"
                item-value="id"
                :label="t('compliance_breaches.show.dialog.assign_label')"
                :error-messages="statusForm.errors.assigned_to"
                density="compact"
                clearable
              />
            </VCol>
            <VCol cols="12">
              <VTextField
                v-model="statusForm.regulator_reference"
                :label="t('compliance_breaches.show.dialog.reference_label')"
                :error-messages="statusForm.errors.regulator_reference"
                density="compact"
                :hint="t('compliance_breaches.show.dialog.reference_hint')"
                persistent-hint
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="statusForm.remediation_notes"
                :label="t('compliance_breaches.show.dialog.notes_label')"
                :error-messages="statusForm.errors.remediation_notes"
                density="compact"
                rows="4"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4 gap-3">
          <VSpacer />
          <VBtn variant="tonal" @click="showStatusDialog = false">{{ t('compliance_breaches.show.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="statusForm.processing" @click="submitStatus">
            {{ t('compliance_breaches.show.dialog.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
