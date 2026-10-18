<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import FileDrop from '@/Modules/Sheq/Partials/FileDrop.vue'

const { t } = useI18n()

type Item = {
  uuid: string; document_type: string; title: string | null; reference: string | null
  issuing_body: string | null; issued_on: string | null; expires_on: string | null
  days_left: number | null; expired: boolean; status: string; counts: boolean
}
type Section = {
  code: string; kind: string; name: string; describes: string; types: string[]
  source: string | null; expires: boolean; is_required: boolean; configured: boolean
  items: Item[]; count: number; expiring_soon: number; state: string; is_history?: boolean
}

const props = defineProps<{
  contractor: { uuid: string; name: string; trade: string | null; access_status: string }
  file: {
    profile: string
    sections: Section[]
    summary: {
      sections: number; required: number; satisfied: number; percent: number | null
      complete: boolean; missing: Array<{ code: string; name: string; state: string }>
    }
  }
  workflow: {
    state: string
    validity_months: number
    history: Array<{
      version: number; state: string; submitted_at: string | null; submitted_by: string | null
      reviewed_at: string | null; reviewed_by: string | null; decision_reason: string | null
      requested_changes: string[]; comments: string | null; valid_until: string | null
      transitions: Array<{
        from_state: string | null; to_state: string; occurred_at: string
        reason: string | null; comment: string | null; actor: string | null
      }>
    }>
  }
  verdict: {
    allowed: boolean; status: string; reasons: string[]; advisory: string[]
    safety_file_state: string | null
  }
  blockingRules: Record<string, boolean>
  allRules: string[]
  /** Rules that always refuse and are not a host's to switch off. */
  alwaysBlocks: string[]
  profiles: Array<{ code: string; name: string; describes: string; required: number }>
  documentTypes: Array<{ value: string; title: string }>
  sites: Array<{ id: number; name: string }>
  knownActivities: string[]
  can: { manage: boolean; configure: boolean }
}>()

const base = computed(() => `/sheq/contractors/${props.contractor.uuid}`)

/*
  An empty divider is the finding, so the gaps are stated before the file rather than left to be
  noticed while scrolling. This is the one thing a pile of uploaded certificates cannot tell you.
*/
const missing = computed(() => props.file.summary.missing)

const sectionColour = (state: string) => ({
  SATISFIED: 'success', EXPIRED: 'error', MISSING: 'warning', NOT_ACCEPTED: 'info',
}[state] ?? 'grey')

/* ── The file's own state (CON-7) ──────────────────────────────────────────────────────────
   The buttons offered are the legal moves from where the file is. Offering every action and
   letting the server refuse five of them teaches people to ignore the buttons.                */
const MOVES: Record<string, string[]> = {
  DRAFT: ['submit'],
  SUBMITTED: ['review', 'request_changes', 'approve'],
  UNDER_REVIEW: ['request_changes', 'approve'],
  CHANGES_REQUIRED: ['submit'],
  APPROVED: ['suspend'],
  SUSPENDED: ['reinstate'],
  EXPIRED: ['submit'],
  BLOCKED: ['submit'],
}

const available = computed(() => props.can.manage ? (MOVES[props.workflow.state] ?? []) : [])

/** The version in play: history is newest first, so it is the first entry. */
const current = computed(() => props.workflow.history[0] ?? null)

const stateColour = (state: string) => ({
  APPROVED: 'success', SUBMITTED: 'info', UNDER_REVIEW: 'info',
  CHANGES_REQUIRED: 'warning', SUSPENDED: 'error', EXPIRED: 'error', BLOCKED: 'error',
}[state] ?? 'grey')

/* Each move that needs something said carries its own dialog: a reason, a list of changes, or a
   validity date. A single "are you sure" would lose all three.                                */
const moving = ref<string | null>(null)
const moveForm = useForm<{
  action: string; reason: string; comment: string; valid_until: string; changes: string[]
}>({ action: '', reason: '', comment: '', valid_until: '', changes: [] })

const needsReason = (action: string) => action === 'request_changes' || action === 'suspend'

function startMove(action: string) {
  moveForm.reset()
  moveForm.clearErrors()
  moveForm.action = action

  // Submit, review and reinstate say nothing extra, so they go straight through rather than
  // through a dialog that asks for nothing.
  if (action === 'review' || action === 'reinstate') {
    moveForm.post(`${base.value}/safety-file/transition`, { preserveScroll: true })

    return
  }

  moving.value = action
}

function submitMove() {
  moveForm.post(`${base.value}/safety-file/transition`, {
    preserveScroll: true,
    onSuccess: () => { moving.value = null },
  })
}

const newChange = ref('')

function addChange() {
  const text = newChange.value.trim()
  if (!text) return
  moveForm.changes = [...moveForm.changes, text]
  newChange.value = ''
}

const showHistory = ref(false)

/* ── What refuses entry (CON-10) ───────────────────────────────────────────────────────────
   A host's decision, not the system's: a mine blocks on an expired medical and an office park
   records it and lets the consultant in.                                                      */
const showRules = ref(false)
const rulesForm = useForm({ rules: { ...props.blockingRules } })

const statusColour = (status: string) => ({
  COMPLIANT: 'success', EXPIRING: 'warning', PENDING_APPROVAL: 'info',
  NON_COMPLIANT: 'warning', BLOCKED: 'error', SUSPENDED: 'error',
}[status] ?? 'grey')

/* ── Accepting or rejecting what has been supplied (CON-8) ───────────────────────────────── */
const deciding = ref<Item | null>(null)
const decisionForm = useForm({ decision: 'ACCEPTED', reason: '' })

function startDecision(item: Item, decision: string) {
  deciding.value = item
  decisionForm.reset()
  decisionForm.clearErrors()
  decisionForm.decision = decision

  // Accepting says nothing extra; rejecting has to say what to fix.
  if (decision === 'ACCEPTED') {
    decisionForm.post(`${base.value}/documents/${item.uuid}/decide`, {
      preserveScroll: true,
      onSuccess: () => { deciding.value = null },
    })
  }
}

function submitDecision() {
  if (!deciding.value) return

  decisionForm.post(`${base.value}/documents/${deciding.value.uuid}/decide`, {
    preserveScroll: true,
    onSuccess: () => { deciding.value = null },
  })
}

/* ── Filing a document into a section ─────────────────────────────────────────────────────── */
const filing = ref<Section | null>(null)
const docForm = useForm<{
  document_type: string; reference: string; issuing_body: string
  issued_on: string; expires_on: string; files: File[]
  warning_days: number | null; site_id: number | null; activities: string[]; status: string
}>({
  document_type: '', reference: '', issuing_body: '', issued_on: '', expires_on: '', files: [],
  warning_days: null, site_id: null, activities: [], status: 'ACCEPTED',
})

function openFiling(section: Section) {
  filing.value = section
  docForm.reset()
  docForm.clearErrors()
  // The section's own type, so nobody has to know which code satisfies which divider.
  docForm.document_type = section.types[0] ?? ''
}

function submitFiling() {
  docForm.post(`${base.value}/documents`, {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => { filing.value = null },
  })
}

/* ── What this host demands ───────────────────────────────────────────────────────────────── */
const choosing = ref(false)
const profileForm = useForm({ profile: props.file.profile })

const sectionForm = useForm<{ is_required: boolean }>({ is_required: false })

function toggleRequired(section: Section) {
  sectionForm.is_required = !section.is_required
  sectionForm.post(`${base.value}/safety-file/sections/${section.code}`, { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-1 d-flex align-center flex-wrap ga-3">
      <h1 class="text-h5 font-weight-bold mb-0">{{ t('sheq.safety_file.title') }}</h1>
      <VChip size="small" variant="tonal">{{ contractor.name }}</VChip>
      <VChip v-if="file.summary.percent !== null" size="small" variant="flat"
             :color="file.summary.complete ? 'success' : 'warning'">
        {{ file.summary.satisfied }}/{{ file.summary.required }} · {{ file.summary.percent }}%
      </VChip>
    </div>
    <!--
      Where else this contractor appears, pre-filtered to them.

      Each of these registers is reachable from the hub, which is the right place for somebody
      who has not yet picked a contractor and the wrong place for somebody looking at one — they
      would arrive at a page defaulted to whichever firm sorts first and have to find this one
      again. The links are the difference between five pages and five pages that are used.
    -->
    <div class="d-flex align-center flex-wrap ga-1 mb-3">
      <VBtn :href="`/sheq/compliance?contractor=${contractor.uuid}`" size="small" variant="text"
            prepend-icon="mdi-shield-search">
        Compliance check
      </VBtn>
      <VBtn :href="`/sheq/contractor-history?contractor=${contractor.uuid}`" size="small"
            variant="text" prepend-icon="mdi-history">
        History
      </VBtn>
      <VBtn :href="`/sheq/document-versions?contractor=${contractor.uuid}`" size="small"
            variant="text" prepend-icon="mdi-file-document-multiple-outline">
        Document versions
      </VBtn>
      <VBtn :href="`/sheq/contractor-performance?contractor=${contractor.uuid}`" size="small"
            variant="text" prepend-icon="mdi-chart-timeline-variant">
        Performance
      </VBtn>
      <VBtn :href="`/sheq/subcontractors?contractor=${contractor.uuid}`" size="small"
            variant="text" prepend-icon="mdi-sitemap-outline">
        Subcontractors
      </VBtn>
    </div>

    <div class="d-flex align-center justify-space-between flex-wrap ga-3 mb-5">
      <p class="text-body-2 text-medium-emphasis mb-0">
        {{ t('sheq.safety_file.subtitle') }}
        <a :href="base" class="text-primary">{{ t('sheq.safety_file.back') }}</a>
      </p>
      <!--
        The whole file as one archive. Downloading twenty-five certificates one at a time is not an
        alternative to the folder; it is the same problem with extra steps.
      -->
      <VBtn variant="tonal" prepend-icon="mdi-folder-zip-outline"
            :href="`${base}/safety-file/export`">
        {{ t('sheq.safety_file.export') }}
      </VBtn>
    </div>

    <!--
      May these people work, and if not exactly why (CON-9, CON-10).

      First on the page because it is the only question a gatehouse is asking, and it is computed
      from everything — the documents, whether anybody accepted the file, and the named workers —
      rather than from the paperwork alone.
    -->
    <VCard elevation="0" border rounded="lg" class="mb-4">
      <VCardText>
        <div class="d-flex align-center flex-wrap ga-3">
          <VChip size="large" variant="flat" :color="statusColour(verdict.status)">
            {{ t('sheq.safety_file.status.' + verdict.status) }}
          </VChip>
          <div class="flex-grow-1 min-width-0">
            <div class="text-body-2">
              {{ verdict.allowed ? t('sheq.safety_file.may_work')
                                 : t('sheq.safety_file.may_not_work') }}
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('sheq.safety_file.status_hint.' + verdict.status) }}
            </div>
          </div>
          <VBtn v-if="can.configure" size="small" variant="text" prepend-icon="mdi-tune"
                @click="showRules = !showRules">
            {{ t('sheq.safety_file.rules') }}
          </VBtn>
        </div>

        <!-- The exact reason, which is what ends an argument at a boom. -->
        <VAlert v-if="verdict.reasons.length" type="error" variant="tonal" class="mt-3">
          <div class="font-weight-semibold mb-1">{{ t('sheq.safety_file.refused_because') }}</div>
          <ul class="ps-4 mb-0">
            <li v-for="(reason, i) in verdict.reasons" :key="i" class="text-body-2">{{ reason }}</li>
          </ul>
        </VAlert>

        <!--
          Findings the host chose not to block on. Shown, not hidden: switching a rule off must not
          mean "stop checking", or the information is lost along with the refusal.
        -->
        <VAlert v-if="verdict.advisory.length" type="warning" variant="tonal" class="mt-3">
          <div class="font-weight-semibold mb-1">{{ t('sheq.safety_file.not_blocking') }}</div>
          <ul class="ps-4 mb-0">
            <li v-for="(note, i) in verdict.advisory" :key="i" class="text-body-2">{{ note }}</li>
          </ul>
        </VAlert>
      </VCardText>

      <VExpandTransition>
        <div v-if="showRules">
          <VDivider />
          <VCardText>
            <p class="text-body-2 text-medium-emphasis">{{ t('sheq.safety_file.rules_hint') }}</p>
            <VRow dense>
              <VCol v-for="rule in allRules" :key="rule" cols="12" md="6">
                <VSwitch
                  v-model="rulesForm.rules[rule]" color="primary" density="compact" hide-details
                  :label="t('sheq.safety_file.rule.' + rule)"
                />
              </VCol>
            </VRow>
            <!-- A rule that is not a host's to change, said rather than hidden: its absence
                 from the switches above is the thing that needs explaining. -->
            <VAlert v-for="rule in alwaysBlocks" :key="rule" type="info" variant="tonal"
                    density="compact" class="mt-3" icon="mdi-lock-outline">
              <span class="font-weight-medium">{{ t('sheq.safety_file.rule.' + rule) }}</span>
              — {{ t('sheq.safety_file.always_blocks') }}
            </VAlert>

            <div class="d-flex justify-end ga-2 mt-3">
              <VBtn variant="tonal" size="small" @click="showRules = false">
                {{ t('sheq.action.cancel') }}
              </VBtn>
              <VBtn color="primary" variant="flat" size="small" :loading="rulesForm.processing"
                    @click="rulesForm.post(`${base}/blocking-rules`, {
                      preserveScroll: true, onSuccess: () => { showRules = false } })">
                {{ t('sheq.safety_file.save_rules') }}
              </VBtn>
            </div>
          </VCardText>
        </div>
      </VExpandTransition>
    </VCard>

    <!--
      The file's own state (CON-7).

      Shown beside the completeness figure rather than instead of it: one says whether the
      documents are there, the other whether somebody accepted them, and a file can be complete
      and refused.
    -->
    <VCard elevation="0" border rounded="lg" class="mb-4">
      <VCardText class="d-flex align-center flex-wrap ga-3">
        <div class="flex-grow-1 min-width-0">
          <div class="text-caption text-medium-emphasis">{{ t('sheq.safety_file.review_state') }}</div>
          <div class="d-flex align-center flex-wrap ga-2 mt-1">
            <VChip size="small" variant="flat" :color="stateColour(workflow.state)">
              {{ t('sheq.safety_file.state_name.' + workflow.state) }}
            </VChip>
            <span v-if="current?.valid_until && workflow.state === 'APPROVED'"
                  class="text-caption text-medium-emphasis">
              {{ t('sheq.safety_file.approved_until', { date: current.valid_until }) }}
            </span>
            <span v-if="current" class="text-caption text-medium-emphasis">
              {{ t('sheq.safety_file.version', { version: current.version }) }}
            </span>
          </div>
          <div class="text-caption text-medium-emphasis mt-1">
            {{ t('sheq.safety_file.state_hint.' + workflow.state) }}
          </div>
          <!-- What the reviewer asked for, where they asked for something. -->
          <div v-if="current?.decision_reason" class="text-body-2 mt-2">
            {{ current.decision_reason }}
          </div>
          <ul v-if="current?.requested_changes?.length" class="text-body-2 ps-4 mt-1 mb-0">
            <li v-for="(change, i) in current.requested_changes" :key="i">{{ change }}</li>
          </ul>
        </div>

        <div class="d-flex align-center flex-wrap ga-2">
          <VBtn v-if="workflow.history.length" size="small" variant="text"
                prepend-icon="mdi-history" @click="showHistory = !showHistory">
            {{ t('sheq.safety_file.history') }}
          </VBtn>
          <!-- Only the legal moves. Offering every action and letting the server refuse five of
               them teaches people to ignore the buttons. -->
          <VBtn v-for="action in available" :key="action" size="small"
                :color="action === 'approve' ? 'success'
                       : (action === 'suspend' || action === 'request_changes') ? 'warning' : 'primary'"
                :variant="action === 'submit' || action === 'approve' ? 'flat' : 'tonal'"
                :loading="moveForm.processing && moveForm.action === action"
                @click="startMove(action)">
            {{ t('sheq.safety_file.action.' + action) }}
          </VBtn>
        </div>
      </VCardText>

      <VExpandTransition>
        <div v-if="showHistory">
          <VDivider />
          <VCardText>
            <div v-for="version in workflow.history" :key="version.version" class="mb-4">
              <div class="d-flex align-center flex-wrap ga-2">
                <span class="text-body-2 font-weight-medium">
                  {{ t('sheq.safety_file.version', { version: version.version }) }}
                </span>
                <VChip size="x-small" variant="tonal" :color="stateColour(version.state)">
                  {{ t('sheq.safety_file.state_name.' + version.state) }}
                </VChip>
              </div>
              <div v-if="version.decision_reason" class="text-caption mt-1">
                {{ version.decision_reason }}
              </div>
              <!-- Every move, with who made it and when: the approval history CON-7 asks for. -->
              <VTable density="compact" class="mt-2">
                <tbody>
                  <tr v-for="(move, i) in version.transitions" :key="i">
                    <td class="text-caption" style="width: 170px">{{ move.occurred_at }}</td>
                    <td class="text-caption">
                      {{ move.from_state
                          ? t('sheq.safety_file.state_name.' + move.from_state) + ' → ' : '' }}
                      {{ t('sheq.safety_file.state_name.' + move.to_state) }}
                    </td>
                    <td class="text-caption text-medium-emphasis">{{ move.actor ?? '—' }}</td>
                    <td class="text-caption text-medium-emphasis">
                      {{ move.reason || move.comment || '' }}
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </div>
          </VCardText>
        </div>
      </VExpandTransition>
    </VCard>

    <!-- Nothing demanded is not a complete file; it is an unconfigured one. -->
    <VAlert v-if="file.summary.percent === null" type="info" variant="tonal" class="mb-4">
      {{ t('sheq.safety_file.nothing_required') }}
    </VAlert>
    <VAlert v-else-if="missing.length" type="warning" variant="tonal" class="mb-4">
      <div class="font-weight-semibold mb-1">
        {{ t('sheq.safety_file.incomplete', { count: missing.length }) }}
      </div>
      <ul class="ps-4 mb-0">
        <li v-for="gap in missing" :key="gap.code" class="text-body-2">
          {{ gap.name }} — {{ t('sheq.safety_file.state.' + gap.state) }}
        </li>
      </ul>
    </VAlert>
    <VAlert v-else type="success" variant="tonal" class="mb-4">
      {{ t('sheq.safety_file.complete') }}
    </VAlert>

    <!-- Which industry's starting point is in force, and the offer to change it. -->
    <VCard v-if="can.configure" elevation="0" border rounded="lg" class="mb-4">
      <VCardText class="d-flex align-center flex-wrap ga-3">
        <div class="flex-grow-1 min-width-0">
          <div class="text-caption text-medium-emphasis">{{ t('sheq.safety_file.profile') }}</div>
          <div class="text-body-2 font-weight-medium">
            {{ profiles.find(p => p.code === file.profile)?.name ?? file.profile }}
          </div>
          <div class="text-caption text-medium-emphasis">
            {{ profiles.find(p => p.code === file.profile)?.describes }}
          </div>
        </div>
        <VBtn size="small" variant="tonal" @click="choosing = !choosing">
          {{ t('sheq.safety_file.change_profile') }}
        </VBtn>
      </VCardText>
      <VExpandTransition>
        <div v-if="choosing">
          <VDivider />
          <VCardText>
            <VRadioGroup v-model="profileForm.profile" hide-details density="compact">
              <VRadio v-for="p in profiles" :key="p.code" :value="p.code">
                <template #label>
                  <div>
                    <div class="text-body-2 font-weight-medium">
                      {{ p.name }}
                      <span class="text-caption text-medium-emphasis">
                        · {{ t('sheq.safety_file.demands', { count: p.required }) }}
                      </span>
                    </div>
                    <div class="text-caption text-medium-emphasis">{{ p.describes }}</div>
                  </div>
                </template>
              </VRadio>
            </VRadioGroup>
            <div class="d-flex justify-end ga-2 mt-3">
              <VBtn variant="tonal" size="small" @click="choosing = false">
                {{ t('sheq.action.cancel') }}
              </VBtn>
              <VBtn color="primary" variant="flat" size="small" :loading="profileForm.processing"
                    @click="profileForm.post(`${base}/safety-file/profile`, {
                      preserveScroll: true, onSuccess: () => { choosing = false } })">
                {{ t('sheq.safety_file.apply') }}
              </VBtn>
            </div>
          </VCardText>
        </div>
      </VExpandTransition>
    </VCard>

    <!-- The dividers. -->
    <VCard v-for="section in file.sections" :key="section.code"
           elevation="0" border rounded="lg" class="mb-3">
      <VCardText>
        <div class="d-flex align-start flex-wrap ga-3">
          <div class="flex-grow-1 min-width-0">
            <div class="d-flex align-center flex-wrap ga-2">
              <span class="text-body-1 font-weight-semibold">{{ section.name }}</span>
              <!-- Colour and words: a chip that only differs by hue says nothing to a reader
                   who cannot tell them apart. -->
              <VChip size="x-small" variant="tonal" :color="sectionColour(section.state)">
                {{ t('sheq.safety_file.state.' + section.state) }}
              </VChip>
              <VChip v-if="section.is_required" size="x-small" variant="tonal" color="primary">
                {{ t('sheq.safety_file.required') }}
              </VChip>
              <VChip v-if="section.expiring_soon" size="x-small" variant="tonal" color="warning">
                {{ t('sheq.safety_file.expiring_soon', { count: section.expiring_soon }) }}
              </VChip>
            </div>
            <div class="text-caption text-medium-emphasis mt-1">{{ section.describes }}</div>
          </div>

          <div class="d-flex align-center ga-2">
            <VBtn v-if="can.configure" size="x-small" variant="text"
                  :disabled="sectionForm.processing" @click="toggleRequired(section)">
              {{ section.is_required ? t('sheq.safety_file.stop_demanding')
                                     : t('sheq.safety_file.demand') }}
            </VBtn>
            <VBtn v-if="can.manage && section.kind === 'DOCUMENT'" size="small" variant="tonal"
                  prepend-icon="mdi-paperclip" @click="openFiling(section)">
              {{ t('sheq.safety_file.file_here') }}
            </VBtn>
          </div>
        </div>

        <!-- A record section is assembled from what this system already holds, so it is counted
             rather than filed into. Saying which is which stops somebody uploading a list of
             workers they have already registered. -->
        <div v-if="section.kind === 'RECORD'" class="mt-3 text-body-2">
          <template v-if="section.is_history">
            {{ t('sheq.safety_file.history_count', { count: section.count }) }}
          </template>
          <template v-else>
            {{ t('sheq.safety_file.record_count', { count: section.count }) }}
          </template>
        </div>

        <VTable v-else-if="section.items.length" density="compact" class="mt-3">
          <tbody>
            <tr v-for="item in section.items" :key="item.uuid">
              <td class="text-body-2">
                {{ item.title || item.reference || t('sheq.safety_file.filed') }}
                <div v-if="item.issuing_body" class="text-caption text-medium-emphasis">
                  {{ item.issuing_body }}
                </div>
              </td>
              <td style="width: 190px">
                <template v-if="item.expires_on">
                  <VChip size="x-small" variant="tonal"
                         :color="item.expired ? 'error' : (item.days_left !== null && item.days_left <= 30 ? 'warning' : 'success')">
                    {{ item.expired ? t('sheq.safety_file.expired_on', { date: item.expires_on })
                                    : t('sheq.safety_file.until', { date: item.expires_on }) }}
                  </VChip>
                </template>
                <span v-else class="text-caption text-medium-emphasis">
                  {{ t('sheq.safety_file.no_expiry') }}
                </span>
                <!-- Supplied and unchecked satisfies nothing, so it says so here rather than
                     looking like any other filed certificate. -->
                <div v-if="item.status !== 'ACCEPTED'" class="mt-1">
                  <VChip size="x-small" variant="tonal"
                         :color="item.status === 'REJECTED' ? 'error' : 'info'">
                    {{ t('sheq.safety_file.doc_status.' + item.status) }}
                  </VChip>
                </div>
              </td>
              <td style="width: 150px" class="text-end">
                <template v-if="can.manage && item.status === 'SUPPLIED'">
                  <VBtn size="x-small" variant="text" color="success"
                        @click="startDecision(item, 'ACCEPTED')">
                    {{ t('sheq.safety_file.accept') }}
                  </VBtn>
                  <VBtn size="x-small" variant="text" color="error"
                        @click="startDecision(item, 'REJECTED')">
                    {{ t('sheq.safety_file.reject') }}
                  </VBtn>
                </template>
                <VBtn v-else-if="can.manage && item.status === 'ACCEPTED'" size="x-small"
                      variant="text" color="error" @click="startDecision(item, 'REJECTED')">
                  {{ t('sheq.safety_file.reject') }}
                </VBtn>
              </td>
            </tr>
          </tbody>
        </VTable>

        <div v-else class="mt-3 text-body-2 text-medium-emphasis">
          {{ t('sheq.safety_file.nothing_filed') }}
        </div>
      </VCardText>
    </VCard>

    <!-- Each move that needs something said gets its own dialog. A single "are you sure" would
         lose the reason, the list of changes and the validity date alike. -->
    <VDialog :model-value="moving !== null" max-width="620" @update:model-value="moving = null">
      <VCard v-if="moving">
        <VCardTitle class="text-body-1 font-weight-semibold">
          {{ t('sheq.safety_file.action.' + moving) }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-4">
            {{ t('sheq.safety_file.move_hint.' + moving) }}
          </p>

          <VTextarea v-if="needsReason(moving)" v-model="moveForm.reason" rows="3" auto-grow
                     variant="outlined" density="comfortable"
                     :label="t('sheq.safety_file.reason')"
                     :hint="t('sheq.safety_file.reason_hint')" persistent-hint
                     :error-messages="moveForm.errors.reason" />

          <!-- The list is separate from the reason: a reason is for the record, these are what
               the contractor works through. -->
          <template v-if="moving === 'request_changes'">
            <div class="d-flex align-end ga-2 mt-4">
              <VTextField v-model="newChange" variant="outlined" density="comfortable"
                          :label="t('sheq.safety_file.add_change')"
                          hide-details @keyup.enter="addChange" />
              <VBtn variant="tonal" @click="addChange">{{ t('sheq.safety_file.add') }}</VBtn>
            </div>
            <VList v-if="moveForm.changes.length" density="compact" class="mt-2">
              <VListItem v-for="(change, i) in moveForm.changes" :key="i">
                <VListItemTitle class="text-body-2">{{ change }}</VListItemTitle>
                <template #append>
                  <VBtn icon="mdi-close" size="x-small" variant="text"
                        @click="moveForm.changes = moveForm.changes.filter((_, n) => n !== i)" />
                </template>
              </VListItem>
            </VList>
          </template>

          <VTextField v-if="moving === 'approve'" v-model="moveForm.valid_until" type="date"
                      variant="outlined" density="comfortable" class="mt-4"
                      :label="t('sheq.safety_file.valid_until')"
                      :hint="t('sheq.safety_file.valid_until_hint', { months: workflow.validity_months })"
                      persistent-hint :error-messages="moveForm.errors.valid_until" />

          <VTextarea v-if="moving === 'submit' || moving === 'approve'" v-model="moveForm.comment"
                     rows="2" auto-grow variant="outlined" density="comfortable" class="mt-4"
                     :label="t('sheq.safety_file.comment')"
                     :error-messages="moveForm.errors.comment" />
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="moving = null">{{ t('sheq.action.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="moveForm.processing"
                :disabled="moveForm.processing" @click="submitMove">
            {{ t('sheq.safety_file.action.' + moving) }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="deciding !== null && decisionForm.decision === 'REJECTED'"
             max-width="560" @update:model-value="deciding = null">
      <VCard v-if="deciding">
        <VCardTitle class="text-body-1 font-weight-semibold">
          {{ t('sheq.safety_file.reject') }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-4">
            {{ t('sheq.safety_file.reject_hint') }}
          </p>
          <VTextarea v-model="decisionForm.reason" rows="3" auto-grow variant="outlined"
                     density="comfortable" :label="t('sheq.safety_file.reason')"
                     :error-messages="decisionForm.errors.reason" />
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="deciding = null">{{ t('sheq.action.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" :loading="decisionForm.processing"
                :disabled="decisionForm.processing" @click="submitDecision">
            {{ t('sheq.safety_file.reject') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="filing !== null" max-width="640" @update:model-value="filing = null">
      <VCard v-if="filing">
        <VCardTitle class="text-body-1 font-weight-semibold">
          {{ t('sheq.safety_file.file_here') }} · {{ filing.name }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VSelect
                v-model="docForm.document_type" :items="documentTypes"
                item-title="title" item-value="value"
                :label="t('sheq.safety_file.document_type')"
                variant="outlined" density="comfortable"
                :error-messages="docForm.errors.document_type"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="docForm.reference" variant="outlined" density="comfortable"
                          :label="t('sheq.safety_file.reference')"
                          :error-messages="docForm.errors.reference" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="docForm.issuing_body" variant="outlined" density="comfortable"
                          :label="t('sheq.safety_file.issuing_body')"
                          :error-messages="docForm.errors.issuing_body" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="docForm.issued_on" type="date" variant="outlined"
                          density="comfortable" :label="t('sheq.safety_file.issued_on')"
                          :error-messages="docForm.errors.issued_on" />
            </VCol>
            <VCol cols="6">
              <!-- Only asked for where this kind of paperwork lapses. Demanding an expiry for a
                   method statement is how a practitioner learns to type 2099. -->
              <VTextField v-if="filing.expires" v-model="docForm.expires_on" type="date"
                          variant="outlined" density="comfortable"
                          :label="t('sheq.safety_file.expires_on')"
                          :error-messages="docForm.errors.expires_on" />
            </VCol>

            <!-- CON-8. Only where the document lapses: a warning period on something that never
                 expires is a field with nothing to do. -->
            <VCol v-if="filing.expires" cols="12" md="6">
              <VTextField v-model.number="docForm.warning_days" type="number" min="1" max="365"
                          variant="outlined" density="comfortable"
                          :label="t('sheq.safety_file.warning_days')"
                          :hint="t('sheq.safety_file.warning_days_hint')" persistent-hint
                          :error-messages="docForm.errors.warning_days" />
            </VCol>
            <VCol cols="12" :md="filing.expires ? 6 : 12">
              <VSelect v-model="docForm.site_id" :items="sites" item-title="name" item-value="id"
                       clearable variant="outlined" density="comfortable"
                       :label="t('sheq.safety_file.site')"
                       :hint="t('sheq.safety_file.site_hint')" persistent-hint
                       :error-messages="docForm.errors.site_id" />
            </VCol>
            <VCol cols="12">
              <!-- A combobox, not a vocabulary: there is no activity catalogue in the module, and
                   inventing one would be a second list to keep in step with how work is described.
                   Offering what has been typed before converges without forcing it. -->
              <VCombobox v-model="docForm.activities" :items="knownActivities" multiple chips
                         closable-chips variant="outlined" density="comfortable"
                         :label="t('sheq.safety_file.activities')"
                         :hint="t('sheq.safety_file.activities_hint')" persistent-hint
                         :error-messages="docForm.errors.activities" />
            </VCol>
            <VCol cols="12">
              <VSelect
                v-model="docForm.status"
                :items="[
                  { title: t('sheq.safety_file.doc_status.ACCEPTED'), value: 'ACCEPTED' },
                  { title: t('sheq.safety_file.doc_status.SUPPLIED'), value: 'SUPPLIED' },
                ]"
                variant="outlined" density="comfortable"
                :label="t('sheq.safety_file.filed_as')"
                :hint="t('sheq.safety_file.filed_as_hint')" persistent-hint
                :error-messages="docForm.errors.status"
              />
            </VCol>
            <VCol cols="12">
              <FileDrop
                :model-value="docForm.files" multiple
                :hint="t('sheq.safety_file.drop_hint')"
                :error-messages="docForm.errors.files"
                @update:model-value="(f: File[]) => docForm.files = f"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="filing = null">{{ t('sheq.action.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="docForm.processing"
                :disabled="docForm.processing" @click="submitFiling">
            {{ t('sheq.safety_file.file_it') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
