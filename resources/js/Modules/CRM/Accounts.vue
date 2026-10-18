<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ReassignDialog from './components/ReassignDialog.vue'
const route = ziggyRoute
const { t } = useI18n()

interface Account {
  id: number; uuid: string; name: string; industry: string | null; website: string | null
  email: string | null; phone: string | null; address: string | null; city: string | null; country: string | null
  notes: string | null; owner_id: number | null
  annual_revenue: number | null; employee_count: number | null; contacts: number; deals: number; owner: string | null
  product_id: number | null; product: string | null
}

const props = defineProps<{
  accounts: Account[]
  products: { id: number; name: string; type: string }[]
  countries: { id: number; name: string }[]
  industries: string[]
  pendingFromAccounting?: number
  showOwner: boolean
  assignableOwners: { id: number; name: string }[]
}>()

// Only offered to someone who can see other people's records; a consultant has nobody to hand to.
const selected = ref<number[]>([])
const reassignIds = ref<number[]>([])
const reassignDialog = ref(false)
const canReassign = computed(() => props.showOwner && props.assignableOwners.length > 0)
function openReassign(ids: number[]) { reassignIds.value = ids; reassignDialog.value = true }
function afterReassign() { selected.value = []; router.reload({ only: ['accounts'] }) }

/**
 * Search. The page already holds every account it's allowed to see, so this filters in place —
 * instant, and it matches what's actually on screen (including Location, which is city+country
 * joined) rather than only the name the server's ?q= filter looks at.
 */
const search = ref('')
const visibleAccounts = computed(() => {
  const term = search.value.trim().toLowerCase()
  if (!term) return props.accounts

  return props.accounts.filter(a =>
    [a.name, a.industry, a.city, a.country, a.email, a.phone, a.owner, a.product]
      .some(v => (v ?? '').toString().toLowerCase().includes(term)))
})

/** Accounting customers not in CRM yet — offered as a one-click pull when there are any. */
const accountingForm = useForm({})
function syncFromAccounting() {
  accountingForm.post(route('crm.accounts.sync-accounting'), { preserveScroll: true })
}

const dialog = ref(false)
const confirm = ref()
const countryId = ref<number | null>(null)
const cityOptions = ref<string[]>([])
/*
 * Everything CrmAccount holds, not a subset.
 *
 * Address and owner were on the record and in the controller's validation, but had no field here —
 * so the only way to change either was the database. That is what stranded VAT numbers inside
 * addresses: CRM owns the address of every synced customer, Accounting shows it read-only, and CRM
 * offered nowhere to edit it. `notes` was in this form object already and likewise never rendered.
 */
const form = useForm({ id: null as number | null, uuid: '', name: '', industry: '', website: '', email: '', phone: '', address: '', city: '', country: '', annual_revenue: null as number | null, employee_count: null as number | null, product_id: null as number | null, owner_id: null as number | null, notes: '' })

async function fetchCities(id: number | null) {
  if (!id) { cityOptions.value = []; return }
  try {
    const res = await fetch(route('crm.locations.cities') + `?country_id=${id}`, { headers: { Accept: 'application/json' } })
    cityOptions.value = res.ok ? await res.json() : []
  } catch { cityOptions.value = [] }
}

function onCountryChange(id: number | null) {
  countryId.value = id
  form.country = props.countries.find(c => c.id === id)?.name ?? ''
  form.city = ''
  fetchCities(id)
}

function openCreate() {
  form.reset(); form.id = null
  countryId.value = null; cityOptions.value = []
  dialog.value = true
}
function openEdit(a: Account) {
  form.id = a.id; form.uuid = a.uuid; form.name = a.name; form.industry = a.industry ?? ''; form.website = a.website ?? ''; form.product_id = a.product_id ?? null
  form.email = a.email ?? ''; form.phone = a.phone ?? ''; form.city = a.city ?? ''; form.country = a.country ?? ''
  form.annual_revenue = a.annual_revenue; form.employee_count = a.employee_count
  form.address = a.address ?? ''; form.owner_id = a.owner_id; form.notes = a.notes ?? ''
  countryId.value = props.countries.find(c => c.name === a.country)?.id ?? null
  fetchCities(countryId.value)
  dialog.value = true
}
function save() {
  if (form.id) form.patch(route('crm.accounts.update', form.uuid), { preserveScroll: true, onSuccess: () => dialog.value = false })
  else form.post(route('crm.accounts.store'), { preserveScroll: true, onSuccess: () => dialog.value = false })
}
function remove(a: Account) {
  confirm.value.open(
    { title: t('crm_accounts_index.delete.title'), message: t('crm_accounts_index.delete.message', { name: a.name }), confirmText: t('crm_accounts_index.delete.confirm'), confirmColor: 'error' },
    () => useForm({}).delete(route('crm.accounts.destroy', a.uuid), { preserveScroll: true }),
  )
}

const headers = computed(()=> [
  { title: t('crm_accounts_index.table.account'), key: 'name' },
  { title: t('crm_accounts_index.table.industry'), key: 'industry' },
  { title: t('crm_accounts_index.table.location'), key: 'city' },
  { title: t('crm_accounts_index.table.contacts'), key: 'contacts' },
  { title: t('crm_accounts_index.table.deals'), key: 'deals' },
  { title: t('crm_accounts_index.table.owner'), key: 'owner' },
  { title: '', key: 'actions', sortable: false },
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3">
      <div>
        <h5 class="text-h5 font-weight-semibold">{{ t('crm_accounts_index.page_title') }}</h5>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_accounts_index.description') }}</p>
      </div>
      <div class="d-flex align-center gap-2">
        <VBtn
          v-if="(props.pendingFromAccounting ?? 0) > 0"
          variant="tonal" color="primary" prepend-icon="tabler-refresh"
          :loading="accountingForm.processing" @click="syncFromAccounting"
        >
          {{ t('crm_accounts_index.sync_accounting', { n: props.pendingFromAccounting }) }}
        </VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('crm_accounts_index.new_account') }}</VBtn>
      </div>
    </div>
    <VAlert
      v-if="(props.pendingFromAccounting ?? 0) > 0"
      type="info" variant="tonal" density="compact" class="mb-4"
    >
      {{ t('crm_accounts_index.sync_accounting_hint', { n: props.pendingFromAccounting }) }}
    </VAlert>

    <VCard>
      <VCardText class="pb-0">
        <VTextField
          v-model="search"
          :placeholder="t('crm_accounts_index.search')"
          prepend-inner-icon="tabler-search"
          density="compact" variant="outlined" hide-details clearable single-line
          style="max-width: 360px"
        />
      </VCardText>
      <div v-if="canReassign && selected.length" class="d-flex align-center gap-2 px-4 pb-2">
        <span class="text-body-2 text-medium-emphasis">{{ t('crm_reassign.selected', selected.length) }}</span>
        <VBtn size="small" variant="tonal" color="primary" prepend-icon="tabler-user-share" @click="openReassign(selected)">{{ t('crm_reassign.action') }}</VBtn>
        <VBtn size="small" variant="text" @click="selected = []">{{ t('crm_reassign.clear') }}</VBtn>
      </div>
      <VDataTable v-model="selected" :headers="headers" :items="visibleAccounts" :items-per-page="25" density="comfortable" :show-select="canReassign" item-value="id">
        <template #item.name="{ item }">
          <a class="text-primary font-weight-medium cursor-pointer text-decoration-none"
            :href="route('crm.accounts.show', item.uuid)">{{ item.name }}</a>
        </template>
        <template #item.city="{ item }">{{ [item.city, item.country].filter(Boolean).join(', ') }}</template>
        <template #item.actions="{ item }">
          <VBtn v-if="canReassign" size="x-small" variant="text" icon="tabler-user-share" :title="t('crm_reassign.action')" @click="openReassign([item.id])" />
          <VBtn size="x-small" variant="text" icon="tabler-edit" :title="t('crm_accounts_index.tips.edit_account')" @click="openEdit(item)" />
          <VBtn size="x-small" variant="text" icon="tabler-trash" color="error" :title="t('crm_accounts_index.tips.delete_account')" @click="remove(item)" />
        </template>
      </VDataTable>
    </VCard>

    <ReassignDialog v-model="reassignDialog" type="account" :owners="assignableOwners" :ids="reassignIds" @done="afterReassign" />

    <VDialog v-model="dialog" max-width="760" persistent scrollable>
      <VCard>
        <VCardTitle>{{ form.id ? t('crm_accounts_index.dialog.title_edit') : t('crm_accounts_index.dialog.title_create') }}</VCardTitle>
        <VCardText>
          <VRow>
            <VCol cols="12"><VTextField v-model="form.name" :label="t('crm_accounts_index.dialog.name')" :error-messages="form.errors.name" /></VCol>
            <VCol cols="6">
              <VCombobox v-model="form.industry" :label="t('crm_accounts_index.dialog.industry')" :items="industries" clearable
                :hint="t('crm_accounts_index.dialog.industry_hint')" :error-messages="form.errors.industry" />
            </VCol>
            <VCol cols="6"><VTextField v-model="form.website" :label="t('crm_accounts_index.dialog.website')" :error-messages="form.errors.website" /></VCol>
            <VCol v-if="products.length" cols="12"><VSelect v-model="form.product_id" label="Product / Service" :items="products.map(p => ({ title: p.name, value: p.id }))" clearable placeholder="— none —" :error-messages="form.errors.product_id" /></VCol>
            <VCol cols="6"><VTextField v-model="form.email" :label="t('crm_accounts_index.dialog.email')" :error-messages="form.errors.email" /></VCol>
            <VCol cols="6"><VTextField v-model="form.phone" :label="t('crm_accounts_index.dialog.phone')" :error-messages="form.errors.phone" /></VCol>
            <VCol cols="6">
              <VAutocomplete
                :model-value="countryId"
                :label="t('crm_accounts_index.dialog.country')"
                :items="countries"
                item-title="name"
                item-value="id"
                clearable
                @update:model-value="onCountryChange"
              />
            </VCol>
            <VCol cols="6">
              <VCombobox v-model="form.city" :label="t('crm_accounts_index.dialog.city')" :items="cityOptions" clearable
                :disabled="!countryId"
                :hint="countryId ? t('crm_accounts_index.dialog.city_hint_pick') : t('crm_accounts_index.dialog.city_hint_country')" persistent-hint :error-messages="form.errors.city" />
            </VCol>
            <VCol cols="6"><VTextField v-model.number="form.annual_revenue" type="number" :label="t('crm_accounts_index.dialog.annual_revenue')" :error-messages="form.errors.annual_revenue" /></VCol>
            <VCol cols="6"><VTextField v-model.number="form.employee_count" type="number" :label="t('crm_accounts_index.dialog.employees')" :error-messages="form.errors.employee_count" /></VCol>
            <!--
              CRM is the source of truth for a synced customer's address, so this is the only place
              it can be corrected — Accounting shows it locked. Keep the VAT number out of it; the
              invoice has a field of its own for that now.
            -->
            <VCol cols="12">
              <VTextarea
                v-model="form.address" :label="t('crm_accounts_index.dialog.address')" rows="2" auto-grow
                :hint="t('crm_accounts_index.dialog.address_hint')" persistent-hint
                :error-messages="form.errors.address"
              />
            </VCol>
            <VCol v-if="showOwner" cols="12">
              <VSelect
                v-model="form.owner_id" :label="t('crm_accounts_index.dialog.owner')"
                :items="assignableOwners.map(o => ({ title: o.name, value: o.id }))"
                clearable :error-messages="form.errors.owner_id"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="form.notes" :label="t('crm_accounts_index.dialog.notes')" rows="2" auto-grow :error-messages="form.errors.notes" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="dialog = false">{{ t('crm_accounts_index.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" @click="save">{{ t('crm_accounts_index.dialog.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirm" />
  </div>
</template>
