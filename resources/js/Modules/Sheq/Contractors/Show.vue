<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Blocking = { type: string; reason: string; detail: string; expired_on?: string }
type Expiring = { type: string; expires_on: string; days_left: number; detail: string }
type Doc = {
  uuid: string; document_type: string; title: string | null; reference: string | null
  issuing_body: string | null; issued_on: string | null; expires_on: string | null
  is_mandatory: boolean
}

const props = defineProps<{
  contractor: Record<string, any>
  /** Computed on view against the host's current demands, never stored at registration. */
  compliance: {
    status: string; blocking: Blocking[]; expiring: Expiring[]; documents: Doc[]
    /** The override in force, with its window, scope and conditions — or null. */
    override?: {
      uuid: string; reason: string; conditions: string | null
      starts_at: string; ends_at: string; approved_by_name: string | null
      hours_left: number
    } | null
  }
  mayWork: { allowed: boolean; status: string; reasons: string[] }
  supplierLink: {
    available: boolean; linked: boolean; supplier_name?: string | null
    assessment?: Record<string, any> | null
  }
  requiredTypes: string[]
  /** The one vocabulary the compliance check also reads. */
  documentTypes: { value: string; title: string }[]
  trades: string[]
  /**
   * Every site, with this contractor's standing at each (CON-14).
   *
   * Including the undecided ones, which is the point of showing it: a list of only the sites that
   * have a decision answers "where are they approved" and drops "and where has nobody looked".
   */
  siteApprovals: {
    site_id: number; site_uuid: string; site: string; code: string | null
    status: 'APPROVED' | 'BLOCKED' | 'EXPIRED' | 'UNDECIDED'
    reason: string | null; conditions: string | null
    valid_until: string | null; decided_at: string | null
  }[]
  /** Where a worker can be placed — both printed on the access card. */
  sites: { value: number; title: string }[]
  /** From Project Management, the system of record. Empty where that module is not licensed. */
  projects: { value: number; title: string }[]
  workers: Record<string, any>[]
  scores: Record<string, any>[]
  accessEvents: Record<string, any>[]
  can: { manage: boolean }
}>()

const base = computed(() => `/sheq/contractors/${props.contractor.uuid}`)
const status = computed(() => String(props.compliance?.status ?? 'BLOCKED'))
/*
 * The override in force, with its terms (CON-17).
 *
 * Read from the compliance file rather than from a flag on the contractor, because an override
 * now has a window, a scope, an approver and conditions — and a banner that said only "there is
 * one" left everybody to guess at all four.
 */
const override = computed(() => props.compliance?.override ?? null)
const overridden = computed(() => override.value !== null)

/*
  All six calculated statuses, with a colour and a translated label.

  The chip rendered the raw value, so a contractor read "PENDING_APPROVAL" in capitals in every
  language, and three of the six had no colour and came out default — including NON_COMPLIANT,
  where a neutral chip reads as "nothing to see".
*/
const statusColour = computed(() => ({
  COMPLIANT: 'success', EXPIRING: 'warning', NON_COMPLIANT: 'warning',
  BLOCKED: 'error', SUSPENDED: 'error', PENDING_APPROVAL: 'info',
}[status.value] ?? 'default'))

const statusLabel = computed(() => t('sheq.safety_file.status.' + status.value))

/** Which required types are still missing, so the page can say what to upload. */
const missingTypes = computed(() =>
  (props.compliance?.blocking ?? []).filter(b => b.reason === 'MISSING').map(b => b.type))

const prettyType = (type: string) =>
  type.replace(/_/g, ' ').toLowerCase().replace(/^./, c => c.toUpperCase())

const latestScore = computed(() => props.scores?.[0] ?? null)

// ── Documents ──────────────────────────────────────────────────────────────
const showDoc = ref(false)
const docForm = useForm({
  document_type: '', title: '', reference: '', issuing_body: '',
  issued_on: '', expires_on: '', is_mandatory: true,
  files: [] as File[],
})

/**
 * Drag and drop, because a safety file arrives as a folder of certificates.
 *
 * A zip is unpacked server-side into one document per file inside it, so the usual case — one
 * attachment holding everything — does not have to be taken apart by hand first.
 */
const dragging = ref(false)

function onDrop(event: DragEvent) {
  dragging.value = false
  const dropped = Array.from(event.dataTransfer?.files ?? [])
  if (dropped.length) docForm.files = [...docForm.files, ...dropped]
}

function removeFile(index: number) {
  docForm.files = docForm.files.filter((_, i) => i !== index)
}

const prettySize = (bytes: number | null) =>
  bytes == null ? '' : bytes < 1024 ? `${bytes} B`
    : bytes < 1048576 ? `${Math.round(bytes / 1024)} KB`
    : `${(bytes / 1048576).toFixed(1)} MB`
function openDoc(type?: string) {
  docForm.reset()
  docForm.files = []
  docForm.document_type = type ?? (props.requiredTypes[0] ?? '')
  showDoc.value = true
}
function submitDoc() {
  // forceFormData: a file cannot travel as JSON, and Inertia only switches automatically when it
  // spots one — an empty list on a re-submit would silently send the wrong encoding.
  docForm.post(`${base.value}/documents`, {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => { showDoc.value = false; docForm.reset(); docForm.files = [] },
  })
}

const showWorker = ref(false)
const workerForm = useForm({
  name: '', trade: '', inducted_on: '', induction_expires: '',
  // Both printed on the access card. Null, not '': an empty string is not a valid site id and
  // would fail validation on every worker whose site simply follows the contractor's.
  site_id: null as number | null,
  project_id: null as number | null,
  photo: null as File | null,
})

/** What the gatehouse sees before the person reaches the turnstile. */
const photoPreview = computed(() =>
  workerForm.photo ? URL.createObjectURL(workerForm.photo) : null)
function submitWorker() {
  workerForm.post(`${base.value}/workers`, {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => { showWorker.value = false; workerForm.reset(); workerForm.photo = null },
  })
}

/* ── Per-site approval (CON-14) ────────────────────────────────────────────────────────────────
   Approval at one site is never approval at the next, so the decision is always made against a
   named site. An explicit block refuses entry there whatever the company-wide file says. */
const siteDialog = ref(false)
const siteRow = ref<Record<string, any> | null>(null)

const siteForm = useForm({
  site_id: null as number | null,
  status: 'APPROVED',
  reason: '',
  conditions: '',
  valid_until: '',
})

const siteColour = (status: string) => ({
  APPROVED: 'success', BLOCKED: 'error', EXPIRED: 'warning',
}[status] ?? 'default')

const siteCounts = computed(() => {
  const counts = { APPROVED: 0, BLOCKED: 0, EXPIRED: 0, UNDECIDED: 0 } as Record<string, number>
  for (const row of props.siteApprovals ?? []) counts[row.status] = (counts[row.status] ?? 0) + 1
  return counts
})

function openSite(row: Record<string, any>) {
  siteRow.value = row
  siteForm.reset()
  siteForm.clearErrors()
  siteForm.site_id = row.site_id
  // Prefilled from the standing decision so editing a condition does not mean retyping it, and
  // an undecided site opens on APPROVED because that is what somebody opening it intends.
  siteForm.status = row.status === 'BLOCKED' ? 'BLOCKED' : 'APPROVED'
  siteForm.reason = row.reason ?? ''
  siteForm.conditions = row.conditions ?? ''
  siteForm.valid_until = row.valid_until ?? ''
  siteDialog.value = true
}

function submitSite() {
  siteForm.post(`${base.value}/sites`, {
    preserveScroll: true,
    onSuccess: () => { siteDialog.value = false },
  })
}

function withdrawSite() {
  if (!siteRow.value) return

  router.delete(`${base.value}/sites`, {
    data: { site_id: siteRow.value.site_id },
    preserveScroll: true,
    onSuccess: () => { siteDialog.value = false },
  })
}

const showOverride = ref(false)
const overrideForm = useForm({ reason: '', until: '' })
function submitOverride() {
  overrideForm.post(`${base.value}/override`, {
    preserveScroll: true,
    onSuccess: () => { showOverride.value = false; overrideForm.reset() },
  })
}

function refresh() { router.post(`${base.value}/refresh`, {}, { preserveScroll: true }) }

/*
  CON-5: the other half of this contractor's standing.

  Their documents are checked at the gate; their SHEQ assessment is what procurement decided. The
  two sat in the same database with nothing joining them, so a gatehouse arguing about a contractor
  could not see that procurement had rejected them.
*/
const linkSupplier = useForm({})

/* ── The QR pass (CON-11, CON-13) ───────────────────────────────────────────────────────────
   The image is fetched from the server, never built here: the token must not reach the browser
   as data, and a code assembled client-side would be a code the page could be made to lie about.
   Rotating is destructive to a printed card, so it asks first.                                 */
const pass = ref<{ subject: 'contractors' | 'workers'; uuid: string; name: string } | null>(null)
const rotating = ref(false)
const rotateForm = useForm({})

function showPass(subject: 'contractors' | 'workers', uuid: string, name: string) {
  pass.value = { subject, uuid, name }
  rotating.value = false
}

/*
  Bumped once, when a code is actually replaced.

  This was `Date.now()` read inside the function the template calls, which meant a new src on every
  render tick for as long as the form reported recent success — the browser re-fetched the QR over
  and over and the dialog flickered. A counter changes the URL exactly as often as the code does.
*/
const passVersion = ref(0)

function passImage(subject: string, uuid: string) {
  // Cache-busted on rotate so the dialog never shows the retired code.
  return `/sheq/pass/${subject}/${uuid}/qr.svg?v=${passVersion.value}`
}

function rotatePass() {
  if (!pass.value) return

  rotateForm.post(`/sheq/pass/${pass.value.subject}/${pass.value.uuid}/rotate`, {
    preserveScroll: true,
    onSuccess: () => { rotating.value = false; passVersion.value++ },
  })
}

/* Setting a wrong document aside. A reason is required: this is the record of why a certificate
   stopped counting, and "correction" tells a reviewer nothing. */
const superseding = ref<Record<string, any> | null>(null)
const supersedeForm = useForm({ reason: '' })

function openSupersede(document: Record<string, any>) {
  superseding.value = document
  supersedeForm.reset()
  supersedeForm.clearErrors()
}

function submitSupersede() {
  if (!superseding.value) return

  supersedeForm.post(`${base.value}/documents/${superseding.value.uuid}/supersede`, {
    preserveScroll: true,
    onSuccess: () => { superseding.value = null },
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center flex-wrap gap-3 mb-1">
      <h1 class="text-h5 font-weight-bold mb-0">{{ contractor.name }}</h1>
      <!--
        The gate's answer, in words. A status chip alone makes a guard decide what amber means:
        EXPIRING is not BLOCKED, and the difference is whether somebody works today.
      -->
      <VChip :color="mayWork.allowed ? 'success' : 'error'" size="small" variant="flat">
        {{ mayWork.allowed ? t('sheq.contractor.may_work_yes') : t('sheq.contractor.may_work_no') }}
      </VChip>
      <VChip :color="statusColour" size="small" variant="tonal">{{ statusLabel }}</VChip>
      <VChip v-if="overridden" size="small" variant="tonal" color="warning">
        {{ t('sheq.contractor.overridden') }}
      </VChip>
      <VChip v-if="contractor.trade" size="small" variant="tonal">{{ contractor.trade }}</VChip>
      <!-- The card a gate scans. Opaque: the code carries a token and nothing else. -->
      <VBtn v-if="can.manage" size="small" variant="tonal" prepend-icon="mdi-qrcode"
            @click="showPass('contractors', contractor.uuid, contractor.name)">
        {{ t('sheq.contractor.pass') }}
      </VBtn>
      <VChip v-if="latestScore" size="small" variant="tonal"
             :color="latestScore.rating === 'POOR' ? 'error' : latestScore.rating === 'FAIR' ? 'warning' : 'success'">
        {{ t('sheq.contractor.score') }} {{ latestScore.score }} · {{ latestScore.rating }}
      </VChip>
    </div>
    <p class="text-body-2 text-medium-emphasis mb-5">
      <span v-if="contractor.site_name">{{ contractor.site_name }} · </span>
      {{ t('sheq.contractor.subtitle') }}
    </p>

    <!-- The gatehouse question, answered first: may these people come in, and if not, why not. -->
    <!-- The commercial record, where there is one. Read-only: the assessment is decided on its
         own screen, and two screens that could both decide it would disagree. -->
    <VCard v-if="supplierLink.available" elevation="0" border rounded="lg" class="mb-4">
      <VCardText class="d-flex align-center flex-wrap ga-3">
        <div class="flex-grow-1 min-width-0">
          <div class="text-caption text-medium-emphasis">{{ t('sheq.contractor.commercial') }}</div>
          <template v-if="supplierLink.linked">
            <div class="text-body-2 font-weight-medium">{{ supplierLink.supplier_name }}</div>
            <div v-if="supplierLink.assessment" class="text-caption text-medium-emphasis">
              {{ t('sheq.contractor.assessment_status', {
                   status: supplierLink.assessment.status,
                   risk: supplierLink.assessment.risk_category }) }}
              <span v-if="supplierLink.assessment.valid_until">
                · {{ t('sheq.contractor.assessment_until', { date: supplierLink.assessment.valid_until }) }}
              </span>
            </div>
            <div v-else class="text-caption text-medium-emphasis">
              {{ t('sheq.contractor.no_assessment') }}
            </div>
          </template>
          <div v-else class="text-body-2 text-medium-emphasis">
            {{ t('sheq.contractor.not_linked') }}
          </div>
        </div>
        <VBtn v-if="!supplierLink.linked && can.manage" size="small" variant="tonal"
              prepend-icon="mdi-link-variant-plus" :loading="linkSupplier.processing"
              @click="linkSupplier.post(`${base}/link-supplier`, { preserveScroll: true })">
          {{ t('sheq.contractor.link_supplier') }}
        </VBtn>
        <VChip v-else-if="supplierLink.assessment" size="small" variant="tonal"
               :color="['APPROVED', 'CONDITIONAL'].includes(supplierLink.assessment.status) ? 'success' : 'error'">
          {{ supplierLink.assessment.status }}
        </VChip>
      </VCardText>
    </VCard>

    <!-- ── Where they may work (CON-14) ──────────────────────────────────────────────────── -->
    <VCard elevation="0" border rounded="lg" class="mb-4">
      <VCardTitle class="text-body-1 font-weight-semibold d-flex align-center flex-wrap ga-2">
        {{ t('sheq.contractor.sites_title') }}
        <VChip v-if="siteCounts.APPROVED" size="x-small" color="success" variant="tonal">
          {{ t('sheq.contractor.sites_approved_n', { n: siteCounts.APPROVED }) }}
        </VChip>
        <VChip v-if="siteCounts.BLOCKED" size="x-small" color="error" variant="tonal">
          {{ t('sheq.contractor.sites_blocked_n', { n: siteCounts.BLOCKED }) }}
        </VChip>
        <VChip v-if="siteCounts.EXPIRED" size="x-small" color="warning" variant="tonal">
          {{ t('sheq.contractor.sites_expired_n', { n: siteCounts.EXPIRED }) }}
        </VChip>
      </VCardTitle>
      <VCardText class="pt-0">
        <p class="text-body-2 text-medium-emphasis mb-3">{{ t('sheq.contractor.sites_hint') }}</p>

        <VAlert v-if="!siteApprovals.length" type="info" variant="tonal" density="compact">
          {{ t('sheq.contractor.sites_none') }}
        </VAlert>

        <VTable v-else density="compact">
          <thead>
            <tr>
              <th scope="col">{{ t('sheq.registers.col.site') }}</th>
              <th scope="col">{{ t('sheq.registers.col.status') }}</th>
              <th scope="col">{{ t('sheq.contractor.sites_detail') }}</th>
              <th scope="col"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in siteApprovals" :key="row.site_id">
              <td class="text-body-2">{{ row.site }}</td>
              <td style="width: 130px">
                <VChip size="x-small" variant="tonal" :color="siteColour(row.status)">
                  {{ t('sheq.contractor.site_status.' + row.status) }}
                </VChip>
              </td>
              <td class="text-caption text-medium-emphasis">
                <div v-if="row.status === 'BLOCKED' && row.reason">{{ row.reason }}</div>
                <div v-else-if="row.status === 'EXPIRED'">
                  {{ t('sheq.contractor.site_lapsed', { date: row.valid_until }) }}
                </div>
                <div v-else-if="row.valid_until">
                  {{ t('sheq.contractor.site_until', { date: row.valid_until }) }}
                </div>
                <div v-if="row.conditions && row.status === 'APPROVED'">
                  {{ t('sheq.contractor.site_conditions', { conditions: row.conditions }) }}
                </div>
              </td>
              <td style="width: 110px" class="text-end">
                <VBtn v-if="can.manage" size="x-small" variant="text" @click="openSite(row)">
                  {{ t('sheq.contractor.site_decide') }}
                </VBtn>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCardText>
    </VCard>

    <VAlert v-if="compliance.blocking.length" type="error" variant="tonal" class="mb-4">
      <div class="font-weight-semibold mb-1">{{ t('sheq.contractor.blocked_title') }}</div>
      <ul class="ps-4 mb-2">
        <li v-for="(b, i) in compliance.blocking" :key="i">{{ b.detail }}</li>
      </ul>
      <div v-if="can.manage" class="d-flex flex-wrap gap-2">
        <VBtn v-for="type in missingTypes" :key="type" size="x-small" variant="tonal" color="error"
              prepend-icon="mdi-upload" @click="openDoc(type)">
          {{ t('sheq.contractor.add_named', { type: prettyType(type) }) }}
        </VBtn>
      </div>
    </VAlert>
    <VAlert v-else type="success" variant="tonal" class="mb-4">
      {{ t('sheq.contractor.compliant') }}
    </VAlert>

    <VAlert v-if="compliance.expiring.length" type="warning" variant="tonal" class="mb-4">
      <div class="font-weight-semibold mb-1">{{ t('sheq.contractor.expiring_title') }}</div>
      <ul class="ps-4 mb-0">
        <li v-for="(e, i) in compliance.expiring" :key="i">{{ e.detail }}</li>
      </ul>
    </VAlert>

    <VAlert v-if="override" type="warning" variant="tonal" class="mb-4">
      <div class="font-weight-semibold">{{ t('sheq.contractor.override_in_force') }}</div>
      <div>{{ override.reason }}</div>
      <!-- The conditions reach the people who have to enforce them. Written where only the
           approver sees them, "escorted at all times" is a condition nobody enforces. -->
      <div v-if="override.conditions" class="mt-1">
        {{ t('sheq.contractor.site_conditions', { conditions: override.conditions }) }}
      </div>
      <div class="text-caption mt-1">
        {{ t('sheq.contractor.override_until', { date: override.ends_at }) }}
        <template v-if="override.approved_by_name">
          · {{ t('sheq.overrides.approved_by', { name: override.approved_by_name }) }}
        </template>
      </div>
    </VAlert>

    <div class="d-flex flex-wrap gap-2 mb-5">
      <VBtn v-if="can.manage" color="primary" prepend-icon="mdi-file-upload" @click="openDoc()">
        {{ t('sheq.contractor.add_document') }}
      </VBtn>
      <VBtn v-if="can.manage" variant="tonal" prepend-icon="mdi-account-hard-hat" @click="showWorker = true">
        {{ t('sheq.contractor.add_worker') }}
      </VBtn>
      <VBtn v-if="can.manage && status === 'BLOCKED' && !overridden"
            variant="tonal" color="warning" prepend-icon="mdi-shield-alert" @click="showOverride = true">
        {{ t('sheq.contractor.override') }}
      </VBtn>
      <VBtn variant="text" prepend-icon="mdi-refresh" @click="refresh">
        {{ t('sheq.contractor.recheck') }}
      </VBtn>
    </div>

    <VRow>
      <VCol cols="12" md="7">
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="text-body-1 font-weight-semibold">
            {{ t('sheq.contractor.documents') }}
            <!-- The whole file, section by section: the documents table below is the live set,
                 and the file is what a gate or an auditor asks to see. -->
            <VBtn size="x-small" variant="text" class="ms-2"
                  :href="`${base}/safety-file`" prepend-icon="mdi-folder-open-outline">
              {{ t('sheq.safety_file.title') }}
            </VBtn>
          </VCardTitle>
          <VDivider />
          <VCardText v-if="!compliance.documents.length" class="text-medium-emphasis">
            {{ t('sheq.contractor.no_documents') }}
          </VCardText>
          <VTable v-else density="compact">
            <thead>
              <tr>
                <th scope="col">{{ t('sheq.contractor.type') }}</th>
                <th scope="col">{{ t('sheq.contractor.issuer') }}</th>
                <th scope="col">{{ t('sheq.contractor.expires') }}</th>
                <th scope="col">{{ t('sheq.contractor.file') }}</th>
                <th scope="col"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="d in compliance.documents" :key="d.uuid">
                <td>
                  {{ prettyType(d.document_type) }}
                  <div v-if="d.title" class="text-caption text-medium-emphasis">{{ d.title }}</div>
                </td>
                <td class="text-medium-emphasis">{{ d.issuing_body ?? '—' }}</td>
                <td>{{ d.expires_on ?? t('sheq.contractor.no_expiry') }}</td>
                <td>
                  <!-- An inspector asks to see the certificate, not to be told it exists. -->
                  <a v-if="d.file_uuid" :href="`/sheq/files/${d.file_uuid}/download`"
                     class="text-body-2">
                    <VIcon icon="mdi-download" size="16" class="me-1" />{{ d.file_name }}
                  </a>
                  <span v-else class="text-caption text-warning">
                    {{ t('sheq.contractor.no_file') }}
                  </span>
                </td>
                <td style="width: 110px" class="text-end">
                  <!--
                    The compliance check keeps the furthest expiry per type, which is right for a
                    renewal and inescapable for a typo: 2030 keyed instead of 2026 keeps winning
                    over the correct certificate attached afterwards. Set aside rather than
                    deleted — compliance evidence is never destroyed.
                  -->
                  <VBtn v-if="can.manage" size="x-small" variant="text"
                        @click="openSupersede(d)">
                    {{ t('sheq.contractor.set_aside') }}
                  </VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
          <VCardText v-if="requiredTypes.length" class="pt-0">
            <div class="text-caption text-medium-emphasis">
              {{ t('sheq.contractor.required_are', { types: requiredTypes.map(prettyType).join(', ') }) }}
            </div>
          </VCardText>
        </VCard>

        <VCard elevation="0" border rounded="lg">
          <VCardTitle class="text-body-1 font-weight-semibold">
            {{ t('sheq.contractor.workers') }}
          </VCardTitle>
          <VDivider />
          <VCardText v-if="!workers.length" class="text-medium-emphasis">
            {{ t('sheq.contractor.no_workers') }}
          </VCardText>
          <VTable v-else density="compact">
            <thead>
              <tr>
                <th scope="col">{{ t('sheq.contractor.worker') }}</th>
                <th scope="col">{{ t('sheq.contractor.trade') }}</th>
                <th scope="col">{{ t('sheq.contractor.worker_placement') }}</th>
                <th scope="col">{{ t('sheq.contractor.induction') }}</th>
                <th scope="col"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="w in workers" :key="w.uuid">
                <td>
                  <div class="d-flex align-center gap-2">
                    <VAvatar size="28" color="surface-variant"
                             :image="w.photo_uuid ? `/sheq/files/${w.photo_uuid}/view` : undefined">
                      <VIcon v-if="!w.photo_uuid" icon="mdi-account" size="16" />
                    </VAvatar>
                    {{ w.name }}
                  </div>
                </td>
                <td class="text-medium-emphasis">{{ w.trade ?? '—' }}</td>
                <!-- What the access card will print. Shown here so nobody discovers at the
                     gatehouse that forty cards went out naming no site. -->
                <td class="text-medium-emphasis">
                  <div>{{ w.site_name ?? contractor.site_name ?? '—' }}</div>
                  <div v-if="w.project_name" class="text-caption">{{ w.project_name }}</div>
                </td>
                <td>
                  <VChip size="x-small" variant="tonal" :color="w.induction_valid ? 'success' : 'error'">
                    {{ w.induction_valid ? (w.induction_expires ?? t('sheq.contractor.inducted'))
                                         : t('sheq.contractor.not_inducted') }}
                  </VChip>
                  <!--
                    Said explicitly, because the two sources are chased differently. Imported
                    paperwork is chased from the contractor; an induction sat here is already
                    done and there is nothing to ask for.
                  -->
                  <div v-if="w.induction_completed_here" class="text-caption text-medium-emphasis">
                    {{ t('sheq.contractor.inducted_here') }}
                  </div>
                </td>
                <td style="width: 110px" class="text-end">
                  <!-- Each worker can carry their own pass (CON-13): the checks differ per person,
                       so one contractor code for a crew of thirty answers the wrong question. -->
                  <VBtn v-if="can.manage" size="x-small" variant="text" prepend-icon="mdi-qrcode"
                        @click="showPass('workers', w.uuid, w.name)">
                    {{ t('sheq.contractor.pass') }}
                  </VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>

      <VCol cols="12" md="5">
        <VCard v-if="scores.length" elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="text-body-1 font-weight-semibold">
            {{ t('sheq.contractor.performance') }}
          </VCardTitle>
          <VDivider />
          <VTable density="compact">
            <thead>
              <tr>
                <th scope="col">{{ t('sheq.contractor.period') }}</th>
                <th scope="col">{{ t('sheq.contractor.incidents') }}</th>
                <th scope="col">{{ t('sheq.contractor.score') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in scores" :key="s.period">
                <td>{{ s.period }}</td>
                <td>{{ s.incidents }}</td>
                <td>
                  <VChip size="x-small" variant="tonal"
                         :color="s.rating === 'POOR' ? 'error' : s.rating === 'FAIR' ? 'warning' : 'success'">
                    {{ s.score }} · {{ s.rating }}
                  </VChip>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>

        <VCard elevation="0" border rounded="lg">
          <VCardTitle class="text-body-1 font-weight-semibold">
            {{ t('sheq.contractor.access_events') }}
          </VCardTitle>
          <VDivider />
          <VCardText v-if="!accessEvents.length" class="text-medium-emphasis">
            {{ t('sheq.contractor.no_access_events') }}
          </VCardText>
          <VTable v-else density="compact">
            <tbody>
              <tr v-for="(e, i) in accessEvents" :key="i">
                <td>
                  {{ e.person_name ?? '—' }}
                  <div class="text-caption text-medium-emphasis">{{ e.occurred_at }}</div>
                </td>
                <td>
                  <VChip size="x-small" variant="tonal" :color="e.allowed ? 'success' : 'error'">
                    {{ e.allowed ? t('sheq.contractor.allowed') : t('sheq.contractor.refused') }}
                  </VChip>
                  <div v-if="e.reasons?.length" class="text-caption text-medium-emphasis">
                    {{ e.reasons.join(', ') }}
                  </div>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="showDoc" max-width="720">
      <VCard>
        <VCardTitle>{{ t('sheq.contractor.add_document') }}</VCardTitle>
        <VDivider />
        <VCardText>
          <!-- The same list the compliance check reads; still a combobox, so a document nobody
               anticipated can be typed rather than forced into "Other". -->
          <VCombobox
            v-model="docForm.document_type"
            :items="documentTypes"
            item-title="title"
            item-value="value"
            :return-object="false"
            :label="t('sheq.contractor.type')"
            variant="outlined" density="comfortable" class="mb-2"
            :error-messages="docForm.errors.document_type"
          >
            <template #item="{ props: itemProps, item }">
              <VListItem
                v-bind="itemProps"
                :subtitle="requiredTypes.includes(item.raw.value) ? t('sheq.contractor.required_here') : undefined"
              />
            </template>
          </VCombobox>
          <VTextField v-model="docForm.title" :label="t('sheq.contractor.title')"
                      variant="outlined" density="comfortable" class="mb-2" />
          <VTextField v-model="docForm.issuing_body" :label="t('sheq.contractor.issuer')"
                      variant="outlined" density="comfortable" class="mb-2" />
          <VRow dense>
            <VCol cols="6">
              <VTextField v-model="docForm.issued_on" type="date"
                          :label="t('sheq.contractor.issued_on')" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="docForm.expires_on" type="date"
                          :label="t('sheq.contractor.expires')" variant="outlined" density="comfortable" />
            </VCol>
          </VRow>
          <p class="text-caption text-medium-emphasis">{{ t('sheq.contractor.expiry_hint') }}</p>

          <!-- Where the file actually goes. Without this the dialog recorded a document that
               proved nothing. -->
          <div
            class="dropzone mt-2"
            :class="dragging ? 'dropzone--over' : ''"
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop"
          >
            <VIcon icon="mdi-tray-arrow-up" size="28" class="mb-1" />
            <div class="text-body-2">{{ t('sheq.contractor.drop_here') }}</div>
            <div class="text-caption text-medium-emphasis mb-2">{{ t('sheq.contractor.drop_hint') }}</div>
            <VFileInput
              v-model="docForm.files"
              multiple
              variant="outlined"
              density="compact"
              hide-details
              prepend-icon=""
              prepend-inner-icon="mdi-paperclip"
              :label="t('sheq.contractor.choose_files')"
              :error-messages="docForm.errors.files"
            />
          </div>

          <VList v-if="docForm.files.length" density="compact" class="mt-2">
            <VListItem v-for="(f, i) in docForm.files" :key="i"
                       :title="f.name" :subtitle="prettySize(f.size)">
              <template #prepend><VIcon icon="mdi-file-outline" size="18" /></template>
              <template #append>
                <VBtn icon="mdi-close" size="x-small" variant="text" @click="removeFile(i)" />
              </template>
            </VListItem>
          </VList>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="showDoc = false">{{ t('sheq.contractor.cancel') }}</VBtn>
          <VBtn color="primary" :loading="docForm.processing"
                :disabled="!String(docForm.document_type).trim()" @click="submitDoc">
            {{ t('sheq.contractor.add_document') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="showWorker" max-width="640">
      <VCard>
        <VCardTitle>{{ t('sheq.contractor.add_worker') }}</VCardTitle>
        <VDivider />
        <VCardText>
          <VTextField v-model="workerForm.name" :label="t('sheq.contractor.worker')"
                      variant="outlined" density="comfortable" class="mb-2"
                      :error-messages="workerForm.errors.name" />
          <!-- A list rather than a free-text box: competency requirements are keyed by trade, so
               "Scaffolding", "scaffold" and "SCAFFOLDING" are three workers whose tickets are
               never checked. Still open, for a trade nobody listed. -->
          <VCombobox v-model="workerForm.trade" :items="trades"
                     :label="t('sheq.contractor.trade')" variant="outlined" density="comfortable"
                     class="mb-2" :error-messages="workerForm.errors.trade" />

          <!-- Where this person works, which the access card prints beside their face. Clearable
               because the honest answer is often "wherever the contractor is", and a blank here
               means exactly that rather than nowhere. -->
          <VRow dense>
            <VCol :cols="projects.length ? 6 : 12">
              <VSelect v-model="workerForm.site_id" :items="sites" clearable
                       :label="t('sheq.contractor.worker_site')" variant="outlined"
                       density="comfortable" :hint="t('sheq.contractor.worker_site_hint')"
                       persistent-hint :error-messages="workerForm.errors.site_id" />
            </VCol>
            <!-- Absent, not disabled, where Project Management is not licensed: a field that can
                 never be filled is a field that reads as something broken. -->
            <VCol v-if="projects.length" cols="6">
              <VSelect v-model="workerForm.project_id" :items="projects" clearable
                       :label="t('sheq.contractor.worker_project')" variant="outlined"
                       density="comfortable" :error-messages="workerForm.errors.project_id" />
            </VCol>
          </VRow>

          <div class="d-flex align-center gap-3 mb-2">
            <VAvatar size="56" :image="photoPreview ?? undefined" color="surface-variant">
              <VIcon v-if="!photoPreview" icon="mdi-account" />
            </VAvatar>
            <VFileInput
              v-model="workerForm.photo"
              accept="image/*"
              variant="outlined" density="compact" hide-details
              prepend-icon="" prepend-inner-icon="mdi-camera"
              :label="t('sheq.contractor.photo')"
              :error-messages="workerForm.errors.photo"
            />
          </div>
          <p class="text-caption text-medium-emphasis">{{ t('sheq.contractor.photo_hint') }}</p>
          <VRow dense>
            <VCol cols="6">
              <VTextField v-model="workerForm.inducted_on" type="date"
                          :label="t('sheq.contractor.inducted_on')" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="workerForm.induction_expires" type="date"
                          :label="t('sheq.contractor.induction_expires')" variant="outlined"
                          density="comfortable" :error-messages="workerForm.errors.induction_expires" />
            </VCol>
          </VRow>
          <p class="text-caption text-medium-emphasis mb-0">{{ t('sheq.contractor.induction_hint') }}</p>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="showWorker = false">{{ t('sheq.contractor.cancel') }}</VBtn>
          <VBtn color="primary" :loading="workerForm.processing"
                :disabled="!workerForm.name.trim()" @click="submitWorker">
            {{ t('sheq.contractor.add_worker') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="siteDialog" max-width="640">
      <VCard v-if="siteRow">
        <VCardTitle class="text-body-1 font-weight-semibold">
          {{ siteRow.site }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VBtnToggle v-model="siteForm.status" mandatory divided density="compact"
                      class="mb-4" color="primary">
            <VBtn value="APPROVED">{{ t('sheq.contractor.site_status.APPROVED') }}</VBtn>
            <VBtn value="BLOCKED">{{ t('sheq.contractor.site_status.BLOCKED') }}</VBtn>
          </VBtnToggle>

          <!-- Said before saving, because a block here is not the same as the company-wide one
               and somebody reaching for it should know which gate they are closing. -->
          <VAlert v-if="siteForm.status === 'BLOCKED'" type="warning" variant="tonal"
                  density="compact" class="mb-3">
            {{ t('sheq.contractor.site_block_warning') }}
          </VAlert>

          <VTextarea v-model="siteForm.reason" rows="2" auto-grow variant="outlined"
                     density="comfortable" class="mb-2"
                     :label="t('sheq.contractor.site_reason')"
                     :hint="siteForm.status === 'BLOCKED'
                       ? t('sheq.contractor.site_reason_required')
                       : t('sheq.contractor.site_reason_hint')"
                     persistent-hint :error-messages="siteForm.errors.reason" />

          <template v-if="siteForm.status === 'APPROVED'">
            <VTextarea v-model="siteForm.conditions" rows="2" auto-grow variant="outlined"
                       density="comfortable" class="mb-2"
                       :label="t('sheq.contractor.site_conditions_label')"
                       :hint="t('sheq.contractor.site_conditions_hint')"
                       persistent-hint :error-messages="siteForm.errors.conditions" />
            <VTextField v-model="siteForm.valid_until" type="date" variant="outlined"
                        density="comfortable"
                        :label="t('sheq.contractor.site_valid_until')"
                        :hint="t('sheq.contractor.site_valid_until_hint')"
                        persistent-hint :error-messages="siteForm.errors.valid_until" />
          </template>
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VBtn v-if="siteRow.status !== 'UNDECIDED'" variant="text" color="warning" size="small"
                @click="withdrawSite">
            {{ t('sheq.contractor.site_withdraw') }}
          </VBtn>
          <VSpacer />
          <VBtn variant="tonal" @click="siteDialog = false">{{ t('sheq.action.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="siteForm.processing"
                :disabled="siteForm.processing" @click="submitSite">
            {{ t('sheq.registers.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="showOverride" max-width="640">
      <VCard>
        <VCardTitle>{{ t('sheq.contractor.override_title') }}</VCardTitle>
        <VDivider />
        <VCardText>
          <VAlert type="warning" variant="tonal" density="compact" class="mb-3">
            {{ t('sheq.contractor.override_warning') }}
          </VAlert>
          <VTextarea v-model="overrideForm.reason" :label="t('sheq.contractor.reason')"
                     rows="3" variant="outlined" class="mb-2"
                     :error-messages="overrideForm.errors.reason" />
          <VTextField v-model="overrideForm.until" type="date"
                      :label="t('sheq.contractor.override_until_label')" variant="outlined"
                      density="comfortable" :error-messages="overrideForm.errors.until" />
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="showOverride = false">{{ t('sheq.contractor.cancel') }}</VBtn>
          <VBtn color="warning" :loading="overrideForm.processing"
                :disabled="overrideForm.reason.trim().length < 10 || !overrideForm.until"
                @click="submitOverride">
            {{ t('sheq.contractor.override_confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>

    <VDialog :model-value="superseding !== null" max-width="560"
             @update:model-value="superseding = null">
      <VCard v-if="superseding">
        <VCardTitle class="text-body-1 font-weight-semibold">
          {{ t('sheq.contractor.set_aside') }} · {{ prettyType(superseding.document_type) }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-4">
            {{ t('sheq.contractor.set_aside_note') }}
          </p>
          <VTextarea
            v-model="supersedeForm.reason" rows="3" auto-grow
            :label="t('sheq.contractor.set_aside_reason')"
            :hint="t('sheq.contractor.set_aside_hint')" persistent-hint
            variant="outlined" density="comfortable"
            :error-messages="supersedeForm.errors.reason"
          />
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="superseding = null">{{ t('sheq.action.cancel') }}</VBtn>
          <VBtn color="warning" variant="flat" :loading="supersedeForm.processing"
                :disabled="supersedeForm.processing" @click="submitSupersede">
            {{ t('sheq.contractor.set_aside') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="pass !== null" max-width="420" @update:model-value="pass = null">
      <VCard v-if="pass">
        <VCardTitle class="text-body-1 font-weight-semibold">
          {{ t('sheq.contractor.pass') }} · {{ pass.name }}
        </VCardTitle>
        <VDivider />
        <VCardText class="text-center">
          <!-- Served by the server as SVG: it prints at any size on whatever card stock a site
               uses, and the token never reaches this page as data. -->
          <img :src="passImage(pass.subject, pass.uuid)" alt="" width="260" height="260"
               style="max-width: 100%; height: auto" />
          <p class="text-caption text-medium-emphasis mt-3 mb-0">
            {{ t('sheq.contractor.pass_hint') }}
          </p>
        </VCardText>
        <VDivider />
        <VCardText v-if="rotating" class="pb-0">
          <VAlert type="warning" variant="tonal" density="compact">
            {{ t('sheq.contractor.pass_rotate_warning') }}
          </VAlert>
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VBtn v-if="!rotating" variant="text" color="warning" size="small"
                @click="rotating = true">
            {{ t('sheq.contractor.pass_rotate') }}
          </VBtn>
          <template v-else>
            <VBtn variant="text" size="small" @click="rotating = false">
              {{ t('sheq.action.cancel') }}
            </VBtn>
            <VBtn color="warning" variant="flat" size="small" :loading="rotateForm.processing"
                  @click="rotatePass">
              {{ t('sheq.contractor.pass_rotate_confirm') }}
            </VBtn>
          </template>
          <VSpacer />
          <!-- The QR on its own, for a site that prints codes onto its own card stock. -->
          <VBtn variant="text" size="small" :href="passImage(pass.subject, pass.uuid)"
                target="_blank">
            {{ t('sheq.contractor.pass_print') }}
          </VBtn>
          <!-- The card a person actually carries: face, name, trade, site and project beside the
               code, at bank-card size. The primary action, because a bare QR tells a guard
               nothing until they scan it. -->
          <VBtn color="primary" variant="flat" size="small" prepend-icon="mdi-card-account-details"
                :href="`/sheq/pass/${pass.subject}/${pass.uuid}/card?v=${passVersion}`"
                target="_blank">
            {{ t('sheq.contractor.card_print') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
</template>

<style scoped>
.dropzone {
  border: 1px dashed rgba(var(--v-theme-on-surface), 0.28);
  border-radius: 8px;
  padding: 16px;
  text-align: center;
  transition: background-color 120ms, border-color 120ms;
}
.dropzone--over {
  border-color: rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.06);
}
</style>
