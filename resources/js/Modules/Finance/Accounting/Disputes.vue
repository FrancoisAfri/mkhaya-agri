<script lang="ts" setup>
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

/** Customer disputes: raised against an invoice (held from reminders), resolved with a credit note or rejected. */
interface Dispute { id: number; party: string | null; invoice: string | null; kind: string; amount: number | null; description: string; status: string; resolution: string | null
  subject_type: string | null; credit_note: string | null; raised_at: string | null; resolved_at: string | null }
const props = defineProps<{ disputes: Dispute[]; customers: { value: number; title: string }[]; invoices: { value: number; title: string; party_id: number }[]; kinds: string[] }>()

const money = (n: number | null) => n === null ? '—' : n.toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const statusColor: Record<string, string> = { open: 'warning', resolved: 'success', rejected: 'default' }
const human = (s: string) => s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, ' ')

const raising = ref(false)
const raiseForm = useForm<any>({ party_id: null, invoice_id: null, kind: 'quality', amount: null, description: '' })
const theirInvoices = computed(() => props.invoices.filter(i => i.party_id === raiseForm.party_id))
function raise() { raiseForm.post(route('finance.accounting.disputes.store'), { preserveScroll: true, onSuccess: () => { raising.value = false; raiseForm.reset() } }) }

const resolving = ref<Dispute | null>(null)
const resolveForm = useForm<any>({ outcome: 'resolved', resolution: '', credit: null })
function openResolve(d: Dispute) { resolving.value = d; resolveForm.reset(); resolveForm.credit = d.amount }
function resolve() { resolveForm.post(route('finance.accounting.disputes.resolve', resolving.value!.id), { preserveScroll: true, onSuccess: () => { resolving.value = null } }) }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.crumb_accounting') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.disputes_title') }}</h2>
        <div class="text-caption text-medium-emphasis">{{ t('acc.disputes_sub') }}</div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="raising = true">{{ t('acc.new_dispute') }}</VBtn>
    </div>

    <VCard border>
      <VTable>
        <thead><tr><th>{{ t('acc.customer') }}</th><th>{{ t('acc.dispute_about') }}</th><th>{{ t('acc.invoice') }}</th><th class="text-right">{{ t('acc.amount') }}</th><th>{{ t('acc.status') }}</th><th class="text-right"></th></tr></thead>
        <tbody>
          <tr v-for="d in disputes" :key="d.id">
            <td>{{ d.party }}<div class="text-caption text-medium-emphasis">{{ d.raised_at }}</div></td>
            <td>{{ human(d.kind) }}<div class="text-caption">{{ d.description }}</div><div v-if="d.resolution" class="text-caption text-success">{{ d.resolution }}</div></td>
            <td class="text-caption">{{ d.invoice ?? '—' }}<div v-if="d.credit_note">{{ t('acc.credit_note') }} {{ d.credit_note }}</div></td>
            <td class="text-right">{{ money(d.amount) }}</td>
            <td><VChip size="x-small" :color="statusColor[d.status]" variant="tonal">{{ human(d.status) }}</VChip></td>
            <td class="text-right"><VBtn v-if="d.status === 'open'" size="x-small" variant="tonal" color="primary" @click="openResolve(d)">{{ t('acc.resolve') }}</VBtn></td>
          </tr>
          <tr v-if="!disputes.length"><td colspan="6" class="text-center text-medium-emphasis py-6">{{ t('acc.no_disputes') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="raising" max-width="620">
      <VCard :title="t('acc.new_dispute')">
        <VCardText><VRow dense>
          <VCol cols="12" md="7"><VAutocomplete v-model="raiseForm.party_id" :items="customers" :label="t('acc.customer')" :error-messages="(raiseForm.errors as any).party_id" @update:model-value="raiseForm.invoice_id = null" /></VCol>
          <VCol cols="12" md="5"><VAutocomplete v-model="raiseForm.invoice_id" :items="theirInvoices" :label="t('acc.invoice')" clearable :disabled="!raiseForm.party_id" /></VCol>
          <VCol cols="6"><VSelect v-model="raiseForm.kind" :items="kinds.map(k => ({ title: human(k), value: k }))" :label="t('acc.dispute_about')" :error-messages="(raiseForm.errors as any).kind" /></VCol>
          <VCol cols="6"><VTextField v-model.number="raiseForm.amount" type="number" :label="t('acc.dispute_amount')" /></VCol>
          <VCol cols="12"><VTextarea v-model="raiseForm.description" :label="t('acc.dispute_says')" rows="2" :error-messages="(raiseForm.errors as any).description" /></VCol>
        </VRow></VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="raising = false">{{ t('acc.cancel') }}</VBtn>
          <VBtn color="primary" :loading="raiseForm.processing" :disabled="!raiseForm.party_id || !raiseForm.description" @click="raise">{{ t('acc.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="!!resolving" max-width="560" @update:model-value="v => { if (!v) resolving = null }">
      <VCard v-if="resolving" :title="`${t('acc.resolve')} — ${resolving.party}`">
        <VCardText><VRow dense>
          <VCol cols="6"><VSelect v-model="resolveForm.outcome" :items="[{ title: t('acc.outcome_resolved'), value: 'resolved' }, { title: t('acc.outcome_rejected'), value: 'rejected' }]" :label="t('acc.outcome')" /></VCol>
          <VCol cols="6"><VTextField v-model.number="resolveForm.credit" type="number" :label="t('acc.credit_to_give')" :disabled="resolveForm.outcome !== 'resolved' || !resolving.invoice" :error-messages="(resolveForm.errors as any).credit" /></VCol>
          <VCol cols="12"><VTextarea v-model="resolveForm.resolution" :label="t('acc.resolution')" rows="2" :error-messages="(resolveForm.errors as any).resolution" /></VCol>
        </VRow></VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="resolving = null">{{ t('acc.cancel') }}</VBtn>
          <VBtn color="primary" :loading="resolveForm.processing" :disabled="!resolveForm.resolution" @click="resolve">{{ t('acc.resolve') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
