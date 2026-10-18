<script lang="ts" setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Suppression { id: number; channel: string; address: string; reason: string; at: string | null }

defineProps<{ suppressions: Suppression[]; count: number }>()

const dialog = ref(false)
const form = useForm({ channel: 'email', address: '', reason: 'manual' })

function add() { form.post(route('crm.suppressions.store'), { preserveScroll: true, onSuccess: () => { dialog.value = false; form.reset() } }) }
function remove(s: Suppression) { useForm({}).delete(route('crm.suppressions.destroy', s.id), { preserveScroll: true }) }

const headers = computed(()=> [
  { title: t('crm_suppressions_index.table.channel'), key: 'channel' }, { title: t('crm_suppressions_index.table.address'), key: 'address' },
  { title: t('crm_suppressions_index.table.reason'), key: 'reason' }, { title: t('crm_suppressions_index.table.date'), key: 'at' },
  { title: '', key: 'actions', sortable: false },
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3"><div><h5 class="text-h5 font-weight-semibold">{{ t('crm_suppressions_index.page_title') }}</h5><p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_suppressions_index.description') }}</p></div><VBtn color="primary" prepend-icon="tabler-plus" @click="dialog = true">{{ t('crm_suppressions_index.add_address') }}</VBtn></div>

    <VCard><VCardText class="text-body-2 text-medium-emphasis">{{ count }} {{ t('crm_suppressions_index.count_label') }}</VCardText>
      <VDataTable :headers="headers" :items="suppressions" :items-per-page="25" density="comfortable">
        <template #item.channel="{ item }"><VChip size="x-small" label class="text-capitalize">{{ item.channel }}</VChip></template>
        <template #item.reason="{ item }"><VChip size="x-small" label :color="item.reason === 'unsubscribed' ? 'warning' : 'blue-grey'">{{ item.reason }}</VChip></template>
        <template #item.actions="{ item }"><VBtn size="x-small" variant="text" icon="tabler-trash" color="error" @click="remove(item)" /></template>
      </VDataTable>
    </VCard>

    <VDialog v-model="dialog" max-width="440" persistent><VCard><VCardTitle>{{ t('crm_suppressions_index.dialog.title') }}</VCardTitle><VCardText>
      <VSelect v-model="form.channel" :label="t('crm_suppressions_index.dialog.channel')" :items="['email', 'whatsapp']" class="mb-3" />
      <VTextField v-model="form.address" :label="form.channel === 'email' ? t('crm_suppressions_index.dialog.address_email') : t('crm_suppressions_index.dialog.address_phone')" class="mb-3" />
      <VSelect v-model="form.reason" :label="t('crm_suppressions_index.dialog.reason')" :items="['manual', 'unsubscribed', 'bounced', 'complained']" />
    </VCardText><VDivider /><VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="dialog = false">{{ t('crm_suppressions_index.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" :disabled="!form.address" @click="add">{{ t('crm_suppressions_index.dialog.add') }}</VBtn></VCardActions></VCard></VDialog>
  </div>
</template>