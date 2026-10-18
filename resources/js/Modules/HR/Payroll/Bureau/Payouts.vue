<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()
const u = (key: string, a?: Record<string, unknown>) => t('bureau.ui.' + key, a ?? {})
const k = (key: string, a?: Record<string, unknown>) => t('bureau.pay.' + key, a ?? {})
type Intg = { uuid: string; provider: string; label: string; base_url: string | null; sandbox: boolean; active: boolean; configured: boolean; last_status: string | null; last_message: string | null }
const props = defineProps<{ company: { uuid: string; name: string }; providers: string[]; integrations: Intg[]; disbursements: any[] }>()

const dialog = ref(false)
const form = useForm({ provider: 'stitch', base_url: '', api_key: '', api_secret: '', sandbox: true, label: '' })
function save() { form.post(route('payroll.bureau.payouts.connect', props.company.uuid), { onSuccess: () => { dialog.value = false; form.reset() } }) }
function toggle(i: Intg) { router.post(route('payroll.bureau.payouts.toggle', [props.company.uuid, i.uuid]), {}, { preserveScroll: true }) }
function test(i: Intg) { router.post(route('payroll.bureau.payouts.test', [props.company.uuid, i.uuid]), {}, { preserveScroll: true }) }
function refresh(d: any) { router.post(route('payroll.bureau.payouts.refresh', [props.company.uuid, d.uuid]), {}, { preserveScroll: true }) }
const rand = (n: number) => 'R ' + Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2 })
</script>

<template>
  <div class="pa-4" style="max-width:920px;margin:auto">
    <div class="d-flex align-center gap-3 mb-4">
      <VBtn icon="tabler-arrow-left" aria-label="Back to the client" class="row-action" variant="text" @click="router.get(route('payroll.bureau.companies.show', company.uuid))" />
      <div class="flex-grow-1"><h4 class="text-h4 mb-0">Real-time payouts — {{ company.name }}</h4></div>
      <VBtn color="primary" prepend-icon="tabler-plug" @click="dialog = true">{{ k('connect_provider') }}</VBtn>
    </div>

    <VAlert type="info" variant="tonal" class="mb-4" density="comfortable">
      Connect the client's payout provider (Stitch / Ozow / Peach). With one active + tested, a finalised pay run can be
      disbursed straight to employees' accounts. Use sandbox until you've confirmed the connection. Credentials are stored encrypted.
    </VAlert>

    <VCard border class="mb-4">
      <VCardTitle class="text-subtitle-1">{{ k('providers') }}</VCardTitle>
      <VList>
        <VListItem v-for="i in integrations" :key="i.uuid">
          <VListItemTitle class="font-weight-medium">
            {{ i.label }}
            <VChip v-if="i.active" size="x-small" color="success" variant="tonal" class="ms-1">{{ u('active') }}</VChip>
            <VChip v-else-if="i.configured" size="x-small" variant="tonal" class="ms-1">{{ k('configured') }}</VChip>
            <VChip v-if="i.sandbox" size="x-small" color="warning" variant="tonal" class="ms-1">{{ k('sandbox') }}</VChip>
          </VListItemTitle>
          <VListItemSubtitle v-if="i.last_message">{{ i.last_message }}</VListItemSubtitle>
          <template #append>
            <VBtn v-if="i.configured" size="small" variant="text" @click="test(i)">{{ k('test') }}</VBtn>
            <VBtn v-if="i.configured" size="small" variant="text" :color="i.active ? 'error' : 'success'" @click="toggle(i)">{{ i.active ? 'Disable' : 'Enable' }}</VBtn>
          </template>
        </VListItem>
        <VListItem v-if="!integrations.length"><VListItemTitle class="text-medium-emphasis py-2">{{ k('no_providers') }}</VListItemTitle></VListItem>
      </VList>
    </VCard>

    <VCard border>
      <VCardTitle class="text-subtitle-1">{{ k('recent_disbursements') }}</VCardTitle>
      <VTable>
        <thead><tr><th>{{ u('reference') }}</th><th>{{ u('provider') }}</th><th>{{ u('status') }}</th><th class="text-end">{{ k('count') }}</th><th class="text-end">{{ u('total') }}</th><th>{{ u('when') }}</th><th></th></tr></thead>
        <tbody>
          <tr v-for="(d, i) in disbursements" :key="i">
            <td>{{ d.reference }}</td><td>{{ d.provider }}</td><td><VChip size="x-small" :color="d.status==='paid'?'success':d.status==='failed'?'error':'warning'" variant="tonal">{{ d.status }}</VChip></td>
            <td class="text-end">{{ d.count }}</td><td class="text-end">{{ rand(d.total) }}</td><td>{{ d.at }}</td><td class="text-end"><VBtn size="x-small" variant="text" @click="refresh(d)">{{ k('refresh') }}</VBtn></td>
          </tr>
          <tr v-if="!disbursements.length"><td colspan="6" class="text-center text-medium-emphasis py-4">No disbursements yet. Finalise a run and use “Disburse”.</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="560">
      <VCard>
        <VCardTitle>{{ k('connect_title') }}</VCardTitle>
        <VCardText class="d-flex flex-column gap-3">
          <VSelect v-model="form.provider" :items="providers" label="Provider" />
          <VTextField v-model="form.base_url" :label="k('api_base')" :error-messages="form.errors.base_url" />
          <VTextField v-model="form.api_key" :label="k('api_key')" :placeholder="k('keep_existing')" />
          <VTextField v-model="form.api_secret" :label="k('api_secret')" />
          <VSwitch v-model="form.sandbox" :label="k('sandbox_mode')" color="primary" hide-details />
        </VCardText>
        <VCardActions class="pa-3"><VSpacer /><VBtn variant="text" @click="dialog=false">{{ u('cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" @click="save">{{ u('save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
