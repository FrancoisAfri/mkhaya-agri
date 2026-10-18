<script setup lang="ts">
import { ref, reactive, computed, watch } from 'vue'
import { useForm, router, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import SubscriptionBuilder from './SubscriptionBuilder.vue'
const route = ziggyRoute

interface Selection { plan: string; modules: string[]; addons: { code: string; quantity: number }[] }
interface Company { id: number; name: string; slug: string; status: string; plan: string; plan_name: string; users: number; max_users: number | null; mrr: number; trial_ends_at: string | null; trial_expired: boolean; trial_days_left: number | null; suspended_reason: string | null; archived_at: string | null; purge_after: string | null; purged: boolean; reseller_uuid: string | null; contact_email: string | null; founding_customer: boolean; white_label: boolean; storage_used: number; storage_quota: number; addon_modules: string[]; addons: { code: string; quantity: number }[] }
interface PlanT { code: string; name: string; price_monthly: number; regular_price_monthly: number | null; max_users: number | null; ai_token_quota: number | null; sms_quota: number | null; storage_gb: number | null; all_modules: boolean }
interface ModT { code: string; name: string; category: string; price: number }
interface AddonT { type: string; code: string; name: string; unit_label: string | null; units: number | null; price: number | null }
const props = defineProps<{
  companies: Company[]
  plans: PlanT[]
  moduleCatalog: ModT[]
  addonCatalog: AddonT[]
  summary: { total: number; active: number; trial: number; suspended: number; archived: number; mrr: number }
  impersonating: number | null
}>()

const money = (n: number) => 'R ' + (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 0, maximumFractionDigits: 2 })
const gb = (bytes: any) => {
  const b = Number(bytes ?? 0)
  if (b >= 1073741824) return (b / 1073741824).toLocaleString(undefined, { maximumFractionDigits: 2 }) + ' GB'
  if (b >= 1048576) return (b / 1048576).toLocaleString(undefined, { maximumFractionDigits: 1 }) + ' MB'
  if (b >= 1024) return (b / 1024).toLocaleString(undefined, { maximumFractionDigits: 0 }) + ' KB'
  return b + ' B'
}
const storagePct = (c: { storage_used: number; storage_quota: number }) =>
  c.storage_quota > 0 ? Math.min(999, Math.round(c.storage_used / c.storage_quota * 100)) : 0
const statusColor: Record<string, string> = { active: 'success', trial: 'info', suspended: 'error', cancelled: 'default', archived: 'secondary' }
const confirm = ref()

// ── Convert a tenant into a reseller ─────────────────────────────────────────
interface Candidate { id: number; name: string; email: string; active: boolean; suggested: 'owner' | 'staff' }
const convertDialog = ref(false)
const convertCompany = ref<Company | null>(null)
const candidates = ref<Candidate[]>([])
const openInvoices = ref(0)
const loadingCandidates = ref(false)
const convertForm = useForm<any>({ commission_type: 'percent', commission_value: 10, retention_years: 5, users: {} as Record<number, string>, confirm_name: '', notes: '' })
const convertErrors = computed(() => (usePage().props.errors ?? {}) as Record<string, string>)
const roleChoices = [{ title: 'Owner', value: 'owner' }, { title: 'Staff', value: 'staff' }, { title: 'Leave behind', value: 'exclude' }]
const moving = computed(() => Object.values(convertForm.users).filter(r => r !== 'exclude').length)
const hasOwner = computed(() => Object.values(convertForm.users).includes('owner'))
async function openConvert(c: Company) {
  convertCompany.value = c
  convertForm.reset(); convertForm.clearErrors()
  candidates.value = []
  convertDialog.value = true
  loadingCandidates.value = true
  try {
    const res = await fetch(route('tenancy.companies.convert.candidates', c.id), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
    const data = await res.json()
    candidates.value = data.users
    openInvoices.value = data.open_invoices
    convertForm.retention_years = data.retention_years
    convertForm.users = Object.fromEntries(data.users.map((u: Candidate) => [u.id, u.active ? u.suggested : 'exclude']))
  } finally {
    loadingCandidates.value = false
  }
}
function convert() {
  if (!convertCompany.value) return
  convertForm.post(route('tenancy.companies.convert', convertCompany.value.id), { preserveScroll: true, onSuccess: () => { convertDialog.value = false } })
}

// ── New company (with Subscription Builder) ──────────────────────────────────
const dialog = ref(false)
const form = useForm<any>({ company_name: '', registration_number: '', admin_name: '', email: '', phone: '', password: '', plan: 'starter', trial_days: 30, modules: [] as string[], addons: [] as any[] })
const composition = reactive<Selection>({ plan: 'starter', modules: [], addons: [] })
const trialOptions = [
  { title: '30-day free trial', value: 30 },
  { title: '14-day free trial', value: 14 },
  { title: '7-day free trial', value: 7 },
  { title: 'Active from day one (no trial)', value: 0 },
]
const trialMessage = computed(() => form.trial_days > 0
  ? `The company starts on a ${form.trial_days}-day free trial.`
  : 'The company goes active immediately — no trial.')
function openNew() {
  form.reset(); form.clearErrors()
  composition.plan = 'starter'; composition.modules = []; composition.addons = []
  dialog.value = true
}
function create() {
  form.transform(d => ({ ...d, plan: composition.plan, modules: composition.modules, addons: composition.addons }))
    .post(route('tenancy.companies.store'), { preserveScroll: true, onSuccess: () => { dialog.value = false; form.reset() } })
}

// ── Edit an existing company's subscription ──────────────────────────────────
const subDialog = ref(false)
const subCompany = ref<Company | null>(null)
const subComposition = reactive<Selection>({ plan: '', modules: [], addons: [] })
const subForm = useForm<any>({})
function openSub(c: Company) {
  subCompany.value = c
  subComposition.plan = c.plan
  subComposition.modules = [...(c.addon_modules || [])]
  subComposition.addons = (c.addons || []).map(a => ({ ...a }))
  subDialog.value = true
}
function saveSub() {
  if (!subCompany.value) return
  subForm.transform(() => ({ plan: subComposition.plan, modules: subComposition.modules, addons: subComposition.addons }))
    .post(route('tenancy.companies.compose', subCompany.value.id), { preserveScroll: true, onSuccess: () => { subDialog.value = false } })
}

function setPlan(c: Company, plan: string) {
  const p = props.plans.find(x => x.code === plan)
  confirm.value.open(
    { title: 'Change plan?', message: `Change ${c.name} to ${p?.name ?? plan}? This updates their monthly charge.`, confirmText: 'Change plan', confirmColor: 'primary', icon: 'tabler-package' },
    () => useForm({ plan }).post(route('tenancy.companies.plan', c.id), { preserveScroll: true }),
  )
}
function impersonate(c: Company) {
  confirm.value.open(
    { title: 'View as this company?', message: `You'll browse the platform as ${c.name} sees it. Use "Exit view as company" to return to the platform admin view.`, confirmText: 'View as company', confirmColor: 'primary', icon: 'tabler-eye' },
    () => useForm({}).post(route('tenancy.companies.impersonate', c.id)),
  )
}
function stopImpersonating() { useForm({}).post(route('tenancy.stop-impersonating')) }

// ── View / edit company details ──────────────────────────────────────────────
const editDialog = ref(false)
const editCompany = ref<Company | null>(null)
const editForm = useForm<any>({ name: '', contact_person: '', contact_email: '', contact_phone: '', registration_number: '', vat_number: '' })
function openEdit(c: Company) {
  editCompany.value = c
  editForm.clearErrors()
  editForm.name = c.name ?? ''
  editForm.contact_person = (c as any).contact_person ?? ''
  editForm.contact_email = c.contact_email ?? ''
  editForm.contact_phone = (c as any).contact_phone ?? ''
  editForm.registration_number = (c as any).registration_number ?? ''
  editForm.vat_number = (c as any).vat_number ?? ''
  editDialog.value = true
}
function saveEdit() {
  if (!editCompany.value) return
  editForm.put(route('tenancy.companies.update', editCompany.value.id), { preserveScroll: true, onSuccess: () => { editDialog.value = false } })
}
function remove(c: Company) {
  confirm.value.open(
    { title: 'Delete company?', message: `Permanently delete ${c.name} and ALL of its data (users, employees, records). This cannot be undone.`, confirmText: 'Delete everything', confirmColor: 'error', icon: 'tabler-trash' },
    () => useForm({}).delete(route('tenancy.companies.destroy', c.id), { preserveScroll: true }),
  )
}

// ── Admin login credentials (shown after create / password reset) ────────────
const page = usePage()
const credentials = ref<any>(null)
watch(() => (page.props as any).flash?.credentials, (c) => { if (c) credentials.value = c }, { immediate: true })
const copiedKey = ref('')
async function copyText(text: string, key: string) {
  try {
    await navigator.clipboard.writeText(text)
    copiedKey.value = key
    setTimeout(() => { if (copiedKey.value === key) copiedKey.value = '' }, 1500)
  } catch { /* clipboard unavailable */ }
}
function copyAll(c: any) {
  copyText(`Login: ${c.login_url}\nEmail: ${c.email}\nPassword: ${c.password}`, 'all')
}
function resetPassword(c: Company) {
  confirm.value.open(
    { title: 'Reset admin password?', message: `Generate a new password for ${c.name}'s admin, email it to them, and show it here so you can copy it.`, confirmText: 'Reset password', confirmColor: 'warning', icon: 'tabler-key' },
    () => useForm({}).post(route('tenancy.companies.reset-admin-password', c.id), { preserveScroll: true }),
  )
}
function suspendCompany(c: Company) {
  confirm.value.open(
    { title: 'Suspend company', message: `Suspend ${c.name}? Nobody at the company will be able to sign in.`, confirmText: 'Suspend', confirmColor: 'error' },
    () => useForm({}).post(route('tenancy.companies.suspend', c.id), { preserveScroll: true }),
  )
}
function activateCompany(c: Company) {
  const wasTrial = c.status === 'trial' || c.trial_expired
  confirm.value.open(
    {
      title: 'Activate subscription',
      message: wasTrial
        ? `Move ${c.name} off the trial and onto a paid subscription? Access is restored immediately and the trial end date is cleared, so they are never locked out again.`
        : `Activate ${c.name}? Everyone at the company can sign in again.`,
      confirmText: 'Activate',
      confirmColor: 'success',
    },
    () => useForm({}).post(route('tenancy.companies.activate', c.id), { preserveScroll: true }),
  )
}

// ── Extend a trial ───────────────────────────────────────────────────────────
// The way to give a trial more time — including after it has already run out and
// locked the company out. Activating instead converts them to a paid subscription.
const trialDialog = ref(false)
const trialCompany = ref<Company | null>(null)
const trialMode = ref<'days' | 'date'>('days')
const extendForm = useForm<any>({ days: 30, trial_ends_at: '' })
const extendPresets = [7, 14, 30, 60, 90]
function openExtendTrial(c: Company) {
  trialCompany.value = c
  trialMode.value = 'days'
  extendForm.clearErrors()
  extendForm.days = 30
  extendForm.trial_ends_at = c.trial_ends_at ?? ''
  trialDialog.value = true
}
const extendSummary = computed(() => {
  const c = trialCompany.value
  if (!c) return ''
  if (trialMode.value === 'date') return 'The trial runs until the date you pick.'
  const running = c.status === 'trial' && (c.trial_days_left ?? 0) > 0
  return running
    ? `${extendForm.days} days are added to the current end date (${c.trial_ends_at}) — no days are lost.`
    : `The trial restarts today and runs for ${extendForm.days} days. Access is restored immediately.`
})
function saveExtendTrial() {
  if (!trialCompany.value) return
  extendForm
    .transform(d => trialMode.value === 'date' ? { trial_ends_at: d.trial_ends_at } : { days: d.days })
    .post(route('tenancy.companies.extend-trial', trialCompany.value.id), {
      preserveScroll: true,
      onSuccess: () => { trialDialog.value = false },
    })
}
function toggleWhiteLabel(c: Company) {
  const enable = !c.white_label
  confirm.value.open(
    { title: enable ? 'Enable white-label' : 'Disable white-label', message: `${enable ? 'Enable' : 'Disable'} white-label branding for ${c.name}? ${enable ? 'They will be able to set their own in-system logo.' : ''}`, confirmText: enable ? 'Enable' : 'Disable', confirmColor: enable ? 'success' : 'warning' },
    () => useForm({ enabled: enable }).post(route('tenancy.companies.white-label', c.id), { preserveScroll: true }),
  )
}
</script>

<template>
  <div class="pa-4">
    <VAlert v-if="impersonating" type="warning" variant="tonal" class="mb-4" prepend-icon="tabler-eye">
      You are viewing as a company. <a href="#" @click.prevent="stopImpersonating" class="font-weight-bold">Exit view as company</a>
    </VAlert>

    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">Platform</div>
        <h2 class="text-h5 mb-0">Companies</h2>
        <div class="text-caption text-medium-emphasis">Manage every company, their plan, status and billing.</div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">New company</VBtn>
    </div>

    <VRow class="mb-2">
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">Companies</div><div class="text-h6">{{ summary.total }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">Active / Trial</div><div class="text-h6">{{ summary.active }} / {{ summary.trial }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">Suspended</div><div class="text-h6 text-error">{{ summary.suspended }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">MRR</div><div class="text-h6 text-success">{{ money(summary.mrr) }}</div></VCardText></VCard></VCol>
    </VRow>

    <VCard border>
      <VTable density="compact">
        <thead><tr><th>Company</th><th>Plan</th><th>Status</th><th class="text-right">Users</th><th class="text-right">MRR</th><th style="min-width:150px">Storage</th><th>Trial ends</th><th class="text-right">Actions</th></tr></thead>
        <tbody>
          <tr v-for="c in companies" :key="c.id">
            <td><div class="font-weight-medium">{{ c.name }}</div><div class="text-caption text-medium-emphasis">{{ c.contact_email }}</div></td>
            <td style="min-width: 150px">
              <VSelect :model-value="c.plan" :items="plans.map(p => ({ title: p.name, value: p.code }))" density="compact" variant="outlined" hide-details @update:model-value="setPlan(c, $event)" />
            </td>
            <td>
              <VChip size="x-small" :color="statusColor[c.status]" variant="tonal" class="text-capitalize">{{ c.status }}</VChip>
              <div v-if="c.status === 'archived'" class="text-caption text-medium-emphasis mt-1">
                Reseller since {{ c.archived_at }} · {{ c.purged ? 'data deleted' : `data deleted on ${c.purge_after}` }}
              </div>
              <div v-else-if="c.trial_expired" class="text-caption text-error mt-1">Trial expired — locked out</div>
              <div v-else-if="c.trial_days_left !== null && c.trial_days_left <= 7" class="text-caption text-warning mt-1">
                {{ c.trial_days_left }} day{{ c.trial_days_left === 1 ? '' : 's' }} left
              </div>
            </td>
            <td class="text-right">{{ c.users }}<span v-if="c.max_users" class="text-medium-emphasis"> / {{ c.max_users }}</span></td>
            <td class="text-right">{{ money(c.mrr) }}</td>
            <td>
              <template v-if="c.storage_quota > 0">
                <div class="d-flex align-center justify-space-between text-caption mb-1">
                  <span>{{ gb(c.storage_used) }} / {{ gb(c.storage_quota) }}</span>
                  <span :class="storagePct(c) >= 100 ? 'text-error' : (storagePct(c) >= 80 ? 'text-warning' : 'text-medium-emphasis')">{{ storagePct(c) }}%</span>
                </div>
                <VProgressLinear :model-value="storagePct(c)" :color="storagePct(c) >= 100 ? 'error' : (storagePct(c) >= 80 ? 'warning' : 'success')" height="6" rounded />
              </template>
              <span v-else class="text-caption text-medium-emphasis">{{ gb(c.storage_used) }} · unlimited</span>
            </td>
            <td class="text-caption text-medium-emphasis">{{ c.trial_ends_at || '—' }}</td>
            <td class="text-right" style="white-space: nowrap">
              <VMenu location="bottom end">
                <template #activator="{ props: menuProps }">
                  <VBtn icon="tabler-dots-vertical" size="small" variant="text" color="default" v-bind="menuProps" />
                </template>
                <VList v-if="c.status === 'archived'" density="compact" min-width="210">
                  <VListItem v-if="c.reseller_uuid" prepend-icon="tabler-building-store" title="Open reseller" @click="router.visit(route('tenancy.resellers.show', c.reseller_uuid))" />
                  <VListItem prepend-icon="tabler-file-invoice" title="Invoices" @click="router.visit(route('tenancy.companies.invoices', c.id))" />
                  <VListItem v-if="!c.purged" prepend-icon="tabler-eye" title="View archived data" @click="impersonate(c)" />
                </VList>
                <VList v-else density="compact" min-width="210">
                  <VListItem prepend-icon="tabler-credit-card" title="Subscription" @click="openSub(c)" />
                  <VListItem prepend-icon="tabler-file-invoice" title="Invoices" @click="router.visit(route('tenancy.companies.invoices', c.id))" />
                  <VListItem prepend-icon="tabler-edit" title="View / Edit" @click="openEdit(c)" />
                  <VListItem prepend-icon="tabler-key" title="Reset password" @click="resetPassword(c)" />
                  <VListItem prepend-icon="tabler-eye" title="View as" @click="impersonate(c)" />
                  <VDivider class="my-1" />
                  <VListItem
                    v-if="c.status !== 'active'"
                    prepend-icon="tabler-player-play"
                    title="Activate subscription"
                    base-color="success"
                    @click="activateCompany(c)"
                  />
                  <VListItem
                    v-if="c.status === 'trial' || c.status === 'suspended' || c.trial_expired"
                    prepend-icon="tabler-clock-plus"
                    :title="c.trial_expired ? 'Extend trial (unlock)' : 'Extend trial'"
                    :base-color="c.trial_expired ? 'warning' : undefined"
                    @click="openExtendTrial(c)"
                  />
                  <VListItem
                    v-if="c.status !== 'suspended'"
                    prepend-icon="tabler-ban"
                    title="Suspend"
                    base-color="error"
                    @click="suspendCompany(c)"
                  />
                  <VListItem
                    prepend-icon="tabler-palette"
                    :title="c.white_label ? 'White-label: On' : 'White-label: Off'"
                    :base-color="c.white_label ? 'success' : undefined"
                    @click="toggleWhiteLabel(c)"
                  />
                  <template v-if="c.id !== 1">
                    <VDivider class="my-1" />
                    <VListItem prepend-icon="tabler-building-store" title="Convert to reseller" @click="openConvert(c)" />
                    <VListItem prepend-icon="tabler-trash" title="Delete" base-color="error" @click="remove(c)" />
                  </template>
                </VList>
              </VMenu>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="900" scrollable>
      <VCard>
        <VCardTitle>New company</VCardTitle>
        <VCardText>
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            {{ trialMessage }} The admin name + email below become the first user (an administrator) who can sign in, set up the workspace and add users. Access is limited to the modules in their subscription.
          </VAlert>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model="form.company_name" label="Company name" variant="outlined" density="comfortable" :error-messages="form.errors.company_name" />
            <VTextField v-model="form.registration_number" label="Registration no. (optional)" variant="outlined" density="comfortable" :error-messages="form.errors.registration_number" />
          </div>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model="form.admin_name" label="Admin name" variant="outlined" density="comfortable" :error-messages="form.errors.admin_name" />
            <VTextField v-model="form.email" type="email" label="Admin email" variant="outlined" density="comfortable" :error-messages="form.errors.email" />
          </div>
          <div class="d-flex gap-3 mb-4">
            <VTextField v-model="form.phone" label="Phone (optional)" variant="outlined" density="comfortable" :error-messages="form.errors.phone" />
            <VTextField v-model="form.password" type="password" label="Temp password" variant="outlined" density="comfortable" :error-messages="form.errors.password" />
            <VSelect v-model="form.trial_days" :items="trialOptions" label="Trial period" variant="outlined" density="comfortable" :error-messages="form.errors.trial_days" />
          </div>

          <VDivider class="mb-4" />
          <SubscriptionBuilder v-if="dialog" v-model="composition" :plans="plans" :module-catalog="moduleCatalog" :addon-catalog="addonCatalog" />
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="dialog = false">Cancel</VBtn><VBtn color="primary" :loading="form.processing" @click="create">Create company</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Edit an existing company's subscription -->
    <VDialog v-model="subDialog" max-width="900" scrollable>
      <VCard>
        <VCardTitle>Subscription — {{ subCompany?.name }}</VCardTitle>
        <VCardText>
          <SubscriptionBuilder v-if="subDialog" v-model="subComposition" :plans="plans" :module-catalog="moduleCatalog" :addon-catalog="addonCatalog" />
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="subDialog = false">Cancel</VBtn><VBtn color="primary" :loading="subForm.processing" @click="saveSub">Save subscription</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- View / edit a company's details -->
    <VDialog v-model="editDialog" max-width="560" scrollable>
      <VCard>
        <VCardTitle>Company details — {{ editCompany?.name }}</VCardTitle>
        <VCardText>
          <VRow dense>
            <VCol cols="12"><VTextField v-model="editForm.name" label="Company name" :error-messages="editForm.errors.name" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="editForm.contact_person" label="Contact person" :error-messages="editForm.errors.contact_person" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="editForm.contact_email" label="Contact email" :error-messages="editForm.errors.contact_email" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="editForm.contact_phone" label="Contact phone" :error-messages="editForm.errors.contact_phone" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="editForm.registration_number" label="Registration number" :error-messages="editForm.errors.registration_number" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="editForm.vat_number" label="VAT number" :error-messages="editForm.errors.vat_number" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="editDialog = false">Cancel</VBtn><VBtn color="primary" :loading="editForm.processing" @click="saveEdit">Save changes</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Admin login credentials (after create / password reset) -->
    <VDialog :model-value="!!credentials" max-width="480" @update:model-value="v => { if (!v) credentials = null }">
      <VCard v-if="credentials">
        <VCardTitle>{{ credentials.title }}</VCardTitle>
        <VCardText>
          <VAlert :type="credentials.emailed ? 'success' : 'warning'" variant="tonal" density="compact" class="mb-4">
            {{ credentials.emailed
              ? 'These login details were emailed to the admin. You can also copy them below.'
              : 'The email could not be sent — copy these details and send them to the admin manually.' }}
          </VAlert>
          <div class="d-flex align-center mb-2">
            <div class="flex-grow-1"><div class="text-caption text-medium-emphasis">Login URL</div><div>{{ credentials.login_url }}</div></div>
            <VBtn size="small" variant="text" @click="copyText(credentials.login_url, 'url')">{{ copiedKey === 'url' ? 'Copied' : 'Copy' }}</VBtn>
          </div>
          <div class="d-flex align-center mb-2">
            <div class="flex-grow-1"><div class="text-caption text-medium-emphasis">Email</div><div>{{ credentials.email }}</div></div>
            <VBtn size="small" variant="text" @click="copyText(credentials.email, 'email')">{{ copiedKey === 'email' ? 'Copied' : 'Copy' }}</VBtn>
          </div>
          <div class="d-flex align-center">
            <div class="flex-grow-1"><div class="text-caption text-medium-emphasis">Password</div><div style="font-family: monospace">{{ credentials.password }}</div></div>
            <VBtn size="small" variant="text" @click="copyText(credentials.password, 'pw')">{{ copiedKey === 'pw' ? 'Copied' : 'Copy' }}</VBtn>
          </div>
        </VCardText>
        <VCardActions class="pa-4">
          <VBtn variant="tonal" color="primary" @click="copyAll(credentials)">{{ copiedKey === 'all' ? 'Copied all' : 'Copy all' }}</VBtn>
          <VSpacer />
          <VBtn variant="text" @click="credentials = null">Close</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Extend a trial — also the way to unlock a company the expired trial shut out -->
    <VDialog v-model="trialDialog" max-width="520">
      <VCard v-if="trialCompany">
        <VCardTitle>Extend trial — {{ trialCompany.name }}</VCardTitle>
        <VCardText>
          <VAlert v-if="trialCompany.trial_expired" type="warning" variant="tonal" density="compact" class="mb-4">
            This company's trial has ended, so nobody there can sign in. Extending the trial restores
            access straight away. To move them onto a paid subscription instead, use
            <strong>Activate subscription</strong>.
          </VAlert>
          <VAlert v-else type="info" variant="tonal" density="compact" class="mb-4">
            Trial currently ends {{ trialCompany.trial_ends_at || '—' }}.
          </VAlert>

          <VBtnToggle v-model="trialMode" mandatory density="comfortable" variant="outlined" divided class="mb-4">
            <VBtn value="days">Add days</VBtn>
            <VBtn value="date">Pick an end date</VBtn>
          </VBtnToggle>

          <template v-if="trialMode === 'days'">
            <!-- A chip group, not loose chips: a bare VChip is a span, so these presets would be
                 unreachable by keyboard. -->
            <VChipGroup v-model="extendForm.days" mandatory="force" class="mb-3" aria-label="Trial length presets">
              <VChip v-for="d in extendPresets" :key="d" :value="d" size="small">{{ d }} days</VChip>
            </VChipGroup>
            <VTextField v-model.number="extendForm.days" type="number" min="1" max="365" label="Days" variant="outlined" density="comfortable" :error-messages="extendForm.errors.days" />
          </template>
          <VTextField v-else v-model="extendForm.trial_ends_at" type="date" label="Trial ends on" variant="outlined" density="comfortable" :error-messages="extendForm.errors.trial_ends_at" />

          <div class="text-caption text-medium-emphasis mt-1">{{ extendSummary }}</div>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="trialDialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="extendForm.processing" @click="saveExtendTrial">Extend trial</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="convertDialog" max-width="720" scrollable>
      <VCard v-if="convertCompany" :title="`Convert ${convertCompany.name} to a reseller`">
        <VCardText>
          <VAlert type="warning" variant="tonal" class="mb-4">
            <div class="font-weight-medium mb-1">The company stops being a tenant.</div>
            <ul class="ps-4 text-body-2">
              <li>Its profile (name, registration, VAT, contacts, address, website, logo) becomes a new reseller.</li>
              <li>The people you keep move to the reseller portal with their existing logins; they lose access to the company's system.</li>
              <li>The subscription stops now; no more invoices.<span v-if="openInvoices"> {{ openInvoices }} unpaid invoice{{ openInvoices === 1 ? '' : 's' }} stay owed.</span></li>
              <li>Its business data (HR, finance, documents, workflows…) is archived, read only for platform admins, and <strong>permanently deleted</strong> after the retention period.</li>
            </ul>
          </VAlert>
          <VAlert v-for="(msg, key) in convertErrors" :key="key" type="error" variant="tonal" density="compact" class="mb-3">{{ msg }}</VAlert>

          <div class="text-subtitle-2 mb-2">Commission</div>
          <VRow dense>
            <VCol cols="12" sm="6"><VSelect v-model="convertForm.commission_type" :items="[{ title: 'Percentage of each client\'s subscription', value: 'percent' }, { title: 'Fixed amount per client per month', value: 'fixed' }]" label="Type" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model.number="convertForm.commission_value" type="number" min="0" :label="convertForm.commission_type === 'percent' ? 'Percent' : 'Rand per client'" :suffix="convertForm.commission_type === 'percent' ? '%' : undefined" :prefix="convertForm.commission_type === 'fixed' ? 'R' : undefined" /></VCol>
          </VRow>

          <div class="text-subtitle-2 mt-3 mb-1">People</div>
          <div class="text-caption text-medium-emphasis mb-2">Owners manage the reseller's team, commission and profile; staff look after clients. People left behind are switched off and deleted with the company's data.</div>
          <VProgressLinear v-if="loadingCandidates" indeterminate class="mb-2" />
          <VTable v-else density="compact" class="mb-2">
            <thead><tr><th>Person</th><th style="width: 180px">Becomes</th></tr></thead>
            <tbody>
              <tr v-for="u in candidates" :key="u.id">
                <td><div>{{ u.name }} <VChip v-if="!u.active" size="x-small" color="error" variant="tonal" class="ms-1">Inactive</VChip></div><div class="text-caption text-medium-emphasis">{{ u.email }}</div></td>
                <td><VSelect v-model="convertForm.users[u.id]" :items="roleChoices" density="compact" variant="outlined" hide-details /></td>
              </tr>
              <tr v-if="!candidates.length"><td colspan="2" class="text-medium-emphasis">This company has no users.</td></tr>
            </tbody>
          </VTable>
          <div class="text-caption mb-3" :class="hasOwner ? 'text-medium-emphasis' : 'text-error'">{{ moving }} moving · {{ hasOwner ? 'at least one owner chosen' : 'choose at least one owner' }}</div>

          <VRow dense>
            <VCol cols="12" sm="6"><VSelect v-model="convertForm.retention_years" :items="[1, 2, 3, 5, 7, 10].map(y => ({ title: `${y} year${y === 1 ? '' : 's'}`, value: y }))" label="Keep archived data for" hint="South African tax records: at least 5 years" persistent-hint /></VCol>
            <VCol cols="12" sm="6"><VTextarea v-model="convertForm.notes" label="Notes (optional)" rows="1" auto-grow /></VCol>
          </VRow>
          <VTextField v-model="convertForm.confirm_name" class="mt-3" :label="`Type ${convertCompany.name} to confirm`" :error-messages="convertForm.errors.confirm_name" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="convertDialog = false">Cancel</VBtn>
          <VBtn color="warning" variant="flat" :loading="convertForm.processing" :disabled="loadingCandidates || !hasOwner || convertForm.confirm_name.trim().toLowerCase() !== convertCompany.name.trim().toLowerCase()" @click="convert">Convert to reseller</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirm" />
  </div>
</template>
