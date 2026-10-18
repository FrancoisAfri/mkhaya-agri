<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Evidence = {
  type: string; id: number; label: string | null; status: string; detail: string | null
}

type PendingChange = {
  change_type: string; from_version: string | null; to_version: string
  diff: Record<string, { from: unknown; to: unknown }>
}

type Line = {
  id: number; uuid: string; instrument: string; clause: string; requirement: string
  applies_to: string; responsible_role: string | null; evidence_types: string[]
  review_frequency: string | null; review_due: string | null; site_id: number | null
  status: string
  status_detail: { findings?: Evidence[]; missing_evidence_types?: string[] }
  is_applicable: boolean; not_applicable_reason: string | null
  evidence: Evidence[]; pending_changes: PendingChange[]
}

type Summary = {
  lines: number; by_status: Record<string, number>; compliance_percent: number
}

const props = defineProps<{
  lines: Line[]
  summary: Summary
  needsCompany?: boolean
}>()

const search = ref('')
const instrument = ref<string | null>(null)
const statusFilter = ref<string | null>(null)
const expanded = ref<string | null>(null)

const instruments = computed(() => [...new Set(props.lines.map(l => l.instrument))])

const statuses = computed(() => Object.keys(props.summary.by_status ?? {}))

const filtered = computed(() => props.lines.filter(l => {
  if (instrument.value && l.instrument !== instrument.value) return false
  if (statusFilter.value && l.status !== statusFilter.value) return false
  if (!search.value) return true
  const q = search.value.toLowerCase()
  return l.requirement.toLowerCase().includes(q) || l.clause.toLowerCase().includes(q)
}))

/**
 * Colour carries the finding, so it has to be honest. NOT_ASSESSED is grey rather than amber: it
 * is an absence of evidence, not a partial pass, and showing it as a near-miss invites a reader to
 * treat an untouched register as nearly compliant.
 */
const statusColour = (status: string) => ({
  COMPLIANT: 'success',
  PARTIAL: 'warning',
  NON_COMPLIANT: 'error',
  REVIEW_REQUIRED: 'info',
  NOT_APPLICABLE: 'secondary',
  NOT_ASSESSED: 'grey',
}[status] ?? 'grey')

const statusLabel = (status: string) => t('sheq.legal.status_' + status.toLowerCase())

const evidenceLabel = (type: string) => t('sheq.evidence_type.' + type.toLowerCase())

const toggle = (uuid: string) => { expanded.value = expanded.value === uuid ? null : uuid }

const isOverdue = (line: Line) =>
  !!line.review_due && new Date(line.review_due) < new Date()
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('sheq.legal.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.legal.subtitle') }}</p>
    </div>

    <VAlert v-if="needsCompany" type="info" variant="tonal" class="mb-4">
      {{ t('sheq.legal.needs_company') }}
    </VAlert>

    <!-- The headline number is compliance across assessable clauses; clauses ruled out of scope
         are excluded from the denominator, so a company that does no construction work is not
         scored down for the construction regulations. -->
    <VRow v-if="!needsCompany" class="mb-2">
      <VCol cols="12" md="4">
        <VCard elevation="0" border rounded="lg" class="pa-4">
          <div class="text-caption text-medium-emphasis">{{ t('sheq.legal.compliance') }}</div>
          <div class="d-flex align-center gap-3 mt-1">
            <div class="text-h4 font-weight-bold">{{ summary.compliance_percent }}%</div>
            <VProgressLinear :model-value="summary.compliance_percent" height="8" rounded
                             :color="summary.compliance_percent >= 90 ? 'success' : 'warning'" />
          </div>
          <div class="text-caption text-medium-emphasis mt-2">
            {{ t('sheq.legal.lines_count', { count: summary.lines }) }}
          </div>
        </VCard>
      </VCol>
      <VCol cols="12" md="8">
        <VCard elevation="0" border rounded="lg" class="pa-4 h-100">
          <div class="text-caption text-medium-emphasis mb-2">{{ t('sheq.legal.by_status') }}</div>
          <div class="d-flex flex-wrap gap-2">
            <VChip v-for="(count, status) in summary.by_status" :key="status"
                   :color="statusColour(String(status))" variant="tonal" size="small"
                   link :aria-pressed="statusFilter === status"
                   :aria-label="t('sheq.legal.filter_by', { status: statusLabel(String(status)) })"
                   @click="statusFilter = statusFilter === status ? null : String(status)">
              {{ statusLabel(String(status)) }}: {{ count }}
            </VChip>
          </div>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mb-2">
      <VCol cols="12" sm="6" md="4">
        <VTextField v-model="search" :label="t('sheq.legal.search')" density="compact"
                    variant="outlined" prepend-inner-icon="mdi-magnify" hide-details clearable />
      </VCol>
      <VCol cols="12" sm="6" md="4">
        <VSelect v-model="instrument" :items="instruments" :label="t('sheq.legal.instrument')"
                 density="compact" variant="outlined" hide-details clearable />
      </VCol>
      <VCol cols="12" sm="6" md="4">
        <VSelect v-model="statusFilter" :items="statuses" :label="t('sheq.legal.status')"
                 density="compact" variant="outlined" hide-details clearable>
          <template #item="{ props: itemProps, item }">
            <VListItem v-bind="itemProps" :title="statusLabel(String(item.value))" />
          </template>
          <template #selection="{ item }">{{ statusLabel(String(item.value)) }}</template>
        </VSelect>
      </VCol>
    </VRow>

    <VCard elevation="0" border rounded="lg">
      <VTable density="comfortable">
        <thead>
          <tr>
            <th scope="col" style="width: 56px"><span class="d-sr-only">{{ t('sheq.legal.expand') }}</span></th>
            <th scope="col">{{ t('sheq.legal.clause') }}</th>
            <th scope="col">{{ t('sheq.legal.requirement') }}</th>
            <th scope="col">{{ t('sheq.legal.responsible') }}</th>
            <th scope="col">{{ t('sheq.legal.evidence') }}</th>
            <th scope="col">{{ t('sheq.legal.status') }}</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="line in filtered" :key="line.uuid">
            <tr class="sheq-row" @click="toggle(line.uuid)">
              <td>
                <!-- The real control, so the row is reachable and operable by keyboard rather than
                     being a click handler on a <tr> that a keyboard user can never trigger. -->
                <VBtn
                  variant="text" size="x-small" icon
                  :aria-expanded="expanded === line.uuid"
                  :aria-label="expanded === line.uuid
                    ? t('sheq.legal.collapse_row', { clause: line.clause })
                    : t('sheq.legal.expand_row', { clause: line.clause })"
                  @click.stop="toggle(line.uuid)"
                >
                  <VIcon size="18" :icon="expanded === line.uuid ? 'mdi-chevron-down' : 'mdi-chevron-right'" />
                </VBtn>
              </td>
              <td style="min-width: 190px">
                <div class="font-weight-medium">{{ line.clause }}</div>
                <div class="text-caption text-medium-emphasis">{{ line.instrument }}</div>
              </td>
              <td>
                {{ line.requirement }}
                <VChip v-if="line.pending_changes.length" color="info" size="x-small"
                       variant="flat" class="ms-2">
                  {{ t('sheq.legal.changed') }}
                </VChip>
              </td>
              <td class="text-body-2">{{ line.responsible_role ?? '—' }}</td>
              <td>
                <VChip v-for="e in line.evidence_types" :key="e" size="x-small" class="me-1 mb-1"
                       :variant="line.evidence.some(x => x.type === e) ? 'flat' : 'tonal'"
                       :color="line.evidence.some(x => x.type === e) ? 'primary' : undefined">
                  {{ evidenceLabel(e) }}
                </VChip>
              </td>
              <td>
                <VChip :color="statusColour(line.status)" size="x-small" variant="tonal">
                  {{ statusLabel(line.status) }}
                </VChip>
                <div v-if="isOverdue(line)" class="text-caption text-warning mt-1">
                  {{ t('sheq.legal.review_overdue', { date: line.review_due }) }}
                </div>
              </td>
            </tr>

            <!-- The reasoning behind the status. A register that shows a red chip without saying
                 what is wrong sends somebody hunting through five other screens. -->
            <tr v-if="expanded === line.uuid">
              <td colspan="6" class="pa-0">
                <div class="pa-4 bg-surface-variant">
                  <div v-if="line.pending_changes.length" class="mb-4">
                    <div class="text-subtitle-2 mb-1">{{ t('sheq.legal.pending_change') }}</div>
                    <div v-for="(change, index) in line.pending_changes" :key="index"
                         class="text-body-2 mb-2">
                      <div class="text-medium-emphasis">
                        {{ change.from_version ?? '—' }} → {{ change.to_version }}
                        ({{ t('sheq.legal.change_' + change.change_type.toLowerCase()) }})
                      </div>
                      <div v-for="(value, field) in change.diff" :key="field" class="mt-1">
                        <span class="font-weight-medium">{{ field }}:</span>
                        <span class="text-decoration-line-through text-medium-emphasis ms-1">
                          {{ value.from ?? '—' }}
                        </span>
                        <span class="ms-1">→ {{ value.to ?? '—' }}</span>
                      </div>
                    </div>
                  </div>

                  <div v-if="line.evidence.length">
                    <div class="text-subtitle-2 mb-2">{{ t('sheq.legal.linked_evidence') }}</div>
                    <div v-for="item in line.evidence" :key="item.type + item.id"
                         class="d-flex align-start gap-2 mb-2">
                      <VChip :color="statusColour(item.status)" size="x-small" variant="flat">
                        {{ statusLabel(item.status) }}
                      </VChip>
                      <div>
                        <div class="text-body-2 font-weight-medium">
                          {{ item.label ?? evidenceLabel(item.type) }}
                        </div>
                        <div class="text-caption text-medium-emphasis">{{ item.detail }}</div>
                      </div>
                    </div>
                  </div>
                  <div v-else class="text-body-2 text-medium-emphasis">
                    {{ t('sheq.legal.no_evidence') }}
                  </div>

                  <VAlert v-if="line.status_detail.missing_evidence_types?.length"
                          type="warning" variant="tonal" density="compact" class="mt-3">
                    {{ t('sheq.legal.missing_evidence') }}
                    <VChip v-for="e in line.status_detail.missing_evidence_types" :key="e"
                           size="x-small" variant="flat" class="ms-1">
                      {{ evidenceLabel(e) }}
                    </VChip>
                  </VAlert>

                  <VAlert v-if="!line.is_applicable" type="info" variant="tonal"
                          density="compact" class="mt-3">
                    {{ t('sheq.legal.not_applicable_because', { reason: line.not_applicable_reason }) }}
                  </VAlert>
                </div>
              </td>
            </tr>
          </template>

          <tr v-if="!filtered.length">
            <td colspan="6" class="text-center py-8 text-medium-emphasis">{{ t('sheq.legal.empty') }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>

<style scoped>
/* The row toggles as a convenience; the button in it is the accessible control. Colour-only
   feedback, so hovering never shifts the table. */
.sheq-row {
  cursor: pointer;
  transition: background-color 200ms ease;
}

@media (prefers-reduced-motion: reduce) {
  .sheq-row { transition: none; }
}
</style>
