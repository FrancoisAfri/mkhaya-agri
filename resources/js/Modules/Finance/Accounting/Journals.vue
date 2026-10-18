<script lang="ts" setup>
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Line { account: string; debit: number; credit: number; narration: string | null }
interface Journal { id: number; journal_no: string; date: string; description: string | null; source_module: string; status: string; amount: number; lines: Line[] }
interface Account { id: number; label: string; type: string }

const props = defineProps<{ journals: Journal[]; accounts: Account[]; authority?: { can_post: boolean; max_amount: number | null } }>()

const canPost = computed(() => props.authority?.can_post ?? true)
const maxAmount = computed(() => props.authority?.max_amount ?? null)

const dialog = ref(false)
const expanded = ref<number[]>([])
const confirm = ref()

const blankLine = () => ({ account_id: null as number | null, debit: null as number | null, credit: null as number | null, narration: '' })
const form = useForm<{ journal_date: string; description: string; lines: any[] }>({
  journal_date: new Date().toISOString().slice(0, 10),
  description: '',
  lines: [blankLine(), blankLine()],
})

const debitTotal = computed(() => form.lines.reduce((s, l) => s + (Number(l.debit) || 0), 0))
const creditTotal = computed(() => form.lines.reduce((s, l) => s + (Number(l.credit) || 0), 0))
const diff = computed(() => Math.round((debitTotal.value - creditTotal.value) * 100) / 100)
const overLimit = computed(() => maxAmount.value !== null && debitTotal.value > maxAmount.value)
const balanced = computed(() => diff.value === 0 && debitTotal.value > 0 && !overLimit.value)

function openNew() { form.reset(); form.journal_date = new Date().toISOString().slice(0, 10); form.lines = [blankLine(), blankLine()]; dialog.value = true }
function addLine() { form.lines.push(blankLine()) }
function removeLine(i: number) { if (form.lines.length > 2) form.lines.splice(i, 1) }
function post() { form.post(route('finance.accounting.journals.store'), { preserveScroll: true, onSuccess: () => { dialog.value = false } }) }

function toggle(id: number) { const i = expanded.value.indexOf(id); i >= 0 ? expanded.value.splice(i, 1) : expanded.value.push(id) }
function reverse(j: Journal) {
  confirm.value.open(
    { title: t('acc.reverse_journal'), message: t('acc.reverse_confirm', { no: j.journal_no }), confirmText: t('acc.reverse'), confirmColor: 'error' },
    () => useForm({ reason: t('acc.reverse_reason') }).post(route('finance.accounting.journals.reverse', j.id), { preserveScroll: true }),
  )
}
const money = (n: number) => (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const statusColor: Record<string, string> = { posted: 'success', reversed: 'error', draft: 'warning' }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.gl_crumb') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.journals') }}</h2>
      </div>
      <VBtn v-if="canPost" color="primary" prepend-icon="tabler-plus" @click="openNew">{{ t('acc.new_journal') }}</VBtn>
      <VChip v-else color="warning" variant="tonal" size="small" prepend-icon="tabler-lock">{{ t('acc.no_post_rights') }}</VChip>
    </div>

    <VAlert v-if="(form.errors as any).journal" type="error" variant="tonal" density="compact" class="mb-3">{{ (form.errors as any).journal }}</VAlert>

    <VCard border>
      <VTable>
        <thead><tr><th></th><th>{{ t('acc.journal_no') }}</th><th>{{ t('acc.date') }}</th><th>{{ t('acc.description') }}</th><th>{{ t('acc.source') }}</th><th class="text-right">{{ t('acc.amount') }}</th><th>{{ t('acc.status') }}</th><th class="text-right"></th></tr></thead>
        <tbody>
          <tr v-if="!journals.length"><td colspan="8" class="text-center text-medium-emphasis py-6">{{ t('acc.no_journals') }}</td></tr>
          <template v-for="j in journals" :key="j.id">
            <tr>
              <td><VBtn :icon="expanded.includes(j.id) ? 'tabler-chevron-down' : 'tabler-chevron-right'" size="x-small" variant="text" @click="toggle(j.id)" /></td>
              <td class="font-weight-medium">{{ j.journal_no }}</td>
              <td class="text-caption">{{ j.date }}</td>
              <td>{{ j.description || '—' }}</td>
              <td><VChip size="x-small" variant="tonal" class="text-capitalize">{{ j.source_module }}</VChip></td>
              <td class="text-right">{{ money(j.amount) }}</td>
              <td><VChip size="x-small" :color="statusColor[j.status] || 'default'" variant="tonal" class="text-capitalize">{{ j.status }}</VChip></td>
              <td class="text-right"><VBtn v-if="j.status === 'posted'" size="x-small" variant="tonal" color="error" @click="reverse(j)">{{ t('acc.reverse') }}</VBtn></td>
            </tr>
            <tr v-if="expanded.includes(j.id)">
              <td colspan="8" class="pa-0">
                <VTable density="compact" class="bg-surface-light">
                  <thead><tr><th>{{ t('acc.account') }}</th><th class="text-right">{{ t('acc.debit') }}</th><th class="text-right">{{ t('acc.credit') }}</th><th>{{ t('acc.narration') }}</th></tr></thead>
                  <tbody>
                    <tr v-for="(l, k) in j.lines" :key="k">
                      <td>{{ l.account }}</td>
                      <td class="text-right">{{ l.debit ? money(l.debit) : '' }}</td>
                      <td class="text-right">{{ l.credit ? money(l.credit) : '' }}</td>
                      <td class="text-caption text-medium-emphasis">{{ l.narration }}</td>
                    </tr>
                  </tbody>
                </VTable>
              </td>
            </tr>
          </template>
        </tbody>
      </VTable>
    </VCard>

    <!-- Capture dialog -->
    <VDialog v-model="dialog" max-width="820" persistent>
      <VCard>
        <VCardTitle>{{ t('acc.new_journal') }}</VCardTitle>
        <VCardText>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model="form.journal_date" type="date" :label="t('acc.date')" variant="outlined" density="comfortable" style="max-width: 200px" :error-messages="(form.errors as any).journal_date" />
            <VTextField v-model="form.description" :label="t('acc.description')" variant="outlined" density="comfortable" />
          </div>

          <VTable density="compact">
            <thead><tr><th style="width: 45%">{{ t('acc.account') }}</th><th class="text-right">{{ t('acc.debit') }}</th><th class="text-right">{{ t('acc.credit') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="(l, i) in form.lines" :key="i">
                <td class="pa-1"><VSelect v-model="l.account_id" :items="accounts.map(a => ({ title: a.label, value: a.id }))" density="compact" variant="outlined" hide-details :placeholder="t('acc.select_account')" /></td>
                <td class="pa-1"><VTextField v-model.number="l.debit" type="number" min="0" step="0.01" density="compact" variant="outlined" hide-details reverse @update:model-value="l.credit = null" /></td>
                <td class="pa-1"><VTextField v-model.number="l.credit" type="number" min="0" step="0.01" density="compact" variant="outlined" hide-details reverse @update:model-value="l.debit = null" /></td>
                <td class="pa-1"><VBtn icon="tabler-x" size="x-small" variant="text" :disabled="form.lines.length <= 2" @click="removeLine(i)" /></td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="font-weight-bold">
                <td class="text-right">{{ t('acc.totals') }}</td>
                <td class="text-right">{{ money(debitTotal) }}</td>
                <td class="text-right">{{ money(creditTotal) }}</td>
                <td></td>
              </tr>
              <tr v-if="diff !== 0">
                <td class="text-right text-error">{{ t('acc.out_of_balance') }}</td>
                <td colspan="2" class="text-right text-error">{{ money(Math.abs(diff)) }}</td>
                <td></td>
              </tr>
            </tfoot>
          </VTable>
          <VBtn size="small" variant="text" prepend-icon="tabler-plus" class="mt-2" @click="addLine">{{ t('acc.add_line') }}</VBtn>
        </VCardText>
        <VCardActions class="pa-4">
          <VChip v-if="overLimit" color="error" size="small" variant="tonal">{{ t('acc.exceeds_limit', { amount: money(maxAmount!) }) }}</VChip>
          <VChip v-else :color="balanced ? 'success' : 'warning'" size="small" variant="tonal">{{ balanced ? t('acc.balanced') : t('acc.debits_equal_credits') }}</VChip>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('acc.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!balanced" @click="post">{{ t('acc.post_journal') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirm" />
  </div>
</template>
