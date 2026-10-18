<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router, Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
const route = ziggyRoute
const { t } = useI18n()
const confirm = ref()

interface Reseller {
  id: number; uuid: string; name: string; type: string; contact_person: string | null; contact_email: string | null
  contact_phone: string | null; commission_type: string; commission_value: number; status: string
  clients: number; pending_total: number; paid_total: number
}
const props = defineProps<{ resellers: Reseller[] }>()

const money = (n: number) => 'R ' + (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 0, maximumFractionDigits: 2 })
const commissionLabel = (r: Reseller) =>
  r.commission_type === 'percent' ? `${r.commission_value}%` : money(r.commission_value)

const dialog = ref(false)
const form = useForm({ name: '', type: 'individual', registration_number: '', contact_person: '', contact_email: '', contact_phone: '', commission_type: 'percent', commission_value: 0, notes: '' })
function submit() {
  form.post(route('tenancy.resellers.store'), { preserveScroll: true, onSuccess: () => { dialog.value = false; form.reset() } })
}
function suspend(r: Reseller) {
  confirm.value.open(
    { title: t('reseller_admin.suspend'), message: t('reseller_admin.confirm_suspend', { name: r.name }), confirmText: t('reseller_admin.suspend'), confirmColor: 'error', icon: 'tabler-ban' },
    () => router.post(route('tenancy.resellers.suspend', r.uuid), {}, { preserveScroll: true }),
  )
}
function activate(r: Reseller) { router.post(route('tenancy.resellers.activate', r.uuid), {}, { preserveScroll: true }) }

const headers = [
  { title: t('reseller_admin.col_name'), key: 'name' },
  { title: t('reseller_admin.col_commission'), key: 'commission' },
  { title: t('reseller_admin.col_clients'), key: 'clients', align: 'end' as const },
  { title: t('reseller_admin.col_pending'), key: 'pending_total', align: 'end' as const },
  { title: t('reseller_admin.col_paid'), key: 'paid_total', align: 'end' as const },
  { title: t('reseller_admin.col_status'), key: 'status' },
  { title: '', key: 'actions', align: 'end' as const, sortable: false },
]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('reseller_admin.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('reseller_admin.subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="dialog = true">{{ t('reseller_admin.add') }}</VBtn>
    </div>

    <VCard elevation="0" border rounded="lg">
      <VDataTable :headers="headers" :items="props.resellers" :items-per-page="25" density="comfortable">
        <template #item.name="{ item }">
          <Link :href="route('tenancy.resellers.show', item.uuid)" class="font-weight-medium text-decoration-none text-primary">{{ item.name }}</Link>
          <div class="text-caption text-medium-emphasis">
            <VChip size="x-small" variant="tonal" :color="item.type === 'company' ? 'primary' : undefined" class="me-1">{{ t('reseller_admin.type_' + item.type) }}</VChip>
            {{ item.contact_email || item.contact_person || '—' }}
          </div>
        </template>
        <template #item.commission="{ item }">{{ commissionLabel(item) }}</template>
        <template #item.clients="{ item }">{{ item.clients }}</template>
        <template #item.pending_total="{ item }">{{ money(item.pending_total) }}</template>
        <template #item.paid_total="{ item }">{{ money(item.paid_total) }}</template>
        <template #item.status="{ item }">
          <VChip size="small" :color="item.status === 'active' ? 'success' : 'error'" variant="tonal">{{ t('reseller_admin.status_' + item.status) }}</VChip>
        </template>
        <template #item.actions="{ item }">
          <Link :href="route('tenancy.resellers.show', item.uuid)"><VBtn size="small" variant="text" icon="tabler-eye" /></Link>
          <VBtn v-if="item.status === 'active'" size="small" variant="text" color="error" icon="tabler-ban" @click="suspend(item)" />
          <VBtn v-else size="small" variant="text" color="success" icon="tabler-circle-check" @click="activate(item)" />
        </template>
        <template #no-data><div class="text-center py-8 text-medium-emphasis">{{ t('reseller_admin.empty') }}</div></template>
      </VDataTable>
    </VCard>

    <!-- Add reseller -->
    <VDialog v-model="dialog" max-width="560">
      <VCard rounded="lg">
        <VCardTitle class="text-h6 pa-4 pb-2">{{ t('reseller_admin.add') }}</VCardTitle>
        <VCardText>
          <VBtnToggle v-model="form.type" mandatory density="comfortable" color="primary" variant="outlined" class="mb-3">
            <VBtn value="individual" size="small">{{ t('reseller_admin.type_individual') }}</VBtn>
            <VBtn value="company" size="small">{{ t('reseller_admin.type_company') }}</VBtn>
          </VBtnToggle>
          <VTextField v-model="form.name" :label="form.type === 'company' ? t('reseller_admin.f_company_name') : t('reseller_admin.f_name')" :error-messages="form.errors.name" density="comfortable" class="mb-2" />
          <VTextField v-if="form.type === 'company'" v-model="form.registration_number" :label="t('reseller_admin.f_reg')" density="comfortable" class="mb-2" />
          <VRow dense>
            <VCol cols="12" sm="6"><VTextField v-model="form.contact_person" :label="t('reseller_admin.f_contact')" density="comfortable" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="form.contact_phone" :label="t('reseller_admin.f_phone')" density="comfortable" /></VCol>
          </VRow>
          <VTextField v-model="form.contact_email" :label="t('reseller_admin.f_email')" :error-messages="form.errors.contact_email" density="comfortable" class="mb-2" />
          <VRow dense>
            <VCol cols="12" sm="6"><VSelect v-model="form.commission_type" :items="[{ title: t('reseller_admin.percent'), value: 'percent' }, { title: t('reseller_admin.fixed'), value: 'fixed' }]" :label="t('reseller_admin.f_type')" density="comfortable" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model.number="form.commission_value" type="number" min="0" step="0.01" :label="t('reseller_admin.f_value')" :suffix="form.commission_type === 'percent' ? '%' : 'R'" :error-messages="form.errors.commission_value" density="comfortable" /></VCol>
          </VRow>
          <VTextarea v-model="form.notes" :label="t('reseller_admin.f_notes')" rows="2" auto-grow density="comfortable" />
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('reseller_admin.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!form.name" @click="submit">{{ t('reseller_admin.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirm" />
  </div>
</template>
