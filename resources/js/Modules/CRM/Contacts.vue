<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useCrmCall } from '@/composables/useCrmCall'
import { useI18n } from 'vue-i18n'
import ReassignDialog from './components/ReassignDialog.vue'
const route = ziggyRoute
const { t } = useI18n()

const { call, calling } = useCrmCall()
const snack = ref<{ show: boolean; text: string; color: string }>({ show: false, text: '', color: 'success' })

async function callContact(c: Contact) {
  const number = c.phone || c.mobile
  if (!number) { snack.value = { show: true, text: t('crm_contacts_index.no_phone'), color: 'warning' }; return }
  const res = await call({ to: number, name: c.name, related_type: 'contact', related_id: c.id })
  snack.value = { show: true, text: res.message, color: res.success ? 'success' : 'error' }
}

interface Contact {
  id: number; uuid: string; name: string; account: string | null; account_id: number | null
  email: string | null; phone: string | null; mobile: string | null; job_title: string | null; owner: string | null
}

interface Owner { id: number; name: string }

const props = defineProps<{ contacts: Contact[]; accounts: { id: number; name: string }[]; showOwner: boolean; assignableOwners: Owner[] }>()

// Only offered to someone who can see other people's records; a consultant has nobody to hand to.
const selected = ref<number[]>([])
const reassignIds = ref<number[]>([])
const reassignDialog = ref(false)
const canReassign = computed(() => props.showOwner && props.assignableOwners.length > 0)
function openReassign(ids: number[]) { reassignIds.value = ids; reassignDialog.value = true }
function afterReassign() { selected.value = []; router.reload({ only: ['contacts'] }) }

/** Filters the contacts already on the page — person, account, job title, email, phone, rep. */
const search = ref('')
const visibleContacts = computed(() => {
  const term = search.value.trim().toLowerCase()
  if (!term) return props.contacts

  return props.contacts.filter(c =>
    [c.name, c.account, c.job_title, c.email, c.phone, c.mobile, c.owner]
      .some(v => (v ?? '').toString().toLowerCase().includes(term)))
})

const dialog = ref(false)
const confirm = ref()
const form = useForm({ id: null as number | null, uuid: '', account_id: null as number | null, first_name: '', last_name: '', email: '', phone: '', mobile: '', job_title: '', notes: '' })

function openCreate() { form.reset(); form.id = null; dialog.value = true }
function openEdit(c: Contact) {
  const [first, ...rest] = (c.name || '').split(' ')
  form.id = c.id; form.uuid = c.uuid; form.account_id = c.account_id; form.first_name = first ?? ''; form.last_name = rest.join(' ')
  form.email = c.email ?? ''; form.phone = c.phone ?? ''; form.mobile = c.mobile ?? ''; form.job_title = c.job_title ?? ''
  dialog.value = true
}
function save() {
  if (form.id) form.patch(route('crm.contacts.update', form.uuid), { preserveScroll: true, onSuccess: () => dialog.value = false })
  else form.post(route('crm.contacts.store'), { preserveScroll: true, onSuccess: () => dialog.value = false })
}
function remove(c: Contact) {
  confirm.value.open(
    { title: t('crm_contacts_index.delete.title'), message: t('crm_contacts_index.delete.message', { name: c.name }), confirmText: t('crm_contacts_index.delete.confirm'), confirmColor: 'error' },
    () => useForm({}).delete(route('crm.contacts.destroy', c.uuid), { preserveScroll: true }),
  )
}

const headers = computed(() => [
  { title: t('crm_contacts_index.table.name'), key: 'name' },
  { title: t('crm_contacts_index.table.account'), key: 'account' },
  ...(props.showOwner ? [{ title: t('crm_contacts_index.table.sales_rep'), key: 'owner' }] : []),
  { title: t('crm_contacts_index.table.job_title'), key: 'job_title' },
  { title: t('crm_contacts_index.table.email'), key: 'email' },
  { title: t('crm_contacts_index.table.phone'), key: 'phone' },
  { title: '', key: 'actions', sortable: false },
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3">
      <div>
        <h5 class="text-h5 font-weight-semibold">{{ t('crm_contacts_index.page_title') }}</h5>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_contacts_index.description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('crm_contacts_index.new_contact') }}</VBtn>
    </div>

    <VCard>
      <VCardText class="pb-0">
        <VTextField
          v-model="search"
          :placeholder="t('crm_contacts_index.search')"
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
      <VDataTable v-model="selected" :headers="headers" :items="visibleContacts" :items-per-page="25" density="comfortable" :show-select="canReassign" item-value="id">
        <template #item.owner="{ item }">
          <VChip v-if="item.owner" size="small" variant="tonal" prepend-icon="tabler-user">{{ item.owner }}</VChip>
          <span v-else class="text-disabled">—</span>
        </template>
        <template #item.actions="{ item }">
          <VBtn size="x-small" variant="text" icon="tabler-phone" color="success" :loading="calling"
            :disabled="!item.phone && !item.mobile" :title="t('crm_contacts_index.tips.call_contact')" @click="callContact(item)" />
          <VBtn v-if="canReassign" size="x-small" variant="text" icon="tabler-user-share" :title="t('crm_reassign.action')" @click="openReassign([item.id])" />
          <VBtn size="x-small" variant="text" icon="tabler-edit" :title="t('crm_contacts_index.tips.edit_contact')" @click="openEdit(item)" />
          <VBtn size="x-small" variant="text" icon="tabler-trash" color="error" :title="t('crm_contacts_index.tips.delete_contact')" @click="remove(item)" />
        </template>
      </VDataTable>
    </VCard>

    <ReassignDialog v-model="reassignDialog" type="contact" :owners="assignableOwners" :ids="reassignIds" @done="afterReassign" />

    <VDialog v-model="dialog" max-width="520" persistent>
      <VCard>
        <VCardTitle>{{ form.id ? t('crm_contacts_index.dialog.title_edit') : t('crm_contacts_index.dialog.title_create') }}</VCardTitle>
        <VCardText>
          <VRow>
            <VCol cols="6"><VTextField v-model="form.first_name" :label="t('crm_contacts_index.dialog.first_name')" :error-messages="form.errors.first_name" /></VCol>
            <VCol cols="6"><VTextField v-model="form.last_name" :label="t('crm_contacts_index.dialog.last_name')" :error-messages="form.errors.last_name" /></VCol>
            <VCol cols="12"><VSelect v-model="form.account_id" :label="t('crm_contacts_index.dialog.account')" clearable :items="accounts.map(a => ({ title: a.name, value: a.id }))" :error-messages="form.errors.account_id" /></VCol>
            <VCol cols="6"><VTextField v-model="form.job_title" :label="t('crm_contacts_index.dialog.job_title')" :error-messages="form.errors.job_title" /></VCol>
            <VCol cols="6"><VTextField v-model="form.email" :label="t('crm_contacts_index.dialog.email')" :error-messages="form.errors.email" /></VCol>
            <VCol cols="6"><VTextField v-model="form.phone" :label="t('crm_contacts_index.dialog.phone')" :error-messages="form.errors.phone" /></VCol>
            <VCol cols="6"><VTextField v-model="form.mobile" :label="t('crm_contacts_index.dialog.mobile')" :error-messages="form.errors.mobile" /></VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="dialog = false">{{ t('crm_contacts_index.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" @click="save">{{ t('crm_contacts_index.dialog.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirm" />
    <VSnackbar v-model="snack.show" :color="snack.color" location="bottom right" timeout="4000">{{ snack.text }}</VSnackbar>
  </div>
</template>
