<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Member { id: number; company_id: number; name: string; ownership_pct: number; fx_rate: number; fx_method: string; is_parent: boolean }
interface Row { code: string; name: string; type: string; balance: number }
const props = defineProps<{
  groups: { id: number; name: string; base_currency: string; members_count: number }[]
  selected: number | null
  detail: { id: number; name: string; base_currency: string; intercompany_codes: string[]; members: Member[] } | null
  result: { as_of: string; rows: Row[]; eliminations: any[]; members: any[]; nci: number; totals: { assets: number; liabilities: number; equity: number }; elapsed_ms: number } | null
  companies: { id: number; name: string }[]
}>()

const money = (n: number) => (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const confirm = ref()
const typeLabel = computed<Record<string, string>>(() => ({ asset: t('acc.assets'), liability: t('acc.liabilities'), equity: t('acc.equity'), income: t('acc.income'), expense: t('acc.expenses_word') }))
const byType = (type: string) => props.result?.rows.filter(r => r.type === type) ?? []

function selectGroup(id: number) { router.get(route('finance.accounting.consolidation'), { group: id }, { preserveState: false }) }

const groupDialog = ref(false)
const groupForm = useForm<any>({ name: '', base_currency: 'ZAR', intercompany_codes: [] })
function saveGroup() { groupForm.post(route('finance.accounting.consolidation.groups.store'), { preserveScroll: true, onSuccess: () => { groupDialog.value = false; groupForm.reset() } }) }

const icForm = useForm<any>({ name: '', base_currency: 'ZAR', intercompany_codes: [] as string[] })
function saveIntercompany() {
  if (!props.detail) return
  icForm.name = props.detail.name; icForm.base_currency = props.detail.base_currency; icForm.intercompany_codes = props.detail.intercompany_codes
  icForm.patch(route('finance.accounting.consolidation.groups.update', props.detail.id), { preserveScroll: true })
}

const memberDialog = ref(false)
const memberForm = useForm<any>({ company_id: null, name: '', ownership_pct: 100, fx_rate: 1, fx_method: 'closing', is_parent: false })
function addMember() { memberForm.post(route('finance.accounting.consolidation.members.add', props.detail!.id), { preserveScroll: true, onSuccess: () => { memberDialog.value = false; memberForm.reset() } }) }
function removeMember(m: Member) {
  confirm.value.open({ title: t('acc.remove_member'), message: t('acc.remove_member_confirm', { name: m.name }), confirmText: t('acc.remove'), confirmColor: 'error', icon: 'tabler-trash' },
    () => router.delete(route('finance.accounting.consolidation.members.remove', m.id), { preserveScroll: true }))
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.crumb_group') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.consolidation_word') }}</h2>
        <div class="text-caption text-medium-emphasis">{{ t('acc.consolidation_sub') }}</div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="groupDialog = true">{{ t('acc.new_group') }}</VBtn>
    </div>

    <VRow>
      <VCol cols="12" md="3">
        <VCard border>
          <VList density="compact" class="py-0">
            <VListItem v-for="g in groups" :key="g.id" :active="g.id === selected" color="primary" @click="selectGroup(g.id)">
              <VListItemTitle class="text-body-2">{{ g.name }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">{{ g.base_currency }} · {{ t('acc.member_count', { n: g.members_count }) }}</VListItemSubtitle>
            </VListItem>
            <VListItem v-if="!groups.length"><VListItemTitle class="text-center text-medium-emphasis py-4">{{ t('acc.no_groups') }}</VListItemTitle></VListItem>
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" md="9">
        <template v-if="detail">
          <VCard border class="mb-3">
            <VCardTitle class="text-subtitle-1 d-flex align-center">{{ t('acc.members_word') }}<VSpacer /><VBtn size="small" variant="tonal" prepend-icon="tabler-plus" @click="memberDialog = true">{{ t('acc.add_member') }}</VBtn></VCardTitle>
            <VTable density="compact">
              <thead><tr><th>{{ t('acc.company_word') }}</th><th class="text-right">{{ t('acc.ownership') }}</th><th class="text-right">{{ t('acc.fx_rate') }}</th><th>{{ t('acc.fx_method') }}</th><th></th></tr></thead>
              <tbody>
                <tr v-for="m in detail.members" :key="m.id">
                  <td>{{ m.name }} <VChip v-if="m.is_parent" size="x-small" color="primary" variant="tonal">{{ t('acc.parent_chip') }}</VChip></td>
                  <td class="text-right">{{ m.ownership_pct }}%</td>
                  <td class="text-right">{{ m.fx_rate }}</td>
                  <td class="text-caption text-capitalize">{{ m.fx_method }}</td>
                  <td class="text-right"><VBtn size="x-small" variant="text" color="error" icon="tabler-trash" @click="removeMember(m)" /></td>
                </tr>
                <tr v-if="!detail.members.length"><td colspan="5" class="text-center text-medium-emphasis py-3">{{ t('acc.no_members') }}</td></tr>
              </tbody>
            </VTable>
            <VCardText>
              <VCombobox v-model="detail.intercompany_codes" :label="t('acc.intercompany_codes_label')" multiple chips closable-chips variant="outlined" density="compact" :hint="t('acc.intercompany_hint')" persistent-hint @update:model-value="saveIntercompany" />
            </VCardText>
          </VCard>

          <VCard v-if="result" border>
            <VCardTitle class="text-subtitle-1 d-flex align-center">
              {{ t('acc.consolidated_statement') }} <span class="text-caption text-medium-emphasis ms-2">{{ t('acc.as_at_word') }} {{ result.as_of }}</span>
              <VSpacer />
              <VChip size="x-small" variant="tonal" color="success">{{ t('acc.ran_in_ms', { ms: result.elapsed_ms }) }}</VChip>
            </VCardTitle>
            <VDivider />
            <VTable density="compact">
              <template v-for="sec in ['asset', 'liability', 'equity', 'income', 'expense']" :key="sec">
                <thead v-if="byType(sec).length"><tr><th colspan="2" class="text-uppercase text-caption">{{ typeLabel[sec] }}</th></tr></thead>
                <tbody v-if="byType(sec).length">
                  <tr v-for="r in byType(sec)" :key="r.code"><td class="text-caption">{{ r.code }} · {{ r.name }}</td><td class="text-right">{{ money(r.balance) }}</td></tr>
                </tbody>
              </template>
            </VTable>
            <VDivider />
            <VCardText>
              <div class="d-flex justify-space-between text-body-2"><span>{{ t('acc.assets') }}</span><span>{{ money(result.totals.assets) }}</span></div>
              <div class="d-flex justify-space-between text-body-2"><span>{{ t('acc.liabilities') }}</span><span>{{ money(result.totals.liabilities) }}</span></div>
              <div class="d-flex justify-space-between text-body-2"><span>{{ t('acc.equity') }}</span><span>{{ money(result.totals.equity) }}</span></div>
              <div v-if="result.nci" class="d-flex justify-space-between text-body-2 text-medium-emphasis mt-1"><span>{{ t('acc.minority_interest') }}</span><span>{{ money(result.nci) }}</span></div>
              <div v-if="result.eliminations.length" class="mt-3">
                <div class="text-caption font-weight-medium mb-1">{{ t('acc.intercompany_eliminations_audit') }}</div>
                <div v-for="(e, i) in result.eliminations" :key="i" class="text-caption text-medium-emphasis d-flex justify-space-between">
                  <span>{{ e.code }} · {{ e.name }}</span><span>({{ money(e.amount) }})</span>
                </div>
              </div>
            </VCardText>
          </VCard>
        </template>
        <VCard v-else border><VCardText class="text-center text-medium-emphasis py-8">{{ t('acc.select_create_group') }}</VCardText></VCard>
      </VCol>
    </VRow>

    <!-- New group -->
    <VDialog v-model="groupDialog" max-width="440">
      <VCard>
        <VCardTitle>{{ t('acc.new_group') }}</VCardTitle>
        <VCardText>
          <VTextField v-model="groupForm.name" :label="t('acc.group_name')" variant="outlined" density="comfortable" class="mb-3" :error-messages="groupForm.errors.name" />
          <VTextField v-model="groupForm.base_currency" :label="t('acc.base_currency')" maxlength="3" variant="outlined" density="comfortable" style="max-width: 140px" />
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="groupDialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="groupForm.processing" :disabled="!groupForm.name" @click="saveGroup">{{ t('acc.create') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Add member -->
    <VDialog v-model="memberDialog" max-width="480">
      <VCard>
        <VCardTitle>{{ t('acc.add_member_company') }}</VCardTitle>
        <VCardText>
          <VSelect v-model="memberForm.company_id" :items="companies.map(c => ({ title: c.name, value: c.id }))" :label="t('acc.company_word')" variant="outlined" density="comfortable" class="mb-3" @update:model-value="memberForm.name = companies.find(c => c.id === memberForm.company_id)?.name ?? ''" :error-messages="memberForm.errors.company_id" />
          <VTextField v-model="memberForm.name" :label="t('acc.display_name')" variant="outlined" density="comfortable" class="mb-3" />
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model.number="memberForm.ownership_pct" type="number" :label="t('acc.ownership_pct')" suffix="%" variant="outlined" density="comfortable" />
            <VTextField v-model.number="memberForm.fx_rate" type="number" :label="t('acc.fx_rate')" variant="outlined" density="comfortable" />
          </div>
          <div class="d-flex gap-3 align-center">
            <VSelect v-model="memberForm.fx_method" :items="[{title:t('acc.closing_rate'),value:'closing'},{title:t('acc.average_word'),value:'average'},{title:t('acc.historic_word'),value:'historic'}]" :label="t('acc.fx_method')" variant="outlined" density="comfortable" />
            <VSwitch v-model="memberForm.is_parent" :label="t('acc.parent_word')" color="primary" hide-details />
          </div>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="memberDialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="memberForm.processing" :disabled="!memberForm.company_id" @click="addMember">{{ t('acc.add_word') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirm" />
  </div>
</template>
