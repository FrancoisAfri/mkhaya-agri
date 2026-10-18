<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Gap = { type: string; subject: string; severity: string; detail: string; expires_at?: string }
type Obligation = {
  uuid: string; obligation_def_id: string; label: string; due_at: string; status: string
  responsible_role: string | null; recipient: string | null; evidence_required: boolean
  submitted_at: string | null
}

defineProps<{
  incident: Record<string, any>
  /* The name is resolved on the server through the employee directory — SHEQ stores a reference
     to an HR person, not a copy of their name, so the page cannot work it out on its own. */
  people: { uuid: string; role: string; name: string | null; job_title: string | null; is_staff: boolean }[]
  obligations: Obligation[]
  context: { as_at: string; captured_at: string; gaps: Gap[]; gap_count: number; sources: Record<string, any>; snapshot: any } | null
}>()

const gapColour: Record<string, string> = { CRITICAL: 'error', HIGH: 'deep-orange', MEDIUM: 'warning', INFO: 'info' }

function hoursLeft(dueAt: string): number {
  return Math.round((new Date(dueAt).getTime() - Date.now()) / 3600000)
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center gap-3 mb-1">
      <h1 class="text-h5 font-weight-bold">{{ incident.reference }}</h1>
      <VChip size="small" variant="tonal">{{ incident.incident_type }}</VChip>
      <VChip v-if="incident.statutory_category" size="small" variant="tonal"
             :color="incident.is_reportable ? 'error' : 'grey'">
        {{ incident.statutory_category }}
      </VChip>
      <VChip v-if="incident.is_anonymous" size="small" variant="tonal" color="blue-grey"
             prepend-icon="mdi-incognito">{{ t('sheq.incident.anonymous_chip') }}</VChip>
    </div>
    <p class="text-body-2 text-medium-emphasis mb-5">
      {{ new Date(incident.occurred_at).toLocaleString() }}
      <span v-if="incident.site_name"> · {{ incident.site_name }}</span>
      <span v-if="incident.location_text"> · {{ incident.location_text }}</span>
    </p>

    <VRow>
      <VCol cols="12" md="7">
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardText>
            <div class="text-caption text-medium-emphasis mb-1">{{ t('sheq.incident.what_happened') }}</div>
            <p class="text-body-1 mb-4">{{ incident.description }}</p>

            <template v-if="incident.immediate_action">
              <div class="text-caption text-medium-emphasis mb-1">{{ t('sheq.incident.immediate_action') }}</div>
              <p class="text-body-2 mb-0">{{ incident.immediate_action }}</p>
            </template>
          </VCardText>
        </VCard>

        <!-- The context snapshot. This is the module's differentiator, so it is shown prominently
             rather than buried in a tab: the gaps it found are what the investigation must address. -->
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="d-flex align-center justify-space-between">
            <span class="text-body-1 font-weight-semibold">{{ t('sheq.incident.context_title') }}</span>
            <VChip v-if="context" :color="context.gap_count ? 'error' : 'success'" size="small" variant="tonal">
              {{ context.gap_count
                 ? t('sheq.incident.gaps_found', { count: context.gap_count })
                 : t('sheq.incident.no_gaps') }}
            </VChip>
          </VCardTitle>
          <VCardText>
            <p class="text-caption text-medium-emphasis mb-3">{{ t('sheq.incident.context_explainer') }}</p>

            <VAlert v-for="(gap, idx) in context?.gaps ?? []" :key="idx"
                    :type="gap.severity === 'INFO' ? 'info' : 'error'"
                    variant="tonal" density="comfortable" class="mb-2">
              <div class="d-flex align-center gap-2 mb-1">
                <VChip :color="gapColour[gap.severity]" size="x-small" variant="flat">{{ gap.severity }}</VChip>
                <span class="text-caption font-weight-medium">{{ gap.type }}</span>
              </div>
              <div class="text-body-2">{{ gap.detail }}</div>
            </VAlert>

            <div v-if="context && !context.gaps.length" class="text-body-2 text-medium-emphasis">
              {{ t('sheq.incident.no_gaps_detail') }}
            </div>

            <!-- "Could not check" must never read as "checked and fine". -->
            <VDivider class="my-3" />
            <div class="text-caption text-medium-emphasis mb-1">{{ t('sheq.incident.sources_checked') }}</div>
            <VChip v-for="(source, name) in context?.sources ?? {}" :key="name"
                   size="x-small" variant="tonal" class="me-1 mb-1"
                   :color="source.available ? 'success' : 'warning'">
              {{ name }}: {{ source.available ? t('sheq.incident.source_ok') : t('sheq.incident.source_unavailable') }}
            </VChip>
          </VCardText>
        </VCard>

        <VCard v-if="people.length" elevation="0" border rounded="lg">
          <VCardTitle class="text-body-1 font-weight-semibold">{{ t('sheq.incident.people') }}</VCardTitle>
          <VCardText>
            <div v-for="p in people" :key="p.uuid" class="d-flex align-center gap-2 py-1">
              <VChip size="x-small" variant="tonal">{{ p.role }}</VChip>
              <div>
                <!-- A staff member used to render as "#12": the id was all the page had. -->
                <span class="text-body-2">{{ p.name ?? t('sheq.incident.unnamed_person') }}</span>
                <span v-if="p.job_title" class="text-caption text-medium-emphasis"> · {{ p.job_title }}</span>
              </div>
              <VChip v-if="!p.is_staff" size="x-small" variant="tonal" color="grey">
                {{ t('sheq.incident.not_staff') }}
              </VChip>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="5">
        <!-- Statutory obligations, with the clock visible. -->
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="text-body-1 font-weight-semibold">{{ t('sheq.incident.statutory_title') }}</VCardTitle>
          <VCardText>
            <div v-if="!obligations.length" class="text-body-2 text-medium-emphasis">
              {{ t('sheq.incident.no_obligations') }}
            </div>

            <div v-for="o in obligations" :key="o.uuid" class="mb-4">
              <div class="d-flex align-center justify-space-between gap-2">
                <span class="text-body-2 font-weight-medium">{{ o.label }}</span>
                <VChip :color="o.status === 'PENDING' && hoursLeft(o.due_at) < 0 ? 'error'
                              : o.status === 'PENDING' ? 'warning' : 'success'"
                       size="x-small" variant="tonal">
                  {{ o.status === 'PENDING' && hoursLeft(o.due_at) < 0 ? t('sheq.obligation.breached') : o.status }}
                </VChip>
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ t('sheq.obligation.due') }} {{ new Date(o.due_at).toLocaleString() }}
                <template v-if="o.status === 'PENDING'">
                  · <strong :class="hoursLeft(o.due_at) < 24 ? 'text-error' : ''">
                    {{ hoursLeft(o.due_at) >= 0
                       ? t('sheq.obligation.hours_left', { count: hoursLeft(o.due_at) })
                       : t('sheq.obligation.hours_overdue', { count: Math.abs(hoursLeft(o.due_at)) }) }}
                  </strong>
                </template>
              </div>
              <div v-if="o.recipient" class="text-caption text-medium-emphasis">{{ o.recipient }}</div>
            </div>
          </VCardText>
        </VCard>

        <VCard elevation="0" border rounded="lg">
          <VCardText>
            <div class="d-flex justify-space-between py-1">
              <span class="text-caption text-medium-emphasis">{{ t('sheq.incident.status') }}</span>
              <span class="text-body-2">{{ incident.status }}</span>
            </div>
            <div class="d-flex justify-space-between py-1">
              <span class="text-caption text-medium-emphasis">{{ t('sheq.incident.judged_by') }}</span>
              <span class="text-body-2"><code>{{ incident.pack ?? '—' }}</code></span>
            </div>
            <div class="d-flex justify-space-between py-1">
              <span class="text-caption text-medium-emphasis">{{ t('sheq.incident.rule') }}</span>
              <span class="text-body-2"><code>{{ incident.rule_id ?? '—' }}</code></span>
            </div>
            <div class="d-flex justify-space-between py-1">
              <span class="text-caption text-medium-emphasis">{{ t('sheq.incident.retention') }}</span>
              <span class="text-body-2">{{ incident.retention_until ?? '—' }}</span>
            </div>
            <div v-if="incident.required_forms?.length" class="d-flex justify-space-between py-1">
              <span class="text-caption text-medium-emphasis">{{ t('sheq.incident.forms') }}</span>
              <span class="text-body-2">{{ incident.required_forms.join(', ') }}</span>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
