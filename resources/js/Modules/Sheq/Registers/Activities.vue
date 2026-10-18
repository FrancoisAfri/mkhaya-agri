<script setup lang="ts">
/**
 * The catalogue of work done on site, and what each kind takes (CON-15).
 *
 * Its own register rather than a card on settings. A safety manager maintains it, a gate reads
 * it and an auditor asks to see it — and the commonest way a catalogue like this fails is by
 * being somewhere nobody looks, which is how a requirement ends up demanding a certificate
 * nobody on the estate holds without anybody noticing.
 *
 * Which is why the readiness column is here and not hidden behind a report: "3 of 14" against
 * Working at height is the difference between a requirement that protects a site and one that
 * only looks like it does.
 */
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t } = useI18n()

type Activity = {
  uuid: string
  code: string
  name: string
  description: string | null
  required_credentials: string[]
  is_high_risk: boolean
  requires_permit: boolean
  requires_medical: boolean
  requires_induction: boolean
  requires_risk_assessment: boolean
  requires_method_statement: boolean
  requires_equipment_certification: boolean
  required_ppe: string[]
  is_active: boolean
}

const props = defineProps<{
  rows: Activity[]
  credentialCodes: { value: string; title: string }[]
  readiness: Record<string, { ready: number; total: number }>
  /** Whether a missing ticket refuses anybody, or only gets recorded. */
  enforced: boolean
  rulesHref: string
  canManage?: boolean
  noCompany?: boolean
  shippedCount: number
}>()

const search = ref('')
const showRetired = ref(false)

const visible = computed(() => {
  const needle = search.value.trim().toLowerCase()

  return props.rows.filter(row => {
    if (!showRetired.value && !row.is_active) return false
    if (!needle) return true

    return [row.name, row.code, row.description ?? '', ...row.required_credentials]
      .join(' ').toLowerCase().includes(needle)
  })
})

const highRisk = computed(() => props.rows.filter(r => r.is_active && r.is_high_risk).length)

/** Activities nobody on the estate can currently do. The figure worth acting on. */
const unstaffed = computed(() => props.rows.filter(r => {
  const score = props.readiness[r.code]
  return r.is_active && score && score.total > 0 && score.ready === 0
}).length)

const pretty = (code: string) =>
  code.replace(/_/g, ' ').toLowerCase().replace(/^./, c => c.toUpperCase())

const readinessOf = (code: string) => props.readiness[code] ?? null

const readinessColour = (code: string) => {
  const score = readinessOf(code)
  if (!score || score.total === 0) return 'default'
  if (score.ready === 0) return 'error'
  if (score.ready < score.total / 2) return 'warning'
  return 'success'
}

// ── The form ──────────────────────────────────────────────────────────────────────────────────
const dialog = ref(false)
const editing = ref<Activity | null>(null)

const form = useForm({
  code: '',
  name: '',
  description: '',
  required_credentials: [] as string[],
  is_high_risk: false,
  requires_permit: false,
  requires_medical: false,
  requires_induction: false,
  requires_risk_assessment: false,
  requires_method_statement: false,
  requires_equipment_certification: false,
  required_ppe: [] as string[],
  is_active: true,
})

/**
 * The evidence kinds an activity can demand, beyond a ticket (CON-26).
 *
 * Listed as data rather than as six hand-written switches, so the next kind added to the
 * engine needs one line here instead of a block of markup somebody copies and mis-edits.
 *
 * Each note says what is actually checked, because "requires a medical" is ambiguous in a way
 * that matters: a host ticking it needs to know the gate looks for an accepted certificate of
 * fitness on the worker's own record, not for a line in a spreadsheet somewhere.
 */
const requirementKinds = [
  { key: 'requires_medical', colour: 'pink',
    label: 'Certificate of fitness',
    note: 'The person must hold an accepted medical on their own record.' },
  { key: 'requires_induction', colour: 'teal',
    label: 'Task induction',
    note: 'A briefing for this work, over and above the general site induction.' },
  { key: 'requires_risk_assessment', colour: 'deep-purple',
    label: 'Risk assessment',
    note: 'An approved assessment must be in force for the site, or company-wide.' },
  { key: 'requires_method_statement', colour: 'indigo',
    label: 'Method statement',
    note: 'A current METHOD_STATEMENT document on the contractor\'s file.' },
  { key: 'requires_equipment_certification', colour: 'brown',
    label: 'Equipment certificate',
    note: 'A current EQUIPMENT_CERTIFICATE document — a crane\'s load test, for instance.' },
] as const

function open(row: Activity | null) {
  editing.value = row
  form.reset()
  form.clearErrors()

  if (row) {
    form.code = row.code
    form.name = row.name
    form.description = row.description ?? ''
    form.required_credentials = [...row.required_credentials]
    form.is_high_risk = row.is_high_risk
    form.requires_permit = row.requires_permit
    form.requires_medical = row.requires_medical
    form.requires_induction = row.requires_induction
    form.requires_risk_assessment = row.requires_risk_assessment
    form.requires_method_statement = row.requires_method_statement
    form.requires_equipment_certification = row.requires_equipment_certification
    form.required_ppe = [...(row.required_ppe ?? [])]
    form.is_active = row.is_active
  }

  dialog.value = true
}

/*
 * The code is what a reader, a permit and an API send, so it is normalised as it is typed.
 * A catalogue entry saved as "hot work" would never match the HOT_WORK a scanner sends, and the
 * mismatch reads as a pass.
 */
function onCode(value: string) {
  form.code = (value ?? '').toUpperCase().replace(/[^A-Z0-9_-]+/g, '_')
}

// Suggested once, from the name, and only while creating — overwriting a code somebody is
// editing would silently re-key an activity that permits and events already refer to.
function onName(value: string) {
  form.name = value

  if (!editing.value && !form.code.trim()) {
    onCode(value)
  }
}

function submit() {
  const options = { preserveScroll: true, onSuccess: () => { dialog.value = false } }

  editing.value
    ? form.put(`/sheq/activities/${editing.value.uuid}`, options)
    : form.post('/sheq/activities', options)
}

const retiring = ref<Activity | null>(null)

function retire() {
  if (!retiring.value) return

  router.delete(`/sheq/activities/${retiring.value.uuid}`, {
    preserveScroll: true,
    onSuccess: () => { retiring.value = null; dialog.value = false },
  })
}

const installing = ref(false)

function install() {
  installing.value = true
  router.post('/sheq/activities/install', {}, {
    preserveScroll: true,
    onFinish: () => { installing.value = false },
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.activities.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.activities.subtitle') }}</p>
      </div>
      <VBtn v-if="canManage && rows.length" color="primary" variant="flat" prepend-icon="mdi-plus"
            @click="open(null)">
        {{ t('sheq.activities.add') }}
      </VBtn>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <!--
        An empty catalogue is the normal starting state and the worst one to leave somebody in:
        twelve activities to invent before the gate does anything at all.
      -->
      <VCard v-if="!rows.length" elevation="0" border rounded="lg" class="pa-8 text-center">
        <VIcon icon="mdi-clipboard-list-outline" size="40" class="mb-3 text-medium-emphasis" />
        <div class="text-h6 font-weight-bold mb-1">{{ t('sheq.activities.empty_title') }}</div>
        <p class="text-body-2 text-medium-emphasis mx-auto mb-4" style="max-width: 520px">
          {{ t('sheq.activities.empty_body') }}
        </p>
        <div class="d-flex justify-center ga-2 flex-wrap">
          <VBtn v-if="canManage" color="primary" variant="flat" :loading="installing"
                prepend-icon="mdi-download" @click="install">
            {{ t('sheq.activities.install', { n: shippedCount }) }}
          </VBtn>
          <VBtn v-if="canManage" variant="outlined" prepend-icon="mdi-plus" @click="open(null)">
            {{ t('sheq.activities.add') }}
          </VBtn>
        </div>
      </VCard>

      <template v-else>
        <!-- Said where the requirements are set, because a catalogue that refuses nothing is a
             catalogue somebody believes is protecting them. -->
        <VAlert v-if="!enforced" type="info" variant="tonal" class="mb-4"
                icon="mdi-information-outline">
          <div class="font-weight-semibold mb-1">{{ t('sheq.activities.advisory_title') }}</div>
          <div>{{ t('sheq.activities.advisory_body') }}</div>
          <template #append>
            <VBtn :href="rulesHref" variant="tonal" size="small" style="cursor: pointer">
              {{ t('sheq.activities.advisory_action') }}
            </VBtn>
          </template>
        </VAlert>

        <VAlert v-if="unstaffed" type="warning" variant="tonal" class="mb-4"
                role="status" aria-live="polite">
          {{ t('sheq.activities.unstaffed', { n: unstaffed }) }}
        </VAlert>

        <VRow dense class="mb-1">
          <VCol cols="12" md="4">
            <VCard elevation="0" border rounded="lg" class="pa-4">
              <div class="text-caption text-uppercase text-medium-emphasis">
                {{ t('sheq.activities.in_catalogue') }}
              </div>
              <div class="text-h4 font-weight-bold">{{ rows.filter(r => r.is_active).length }}</div>
            </VCard>
          </VCol>
          <VCol cols="12" md="4">
            <VCard elevation="0" border rounded="lg" class="pa-4">
              <div class="text-caption text-uppercase text-medium-emphasis">
                {{ t('sheq.activities.high_risk') }}
              </div>
              <div class="text-h4 font-weight-bold">{{ highRisk }}</div>
            </VCard>
          </VCol>
          <VCol cols="12" md="4">
            <VCard elevation="0" border rounded="lg" class="pa-4">
              <div class="text-caption text-uppercase text-medium-emphasis">
                {{ t('sheq.activities.nobody_qualified') }}
              </div>
              <div class="text-h4 font-weight-bold" :class="unstaffed ? 'text-error' : ''">
                {{ unstaffed }}
              </div>
            </VCard>
          </VCol>
        </VRow>

        <VCard elevation="0" border rounded="lg">
          <VCardText class="d-flex flex-wrap align-center ga-3 pb-2">
            <VTextField
              v-model="search" density="compact" variant="outlined" hide-details
              prepend-inner-icon="mdi-magnify" :label="t('sheq.activities.search')"
              style="max-width: 320px" clearable
            />
            <VSwitch v-model="showRetired" density="compact" hide-details color="primary"
                     :label="t('sheq.activities.show_retired')" />
            <VSpacer />
            <VBtn v-if="canManage" variant="text" size="small" :loading="installing"
                  prepend-icon="mdi-download" @click="install">
              {{ t('sheq.activities.install_missing') }}
            </VBtn>
          </VCardText>
          <VDivider />

          <div v-if="!visible.length" class="text-center py-10 text-medium-emphasis">
            <div class="text-body-2">{{ t('sheq.activities.no_match') }}</div>
          </div>

          <VTable v-else density="comfortable">
            <thead>
              <tr>
                <th scope="col">{{ t('sheq.activities.activity') }}</th>
                <th scope="col">{{ t('sheq.activities.requires') }}</th>
                <th scope="col">{{ t('sheq.activities.who_can') }}</th>
                <th scope="col"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in visible" :key="row.uuid" :class="row.is_active ? '' : 'text-medium-emphasis'">
                <td class="py-3">
                  <div class="d-flex align-center flex-wrap ga-2">
                    <span class="text-body-2 font-weight-medium">{{ row.name }}</span>
                    <VChip v-if="row.is_high_risk" size="x-small" color="error" variant="tonal">
                      {{ t('sheq.activities.high_risk_chip') }}
                    </VChip>
                    <!-- What this activity demands, at a glance. A catalogue that hides its
                         requirements behind an edit dialog is a catalogue nobody audits. -->
                    <VChip v-for="kind in requirementKinds.filter(k => row[k.key])"
                           :key="kind.key" size="x-small" :color="kind.colour" variant="tonal">
                      {{ kind.label }}
                    </VChip>
                    <VChip v-if="row.required_ppe?.length" size="x-small" color="grey"
                           variant="tonal">
                      {{ row.required_ppe.length }} PPE
                    </VChip>
                    <VChip v-if="row.requires_permit" size="x-small" color="info" variant="tonal">
                      {{ t('sheq.activities.permit_chip') }}
                    </VChip>
                    <VChip v-if="!row.is_active" size="x-small" variant="tonal">
                      {{ t('sheq.activities.retired_chip') }}
                    </VChip>
                  </div>
                  <div class="text-caption text-medium-emphasis">{{ row.code }}</div>
                  <div v-if="row.description" class="text-caption text-medium-emphasis mt-1">
                    {{ row.description }}
                  </div>
                </td>
                <td style="min-width: 220px">
                  <span v-if="!row.required_credentials.length"
                        class="text-caption text-medium-emphasis">
                    {{ t('sheq.activities.requires_nothing') }}
                  </span>
                  <VChip v-for="code in row.required_credentials" v-else :key="code"
                         size="x-small" variant="tonal" class="me-1 mb-1">
                    {{ pretty(code) }}
                  </VChip>
                </td>
                <td style="width: 150px">
                  <template v-if="readinessOf(row.code)">
                    <VChip size="small" variant="tonal" :color="readinessColour(row.code)">
                      {{ t('sheq.activities.ready_of', {
                        ready: readinessOf(row.code)!.ready,
                        total: readinessOf(row.code)!.total,
                      }) }}
                    </VChip>
                  </template>
                  <span v-else class="text-caption text-medium-emphasis">—</span>
                </td>
                <td style="width: 90px" class="text-end">
                  <VBtn v-if="canManage" size="x-small" variant="text" @click="open(row)">
                    {{ t('sheq.activities.edit') }}
                  </VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </template>
    </template>

    <!-- ── The form ──────────────────────────────────────────────────────────────────────── -->
    <VDialog v-model="dialog" max-width="760" scrollable>
      <VCard>
        <VCardTitle class="text-body-1 font-weight-semibold">
          {{ editing ? t('sheq.activities.edit_title') : t('sheq.activities.add') }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VRow>
            <VCol cols="12" md="7">
              <VTextField
                :model-value="form.name" variant="outlined" density="comfortable"
                :label="t('sheq.activities.name')" :error-messages="form.errors.name"
                @update:model-value="onName"
              />
            </VCol>
            <VCol cols="12" md="5">
              <VTextField
                :model-value="form.code" variant="outlined" density="comfortable"
                :label="t('sheq.activities.code')" :error-messages="form.errors.code"
                :hint="t('sheq.activities.code_hint')" persistent-hint
                @update:model-value="onCode"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="form.description" rows="2" auto-grow variant="outlined"
                         density="comfortable" :label="t('sheq.activities.description')"
                         :error-messages="form.errors.description" />
            </VCol>
            <VCol cols="12">
              <!-- A picker over codes that have actually been filed, not a free-text box: two
                   spellings of one certificate is a requirement nobody can satisfy. Still open,
                   for a ticket no contractor has filed yet. -->
              <VCombobox
                v-model="form.required_credentials" :items="credentialCodes"
                item-title="title" item-value="value" :return-object="false"
                multiple chips closable-chips variant="outlined" density="comfortable"
                :label="t('sheq.activities.requires')"
                :hint="t('sheq.activities.requires_hint')" persistent-hint
                :error-messages="form.errors.required_credentials"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VSwitch v-model="form.is_high_risk" color="error" density="compact" hide-details
                       :label="t('sheq.activities.high_risk_label')" />
              <p class="text-caption text-medium-emphasis mb-0">
                {{ t('sheq.activities.high_risk_hint') }}
              </p>
            </VCol>
            <VCol cols="12" md="6">
              <VSwitch v-model="form.requires_permit" color="info" density="compact" hide-details
                       :label="t('sheq.activities.permit_label')" />
              <p class="text-caption text-medium-emphasis mb-0">
                {{ t('sheq.activities.permit_hint') }}
              </p>
            </VCol>

            <!-- ── What else this work takes (CON-26) ───────────────────────────────────── -->
            <VCol cols="12">
              <VDivider class="mb-3" />
              <div class="text-subtitle-2 font-weight-bold">What else this work takes</div>
              <!-- Said here rather than discovered later: ticking one of these records the
                   requirement and does not refuse anybody by itself. That is deliberate — a
                   requirement switched on at the same moment it starts turning crews away is
                   how a site finds out at 06:00 on a Monday. -->
              <p class="text-caption text-medium-emphasis mb-0">
                Switching one of these on records what is missing. Whether it refuses entry is a
                separate decision, on the
                <a :href="rulesHref" class="text-primary">blocking rules</a>.
              </p>
            </VCol>

            <VCol v-for="kind in requirementKinds" :key="kind.key" cols="12" md="6">
              <VSwitch v-model="form[kind.key]" :color="kind.colour" density="compact"
                       hide-details :label="kind.label" />
              <p class="text-caption text-medium-emphasis mb-0">{{ kind.note }}</p>
            </VCol>

            <VCol cols="12">
              <!-- Open text, unlike the credentials picker: PPE has no register of codes behind
                   it, and what the gate does with this list is print it for whoever is standing
                   there. It is never enforced — this system knows what the work requires and
                   cannot know what somebody is wearing. -->
              <VCombobox
                v-model="form.required_ppe" multiple chips closable-chips variant="outlined"
                density="comfortable" label="PPE required"
                hint="Shown to whoever scans a pass for this work. Never enforced — nobody can
                      check from here what a person is actually wearing."
                persistent-hint :error-messages="form.errors.required_ppe"
              />
            </VCol>
            <VCol v-if="editing" cols="12">
              <VSwitch v-model="form.is_active" color="primary" density="compact" hide-details
                       :label="t('sheq.activities.active_label')" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VBtn v-if="editing" variant="text" color="error" size="small"
                @click="retiring = editing">
            {{ t('sheq.activities.retire') }}
          </VBtn>
          <VSpacer />
          <VBtn variant="tonal" @click="dialog = false">{{ t('sheq.action.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="form.processing"
                :disabled="form.processing || !form.name.trim() || !form.code.trim()"
                @click="submit">
            {{ t('sheq.registers.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="retiring !== null" max-width="480"
             @update:model-value="retiring = null">
      <VCard v-if="retiring">
        <VCardTitle class="text-body-1 font-weight-semibold">
          {{ t('sheq.activities.retire_title', { name: retiring.name }) }}
        </VCardTitle>
        <VDivider />
        <VCardText class="text-body-2">{{ t('sheq.activities.retire_body') }}</VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="retiring = null">{{ t('sheq.action.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" @click="retire">
            {{ t('sheq.activities.retire') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
