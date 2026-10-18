<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

interface Check {
  key: string; label: string; status: string; severity: string
  summary: string | null; provider: string | null
  checked_at: string | null; checked_days: number | null
  stale: boolean; mismatched: string[]; has_source: boolean
}
interface HistoryRow {
  id: number; check: string; status: string; severity: string; provider: string | null
  subject: string | null; summary: string | null; note: string | null
  reference: string | null; checked_at: string | null; by: string | null
}
interface Person {
  id: number; name: string; role: string; position: string | null; nationality: string | null
  id_number: string | null; has_id: boolean
  appointed_at: string | null; resigned_at: string | null; source: string
}

const props = defineProps<{
  /** The supplier's uuid — suppliers are addressed by it, never by the row id. */
  supplierUuid: string
  hasTcsPin: boolean
  dueDiligence: {
    checks: Check[]
    banking: { state: string; blocks: boolean; reason: string | null; checked_at: string | null }
    history: HistoryRow[]
    people: Person[]
  }
}>()

// Severity carries an icon and a word as well as a colour. "Never checked" and "clear" must not be
// separable by hue alone — that is the pair a reader must never get wrong.
const severityMeta: Record<string, { colour: string; icon: string }> = {
  clear: { colour: 'success', icon: 'tabler-circle-check' },
  watch: { colour: 'warning', icon: 'tabler-alert-circle' },
  high: { colour: 'error', icon: 'tabler-alert-triangle' },
  unknown: { colour: 'default', icon: 'tabler-help-circle' },
}

const statusLabel = (status: string) => t(`supplier_management.verifications.status.${status}`, status.replace(/_/g, ' '))

const outstanding = computed(() => props.dueDiligence.checks.filter(c => c.status === 'never_checked' || c.severity === 'high'))
const verifiedCount = computed(() => props.dueDiligence.checks.filter(c => c.severity === 'clear' && !c.stale).length)

// ── Record / run a check ─────────────────────────────────────────────────────
const dialog = ref(false)
const activeCheck = ref<Check | null>(null)
const observedRows = ref<{ field: string; value: string }[]>([])

const recordForm = useForm<any>({
  check_type: '', status: 'verified', observed: {} as Record<string, string>,
  note: '', reference: '', person_id: null as number | null,
})
const runForm = useForm<any>({ check_type: '', person_id: null as number | null, reference: '' })

function openRecord(check: Check) {
  activeCheck.value = check
  recordForm.clearErrors()
  recordForm.check_type = check.key
  recordForm.status = 'verified'
  recordForm.note = ''
  recordForm.reference = ''
  recordForm.person_id = null
  observedRows.value = [{ field: '', value: '' }]
  dialog.value = true
}

function submitRecord() {
  recordForm.observed = observedRows.value
    .filter(r => r.field && r.value)
    .reduce((acc, r) => ({ ...acc, [r.field]: r.value }), {})

  recordForm.post(route('suppliers.verifications.record', props.supplierUuid), {
    preserveScroll: true,
    onSuccess: () => { dialog.value = false },
  })
}

function run(check: Check) {
  runForm.check_type = check.key
  runForm.post(route('suppliers.verifications.run', props.supplierUuid), { preserveScroll: true })
}

// ── TCS PIN ──────────────────────────────────────────────────────────────────
const pinDialog = ref(false)
const pinForm = useForm({ tcs_pin: '', tcs_pin_expires_at: '' })
function savePin() {
  pinForm.post(route('suppliers.tcs-pin.store', props.supplierUuid), {
    preserveScroll: true,
    onSuccess: () => { pinDialog.value = false; pinForm.reset() },
  })
}
</script>

<template>
  <VCard border>
    <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-2">
      <span class="text-subtitle-1">{{ t('supplier_management.verifications.title') }}</span>
      <span class="text-caption text-medium-emphasis">
        {{ t('supplier_management.verifications.verified_of', { verified: verifiedCount, total: dueDiligence.checks.length }) }}
      </span>
    </VCardTitle>

    <VCardText>
      <!-- Banking first: it is the one that stops money moving -->
      <VAlert
        v-if="dueDiligence.banking.blocks" type="error" variant="tonal" density="compact" class="mb-4"
        prepend-icon="tabler-cash-off"
      >
        <div class="font-weight-medium">{{ t('supplier_management.verifications.payment_held') }}</div>
        <div class="text-caption">{{ dueDiligence.banking.reason }}</div>
      </VAlert>
      <VAlert
        v-else-if="dueDiligence.banking.state === 'unverified'" type="warning" variant="tonal" density="compact" class="mb-4"
        prepend-icon="tabler-building-bank"
      >
        {{ dueDiligence.banking.reason }}
      </VAlert>

      <VAlert v-if="outstanding.length" type="info" variant="tonal" density="compact" class="mb-4">
        {{ t('supplier_management.verifications.outstanding', { count: outstanding.length }) }}
      </VAlert>

      <!-- Every Layer 1 check, including the ones nobody has done -->
      <div style="overflow-x: auto">
        <VTable density="compact">
          <thead>
            <tr>
              <th>{{ t('supplier_management.verifications.check') }}</th>
              <th>{{ t('supplier_management.verifications.state') }}</th>
              <th>{{ t('supplier_management.verifications.as_at') }}</th>
              <th class="text-right">{{ t('supplier_management.verifications.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in dueDiligence.checks" :key="c.key">
              <td>
                <div class="font-weight-medium">{{ c.label }}</div>
                <div v-if="c.summary" class="text-caption text-medium-emphasis" style="max-width: 46ch">{{ c.summary }}</div>
              </td>
              <td style="min-width: 150px">
                <VChip
                  size="x-small" variant="tonal"
                  :color="severityMeta[c.severity]?.colour" :prepend-icon="severityMeta[c.severity]?.icon"
                >{{ statusLabel(c.status) }}</VChip>
                <VChip v-if="c.stale" size="x-small" variant="tonal" color="warning" class="ms-1">
                  {{ t('supplier_management.verifications.stale') }}
                </VChip>
              </td>
              <td class="text-caption text-medium-emphasis" style="white-space: nowrap">
                <!-- An age, not a bare date: "checked 41 days ago" is the fact a reader needs -->
                <template v-if="c.checked_at">
                  {{ t('supplier_management.verifications.days_ago', { n: c.checked_days }) }}
                  <div>{{ c.provider }}</div>
                </template>
                <span v-else>{{ t('supplier_management.verifications.never') }}</span>
              </td>
              <td class="text-right" style="white-space: nowrap">
                <VBtn
                  v-if="c.has_source" size="x-small" variant="tonal" color="primary" class="me-1"
                  prepend-icon="tabler-bolt" @click="run(c)"
                >{{ t('supplier_management.verifications.run') }}</VBtn>
                <VBtn size="x-small" variant="tonal" prepend-icon="tabler-user-edit" @click="openRecord(c)">
                  {{ t('supplier_management.verifications.record') }}
                </VBtn>
              </td>
            </tr>
          </tbody>
        </VTable>
      </div>

      <!-- The PIN is what makes a tax check possible at all -->
      <VDivider class="my-4" />
      <div class="d-flex align-center justify-space-between flex-wrap gap-2">
        <div>
          <div class="text-subtitle-2">{{ t('supplier_management.verifications.tcs_pin') }}</div>
          <div class="text-caption text-medium-emphasis" style="max-width: 60ch">
            {{ hasTcsPin
              ? t('supplier_management.verifications.pin_held')
              : t('supplier_management.verifications.pin_missing') }}
          </div>
        </div>
        <VBtn size="small" variant="tonal" prepend-icon="tabler-key" @click="pinDialog = true">
          {{ hasTcsPin ? t('supplier_management.verifications.pin_replace') : t('supplier_management.verifications.pin_add') }}
        </VBtn>
      </div>

      <!-- People, with identifiers masked -->
      <template v-if="dueDiligence.people.length">
        <VDivider class="my-4" />
        <div class="text-subtitle-2 mb-2">{{ t('supplier_management.verifications.people') }}</div>
        <div style="overflow-x: auto">
          <VTable density="compact">
            <thead>
              <tr>
                <th>{{ t('supplier_management.verifications.person') }}</th>
                <th>{{ t('supplier_management.verifications.role') }}</th>
                <th>{{ t('supplier_management.verifications.identifier') }}</th>
                <th>{{ t('supplier_management.verifications.appointed') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in dueDiligence.people" :key="p.id">
                <td>
                  <div class="font-weight-medium">{{ p.name }}</div>
                  <div v-if="p.position" class="text-caption text-medium-emphasis">{{ p.position }}</div>
                </td>
                <td class="text-caption text-capitalize">{{ p.role.replace('_', ' ') }}</td>
                <td class="text-caption">
                  <span v-if="p.id_number" style="font-family: monospace">{{ p.id_number }}</span>
                  <VChip v-else size="x-small" variant="tonal" color="warning">
                    {{ t('supplier_management.verifications.no_identifier') }}
                  </VChip>
                </td>
                <td class="text-caption text-medium-emphasis">
                  {{ p.appointed_at || '—' }}
                  <span v-if="p.resigned_at"> · {{ t('supplier_management.verifications.resigned', { date: p.resigned_at }) }}</span>
                </td>
              </tr>
            </tbody>
          </VTable>
        </div>
      </template>

      <!-- History: what the source said, and when -->
      <template v-if="dueDiligence.history.length">
        <VDivider class="my-4" />
        <VExpansionPanels variant="accordion">
          <VExpansionPanel>
            <VExpansionPanelTitle>
              {{ t('supplier_management.verifications.history', { n: dueDiligence.history.length }) }}
            </VExpansionPanelTitle>
            <VExpansionPanelText>
              <VTimeline side="end" density="compact" truncate-line="both">
                <VTimelineItem
                  v-for="h in dueDiligence.history" :key="h.id" size="x-small"
                  :dot-color="severityMeta[h.severity]?.colour"
                >
                  <div class="d-flex flex-wrap align-center gap-2">
                    <span class="font-weight-medium">{{ h.check }}</span>
                    <VChip size="x-small" variant="tonal" :color="severityMeta[h.severity]?.colour">
                      {{ statusLabel(h.status) }}
                    </VChip>
                    <span class="text-caption text-medium-emphasis">{{ h.checked_at }}</span>
                  </div>
                  <div v-if="h.subject" class="text-caption text-medium-emphasis">{{ h.subject }}</div>
                  <div v-if="h.summary" class="text-caption">{{ h.summary }}</div>
                  <div v-if="h.note" class="text-caption text-medium-emphasis">“{{ h.note }}”</div>
                  <div class="text-caption text-medium-emphasis">
                    {{ h.provider }}<span v-if="h.by"> · {{ h.by }}</span><span v-if="h.reference"> · {{ h.reference }}</span>
                  </div>
                </VTimelineItem>
              </VTimeline>
            </VExpansionPanelText>
          </VExpansionPanel>
        </VExpansionPanels>
      </template>
    </VCardText>

    <!-- Record a check performed by hand -->
    <VDialog v-model="dialog" max-width="620" scrollable>
      <VCard v-if="activeCheck">
        <VCardTitle>{{ t('supplier_management.verifications.record_title', { check: activeCheck.label }) }}</VCardTitle>
        <VCardText>
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            {{ t('supplier_management.verifications.record_help') }}
          </VAlert>

          <VSelect
            v-model="recordForm.status" density="comfortable" variant="outlined" class="mb-3"
            :label="t('supplier_management.verifications.outcome')"
            :items="[
              { title: statusLabel('verified'), value: 'verified' },
              { title: statusLabel('mismatch'), value: 'mismatch' },
              { title: statusLabel('not_found'), value: 'not_found' },
              { title: statusLabel('manual_review'), value: 'manual_review' },
            ]"
            :error-messages="recordForm.errors.status"
          />

          <VSelect
            v-if="dueDiligence.people.length" v-model="recordForm.person_id" clearable
            density="comfortable" variant="outlined" class="mb-3"
            :label="t('supplier_management.verifications.about_person')"
            :items="dueDiligence.people.map(p => ({ title: `${p.name} (${p.role})`, value: p.id }))"
          />

          <div class="text-subtitle-2 mb-1">{{ t('supplier_management.verifications.what_source_said') }}</div>
          <div class="text-caption text-medium-emphasis mb-2">{{ t('supplier_management.verifications.observed_help') }}</div>
          <div v-for="(row, i) in observedRows" :key="i" class="d-flex gap-2 mb-2">
            <VTextField v-model="row.field" :label="t('supplier_management.verifications.field')" density="compact" variant="outlined" hide-details />
            <VTextField v-model="row.value" :label="t('supplier_management.verifications.value')" density="compact" variant="outlined" hide-details />
            <VBtn icon="tabler-plus" size="small" variant="text"
                    :aria-label="t('supplier_management.verifications.add_field_row')"
                    @click="observedRows.push({ field: '', value: '' })" />
          </div>

          <VTextField
            v-model="recordForm.reference" class="mt-3" density="comfortable" variant="outlined"
            :label="t('supplier_management.verifications.reference')" :error-messages="recordForm.errors.reference"
          />
          <VTextarea
            v-model="recordForm.note" rows="3" density="comfortable" variant="outlined"
            :label="t('supplier_management.verifications.note')" :error-messages="recordForm.errors.note"
          />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('common.cancel', 'Cancel') }}</VBtn>
          <VBtn color="primary" :loading="recordForm.processing" @click="submitRecord">
            {{ t('supplier_management.verifications.save_record') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- TCS PIN -->
    <VDialog v-model="pinDialog" max-width="480">
      <VCard>
        <VCardTitle>{{ t('supplier_management.verifications.tcs_pin') }}</VCardTitle>
        <VCardText>
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            {{ t('supplier_management.verifications.pin_help') }}
          </VAlert>
          <VTextField
            v-model="pinForm.tcs_pin" class="mb-3" density="comfortable" variant="outlined"
            :label="t('supplier_management.verifications.pin')" :error-messages="pinForm.errors.tcs_pin"
          />
          <VTextField
            v-model="pinForm.tcs_pin_expires_at" type="date" density="comfortable" variant="outlined"
            :label="t('supplier_management.verifications.pin_expires')" :error-messages="pinForm.errors.tcs_pin_expires_at"
          />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="pinDialog = false">{{ t('common.cancel', 'Cancel') }}</VBtn>
          <VBtn color="primary" :loading="pinForm.processing" @click="savePin">{{ t('common.save', 'Save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VCard>
</template>
