<script lang="ts" setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Claim { number: string; date: string; total: number; outstanding: number; status: string }
interface Contract { id: number; name: string; party: string; party_id: number; income_account_id: number; contract_value: number; retention_percent: number; billed_net: number; remaining: number; retention_held: number; status: string; description: string | null; claims: Claim[] }
interface Option { id: number; label: string }
const props = defineProps<{ contracts: Contract[]; customers: { id: number; name: string }[]; incomeAccounts: Option[] }>()

const money = (n: number) => (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const statusColor: Record<string, string> = { active: 'success', completed: 'default', open: 'info', part_paid: 'warning', paid: 'success' }
const confirm = ref()

// Contract create/edit
const dialog = ref(false)
const editingId = ref<number | null>(null)
const form = useForm<any>({ party_id: null, income_account_id: props.incomeAccounts[0]?.id ?? null, name: '', contract_value: null, retention_percent: 10, status: 'active', description: '' })
function openNew() { editingId.value = null; form.reset(); form.income_account_id = props.incomeAccounts[0]?.id ?? null; form.retention_percent = 10; dialog.value = true }
function openEdit(c: Contract) {
  editingId.value = c.id
  Object.assign(form, { party_id: c.party_id, income_account_id: c.income_account_id, name: c.name, contract_value: c.contract_value, retention_percent: c.retention_percent, status: c.status, description: c.description ?? '' })
  dialog.value = true
}
function save() {
  const opts = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  editingId.value ? form.patch(route('finance.accounting.contracts.update', editingId.value), opts) : form.post(route('finance.accounting.contracts.store'), opts)
}

// Progress claim
const claimDialog = ref(false)
const claimContract = ref<Contract | null>(null)
const claimForm = useForm({ net: null as number | null, tax_rate: 15, description: t('acc.progress_claim_title') })
function openClaim(c: Contract) { claimContract.value = c; claimForm.reset(); claimForm.net = c.remaining; claimDialog.value = true }
function saveClaim() { claimForm.post(route('finance.accounting.contracts.claim', claimContract.value!.id), { preserveScroll: true, onSuccess: () => { claimDialog.value = false } }) }

function release(c: Contract) {
  confirm.value.open(
    { title: t('acc.release_retention_title'), message: t('acc.release_retention_confirm', { amount: money(c.retention_held), name: c.name }), confirmText: t('acc.release'), confirmColor: 'primary', icon: 'tabler-lock-open' },
    () => useForm({}).post(route('finance.accounting.contracts.release', c.id), { preserveScroll: true }),
  )
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.crumb_accounting') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.contracts_word') }}</h2>
        <div class="text-caption text-medium-emphasis">{{ t('acc.contracts_sub') }}</div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">{{ t('acc.new_contract') }}</VBtn>
    </div>

    <VAlert v-if="(claimForm.errors as any).contract" type="error" variant="tonal" density="compact" class="mb-3">{{ (claimForm.errors as any).contract }}</VAlert>

    <VExpansionPanels variant="accordion">
      <VExpansionPanel v-for="c in contracts" :key="c.id">
        <VExpansionPanelTitle>
          <div class="d-flex align-center justify-space-between" style="width: 100%">
            <div>
              <span class="font-weight-medium">{{ c.name }}</span>
              <span class="text-caption text-medium-emphasis ms-2">{{ c.party }}</span>
              <VChip size="x-small" :color="statusColor[c.status]" variant="tonal" class="ms-2 text-capitalize">{{ c.status }}</VChip>
            </div>
            <div class="d-flex gap-4 me-3 text-caption">
              <span>{{ t('acc.value_label') }} <strong>{{ money(c.contract_value) }}</strong></span>
              <span>{{ t('acc.billed') }} <strong>{{ money(c.billed_net) }}</strong></span>
              <span>{{ t('acc.retention_held_label') }} <strong class="text-warning">{{ money(c.retention_held) }}</strong></span>
            </div>
          </div>
        </VExpansionPanelTitle>
        <VExpansionPanelText>
          <div class="d-flex gap-2 mb-3 flex-wrap">
            <VBtn size="small" color="primary" variant="tonal" :disabled="c.remaining <= 0" @click="openClaim(c)">{{ t('acc.progress_claim_left', { amount: money(c.remaining) }) }}</VBtn>
            <VBtn size="small" variant="tonal" :disabled="c.retention_held <= 0" @click="release(c)">{{ t('acc.release_retention') }}</VBtn>
            <VBtn size="small" variant="text" @click="openEdit(c)">{{ t('acc.edit') }}</VBtn>
          </div>
          <VTable v-if="c.claims.length" density="compact">
            <thead><tr><th>{{ t('acc.document') }}</th><th>{{ t('acc.date') }}</th><th class="text-right">{{ t('acc.amount') }}</th><th class="text-right">{{ t('acc.outstanding') }}</th><th>{{ t('acc.status') }}</th></tr></thead>
            <tbody>
              <tr v-for="(cl, i) in c.claims" :key="i">
                <td class="font-weight-medium">{{ cl.number }}</td>
                <td class="text-caption">{{ cl.date }}</td>
                <td class="text-right">{{ money(cl.total) }}</td>
                <td class="text-right">{{ money(cl.outstanding) }}</td>
                <td><VChip size="x-small" :color="statusColor[cl.status]" variant="tonal" class="text-capitalize">{{ cl.status.replace('_', ' ') }}</VChip></td>
              </tr>
            </tbody>
          </VTable>
          <div v-else class="text-caption text-medium-emphasis">{{ t('acc.no_claims') }}</div>
        </VExpansionPanelText>
      </VExpansionPanel>
    </VExpansionPanels>
    <VCard v-if="!contracts.length" border><VCardText class="text-center text-medium-emphasis py-6">{{ t('acc.no_contracts') }}</VCardText></VCard>

    <!-- Contract dialog -->
    <VDialog v-model="dialog" max-width="640" persistent>
      <VCard>
        <VCardTitle>{{ editingId ? t('acc.edit_contract') : t('acc.new_contract_dlg') }}</VCardTitle>
        <VCardText>
          <VTextField v-model="form.name" :label="t('acc.contract_name')" variant="outlined" density="comfortable" class="mb-3" :error-messages="form.errors.name" />
          <div class="d-flex gap-3 mb-3">
            <VAutocomplete v-model="form.party_id" :items="customers.map(c => ({ title: c.name, value: c.id }))" :label="t('acc.customer')" variant="outlined" density="comfortable" :error-messages="(form.errors as any).party_id" />
            <VAutocomplete v-model="form.income_account_id" :items="incomeAccounts.map(a => ({ title: a.label, value: a.id }))" :label="t('acc.income_account')" variant="outlined" density="comfortable" />
          </div>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model.number="form.contract_value" type="number" :label="t('acc.contract_value')" variant="outlined" density="comfortable" :error-messages="form.errors.contract_value" />
            <VTextField v-model.number="form.retention_percent" type="number" :label="t('acc.retention_pct')" variant="outlined" density="comfortable" style="max-width: 160px" suffix="%" />
            <VSelect v-model="form.status" :items="[{ title: t('acc.active'), value: 'active' }, { title: t('acc.completed'), value: 'completed' }]" :label="t('acc.status')" variant="outlined" density="comfortable" style="max-width: 160px" />
          </div>
          <VTextarea v-model="form.description" :label="t('acc.notes')" variant="outlined" density="comfortable" rows="2" />
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" :disabled="!form.name || !form.party_id || !form.contract_value" @click="save">{{ editingId ? t('acc.save') : t('acc.create') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Progress claim dialog -->
    <VDialog v-model="claimDialog" max-width="480">
      <VCard>
        <VCardTitle>{{ t('acc.progress_claim_title') }} — {{ claimContract?.name }}</VCardTitle>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-3">{{ t('acc.remaining_to_bill') }} <strong>{{ money(claimContract?.remaining || 0) }}</strong> · {{ t('acc.retention_pct') }} {{ claimContract?.retention_percent }}% {{ t('acc.retention_held_back') }}</p>
          <div class="d-flex gap-3">
            <VTextField v-model.number="claimForm.net" type="number" :label="t('acc.claim_net')" variant="outlined" density="comfortable" />
            <VTextField v-model.number="claimForm.tax_rate" type="number" :label="t('acc.tax_pct')" variant="outlined" density="comfortable" style="max-width: 120px" suffix="%" />
          </div>
          <VTextField v-model="claimForm.description" :label="t('acc.description')" variant="outlined" density="comfortable" class="mt-3" />
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="claimDialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="claimForm.processing" :disabled="!claimForm.net" @click="saveClaim">{{ t('acc.raise_claim') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirm" />
  </div>
</template>
