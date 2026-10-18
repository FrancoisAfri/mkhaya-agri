<script setup lang="ts">
import { reactive, ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import DueDiligencePanel from './partials/DueDiligencePanel.vue'
import OwnershipPanel from './partials/OwnershipPanel.vue'
import RiskPanel from './partials/RiskPanel.vue'
import ReputationPanel from './partials/ReputationPanel.vue'

const { t } = useI18n()
const confirmDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null)
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
interface RiskFlag {
  id: number
  type: string
  severity: string
  message: string
}

interface IndustryType {
  id: number
  industry_type_name: string
}

interface DocumentType {
  id: number
  document_type_name: string
  compulsory: boolean
  authority: string | null
  /** How long one is valid, so the form can offer the date rather than leave it to be worked out. */
  default_validity_months: number | null
  guidance: string | null
}

interface ParentOption {
  id: number
  company_name: string
}

interface Contact {
  id: number
  first_name: string
  surname: string
  email: string
  tel: string
}

interface Director {
  id: number
  first_name: string
  surname: string
  email: string
  tel: string
}

interface Product {
  id: number
  item_name: string
  item_price: string | null
  active: boolean
}

interface Document {
  id: number
  document_type: DocumentType | null
  file_name: string | null
  expiry_date: string | null
}

interface Screening {
  risk_level: string
  screened_at: string
  summary: string | null
  sanctions_hits: { name: string; confidence: string }[]
  pep_hits: { name: string; position: string }[]
  adverse_media: { headline: string; severity: string }[]
  recommendations: string[]
}

interface Bbbee {
  level: number | null
  black_ownership_pct: number | null
  black_women_ownership_pct: number | null
  recognition_pct: number | null
  certificate_expiry: string | null
  notes: string | null
}

interface Supplier {
  id: number
  company_name: string
  is_active: number
  segment: string | null
  parent: ParentOption | null
  parent_id: number | null
  company_registration_number: string | null
  vat_registration_number: string | null
  tax_reference_number: string | null
  bbbee_status_level: string | null
  email: string | null
  phone_number: string | null
  payment_terms: string | null
  years_in_operation: number | null
  contacts: Contact[]
  directors: Director[]
  products: Product[]
  documents: Document[]
  industries: IndustryType[]
  bbbee: Bbbee | null
  screening: Screening | null
  scars: { status: string }[]
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  supplier: Supplier
  riskFlags?: RiskFlag[]
  industryTypes: IndustryType[]
  documentTypes: DocumentType[]
  parentOptions?: ParentOption[]
  // Due diligence: every Layer 1 check with its newest answer, the banking verdict, the people and
  // the history. Optional so the page still renders where the controller has not supplied it.
  dueDiligence?: any
  hasTcsPin?: boolean
  // The ownership chain resolved to the people behind it, and the suppliers this one shares a
  // person or a parent with.
  ownership?: any
  relatedSuppliers?: any[]
  // What the world says about this supplier, and whether anybody has read it.
  reputation?: any
  // The assessment: the score, what it authorises, and what is holding it up.
  risk?: any
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const tab = ref<string>('company')

// ── Compliance screening ──────────────────────────────────────────────────────
const screeningRunning = ref<boolean>(false)
const screening = computed(() => props.supplier.screening)

const riskLevelColor: Record<string, string> = {
  low: 'success',
  medium: 'warning',
  high: 'error',
}

function runScreening(): void {
  screeningRunning.value = true
  router.post(
    route('suppliers.screen', props.supplier.uuid),
    {},
    {
      preserveScroll: true,
      onFinish: () => (screeningRunning.value = false),
    }
  )
}

// ── B-BBEE ────────────────────────────────────────────────────────────────────
const bbbeeForm = reactive<{
  level: number | null
  black_ownership_pct: number | null
  black_women_ownership_pct: number | null
  recognition_pct: number | null
  certificate_expiry: string | null
  notes: string
}>({
  level: props.supplier.bbbee?.level ?? null,
  black_ownership_pct: props.supplier.bbbee?.black_ownership_pct ?? null,
  black_women_ownership_pct: props.supplier.bbbee?.black_women_ownership_pct ?? null,
  recognition_pct: props.supplier.bbbee?.recognition_pct ?? null,
  certificate_expiry: props.supplier.bbbee?.certificate_expiry ?? null,
  notes: props.supplier.bbbee?.notes ?? '',
})

function saveBbbee(): void {
  router.post(
    route('suppliers.bbbee.upsert', props.supplier.uuid),
    bbbeeForm,
    { preserveScroll: true }
  )
}

// ── Segmentation & hierarchy ──────────────────────────────────────────────────
const segmentItems = [
  { title: t('supplier_show.segments.strategic'), value: 'strategic' },
  { title: t('supplier_show.segments.preferred'), value: 'preferred' },
  { title: t('supplier_show.segments.approved'), value: 'approved' },
  { title: t('supplier_show.segments.tail'), value: 'tail' },
]

function setSegment(v: string | null): void {
  router.patch(
    route('suppliers.segment.update', props.supplier.uuid),
    { segment: v },
    { preserveScroll: true }
  )
}

function setParent(v: number | null): void {
  router.patch(
    route('suppliers.parent.update', props.supplier.uuid),
    { parent_id: v },
    { preserveScroll: true }
  )
}

// ── Risk flags ─────────────────────────────────────────────────────────────────
const severityColor: Record<string, string> = {
  low: 'info',
  medium: 'warning',
  high: 'orange',
  critical: 'error',
}

function hasCritical(): boolean {
  return (props.riskFlags ?? []).some(f => f.severity === 'critical')
}

function resolveFlag(id: number): void {
  router.post(
    route('suppliers.risk-flags.resolve', id),
    { status: 'resolved' },
    { preserveScroll: true }
  )
}

function dismissFlag(id: number): void {
  router.post(
    route('suppliers.risk-flags.resolve', id),
    { status: 'dismissed' },
    { preserveScroll: true }
  )
}

// ── Status helpers ────────────────────────────────────────────────────────────
const statusLabel: Record<number, string> = {
  0: t('supplier_show.statuses.pending'),
  1: t('supplier_show.statuses.active'),
  2: t('supplier_show.statuses.refused'),
  3: t('supplier_show.statuses.pending_kyc'),
}

const statusColor: Record<number, string> = {
  0: 'warning',
  1: 'success',
  2: 'error',
  3: 'info',
}

function openScars(): number {
  return (props.supplier.scars ?? []).filter(
    (s: any) => !['closed', 'rejected'].includes(s.status)
  ).length
}

// ── Contacts ──────────────────────────────────────────────────────────────────
const contactForm = ref<{
  first_name: string
  surname: string
  email: string
  tel: string
}>({
  first_name: '',
  surname: '',
  email: '',
  tel: '',
})

function addContact(): void {
  router.post(
    route('suppliers.contacts.store', props.supplier.uuid),
    contactForm.value,
    {
      preserveScroll: true,
      onSuccess: () => {
        contactForm.value = { first_name: '', surname: '', email: '', tel: '' }
      },
    }
  )
}

function delContact(id: number): void {
  confirmDialog.value?.open(
    {
      title: t('supplier_show.contacts.delete_confirm_title'),
      message: t('supplier_show.contacts.delete_confirm_message'),
      confirmText: t('supplier_show.contacts.delete'),
      confirmColor: 'error',
      icon: 'tabler-trash',
    },
    () => {
      router.delete(
        route('suppliers.contacts.destroy', [props.supplier.uuid, id]),
        { preserveScroll: true }
      )
    }
  )
}

// ── Directors ─────────────────────────────────────────────────────────────────
const directorForm = ref<{
  first_name: string
  surname: string
  email: string
  tel: string
}>({
  first_name: '',
  surname: '',
  email: '',
  tel: '',
})

function addDirector(): void {
  router.post(
    route('suppliers.directors.store', props.supplier.uuid),
    directorForm.value,
    {
      preserveScroll: true,
      onSuccess: () => {
        directorForm.value = { first_name: '', surname: '', email: '', tel: '' }
      },
    }
  )
}

function delDirector(id: number): void {
  confirmDialog.value?.open(
    {
      title: t('supplier_show.directors.delete_confirm_title'),
      message: t('supplier_show.directors.delete_confirm_message'),
      confirmText: t('supplier_show.directors.delete'),
      confirmColor: 'error',
      icon: 'tabler-trash',
    },
    () => {
      router.delete(
        route('suppliers.directors.destroy', [props.supplier.uuid, id]),
        { preserveScroll: true }
      )
    }
  )
}

/**
 * Replace the supplier's portal link.
 *
 * Confirmed because it is destructive from the supplier's side: whoever is holding the current URL
 * loses access the moment this runs, which is the point, but not something to do by mis-click.
 */
function rotatePortalLink(): void {
  confirmDialog.value?.open(
    {
      title: t('supplier_show.rotate_portal_confirm_title'),
      message: t('supplier_show.rotate_portal_confirm_message'),
      confirmText: t('supplier_show.rotate_portal_link'),
      confirmColor: 'warning',
      icon: 'tabler-link',
    },
    () => {
      router.post(route('suppliers.portal-token.rotate', props.supplier.uuid), {}, { preserveScroll: true })
    }
  )
}

// ── Industries ────────────────────────────────────────────────────────────────
const industrySel = ref<number[]>(
  (props.supplier.industries ?? []).map((i: IndustryType) => i.id)
)

function syncIndustries(): void {
  router.post(
    route('suppliers.industries.sync', props.supplier.uuid),
    { industry_ids: industrySel.value },
    { preserveScroll: true }
  )
}

// ── Documents ─────────────────────────────────────────────────────────────────
const docForm = ref<{
  document_type_id: number | null
  file: File | null
  expiry_date: string
}>({
  document_type_id: null,
  file: null,
  expiry_date: '',
})

const selectedDocType = computed(() =>
  props.documentTypes.find(d => d.id === docForm.value.document_type_id) ?? null)

/*
 * Offer the expiry date the moment a type is chosen.
 *
 * A Letter of Good Standing lasts twelve months, a bank confirmation about three, and a registration
 * certificate does not expire at all. Leaving every one of those to be worked out by hand is how a
 * date gets typed a year wrong — and the expiry date is the entire verification for a document
 * nothing can be checked against.
 *
 * Offered, not forced: the date on the certificate wins whenever it differs, so this only fills an
 * empty field and never overwrites something already typed.
 */
function offerExpiry(): void {
  const months = selectedDocType.value?.default_validity_months
  if (!months || docForm.value.expiry_date) return
  const date = new Date()
  date.setMonth(date.getMonth() + months)
  docForm.value.expiry_date = date.toISOString().slice(0, 10)
}

function addDoc(): void {
  router.post(
    route('suppliers.documents.store', props.supplier.uuid),
    {
      document_type_id: docForm.value.document_type_id,
      expiry_date: docForm.value.expiry_date,
      file: docForm.value.file,
    },
    {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => {
        docForm.value = { document_type_id: null, file: null, expiry_date: '' }
      },
    }
  )
}

function detectExpiry(documentId: number): void {
  router.post(
    route('suppliers.documents.detect-expiry', documentId),
    {},
    { preserveScroll: true }
  )
}

// ── Actions ──────────────────────────────────────────────────────────────────
function activate(): void {
  router.post(
    route('suppliers.activate', props.supplier.uuid),
    {},
    { preserveScroll: true }
  )
}

function refuse(): void {
  router.post(
    route('suppliers.refuse', props.supplier.uuid),
    {},
    { preserveScroll: true }
  )
}

function requestInfo(): void {
  router.post(
    route('suppliers.request-info', props.supplier.uuid),
    {},
    { preserveScroll: true }
  )
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-2 flex-wrap">
        <div>
          <h1 class="text-h4 mb-0">{{ supplier.company_name }}</h1>
          <VChip
            size="small"
            :color="statusColor[supplier.is_active]"
            variant="tonal"
          >{{ statusLabel[supplier.is_active] }}</VChip>
        </div>
        <VChip
          v-if="supplier.segment"
          size="small"
          color="primary"
          variant="tonal"
          class="text-capitalize"
        >
          {{ t(`supplier_show.segments.${supplier.segment}`) }}
        </VChip>
        <VChip
          v-if="supplier.parent"
          size="small"
          variant="outlined"
          prepend-icon="tabler-sitemap"
        >
          {{ t('supplier_show.subsidiary_of', { name: supplier.parent.company_name }) }}
        </VChip>
        <VChip
          v-if="openScars()"
          size="small"
          color="error"
          variant="tonal"
        >
          {{ t('supplier_show.open_scars', { count: openScars() }) }}
        </VChip>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VBtn
          color="primary"
          variant="tonal"
          prepend-icon="tabler-chart-box"
          :href="route('suppliers.scorecard', supplier.uuid)"
        >
          {{ t('supplier_show.scorecard') }}
        </VBtn>
        <VBtn
          color="deep-purple"
          variant="tonal"
          prepend-icon="tabler-shield-search"
          :loading="screeningRunning"
          @click="runScreening"
        >
          {{ t('supplier_show.screen') }}
        </VBtn>
        <!--
          A navigation link, a decision and a suspension used to be five tonal buttons of identical
          weight, and Activate was offered on a supplier that was already active. Each action is now
          shown only when it is available, and refusing — the one that stops the supplier trading —
          is outlined in error rather than dressed like the rest.
        -->
        <VBtn
          v-if="supplier.is_active !== 1"
          color="success"
          variant="flat"
          prepend-icon="tabler-check"
          @click="activate"
        >
          {{ t('supplier_show.activate') }}
        </VBtn>
        <VBtn
          v-if="supplier.is_active !== 2"
          color="error"
          variant="outlined"
          prepend-icon="tabler-ban"
          @click="refuse"
        >
          {{ t('supplier_show.refuse') }}
        </VBtn>
        <VBtn color="info" variant="tonal" prepend-icon="tabler-mail-question" @click="requestInfo">
          {{ t('supplier_show.request_info') }}
        </VBtn>
        <VBtn
          variant="tonal"
          prepend-icon="tabler-link"
          :title="t('supplier_show.portal_link_hint')"
          @click="rotatePortalLink"
        >
          {{ t('supplier_show.rotate_portal_link') }}
        </VBtn>
      </div>
    </div>

    <!-- Critical Risk Alert -->
    <VAlert
      v-if="hasCritical()"
      type="error"
      variant="tonal"
      prominent
      class="mb-4"
      icon="tabler-alert-octagon"
    >
      <div class="font-weight-bold">{{ t('supplier_show.critical_risk_alert') }}</div>
      <div class="text-body-2">{{ t('supplier_show.critical_risk_message') }}</div>
    </VAlert>

    <!-- Risk Flags -->
    <VCard v-if="(riskFlags ?? []).length" border class="mb-4">
      <VCardTitle class="d-flex align-center gap-2">
        <VIcon icon="tabler-shield-exclamation" color="error" size="20" />
        {{ t('supplier_show.risk_flags') }}
        <VChip size="x-small" color="error" variant="tonal">{{ (riskFlags ?? []).length }}</VChip>
      </VCardTitle>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('supplier_show.severity.critical') }}</th>
            <th>{{ t('supplier_show.documents.type') }}</th>
            <th>{{ t('supplier_show.contacts.title') }}</th>
            <th class="text-right">{{ t('supplier_show.actions.resolve') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="flag in riskFlags" :key="flag.id">
            <td>
              <VChip
                size="small"
                :color="severityColor[flag.severity] ?? 'default'"
                variant="tonal"
              >
                {{ t(`supplier_show.severity.${flag.severity}`) }}
              </VChip>
            </td>
            <td class="text-no-wrap">{{ flag.type }}</td>
            <td>{{ flag.message }}</td>
            <td class="text-right text-no-wrap">
              <VBtn
                size="small"
                color="success"
                variant="tonal"
                class="me-2"
                @click="resolveFlag(flag.id)"
              >
                {{ t('supplier_show.actions.resolve') }}
              </VBtn>
              <VBtn
                size="small"
                variant="text"
                @click="dismissFlag(flag.id)"
              >
                {{ t('supplier_show.actions.dismiss') }}
              </VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Due diligence: verify who this supplier is, and what stops a payment -->
    <DueDiligencePanel
      v-if="dueDiligence"
      :supplier-uuid="supplier.uuid"
      :due-diligence="dueDiligence"
      :has-tcs-pin="!!hasTcsPin"
      class="mb-4"
    />

    <!-- One line to the Passport: the summary somebody with four minutes reads instead of this page -->
    <VAlert v-if="risk" type="info" variant="tonal" density="compact" class="mb-4" prepend-icon="tabler-id-badge-2">
      <div class="d-flex align-center justify-space-between flex-wrap gap-2">
        <span>{{ t('supplier_management.passport.eyebrow') }}</span>
        <VBtn size="small" variant="tonal" :href="`/suppliers/${supplier.id}/passport`">
          {{ t('supplier_management.passport.open') }}
        </VBtn>
      </div>
    </VAlert>

    <!-- The assessment, above ownership: it is the summary the rest explains -->
    <RiskPanel
      v-if="risk"
      :supplier-uuid="supplier.uuid"
      :risk="risk"
      :people="dueDiligence?.people ?? []"
      class="mb-4"
    />

    <!-- What the world says — below the score it feeds, above the ownership detail -->
    <ReputationPanel
      v-if="reputation"
      :supplier-uuid="supplier.uuid"
      :reputation="reputation"
      class="mb-4"
    />

    <!-- Who actually owns this supplier, resolved through the chain -->
    <OwnershipPanel
      v-if="ownership"
      :supplier-uuid="supplier.uuid"
      :ownership="ownership"
      :related-suppliers="relatedSuppliers ?? []"
      :people="dueDiligence?.people ?? []"
      class="mb-4"
    />

    <!-- Main Tabs -->
    <VCard border>
      <VTabs v-model="tab">
        <VTab value="company">{{ t('supplier_show.tabs.company') }}</VTab>
        <VTab value="contacts">{{ t('supplier_show.tabs.contacts') }}</VTab>
        <VTab value="directors">{{ t('supplier_show.tabs.directors') }}</VTab>
        <VTab value="industries">{{ t('supplier_show.tabs.industries') }}</VTab>
        <VTab value="products">{{ t('supplier_show.tabs.products') }}</VTab>
        <VTab value="documents">{{ t('supplier_show.tabs.documents') }}</VTab>
        <VTab value="compliance">{{ t('supplier_show.tabs.compliance') }}</VTab>
      </VTabs>
      <VCardText>
        <VWindow v-model="tab">
          <!-- Company -->
          <VWindowItem value="company">
            <VRow>
              <VCol cols="12" md="6">
                <strong>{{ t('supplier_show.company.registration') }}:</strong>
                {{ supplier.company_registration_number ?? '—' }}
              </VCol>
              <VCol cols="12" md="6">
                <strong>{{ t('supplier_show.company.vat') }}:</strong>
                {{ supplier.vat_registration_number ?? '—' }}
              </VCol>
              <VCol cols="12" md="6">
                <strong>{{ t('supplier_show.company.tax_ref') }}:</strong>
                {{ supplier.tax_reference_number ?? '—' }}
              </VCol>
              <VCol cols="12" md="6">
                <strong>{{ t('supplier_show.company.bbbee') }}:</strong>
                {{ supplier.bbbee_status_level ?? '—' }}
              </VCol>
              <VCol cols="12" md="6">
                <strong>{{ t('supplier_show.company.email') }}:</strong>
                {{ supplier.email ?? '—' }}
              </VCol>
              <VCol cols="12" md="6">
                <strong>{{ t('supplier_show.company.phone') }}:</strong>
                {{ supplier.phone_number ?? '—' }}
              </VCol>
              <VCol cols="12" md="6">
                <strong>{{ t('supplier_show.company.payment_terms') }}:</strong>
                {{ supplier.payment_terms ?? '—' }}
              </VCol>
              <VCol cols="12" md="6">
                <strong>{{ t('supplier_show.company.years_in_operation') }}:</strong>
                {{ supplier.years_in_operation ?? '—' }}
              </VCol>
            </VRow>
          </VWindowItem>

          <!-- Contacts -->
          <VWindowItem value="contacts">
            <VTable class="mb-4">
              <thead>
                <tr>
                  <th>{{ t('supplier_show.contacts.name') }}</th>
                  <th>{{ t('supplier_show.contacts.email') }}</th>
                  <th>{{ t('supplier_show.contacts.tel') }}</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="c in supplier.contacts" :key="c.id">
                  <td>{{ c.first_name }} {{ c.surname }}</td>
                  <td>{{ c.email }}</td>
                  <td>{{ c.tel }}</td>
                  <td>
                    <VBtn
                      icon
                      size="small"
                      :aria-label="t('supplier_show.contacts.delete')"
                      :title="t('supplier_show.contacts.delete')"
                      variant="text"
                      color="error"
                      @click="delContact(c.id)"
                    >
                      <VIcon icon="tabler-trash" />
                    </VBtn>
                  </td>
                </tr>
                <tr v-if="!supplier.contacts?.length">
                  <td colspan="4" class="text-medium-emphasis py-4">
                    {{ t('supplier_show.contacts.no_contacts') }}
                  </td>
                </tr>
              </tbody>
            </VTable>
            <VRow>
              <VCol cols="6" md="3">
                <VTextField
                  v-model="contactForm.first_name"
                  :label="t('supplier_show.contacts.first_name')"
                  density="compact"
                  variant="outlined"
                />
              </VCol>
              <VCol cols="6" md="3">
                <VTextField
                  v-model="contactForm.surname"
                  :label="t('supplier_show.contacts.surname')"
                  density="compact"
                  variant="outlined"
                />
              </VCol>
              <VCol cols="6" md="3">
                <VTextField
                  v-model="contactForm.email"
                  :label="t('supplier_show.contacts.email')"
                  density="compact"
                  variant="outlined"
                />
              </VCol>
              <VCol cols="6" md="2">
                <VTextField
                  v-model="contactForm.tel"
                  :label="t('supplier_show.contacts.tel')"
                  density="compact"
                  variant="outlined"
                />
              </VCol>
              <VCol cols="12" md="1">
                <VBtn
                  color="primary"
                  :disabled="!contactForm.first_name"
                  @click="addContact"
                >
                  {{ t('supplier_show.contacts.add') }}
                </VBtn>
              </VCol>
            </VRow>
          </VWindowItem>

          <!-- Directors -->
          <VWindowItem value="directors">
            <VTable class="mb-4">
              <thead>
                <tr>
                  <th>{{ t('supplier_show.directors.name') }}</th>
                  <th>{{ t('supplier_show.directors.email') }}</th>
                  <th>{{ t('supplier_show.directors.tel') }}</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="d in supplier.directors" :key="d.id">
                  <td>{{ d.first_name }} {{ d.surname }}</td>
                  <td>{{ d.email }}</td>
                  <td>{{ d.tel }}</td>
                  <td>
                    <VBtn
                      icon
                      size="small"
                      :aria-label="t('supplier_show.directors.delete')"
                      :title="t('supplier_show.directors.delete')"
                      variant="text"
                      color="error"
                      @click="delDirector(d.id)"
                    >
                      <VIcon icon="tabler-trash" />
                    </VBtn>
                  </td>
                </tr>
                <tr v-if="!supplier.directors?.length">
                  <td colspan="4" class="text-medium-emphasis py-4">
                    {{ t('supplier_show.directors.no_directors') }}
                  </td>
                </tr>
              </tbody>
            </VTable>
            <VRow>
              <VCol cols="6" md="3">
                <VTextField
                  v-model="directorForm.first_name"
                  :label="t('supplier_show.directors.first_name')"
                  density="compact"
                  variant="outlined"
                />
              </VCol>
              <VCol cols="6" md="3">
                <VTextField
                  v-model="directorForm.surname"
                  :label="t('supplier_show.directors.surname')"
                  density="compact"
                  variant="outlined"
                />
              </VCol>
              <VCol cols="6" md="3">
                <VTextField
                  v-model="directorForm.email"
                  :label="t('supplier_show.directors.email')"
                  density="compact"
                  variant="outlined"
                />
              </VCol>
              <VCol cols="6" md="2">
                <VTextField
                  v-model="directorForm.tel"
                  :label="t('supplier_show.directors.tel')"
                  density="compact"
                  variant="outlined"
                />
              </VCol>
              <VCol cols="12" md="1">
                <VBtn
                  color="primary"
                  :disabled="!directorForm.first_name"
                  @click="addDirector"
                >
                  {{ t('supplier_show.directors.add') }}
                </VBtn>
              </VCol>
            </VRow>
          </VWindowItem>

          <!-- Industries -->
          <VWindowItem value="industries">
            <VSelect
              v-model="industrySel"
              :items="industryTypes"
              item-title="industry_type_name"
              item-value="id"
              :label="t('supplier_show.industries.title')"
              variant="outlined"
              multiple
              chips
              class="mb-3"
            />
            <VBtn color="primary" @click="syncIndustries">
              {{ t('supplier_show.industries.save') }}
            </VBtn>
          </VWindowItem>

          <!-- Products -->
          <VWindowItem value="products">
            <VTable>
              <thead>
                <tr>
                  <th>{{ t('supplier_show.products.item') }}</th>
                  <th>{{ t('supplier_show.products.price') }}</th>
                  <th>{{ t('supplier_show.products.active') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!supplier.products?.length">
                  <td colspan="3" class="text-medium-emphasis py-4">
                    {{ t('supplier_show.products.no_products') }}
                  </td>
                </tr>
                <tr v-for="p in supplier.products" :key="p.id">
                  <td>{{ p.item_name }}</td>
                  <td>{{ p.item_price ?? '—' }}</td>
                  <td>{{ p.active ? t('supplier_show.yes') : t('supplier_show.no') }}</td>
                </tr>
              </tbody>
            </VTable>
          </VWindowItem>

          <!-- Documents -->
          <VWindowItem value="documents">
            <VTable class="mb-4">
              <thead>
                <tr>
                  <th>{{ t('supplier_show.documents.type') }}</th>
                  <th>{{ t('supplier_show.documents.file') }}</th>
                  <th>{{ t('supplier_show.documents.expiry') }}</th>
                  <th class="text-right">{{ t('supplier_show.documents.download') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!supplier.documents?.length">
                  <td colspan="4" class="text-medium-emphasis py-4">
                    {{ t('supplier_show.documents.no_documents') }}
                  </td>
                </tr>
                <tr v-for="d in supplier.documents" :key="d.id">
                  <td>{{ d.document_type?.document_type_name ?? '—' }}</td>
                  <td>{{ d.file_name ?? '—' }}</td>
                  <td>{{ d.expiry_date ?? '—' }}</td>
                  <td class="text-right text-no-wrap">
                    <VBtn
                      size="x-small"
                      variant="text"
                      color="purple"
                      prepend-icon="tabler-sparkles"
                      @click="detectExpiry(d.id)"
                    >
                      {{ t('supplier_show.documents.detect_expiry') }}
                    </VBtn>
                    <VBtn
                      v-if="d.file_name"
                      size="small"
                      variant="text"
                      prepend-icon="tabler-download"
                      :href="route('suppliers.documents.download', d.id)"
                      target="_blank"
                    >
                      {{ t('supplier_show.documents.download') }}
                    </VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>
            <VRow>
              <VCol cols="12" md="4">
                <VSelect
                  v-model="docForm.document_type_id"
                  :items="documentTypes"
                  item-title="document_type_name"
                  item-value="id"
                  :label="t('supplier_show.documents.type_label')"
                  density="compact"
                  variant="outlined"
                  @update:model-value="offerExpiry"
                >
                  <template #item="{ item, props: ip }">
                    <VListItem v-bind="ip" :title="item.raw.document_type_name"
                               :subtitle="item.raw.authority ?? undefined">
                      <template #append>
                        <VChip v-if="item.raw.compulsory" size="x-small" color="warning" variant="tonal">
                          {{ t('supplier_show.documents.required') }}
                        </VChip>
                      </template>
                    </VListItem>
                  </template>
                </VSelect>
              </VCol>
              <VCol cols="12" md="4">
                <VFileInput
                  :label="t('supplier_show.documents.file_label')"
                  density="compact"
                  variant="outlined"
                  prepend-icon="tabler-paperclip"
                  @update:model-value="(f: File | File[]) => docForm.file = Array.isArray(f) ? f[0] : f"
                />
              </VCol>
              <VCol cols="6" md="3">
                <VTextField
                  v-model="docForm.expiry_date"
                  :label="t('supplier_show.documents.expiry_label')"
                  type="date"
                  density="compact"
                  variant="outlined"
                />
              </VCol>
              <!--
                Where to get the document. For a statutory one there is nothing to verify against —
                the Department of Employment and Labour publishes no interface — so this is the whole
                of the help the system can give.
              -->
              <VCol v-if="selectedDocType?.guidance" cols="12" class="pt-0">
                <VAlert type="info" variant="tonal" density="compact">
                  <span class="text-caption">{{ selectedDocType.guidance }}</span>
                </VAlert>
              </VCol>
              <VCol cols="6" md="1">
                <VBtn
                  color="primary"
                  :disabled="!docForm.file"
                  @click="addDoc"
                >
                  {{ t('supplier_show.documents.add') }}
                </VBtn>
              </VCol>
            </VRow>
          </VWindowItem>

          <!-- Compliance -->
          <VWindowItem value="compliance">
            <!-- Segmentation & hierarchy -->
            <VRow class="mb-2">
              <VCol cols="12" md="4">
                <VSelect
                  :model-value="supplier.segment"
                  :items="segmentItems"
                  :label="t('supplier_show.compliance.segment')"
                  variant="outlined"
                  density="compact"
                  clearable
                  @update:model-value="setSegment"
                />
              </VCol>
              <VCol cols="12" md="5">
                <VSelect
                  :model-value="supplier.parent_id"
                  :items="parentOptions ?? []"
                  item-title="company_name"
                  item-value="id"
                  :label="t('supplier_show.compliance.parent_company')"
                  variant="outlined"
                  density="compact"
                  clearable
                  @update:model-value="setParent"
                />
              </VCol>
            </VRow>

            <!-- AI compliance screening -->
            <VCard variant="outlined" class="mb-4">
              <VCardTitle class="d-flex align-center justify-space-between">
                <span>
                  <VIcon icon="tabler-shield-search" class="me-2" />
                  {{ t('supplier_show.compliance.screening_title') }}
                </span>
                <VChip
                  v-if="screening"
                  :color="riskLevelColor[screening.risk_level] ?? 'grey'"
                  variant="flat"
                >
                  {{ screening.risk_level ?? 'n/a' }} risk
                </VChip>
              </VCardTitle>
              <VCardText>
                <div v-if="!screening" class="text-medium-emphasis" v-html="t('supplier_show.compliance.no_screening')" />
                <template v-else>
                  <p class="text-caption text-medium-emphasis">
                    {{ t('supplier_show.compliance.screened_at', { date: screening.screened_at }) }}
                  </p>
                  <p v-if="screening.summary" class="mb-3">{{ screening.summary }}</p>

                  <!-- Sanctions -->
                  <div v-if="screening.sanctions_hits?.length" class="mb-2">
                    <div class="text-caption text-medium-emphasis">{{ t('supplier_show.compliance.sanctions') }}</div>
                    <VChip
                      v-for="(h, i) in screening.sanctions_hits"
                      :key="i"
                      size="small"
                      color="error"
                      variant="tonal"
                      class="me-1 mb-1"
                    >
                      {{ h.name }} ({{ h.confidence }})
                    </VChip>
                  </div>

                  <!-- PEP -->
                  <div v-if="screening.pep_hits?.length" class="mb-2">
                    <div class="text-caption text-medium-emphasis">{{ t('supplier_show.compliance.pep') }}</div>
                    <VChip
                      v-for="(h, i) in screening.pep_hits"
                      :key="i"
                      size="small"
                      color="warning"
                      variant="tonal"
                      class="me-1 mb-1"
                    >
                      {{ h.name }} — {{ h.position }}
                    </VChip>
                  </div>

                  <!-- Adverse media -->
                  <div v-if="screening.adverse_media?.length" class="mb-2">
                    <div class="text-caption text-medium-emphasis">{{ t('supplier_show.compliance.adverse_media') }}</div>
                    <ul class="ms-4">
                      <li v-for="(m, i) in screening.adverse_media" :key="i">
                        {{ m.headline }}
                        <VChip
                          size="x-small"
                          :color="riskLevelColor[m.severity] ?? 'grey'"
                          variant="tonal"
                        >
                          {{ t(`supplier_show.severity.${m.severity}`) }}
                        </VChip>
                      </li>
                    </ul>
                  </div>

                  <!-- Recommendations -->
                  <div v-if="screening.recommendations?.length">
                    <div class="text-caption text-medium-emphasis">{{ t('supplier_show.compliance.recommendations') }}</div>
                    <ul class="ms-4">
                      <li v-for="(r, i) in screening.recommendations" :key="i">{{ r }}</li>
                    </ul>
                  </div>
                </template>
              </VCardText>
            </VCard>

            <!-- B-BBEE scorecard -->
            <VCard variant="outlined">
              <VCardTitle>{{ t('supplier_show.compliance.bbbee_title') }}</VCardTitle>
              <VCardText>
                <VRow v-if="supplier.bbbee" class="mb-2">
                  <VCol cols="6" md="3">
                    <div class="text-caption text-medium-emphasis">{{ t('supplier_show.compliance.bbbee_level') }}</div>
                    <div class="text-h6">{{ supplier.bbbee.level ?? '—' }}</div>
                  </VCol>
                  <VCol cols="6" md="3">
                    <div class="text-caption text-medium-emphasis">{{ t('supplier_show.compliance.black_ownership') }}</div>
                    <div class="text-h6">{{ supplier.bbbee.black_ownership_pct ?? '—' }}%</div>
                  </VCol>
                  <VCol cols="6" md="3">
                    <div class="text-caption text-medium-emphasis">{{ t('supplier_show.compliance.recognition') }}</div>
                    <div class="text-h6">{{ supplier.bbbee.recognition_pct ?? '—' }}%</div>
                  </VCol>
                  <VCol cols="6" md="3">
                    <div class="text-caption text-medium-emphasis">{{ t('supplier_show.compliance.certificate_expiry') }}</div>
                    <div class="text-h6">{{ supplier.bbbee.certificate_expiry ?? '—' }}</div>
                  </VCol>
                </VRow>
                <VRow>
                  <VCol cols="12" md="4">
                    <VSelect
                      v-model.number="bbbeeForm.level"
                      :items="[1, 2, 3, 4, 5, 6, 7, 8]"
                      :label="t('supplier_show.compliance.level_label')"
                      density="compact"
                      variant="outlined"
                      clearable
                    />
                  </VCol>
                  <VCol cols="12" md="4">
                    <VTextField
                      v-model.number="bbbeeForm.black_ownership_pct"
                      :label="t('supplier_show.compliance.black_ownership_label')"
                      type="number"
                      density="compact"
                      variant="outlined"
                    />
                  </VCol>
                  <VCol cols="12" md="4">
                    <VTextField
                      v-model.number="bbbeeForm.black_women_ownership_pct"
                      :label="t('supplier_show.compliance.black_women_ownership_label')"
                      type="number"
                      density="compact"
                      variant="outlined"
                    />
                  </VCol>
                  <VCol cols="12" md="4">
                    <VTextField
                      v-model.number="bbbeeForm.recognition_pct"
                      :label="t('supplier_show.compliance.recognition_label')"
                      type="number"
                      density="compact"
                      variant="outlined"
                    />
                  </VCol>
                  <VCol cols="12" md="4">
                    <VTextField
                      v-model="bbbeeForm.certificate_expiry"
                      :label="t('supplier_show.compliance.certificate_expiry_label')"
                      type="date"
                      density="compact"
                      variant="outlined"
                    />
                  </VCol>
                  <VCol cols="12">
                    <VTextarea
                      v-model="bbbeeForm.notes"
                      :label="t('supplier_show.compliance.notes_label')"
                      rows="2"
                      density="compact"
                      variant="outlined"
                    />
                  </VCol>
                  <VCol cols="12">
                    <VBtn color="primary" @click="saveBbbee">
                      {{ t('supplier_show.compliance.save_bbbee') }}
                    </VBtn>
                  </VCol>
                </VRow>
              </VCardText>
            </VCard>
          </VWindowItem>
        </VWindow>
      </VCardText>
    </VCard>

    <ConfirmDialog ref="confirmDialog" />
  </div>
</template>
