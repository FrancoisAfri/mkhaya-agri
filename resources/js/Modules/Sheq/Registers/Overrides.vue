<script setup lang="ts">
/**
 * Who has been let past a gate they should not have been, and on whose authority (CON-17).
 *
 * The one control in this module that works by being visible. Granting an override is easy on
 * purpose — a contractor with work to do and no way through is how people end up signed in
 * under a colleague's name — and what makes it safe is that every one is on this page with who
 * approved it, why, until when and on what conditions.
 *
 * Buried on a contractor's profile it would only ever be seen by somebody who already went
 * looking for that contractor. Here it is seen by whoever opens the page.
 */
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t, d } = useI18n()

type Override = {
  uuid: string
  subject_type: 'CONTRACTOR' | 'CONTRACTOR_WORKER'
  subject_name: string | null
  site_id: number | null
  site_name: string | null
  activity: string | null
  reason: string
  conditions: string | null
  starts_at: string
  ends_at: string
  expired_at: string | null
  revoked_at: string | null
  revoke_reason: string | null
  approved_at: string | null
  issued_by_name: string | null
  approved_by_name: string | null
  state: 'ACTIVE' | 'SCHEDULED' | 'EXPIRED' | 'REVOKED'
}

const props = defineProps<{
  rows: Override[]
  endingSoon: Override[]
  contractors: { value: string; title: string; subtitle: string }[]
  workers: { value: string; title: string; subtitle: string }[]
  sites: { value: number; title: string }[]
  activities: { value: string; title: string }[]
  approvers: { value: number; title: string }[]
  maxDays: number
  canManage?: boolean
  noCompany?: boolean
}>()

const search = ref('')
const showClosed = ref(false)

const visible = computed(() => {
  const needle = search.value.trim().toLowerCase()

  return props.rows.filter(row => {
    const closed = row.state === 'EXPIRED' || row.state === 'REVOKED'
    if (!showClosed.value && closed) return false
    if (!needle) return true

    return [row.subject_name ?? '', row.reason, row.site_name ?? '', row.activity ?? '',
      row.approved_by_name ?? ''].join(' ').toLowerCase().includes(needle)
  })
})

const counts = computed(() => ({
  active: props.rows.filter(r => r.state === 'ACTIVE').length,
  scheduled: props.rows.filter(r => r.state === 'SCHEDULED').length,
  workers: props.rows.filter(r => r.state === 'ACTIVE' && r.subject_type === 'CONTRACTOR_WORKER').length,
}))

const stateColour = (state: string) =>
  ({ ACTIVE: 'warning', SCHEDULED: 'info', EXPIRED: 'default', REVOKED: 'error' }[state] ?? 'default')

const when = (value: string | null) => {
  if (!value) return '—'
  try { return d(new Date(value), 'short') } catch { return value }
}

/** What it covers, in words — the thing a one-line register has to get across. */
const scope = (row: Override) => {
  const where = row.site_name ?? t('sheq.overrides.every_site')
  const what = row.activity
    ? (props.activities.find(a => a.value === row.activity)?.title ?? row.activity)
    : t('sheq.overrides.all_work')

  return `${where} · ${what}`
}

// ── Granting ──────────────────────────────────────────────────────────────────────────────────
const dialog = ref(false)

const form = useForm({
  subject_type: 'CONTRACTOR' as 'CONTRACTOR' | 'CONTRACTOR_WORKER',
  subject_uuid: null as string | null,
  reason: '',
  conditions: '',
  site_id: null as number | null,
  activity: null as string | null,
  starts_at: '',
  ends_at: '',
  approved_by: null as number | null,
})

const subjects = computed(() =>
  form.subject_type === 'CONTRACTOR' ? props.contractors : props.workers)

function open() {
  form.reset()
  form.clearErrors()
  dialog.value = true
}

// Changing who it is about clears the choice: the uuid of a contractor is not the uuid of a
// worker, and leaving it set would post one kind of id against the other.
function onSubjectType(value: string) {
  form.subject_type = value as 'CONTRACTOR' | 'CONTRACTOR_WORKER'
  form.subject_uuid = null
}

function submit() {
  form.post('/sheq/overrides', {
    preserveScroll: true,
    onSuccess: () => { dialog.value = false },
  })
}

// ── Revoking ──────────────────────────────────────────────────────────────────────────────────
const revoking = ref<Override | null>(null)
const revokeForm = useForm({ reason: '' })

function openRevoke(row: Override) {
  revoking.value = row
  revokeForm.reset()
  revokeForm.clearErrors()
}

function revoke() {
  if (!revoking.value) return

  revokeForm.post(`/sheq/overrides/${revoking.value.uuid}/revoke`, {
    preserveScroll: true,
    onSuccess: () => { revoking.value = null },
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.overrides.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.overrides.subtitle') }}</p>
      </div>
      <VBtn v-if="canManage" color="warning" variant="flat" prepend-icon="mdi-key-variant"
            @click="open">
        {{ t('sheq.overrides.grant') }}
      </VBtn>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VRow dense class="mb-1">
        <VCol cols="12" md="4">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <div class="text-caption text-uppercase text-medium-emphasis">
              {{ t('sheq.overrides.in_force') }}
            </div>
            <div class="text-h3 font-weight-bold" :class="counts.active ? 'text-warning' : ''">
              {{ counts.active }}
            </div>
          </VCard>
        </VCol>
        <VCol cols="12" md="4">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <div class="text-caption text-uppercase text-medium-emphasis">
              {{ t('sheq.overrides.scheduled') }}
            </div>
            <div class="text-h3 font-weight-bold">{{ counts.scheduled }}</div>
          </VCard>
        </VCol>
        <VCol cols="12" md="4">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <div class="text-caption text-uppercase text-medium-emphasis">
              {{ t('sheq.overrides.ending_soon') }}
            </div>
            <div class="text-h3 font-weight-bold">{{ endingSoon.length }}</div>
          </VCard>
        </VCol>
      </VRow>

      <!-- Said once, at the top: an override is a decision somebody is answerable for, not a
           setting. The page is designed to be read by people who did not grant them. -->
      <VAlert type="info" variant="tonal" class="mb-4" icon="mdi-information-outline">
        {{ t('sheq.overrides.explainer', { days: maxDays }) }}
      </VAlert>

      <VAlert v-if="endingSoon.length" type="warning" variant="tonal" class="mb-4"
              role="status" aria-live="polite">
        {{ t('sheq.overrides.ending_soon_hint', { n: endingSoon.length }) }}
      </VAlert>

      <VCard elevation="0" border rounded="lg">
        <VCardText class="d-flex flex-wrap align-center ga-3 pb-2">
          <VTextField
            v-model="search" density="compact" variant="outlined" hide-details clearable
            prepend-inner-icon="mdi-magnify" style="max-width: 320px"
            :label="t('sheq.overrides.search')"
          />
          <VSwitch v-model="showClosed" density="compact" hide-details color="primary"
                   :label="t('sheq.overrides.show_closed')" />
        </VCardText>
        <VDivider />

        <div v-if="!visible.length" class="text-center py-10 text-medium-emphasis">
          <VIcon icon="mdi-key-outline" size="32" class="mb-2" />
          <div class="text-body-2">
            {{ rows.length ? t('sheq.overrides.none_open') : t('sheq.overrides.none_ever') }}
          </div>
        </div>

        <VTable v-else density="comfortable">
          <thead>
            <tr>
              <th scope="col">{{ t('sheq.overrides.who') }}</th>
              <th scope="col">{{ t('sheq.overrides.covers') }}</th>
              <th scope="col">{{ t('sheq.overrides.window') }}</th>
              <th scope="col">{{ t('sheq.overrides.authority') }}</th>
              <th scope="col"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in visible" :key="row.uuid">
              <td class="py-3">
                <div class="d-flex align-center flex-wrap ga-2">
                  <span class="text-body-2 font-weight-medium">{{ row.subject_name ?? '—' }}</span>
                  <VChip size="x-small" variant="tonal" :color="stateColour(row.state)">
                    {{ t('sheq.overrides.states.' + row.state) }}
                  </VChip>
                  <VChip v-if="row.subject_type === 'CONTRACTOR_WORKER'" size="x-small"
                         variant="tonal">
                    {{ t('sheq.overrides.one_person') }}
                  </VChip>
                </div>
                <div class="text-caption text-medium-emphasis mt-1">{{ row.reason }}</div>
                <!-- The conditions are on the register, not only in the grant form. An
                     override nobody can read the terms of is one nobody can hold anybody to. -->
                <div v-if="row.conditions" class="text-caption mt-1">
                  {{ t('sheq.contractor.site_conditions', { conditions: row.conditions }) }}
                </div>
                <div v-if="row.revoke_reason" class="text-caption text-error mt-1">
                  {{ t('sheq.overrides.revoked_because', { reason: row.revoke_reason }) }}
                </div>
              </td>
              <td class="text-caption text-medium-emphasis" style="min-width: 160px">
                {{ scope(row) }}
              </td>
              <td class="text-caption text-medium-emphasis" style="min-width: 170px">
                <div>{{ when(row.starts_at) }}</div>
                <div>→ {{ when(row.ends_at) }}</div>
              </td>
              <td class="text-caption text-medium-emphasis" style="min-width: 150px">
                <div v-if="row.approved_by_name">
                  {{ t('sheq.overrides.approved_by', { name: row.approved_by_name }) }}
                </div>
                <div v-if="row.issued_by_name && row.issued_by_name !== row.approved_by_name">
                  {{ t('sheq.overrides.issued_by', { name: row.issued_by_name }) }}
                </div>
              </td>
              <td style="width: 100px" class="text-end">
                <VBtn v-if="canManage && (row.state === 'ACTIVE' || row.state === 'SCHEDULED')"
                      size="x-small" variant="text" color="error" @click="openRevoke(row)">
                  {{ t('sheq.overrides.revoke') }}
                </VBtn>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </template>

    <!-- ── Granting ──────────────────────────────────────────────────────────────────────── -->
    <VDialog v-model="dialog" max-width="820" scrollable>
      <VCard>
        <VCardTitle class="text-body-1 font-weight-semibold">
          {{ t('sheq.overrides.grant') }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VAlert type="warning" variant="tonal" density="compact" class="mb-4">
            {{ t('sheq.overrides.grant_warning') }}
          </VAlert>

          <VBtnToggle :model-value="form.subject_type" mandatory divided density="compact"
                      class="mb-4" color="primary"
                      @update:model-value="onSubjectType">
            <VBtn value="CONTRACTOR">{{ t('sheq.overrides.whole_contractor') }}</VBtn>
            <VBtn value="CONTRACTOR_WORKER">{{ t('sheq.overrides.one_person') }}</VBtn>
          </VBtnToggle>

          <!-- One person rather than their whole employer. A worker whose medical lapsed on the
               day of a shutdown used to mean overriding the firm, which admits forty people to
               fix a problem with one. -->
          <VAutocomplete
            v-model="form.subject_uuid" :items="subjects" item-title="title" item-value="value"
            variant="outlined" density="comfortable" class="mb-3"
            :label="form.subject_type === 'CONTRACTOR'
              ? t('sheq.registers.col.contractor') : t('sheq.attendance.person')"
            :error-messages="form.errors.subject_uuid"
          >
            <template #item="{ props: itemProps, item }">
              <VListItem v-bind="itemProps" :subtitle="item.raw.subtitle" />
            </template>
          </VAutocomplete>

          <VTextarea v-model="form.reason" rows="3" auto-grow variant="outlined"
                     density="comfortable" class="mb-3"
                     :label="t('sheq.overrides.reason')"
                     :hint="t('sheq.overrides.reason_hint')" persistent-hint
                     :error-messages="form.errors.reason" />

          <VTextarea v-model="form.conditions" rows="2" auto-grow variant="outlined"
                     density="comfortable" class="mb-3"
                     :label="t('sheq.overrides.conditions')"
                     :hint="t('sheq.overrides.conditions_hint')" persistent-hint
                     :error-messages="form.errors.conditions" />

          <VRow dense>
            <VCol cols="12" md="6">
              <VTextField v-model="form.starts_at" type="datetime-local" variant="outlined"
                          density="comfortable" :label="t('sheq.overrides.starts')"
                          :hint="t('sheq.overrides.starts_hint')" persistent-hint
                          :error-messages="form.errors.starts_at" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.ends_at" type="datetime-local" variant="outlined"
                          density="comfortable" :label="t('sheq.overrides.ends')"
                          :hint="t('sheq.overrides.ends_hint', { days: maxDays })" persistent-hint
                          :error-messages="form.errors.ends_at" />
            </VCol>

            <!-- Narrowing is the safe default thing to do, so it is offered rather than
                 hidden: an afternoon's grant at the depot should not admit the same people to
                 the refinery. -->
            <VCol cols="12" md="6">
              <VSelect v-model="form.site_id" :items="sites" clearable
                       variant="outlined" density="comfortable"
                       :label="t('sheq.registers.col.site')"
                       :hint="t('sheq.overrides.site_hint')" persistent-hint
                       :error-messages="form.errors.site_id" />
            </VCol>
            <VCol v-if="activities.length" cols="12" md="6">
              <VSelect v-model="form.activity" :items="activities" clearable
                       variant="outlined" density="comfortable"
                       :label="t('sheq.activities.activity')"
                       :hint="t('sheq.overrides.activity_hint')" persistent-hint
                       :error-messages="form.errors.activity" />
            </VCol>

            <VCol cols="12">
              <VSelect v-model="form.approved_by" :items="approvers" clearable
                       variant="outlined" density="comfortable"
                       :label="t('sheq.overrides.approver')"
                       :hint="t('sheq.overrides.approver_hint')" persistent-hint
                       :error-messages="form.errors.approved_by" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="dialog = false">{{ t('sheq.action.cancel') }}</VBtn>
          <VBtn color="warning" variant="flat" :loading="form.processing"
                :disabled="form.processing || !form.subject_uuid || form.reason.trim().length < 10
                           || !form.ends_at"
                @click="submit">
            {{ t('sheq.overrides.grant') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Revoking ──────────────────────────────────────────────────────────────────────── -->
    <VDialog :model-value="revoking !== null" max-width="560"
             @update:model-value="revoking = null">
      <VCard v-if="revoking">
        <VCardTitle class="text-body-1 font-weight-semibold">
          {{ t('sheq.overrides.revoke_title', { name: revoking.subject_name }) }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-3">
            {{ t('sheq.overrides.revoke_body') }}
          </p>
          <VTextarea v-model="revokeForm.reason" rows="2" auto-grow variant="outlined"
                     density="comfortable" :label="t('sheq.overrides.revoke_reason')"
                     :error-messages="revokeForm.errors.reason" />
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="revoking = null">{{ t('sheq.action.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" :loading="revokeForm.processing"
                :disabled="revokeForm.processing || !revokeForm.reason.trim()" @click="revoke">
            {{ t('sheq.overrides.revoke') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
