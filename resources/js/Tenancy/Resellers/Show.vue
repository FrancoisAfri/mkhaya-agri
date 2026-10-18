<script setup lang="ts">
import { ref, watch } from 'vue'
import { useForm, router, Link, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
const route = ziggyRoute
const { t } = useI18n()
const confirm = ref()
const page = usePage()

// Copyable login credentials, shown after a login is created (setLogin flashes them).
const credentials = ref<any>(null)
watch(() => (page.props.flash as any)?.credentials, (c) => { if (c) credentials.value = c }, { immediate: true })
const copiedKey = ref('')
async function copyText(text: string, key: string) {
  let ok = false
  // Clipboard API only exists in a secure context (https / localhost). Fall back to a
  // hidden textarea + execCommand so copy still works over plain http, an IP or a proxy.
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text)
      ok = true
    }
  } catch { /* fall through to legacy path */ }
  if (!ok) {
    try {
      const ta = document.createElement('textarea')
      ta.value = text
      ta.style.position = 'fixed'
      ta.style.opacity = '0'
      document.body.appendChild(ta)
      ta.focus(); ta.select()
      ok = document.execCommand('copy')
      document.body.removeChild(ta)
    } catch { ok = false }
  }
  copiedKey.value = ok ? key : 'fail'
  setTimeout(() => { if (copiedKey.value === key || copiedKey.value === 'fail') copiedKey.value = '' }, 1800)
}
function copyAll(c: any) { copyText(`${t('reseller_admin.f_email')}: ${c.email}\n${t('reseller_admin.password')}: ${c.password}\n${c.login_url}`, 'all') }

interface Product { id: number; name: string; type: string; price: number; currency: string }
interface Client { id: number; name: string; status: string; plan: string; monthly_total: number; created_at: string | null }
interface Commission { id: number; period: string; client: string | null; base_amount: number; commission_amount: number; status: string; paid_at: string | null }
const props = defineProps<{
  reseller: { id: number; uuid: string; name: string; type: string; registration_number: string | null; contact_person: string | null; contact_email: string | null; contact_phone: string | null; commission_type: string; commission_value: number; status: string; notes: string | null; product_ids: number[]; login_email: string | null; vat_number: string | null; converted_from: { name: string; at: string; purge_after: string | null; purged: boolean } | null; team: { name: string; email: string; role: string; active: boolean }[] }
  products: Product[]
  clients: Client[]
  commissions: Commission[]
  totals: { pending: number; paid: number }
  catalog: Product[]
  unassigned: { id: number; name: string }[]
}>()

const money = (n: number) => 'R ' + (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 0, maximumFractionDigits: 2 })

// Edit details / commission
const editing = ref(false)
const edit = useForm({
  name: props.reseller.name, type: props.reseller.type, registration_number: props.reseller.registration_number ?? '',
  contact_person: props.reseller.contact_person ?? '', contact_email: props.reseller.contact_email ?? '',
  contact_phone: props.reseller.contact_phone ?? '', commission_type: props.reseller.commission_type,
  commission_value: props.reseller.commission_value, notes: props.reseller.notes ?? '',
})
function saveEdit() { edit.put(route('tenancy.resellers.update', props.reseller.uuid), { preserveScroll: true, onSuccess: () => editing.value = false }) }

// Products
const productForm = useForm({ product_ids: [...props.reseller.product_ids] })
function saveProducts() { productForm.post(route('tenancy.resellers.products', props.reseller.uuid), { preserveScroll: true }) }

// Assign / unassign clients
const assignForm = useForm({ company_id: null as number | null })
function assign() { assignForm.post(route('tenancy.resellers.clients.assign', props.reseller.uuid), { preserveScroll: true, onSuccess: () => assignForm.reset() }) }
function unassign(c: Client) {
  confirm.value.open(
    { title: t('reseller_admin.unlink'), message: t('reseller_admin.confirm_unlink', { name: c.name }), confirmText: t('reseller_admin.unlink'), confirmColor: 'error', icon: 'tabler-unlink' },
    () => router.delete(route('tenancy.resellers.clients.unassign', props.reseller.uuid), { data: { company_id: c.id }, preserveScroll: true }),
  )
}

// Reseller portal login
const loginDialog = ref(false)
const loginForm = useForm({ email: props.reseller.login_email ?? '', password: '' })
function saveLogin() { loginForm.post(route('tenancy.resellers.login', props.reseller.uuid), { preserveScroll: true, onSuccess: () => { loginDialog.value = false; loginForm.reset('password') } }) }

function markPaid(x: Commission) { router.post(route('tenancy.resellers.commissions.paid', x.id), {}, { preserveScroll: true }) }
function suspend() {
  confirm.value.open(
    { title: t('reseller_admin.suspend'), message: t('reseller_admin.confirm_suspend', { name: props.reseller.name }), confirmText: t('reseller_admin.suspend'), confirmColor: 'error', icon: 'tabler-ban' },
    () => router.post(route('tenancy.resellers.suspend', props.reseller.uuid), {}, { preserveScroll: true }),
  )
}
function activate() { router.post(route('tenancy.resellers.activate', props.reseller.uuid), {}, { preserveScroll: true }) }
function destroy() {
  confirm.value.open(
    { title: t('reseller_admin.delete'), message: t('reseller_admin.confirm_delete', { name: props.reseller.name }), confirmText: t('reseller_admin.delete'), confirmColor: 'error', icon: 'tabler-trash' },
    () => router.delete(route('tenancy.resellers.destroy', props.reseller.uuid)),
  )
}

const clientHeaders = [
  { title: t('reseller_admin.col_client'), key: 'name' },
  { title: t('reseller_admin.col_plan'), key: 'plan' },
  { title: t('reseller_admin.col_mrr'), key: 'monthly_total', align: 'end' as const },
  { title: t('reseller_admin.col_status'), key: 'status' },
  { title: '', key: 'actions', align: 'end' as const, sortable: false },
]
const commHeaders = [
  { title: t('reseller_admin.col_period'), key: 'period' },
  { title: t('reseller_admin.col_client'), key: 'client' },
  { title: t('reseller_admin.col_base'), key: 'base_amount', align: 'end' as const },
  { title: t('reseller_admin.col_commission'), key: 'commission_amount', align: 'end' as const },
  { title: t('reseller_admin.col_status'), key: 'status' },
  { title: '', key: 'actions', align: 'end' as const, sortable: false },
]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center ga-2 mb-1">
      <Link :href="route('tenancy.resellers.index')"><VBtn size="small" variant="text" prepend-icon="tabler-arrow-left">{{ t('reseller_admin.back') }}</VBtn></Link>
    </div>
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div class="d-flex align-center ga-3">
        <h1 class="text-h5 font-weight-bold mb-0">{{ reseller.name }}</h1>
        <VChip size="small" :color="reseller.status === 'active' ? 'success' : 'error'" variant="tonal">{{ t('reseller_admin.status_' + reseller.status) }}</VChip>
      </div>
      <div class="d-flex ga-2">
        <VBtn variant="tonal" prepend-icon="tabler-edit" @click="editing = true">{{ t('reseller_admin.edit') }}</VBtn>
        <VBtn v-if="reseller.status === 'active'" variant="tonal" color="error" prepend-icon="tabler-ban" @click="suspend">{{ t('reseller_admin.suspend') }}</VBtn>
        <VBtn v-else variant="tonal" color="success" prepend-icon="tabler-circle-check" @click="activate">{{ t('reseller_admin.activate') }}</VBtn>
        <VBtn variant="text" color="error" icon="tabler-trash" @click="destroy" />
      </div>
    </div>

    <VAlert v-if="reseller.converted_from" type="info" variant="tonal" density="compact" class="mb-4">
      Converted from the tenant <strong>{{ reseller.converted_from.name }}</strong> on {{ reseller.converted_from.at }}.
      <span v-if="reseller.converted_from.purged">Its archived data has been deleted.</span>
      <span v-else-if="reseller.converted_from.purge_after">Its archived data is deleted on {{ reseller.converted_from.purge_after }}.</span>
    </VAlert>
    <VRow>
      <!-- Summary tiles -->
      <VCol cols="12" md="4"><VCard elevation="0" border rounded="lg"><VCardText class="text-center py-4"><div class="text-caption text-medium-emphasis">{{ t('reseller_admin.col_clients') }}</div><div class="text-h5 font-weight-bold">{{ clients.length }}</div></VCardText></VCard></VCol>
      <VCol cols="12" md="4"><VCard elevation="0" border rounded="lg"><VCardText class="text-center py-4"><div class="text-caption text-medium-emphasis">{{ t('reseller_admin.pending_commission') }}</div><div class="text-h5 font-weight-bold text-warning">{{ money(totals.pending) }}</div></VCardText></VCard></VCol>
      <VCol cols="12" md="4"><VCard elevation="0" border rounded="lg"><VCardText class="text-center py-4"><div class="text-caption text-medium-emphasis">{{ t('reseller_admin.paid_commission') }}</div><div class="text-h5 font-weight-bold text-success">{{ money(totals.paid) }}</div></VCardText></VCard></VCol>
    </VRow>

    <VRow class="mt-1">
      <!-- Details -->
      <VCol cols="12" md="6">
        <VCard elevation="0" border rounded="lg" class="h-100">
          <VCardTitle class="text-subtitle-1 font-weight-semibold">{{ t('reseller_admin.details') }}</VCardTitle>
          <VCardText>
            <div class="d-flex justify-space-between py-1"><span class="text-medium-emphasis">{{ t('reseller_admin.f_contact') }}</span><span>{{ reseller.contact_person || '—' }}</span></div>
            <div class="d-flex justify-space-between py-1"><span class="text-medium-emphasis">{{ t('reseller_admin.f_email') }}</span><span>{{ reseller.contact_email || '—' }}</span></div>
            <div class="d-flex justify-space-between py-1"><span class="text-medium-emphasis">{{ t('reseller_admin.f_phone') }}</span><span>{{ reseller.contact_phone || '—' }}</span></div>
            <div class="d-flex justify-space-between py-1"><span class="text-medium-emphasis">{{ t('reseller_admin.commission') }}</span><span class="font-weight-medium">{{ reseller.commission_type === 'percent' ? reseller.commission_value + '%' : money(reseller.commission_value) }} {{ t('reseller_admin.per_month') }}</span></div>
            <div class="d-flex justify-space-between align-center py-1">
              <span class="text-medium-emphasis">{{ t('reseller_admin.portal_login') }}</span>
              <span class="d-flex align-center ga-2">
                <span v-if="reseller.login_email" class="text-body-2">{{ reseller.login_email }}</span>
                <span v-else class="text-caption text-medium-emphasis">{{ t('reseller_admin.no_login') }}</span>
                <VBtn size="x-small" variant="tonal" @click="loginDialog = true">{{ reseller.login_email ? t('reseller_admin.change_login') : t('reseller_admin.create_login') }}</VBtn>
              </span>
            </div>
            <div v-if="reseller.team.length" class="mt-3">
              <div class="text-caption text-medium-emphasis mb-1">Team ({{ reseller.team.length }})</div>
              <div v-for="(m, i) in reseller.team" :key="i" class="d-flex justify-space-between align-center py-1 text-body-2">
                <span>{{ m.name }} <span class="text-caption text-medium-emphasis">{{ m.email }}</span></span>
                <span><VChip v-if="!m.active" size="x-small" color="error" variant="tonal" class="me-1">Inactive</VChip><VChip size="x-small" :color="m.role === 'owner' ? 'primary' : undefined" variant="tonal" class="text-capitalize">{{ m.role }}</VChip></span>
              </div>
            </div>
            <p v-if="reseller.notes" class="text-body-2 text-medium-emphasis mt-2 mb-0">{{ reseller.notes }}</p>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Products the reseller may sell -->
      <VCol cols="12" md="6">
        <VCard elevation="0" border rounded="lg" class="h-100">
          <VCardTitle class="text-subtitle-1 font-weight-semibold">{{ t('reseller_admin.products') }}</VCardTitle>
          <VCardText>
            <VSelect v-model="productForm.product_ids" :items="catalog" item-title="name" item-value="id" multiple chips closable-chips :label="t('reseller_admin.products_hint')" density="comfortable" :no-data-text="t('reseller_admin.no_catalog')" />
            <VBtn size="small" color="primary" variant="tonal" :loading="productForm.processing" @click="saveProducts">{{ t('reseller_admin.save_products') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Clients -->
    <VCard elevation="0" border rounded="lg" class="mt-4">
      <VCardTitle class="text-subtitle-1 font-weight-semibold d-flex align-center justify-space-between flex-wrap ga-2">
        <span>{{ t('reseller_admin.clients') }}</span>
        <div class="d-flex align-center ga-2" style="min-width: 320px">
          <VSelect v-model="assignForm.company_id" :items="unassigned" item-title="name" item-value="id" :label="t('reseller_admin.link_client')" density="compact" hide-details style="max-width: 260px" />
          <VBtn size="small" color="primary" :disabled="!assignForm.company_id" :loading="assignForm.processing" @click="assign">{{ t('reseller_admin.link') }}</VBtn>
        </div>
      </VCardTitle>
      <VDataTable :headers="clientHeaders" :items="clients" :items-per-page="10" density="comfortable">
        <template #item.monthly_total="{ item }">{{ money(item.monthly_total) }}</template>
        <template #item.status="{ item }"><VChip size="x-small" :color="item.status === 'active' ? 'success' : item.status === 'trial' ? 'info' : 'error'" variant="tonal">{{ item.status }}</VChip></template>
        <template #item.actions="{ item }"><VBtn size="small" variant="text" color="error" icon="tabler-unlink" @click="unassign(item)" /></template>
        <template #no-data><div class="text-center py-6 text-medium-emphasis">{{ t('reseller_admin.no_clients') }}</div></template>
      </VDataTable>
    </VCard>

    <!-- Commission ledger -->
    <VCard elevation="0" border rounded="lg" class="mt-4">
      <VCardTitle class="text-subtitle-1 font-weight-semibold">{{ t('reseller_admin.commission_ledger') }}</VCardTitle>
      <VDataTable :headers="commHeaders" :items="commissions" :items-per-page="15" density="comfortable">
        <template #item.base_amount="{ item }">{{ money(item.base_amount) }}</template>
        <template #item.commission_amount="{ item }"><span class="font-weight-medium">{{ money(item.commission_amount) }}</span></template>
        <template #item.status="{ item }"><VChip size="x-small" :color="item.status === 'paid' ? 'success' : item.status === 'void' ? 'default' : 'warning'" variant="tonal">{{ t('reseller_admin.status_' + item.status) }}</VChip></template>
        <template #item.actions="{ item }"><VBtn v-if="item.status === 'pending'" size="small" variant="tonal" color="success" @click="markPaid(item)">{{ t('reseller_admin.mark_paid') }}</VBtn></template>
        <template #no-data><div class="text-center py-6 text-medium-emphasis">{{ t('reseller_admin.no_commissions') }}</div></template>
      </VDataTable>
    </VCard>

    <!-- Edit dialog -->
    <VDialog v-model="editing" max-width="560">
      <VCard rounded="lg">
        <VCardTitle class="text-h6 pa-4 pb-2">{{ t('reseller_admin.edit') }}</VCardTitle>
        <VCardText>
          <VBtnToggle v-model="edit.type" mandatory density="comfortable" color="primary" variant="outlined" class="mb-3">
            <VBtn value="individual" size="small">{{ t('reseller_admin.type_individual') }}</VBtn>
            <VBtn value="company" size="small">{{ t('reseller_admin.type_company') }}</VBtn>
          </VBtnToggle>
          <VTextField v-model="edit.name" :label="edit.type === 'company' ? t('reseller_admin.f_company_name') : t('reseller_admin.f_name')" :error-messages="edit.errors.name" density="comfortable" class="mb-2" />
          <VTextField v-if="edit.type === 'company'" v-model="edit.registration_number" :label="t('reseller_admin.f_reg')" density="comfortable" class="mb-2" />
          <VRow dense>
            <VCol cols="12" sm="6"><VTextField v-model="edit.contact_person" :label="t('reseller_admin.f_contact')" density="comfortable" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="edit.contact_phone" :label="t('reseller_admin.f_phone')" density="comfortable" /></VCol>
          </VRow>
          <VTextField v-model="edit.contact_email" :label="t('reseller_admin.f_email')" :error-messages="edit.errors.contact_email" density="comfortable" class="mb-2" />
          <VRow dense>
            <VCol cols="12" sm="6"><VSelect v-model="edit.commission_type" :items="[{ title: t('reseller_admin.percent'), value: 'percent' }, { title: t('reseller_admin.fixed'), value: 'fixed' }]" :label="t('reseller_admin.f_type')" density="comfortable" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model.number="edit.commission_value" type="number" min="0" step="0.01" :label="t('reseller_admin.f_value')" :suffix="edit.commission_type === 'percent' ? '%' : 'R'" density="comfortable" /></VCol>
          </VRow>
          <VTextarea v-model="edit.notes" :label="t('reseller_admin.f_notes')" rows="2" auto-grow density="comfortable" />
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="editing = false">{{ t('reseller_admin.cancel') }}</VBtn>
          <VBtn color="primary" :loading="edit.processing" @click="saveEdit">{{ t('reseller_admin.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Reseller portal login -->
    <VDialog v-model="loginDialog" max-width="480">
      <VCard rounded="lg">
        <VCardTitle class="text-h6 pa-4 pb-1">{{ reseller.login_email ? t('reseller_admin.change_login') : t('reseller_admin.create_login') }}</VCardTitle>
        <VCardSubtitle class="px-4 pb-2">{{ t('reseller_admin.login_hint') }}</VCardSubtitle>
        <VCardText>
          <VTextField v-model="loginForm.email" type="email" :label="t('reseller_admin.f_email')" :error-messages="loginForm.errors.email" density="comfortable" class="mb-2" />
          <VTextField v-model="loginForm.password" type="text" :label="reseller.login_email ? t('reseller_admin.new_password_optional') : t('reseller_admin.password')" :error-messages="loginForm.errors.password" density="comfortable" />
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="loginDialog = false">{{ t('reseller_admin.cancel') }}</VBtn>
          <VBtn color="primary" :loading="loginForm.processing" :disabled="!loginForm.email" @click="saveLogin">{{ t('reseller_admin.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Copyable login credentials (after creating a reseller login) -->
    <VDialog :model-value="!!credentials" max-width="480" @update:model-value="v => { if (!v) credentials = null }">
      <VCard v-if="credentials" rounded="lg">
        <VCardTitle class="text-h6 pa-4 pb-2">{{ credentials.title }}</VCardTitle>
        <VCardText>
          <VAlert :type="credentials.emailed ? 'success' : 'warning'" variant="tonal" density="compact" class="mb-4">
            {{ credentials.emailed ? t('reseller_admin.creds_emailed') : t('reseller_admin.creds_not_emailed') }}
          </VAlert>
          <div class="d-flex align-center mb-2">
            <div class="flex-grow-1"><div class="text-caption text-medium-emphasis">{{ t('reseller_admin.login_url') }}</div><div style="user-select: all">{{ credentials.login_url }}</div></div>
            <VBtn size="small" variant="text" @click="copyText(credentials.login_url, 'url')">{{ copiedKey === 'url' ? t('reseller_admin.copied') : t('reseller_admin.copy') }}</VBtn>
          </div>
          <div class="d-flex align-center mb-2">
            <div class="flex-grow-1"><div class="text-caption text-medium-emphasis">{{ t('reseller_admin.f_email') }}</div><div style="user-select: all">{{ credentials.email }}</div></div>
            <VBtn size="small" variant="text" @click="copyText(credentials.email, 'email')">{{ copiedKey === 'email' ? t('reseller_admin.copied') : t('reseller_admin.copy') }}</VBtn>
          </div>
          <div class="d-flex align-center">
            <div class="flex-grow-1"><div class="text-caption text-medium-emphasis">{{ t('reseller_admin.password') }}</div><div style="font-family: monospace; user-select: all">{{ credentials.password }}</div></div>
            <VBtn size="small" variant="text" @click="copyText(credentials.password, 'pw')">{{ copiedKey === 'pw' ? t('reseller_admin.copied') : t('reseller_admin.copy') }}</VBtn>
          </div>
          <div v-if="copiedKey === 'fail'" class="text-caption text-error mt-2">{{ t('reseller_admin.copy_failed') }}</div>
        </VCardText>
        <VCardActions class="pa-4">
          <VBtn variant="tonal" color="primary" @click="copyAll(credentials)">{{ copiedKey === 'all' ? t('reseller_admin.copied_all') : t('reseller_admin.copy_all') }}</VBtn>
          <VSpacer />
          <VBtn variant="text" @click="credentials = null">{{ t('reseller_admin.close') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirm" />
  </div>
</template>
