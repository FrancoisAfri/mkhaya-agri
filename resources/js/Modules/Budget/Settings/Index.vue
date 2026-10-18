<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Period = { id: number; name: string; start_date: string | null; end_date: string | null }
type FinancialYear = {
  id: number; name: string; start_date: string | null; end_date: string | null
  is_current: boolean; status: string; periods: Period[]; variance_threshold_pct?: number | null
}
type Category = { id: number; name: string; type: string; status: string }

defineProps<{
  financialYears: FinancialYear[]
  categories: Category[]
}>()

const tab = ref('years')
const confirmDialog = ref()

// ── Financial years ──────────────────────────────────────────────────────────
const yearForm = ref({ name: '', start_date: '', end_date: '', is_current: false, variance_threshold_pct: 10 })
const addingYear = ref(false)
function addYear() {
  addingYear.value = true
  router.post(route('budgets.settings.years.store'), yearForm.value, {
    preserveScroll: true,
    onSuccess: () => (yearForm.value = { name: '', start_date: '', end_date: '', is_current: false, variance_threshold_pct: 10 }),
    onFinish: () => (addingYear.value = false),
  })
}
// A period variance at or above this share of plan must be explained on the budget's Phasing tab.
function saveThreshold(year: { id: number; variance_threshold_pct?: number | null }, value: string | number) {
  const pct = value === '' || value === null ? null : Number(value)
  if (pct === (year.variance_threshold_pct ?? null)) return
  router.patch(route('budgets.settings.years.update', year.id), { variance_threshold_pct: pct }, { preserveScroll: true })
}
function deleteYear(id: number) {
  confirmDialog.value.open(
    { title: t('budgets_settings.confirm.delete_year_title'), message: t('budgets_settings.confirm.delete_year_message'), confirmText: t('budgets_settings.confirm.delete_text'), confirmColor: 'error', icon: 'tabler-trash' },
    () => router.delete(route('budgets.settings.years.destroy', id), { preserveScroll: true }),
  )
}

// ── Periods ──────────────────────────────────────────────────────────────────
const periodForms = ref<Record<number, { name: string; start_date: string; end_date: string }>>({})
const addingPeriod = ref<Record<number, boolean>>({})
function periodForm(yearId: number) {
  if (!periodForms.value[yearId]) periodForms.value[yearId] = { name: '', start_date: '', end_date: '' }
  return periodForms.value[yearId]
}
function addPeriod(yearId: number) {
  addingPeriod.value[yearId] = true
  router.post(route('budgets.settings.periods.store', yearId), periodForm(yearId), {
    preserveScroll: true,
    onSuccess: () => (periodForms.value[yearId] = { name: '', start_date: '', end_date: '' }),
    onFinish: () => (addingPeriod.value[yearId] = false),
  })
}
function deletePeriod(id: number) {
  confirmDialog.value.open(
    { title: t('budgets_settings.confirm.delete_period_title'), message: t('budgets_settings.confirm.delete_period_message'), confirmText: t('budgets_settings.confirm.delete_text'), confirmColor: 'error', icon: 'tabler-trash' },
    () => router.delete(route('budgets.settings.periods.destroy', id), { preserveScroll: true }),
  )
}

// ── Categories ───────────────────────────────────────────────────────────────
const catForm = ref({ name: '', type: 'opex', status: true })
const addingCategory = ref(false)
const catTypes = ['capex', 'opex']
const catTypeItems = [
  { title: t('budgets_settings.categories.types.capex'), value: 'capex' },
  { title: t('budgets_settings.categories.types.opex'), value: 'opex' },
]
function addCategory() {
  addingCategory.value = true
  router.post(route('budgets.settings.categories.store'), catForm.value, {
    preserveScroll: true,
    onSuccess: () => (catForm.value = { name: '', type: 'opex', status: true }),
    onFinish: () => (addingCategory.value = false),
  })
}
function updateCategory(category: Category) {
  router.put(route('budgets.settings.categories.update', category.id), {
    name: category.name, type: category.type, status: category.status,
  }, { preserveScroll: true })
}
function deleteCategory(id: number) {
  confirmDialog.value.open(
    { title: t('budgets_settings.confirm.delete_category_title'), message: t('budgets_settings.confirm.delete_category_message'), confirmText: t('budgets_settings.confirm.delete_text'), confirmColor: 'error', icon: 'tabler-trash' },
    () => router.delete(route('budgets.settings.categories.destroy', id), { preserveScroll: true }),
  )
}
</script>

<template>
  <div class="pa-4">
    <ConfirmDialog ref="confirmDialog" />

    <div class="d-flex align-center gap-3 mb-4">
      <VAvatar color="primary" variant="tonal" size="48" rounded>
        <VIcon icon="tabler-settings" size="26" />
      </VAvatar>
      <div>
        <h4 class="text-h4 mb-0">{{ t('budgets_settings.page_title') }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">{{ t('budgets_settings.description') }}</p>
      </div>
    </div>

    <VCard border>
      <VTabs v-model="tab">
        <VTab value="years">{{ t('budgets_settings.tabs.financial_years') }}</VTab>
        <VTab value="categories">{{ t('budgets_settings.tabs.categories') }}</VTab>
      </VTabs>

      <VWindow v-model="tab">
        <!-- Financial years -->
        <VWindowItem value="years">
          <VCardText>
            <VCard border class="mb-4">
              <VCardText>
                <div class="text-caption text-medium-emphasis mb-2">{{ t('budgets_settings.financial_years.add_year') }}</div>
                <VRow dense align="center">
                  <VCol cols="12" md="2">
                    <VTextField v-model="yearForm.name" :label="t('budgets_settings.financial_years.name')" density="compact" variant="outlined" hide-details :disabled="addingYear" />
                  </VCol>
                  <VCol cols="12" md="2">
                    <VTextField v-model="yearForm.start_date" type="date" :label="t('budgets_settings.financial_years.start_date')" density="compact" variant="outlined" hide-details :disabled="addingYear" />
                  </VCol>
                  <VCol cols="12" md="2">
                    <VTextField v-model="yearForm.end_date" type="date" :label="t('budgets_settings.financial_years.end_date')" density="compact" variant="outlined" hide-details :disabled="addingYear" />
                  </VCol>
                  <VCol cols="12" md="2">
                    <VSwitch v-model="yearForm.is_current" :label="t('budgets_settings.financial_years.current')" hide-details :disabled="addingYear" />
                  </VCol>
                  <VCol cols="12" md="2">
                    <VTextField v-model.number="yearForm.variance_threshold_pct" type="number" min="0" max="100" suffix="%"
                      label="Explain variances over" density="compact" variant="outlined" hide-details :disabled="addingYear" />
                  </VCol>
                  <VCol cols="12" md="1">
                    <VBtn color="primary" size="small" :loading="addingYear" :disabled="!yearForm.name || addingYear" @click="addYear">
                      {{ t('budgets_settings.financial_years.add') }}
                    </VBtn>
                  </VCol>
                </VRow>
              </VCardText>
            </VCard>

            <div v-if="!financialYears.length" class="text-center text-medium-emphasis py-6">
              {{ t('budgets_settings.financial_years.empty') }}
            </div>

            <VCard v-for="year in financialYears" :key="year.id" border class="mb-4">
              <VCardTitle class="d-flex align-center justify-space-between">
                <div class="d-flex align-center gap-2">
                  <span>{{ year.name }}</span>
                  <VChip v-if="year.is_current" size="small" color="success" variant="tonal">{{ t('budgets_settings.financial_years.current_tag') }}</VChip>
                  <VChip size="small" variant="tonal">{{ year.status }}</VChip>
                  <span class="text-caption text-medium-emphasis">{{ year.start_date ?? '—' }} → {{ year.end_date ?? '—' }}</span>
                  <VTextField
                    :model-value="year.variance_threshold_pct ?? 10" type="number" min="0" max="100" suffix="%"
                    label="Explain variances over" density="compact" variant="outlined" hide-details
                    style="max-inline-size: 190px" class="ms-4"
                    @change="(e: Event) => saveThreshold(year, (e.target as HTMLInputElement).value)"
                  />
                </div>
                <VBtn icon="tabler-trash" size="small" variant="text" color="error" @click="deleteYear(year.id)" />
              </VCardTitle>
              <VTable>
                <thead>
                  <tr>
                    <th>{{ t('budgets_settings.table.period') }}</th>
                    <th>{{ t('budgets_settings.table.start') }}</th>
                    <th>{{ t('budgets_settings.table.end') }}</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="!year.periods.length">
                    <td colspan="4" class="text-center text-medium-emphasis py-3">{{ t('budgets_settings.financial_years.no_periods') }}</td>
                  </tr>
                  <tr v-for="period in year.periods" :key="period.id">
                    <td>{{ period.name }}</td>
                    <td>{{ period.start_date ?? '—' }}</td>
                    <td>{{ period.end_date ?? '—' }}</td>
                    <td class="text-right">
                      <VBtn icon="tabler-trash" size="small" variant="text" color="error" @click="deletePeriod(period.id)" />
                    </td>
                  </tr>
                </tbody>
              </VTable>
              <VDivider />
              <VCardText>
                <div class="text-caption text-medium-emphasis mb-2">{{ t('budgets_settings.financial_years.add_period') }}</div>
                <VRow dense align="center">
                  <VCol cols="12" md="4">
                    <VTextField v-model="periodForm(year.id).name" :label="t('budgets_settings.financial_years.name')" density="compact" variant="outlined" hide-details :disabled="addingPeriod[year.id]" />
                  </VCol>
                  <VCol cols="12" md="3">
                    <VTextField v-model="periodForm(year.id).start_date" type="date" :label="t('budgets_settings.financial_years.start')" density="compact" variant="outlined" hide-details :disabled="addingPeriod[year.id]" />
                  </VCol>
                  <VCol cols="12" md="3">
                    <VTextField v-model="periodForm(year.id).end_date" type="date" :label="t('budgets_settings.financial_years.end')" density="compact" variant="outlined" hide-details :disabled="addingPeriod[year.id]" />
                  </VCol>
                  <VCol cols="12" md="2">
                    <VBtn color="primary" size="small" :loading="addingPeriod[year.id]" :disabled="!periodForm(year.id).name || addingPeriod[year.id]" @click="addPeriod(year.id)">
                      {{ t('budgets_settings.financial_years.add') }}
                    </VBtn>
                  </VCol>
                </VRow>
              </VCardText>
            </VCard>
          </VCardText>
        </VWindowItem>

        <!-- Categories -->
        <VWindowItem value="categories">
          <VCardText>
            <VCard border class="mb-4">
              <VCardText>
                <div class="text-caption text-medium-emphasis mb-2">{{ t('budgets_settings.categories.add_category') }}</div>
                <VRow dense align="center">
                  <VCol cols="12" md="5">
                    <VTextField v-model="catForm.name" :label="t('budgets_settings.categories.name')" density="compact" variant="outlined" hide-details :disabled="addingCategory" />
                  </VCol>
                  <VCol cols="12" md="3">
                    <VSelect v-model="catForm.type" :items="catTypeItems" :label="t('budgets_settings.categories.type')" density="compact" variant="outlined" hide-details :disabled="addingCategory" />
                  </VCol>
                  <VCol cols="12" md="2">
                    <VSwitch v-model="catForm.status" :label="t('budgets_settings.categories.active')" hide-details :disabled="addingCategory" />
                  </VCol>
                  <VCol cols="12" md="2">
                    <VBtn color="primary" size="small" :loading="addingCategory" :disabled="!catForm.name || addingCategory" @click="addCategory">
                      {{ t('budgets_settings.categories.add') }}
                    </VBtn>
                  </VCol>
                </VRow>
              </VCardText>
            </VCard>

            <VTable>
              <thead>
                <tr>
                  <th>{{ t('budgets_settings.table.name') }}</th>
                  <th>{{ t('budgets_settings.table.type') }}</th>
                  <th>{{ t('budgets_settings.table.status') }}</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!categories.length">
                  <td colspan="4" class="text-center text-medium-emphasis py-6">{{ t('budgets_settings.categories.empty') }}</td>
                </tr>
                <tr v-for="category in categories" :key="category.id">
                  <td style="max-width:240px">
                    <VTextField v-model="category.name" density="compact" variant="plain" hide-details />
                  </td>
                  <td style="max-width:140px">
                    <VSelect v-model="category.type" :items="catTypeItems" density="compact" variant="plain" hide-details />
                  </td>
                  <td>
                    <VChip size="small" :color="category.status === 'active' || category.status === true as any ? 'success' : 'grey'" variant="tonal">
                      {{ category.status }}
                    </VChip>
                  </td>
                  <td class="text-right">
                    <VBtn icon="tabler-device-floppy" size="small" variant="text" color="primary" @click="updateCategory(category)" />
                    <VBtn icon="tabler-trash" size="small" variant="text" color="error" @click="deleteCategory(category.id)" />
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VWindowItem>
      </VWindow>
    </VCard>
  </div>
</template>
