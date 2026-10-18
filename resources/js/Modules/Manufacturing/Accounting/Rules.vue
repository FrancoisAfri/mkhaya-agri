<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type AcctRule = { id: number; event_type: string; treatment: string; debit: string | null; credit: string | null; description: string
  status: string; notes: string | null; approved_by: string | null; approved_at: string | null }

const props = defineProps<{ rules: AcctRule[]; accounts: Array<{ code: string; name: string }>; can: { manage: boolean } }>()

const editing = ref<AcctRule | null>(null)
const form = ref({ treatment: 'MEMO', debit_account_code: null as string | null, credit_account_code: null as string | null, notes: '' })
const reason = ref('')
const accountItems = [{ code: 'INVENTORY', name: t('manufacturing.accounting.item_inventory') }, { code: 'ORIGINAL', name: t('manufacturing.accounting.original_journal') }, ...props.accounts]
const edit = (r: AcctRule) => { editing.value = r; form.value = { treatment: r.treatment, debit_account_code: r.debit, credit_account_code: r.credit, notes: r.notes ?? '' } }
const act = (r: AcctRule, step: string, data: Record<string, unknown> = {}) =>
  router.post(route('manufacturing.accounting.rules.act', { id: r.id, step }), data, { preserveScroll: true, onSuccess: () => { editing.value = null } })
const statusColor = (s: string) => ({ APPROVED: 'success', DRAFT: 'warning', SUSPENDED: 'error' } as Record<string, string>)[s] ?? 'grey'
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('manufacturing.accounting.rules_title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.accounting.rules_subtitle') }}</p>
    </div>
    <VCard border>
      <VTable density="compact">
        <thead><tr><th>{{ t('manufacturing.accounting.event') }}</th><th>{{ t('manufacturing.accounting.treatment') }}</th><th>{{ t('manufacturing.accounting.debit_credit') }}</th>
          <th>{{ t('manufacturing.accounting.what_happens') }}</th><th>{{ t('manufacturing.accounting.status') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="r in rules" :key="r.id">
            <td class="font-weight-medium">{{ t(`manufacturing.accounting.events.${r.event_type}`) }}</td>
            <td><VChip size="x-small" label>{{ t(`manufacturing.accounting.treatments.${r.treatment}`) }}</VChip></td>
            <td class="text-caption">{{ r.debit ?? '—' }} / {{ r.credit ?? '—' }}</td>
            <td class="text-caption" style="max-width: 420px">{{ r.description }}<div v-if="r.notes" class="text-medium-emphasis">{{ r.notes }}</div></td>
            <td><VChip size="x-small" label :color="statusColor(r.status)">{{ t(`manufacturing.accounting.statuses.${r.status}`) }}</VChip>
              <div v-if="r.approved_at" class="text-caption">{{ r.approved_by ?? t('manufacturing.accounting.system') }} · {{ r.approved_at }}</div></td>
            <td class="text-end text-no-wrap">
              <template v-if="can.manage">
                <VBtn size="small" variant="text" @click="edit(r)">{{ t('manufacturing.accounting.change') }}</VBtn>
                <VBtn v-if="r.status !== 'APPROVED'" size="small" variant="tonal" color="success" @click="act(r, 'approve')">{{ t('manufacturing.accounting.approve') }}</VBtn>
              </template>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog :model-value="!!editing" max-width="620" @update:model-value="v => { if (!v) editing = null }">
      <VCard v-if="editing">
        <VCardItem><VCardTitle>{{ t(`manufacturing.accounting.events.${editing.event_type}`) }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.accounting.change_hint') }}</VCardSubtitle></VCardItem>
        <VCardText>
          <VSelect v-model="form.treatment" :items="['POST', 'MEMO', 'NONE'].map(x => ({ value: x, title: t(`manufacturing.accounting.treatments.${x}`) }))" :label="t('manufacturing.accounting.treatment')" class="mb-2" />
          <template v-if="form.treatment === 'POST'">
            <VAutocomplete v-model="form.debit_account_code" :items="accountItems" item-title="name" item-value="code" :label="t('manufacturing.accounting.debit')" class="mb-2" />
            <VAutocomplete v-model="form.credit_account_code" :items="accountItems" item-title="name" item-value="code" :label="t('manufacturing.accounting.credit')" class="mb-2" />
          </template>
          <VTextarea v-model="form.notes" rows="2" :label="t('manufacturing.accounting.notes')" />
          <VDivider class="my-3" />
          <div class="d-flex gap-2 align-center">
            <VTextField v-model="reason" density="compact" hide-details :label="t('manufacturing.accounting.suspend_reason')" />
            <VBtn color="error" variant="tonal" :disabled="!reason" @click="act(editing, 'suspend', { reason })">{{ t('manufacturing.accounting.suspend') }}</VBtn>
          </div>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="editing = null">{{ t('manufacturing.accounting.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" @click="act(editing, 'update', form)">{{ t('manufacturing.accounting.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
