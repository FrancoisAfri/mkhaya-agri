<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { money } from './useTax'
import BusinessFilter from './components/BusinessFilter.vue'

const route = ziggyRoute

type Account = { account_id: number; code: string; name: string; type: string; amount: string; treatment: string | null; note: string | null }

const props = defineProps<{
  accounts: Account[]
  treatments: Record<string, string>
  year: { start: string; end: string }
  can: { classify: boolean }
  businesses: Array<{ id: number; name: string }>
  business: number | null
}>()

const yearEnd = ref(props.year.end)
function changeYear() {
  router.get(route('tax.income.classifications'), { year_end: yearEnd.value, ...(props.business ? { business: props.business } : {}) }, { preserveState: true })
}

// Income accounts can only be taxable, exempt or ignored; expense accounts the rest.
function options(a: Account) {
  const keys = a.type === 'income' ? ['taxable', 'exempt', 'ignore'] : ['deductible', 'non_deductible', 'capital', 'ignore']
  return keys.map(k => ({ title: props.treatments[k], value: k }))
}

const form = useForm({ entity_id: props.business, account_id: 0, treatment: '', note: '' })
const noteFor = ref<Record<number, string>>(Object.fromEntries(props.accounts.map(a => [a.account_id, a.note ?? ''])))
function save(a: Account, treatment: string) {
  form.entity_id = props.business
  form.account_id = a.account_id
  form.treatment = treatment
  form.note = noteFor.value[a.account_id] ?? ''
  form.post(route('tax.income.classify'), { preserveScroll: true, preserveState: true })
}
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-2" @click="router.get(route('tax.income.index'))">Income tax</VBtn>
    <h4 class="text-h4">Classify accounts for income tax</h4>
    <p class="text-body-2 text-medium-emphasis mb-4">
      How each income and expense account is treated in the computation. Classified once per business and kept between years; an unclassified account is treated as taxable income or a deductible expense, and the computation says so.
    </p>

    <VCard border>
      <VCardText class="d-flex flex-wrap gap-3 align-center">
        <BusinessFilter :businesses="businesses" :business="business" />
        <VTextField v-model="yearEnd" type="date" label="Year ending" density="compact" hide-details style="max-inline-size: 200px" @change="changeYear" />
        <span class="text-caption text-medium-emphasis">Showing movement {{ year.start }} → {{ year.end }}</span>
      </VCardText>
      <VTable density="compact">
        <thead><tr><th>Account</th><th class="text-right">This year</th><th style="min-inline-size: 300px">Treatment</th><th>Note</th></tr></thead>
        <tbody>
          <tr v-for="a in accounts" :key="a.account_id">
            <td>{{ a.code }} {{ a.name }}<div class="text-caption text-disabled">{{ a.type }}</div></td>
            <td class="text-right">{{ money(a.amount) }}</td>
            <td>
              <VSelect :model-value="a.treatment" :items="options(a)" density="compact" hide-details :disabled="!can.classify || !business"
                       placeholder="Not classified" persistent-placeholder @update:model-value="(v: string) => save(a, v)" />
            </td>
            <td><VTextField v-model="noteFor[a.account_id]" density="compact" hide-details placeholder="e.g. s23(b)" :disabled="!can.classify"
                            @change="a.treatment && save(a, a.treatment)" /></td>
          </tr>
          <tr v-if="!accounts.length"><td colspan="4" class="text-center text-medium-emphasis py-6">No income or expense posted for this business in the year.</td></tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
