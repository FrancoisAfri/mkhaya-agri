<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute

type Year = {
  id: number; name: string; start_date: string | null; end_date: string | null
  status: string; is_current: boolean; periods: number; budgets: number; allocated: number
}

type Column = { key: string; required: boolean; values?: string[] }

const props = defineProps<{
  financialYears: Year[]
  importColumns: Column[]
  categories: string[]
  importWarnings: string[]
}>()

const confirmer = ref()

function money(v: number) {
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'ZAR', maximumFractionDigits: 0 }).format(v)
  } catch {
    return String(Math.round(v))
  }
}

// ── Carry forward ────────────────────────────────────────────────────────────

const carryForm = useForm({ from_year_id: null as number | null, to_year_id: null as number | null, mode: 'allocation' })

function carry() {
  carryForm.post(route('budgets.planning.carry_forward'), { preserveScroll: true })
}

// ── Import ───────────────────────────────────────────────────────────────────

const importForm = useForm<{ financial_year_id: number | null; file: File | null }>({
  financial_year_id: null,
  file: null,
})

function submitImport() {
  importForm.post(route('budgets.planning.import'), {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => { importForm.reset('file') },
  })
}

/**
 * The template as a real file.
 *
 * A normal navigation rather than XHR — the browser has to receive it as a download. It is the same
 * file the parser is written against, so it cannot drift into describing columns that do not work.
 */
function downloadTemplate() {
  window.location.href = route('budgets.planning.template')
}

const instructionsOpen = ref<number[]>([])

/** Warnings survive the redirect, so a partly-imperfect import can explain itself in place. */
const warnings = computed(() => props.importWarnings ?? [])

// ── Year end ─────────────────────────────────────────────────────────────────

function closeYear(year: Year) {
  confirmer.value.open({
    title: t('budgets_planning.confirm_close_title'),
    message: t('budgets_planning.confirm_close_body', { name: year.name }),
    confirmText: t('budgets_planning.close_year'),
    confirmColor: 'warning',
    icon: 'tabler-lock',
  }, () => router.post(route('budgets.planning.close_year', year.id), {}, { preserveScroll: true }))
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('budgets_planning.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('budgets_planning.subtitle') }}</p>
      </div>
      <VBtn variant="tonal" prepend-icon="tabler-settings" @click="router.get(route('budgets.settings.index'))">
        {{ t('budgets_planning.manage_years') }}
      </VBtn>
    </div>

    <!-- Financial years ---------------------------------------------------- -->
    <VCard border class="mb-4">
      <VCardItem>
        <VCardTitle class="text-body-1">{{ t('budgets_planning.years_title') }}</VCardTitle>
        <VCardSubtitle>{{ t('budgets_planning.years_sub') }}</VCardSubtitle>
      </VCardItem>
      <VDivider />
      <VTable density="comfortable">
        <thead>
          <tr>
            <th>{{ t('budgets_planning.col_year') }}</th>
            <th>{{ t('budgets_planning.col_dates') }}</th>
            <th class="text-right">{{ t('budgets_planning.col_periods') }}</th>
            <th class="text-right">{{ t('budgets_planning.col_budgets') }}</th>
            <th class="text-right">{{ t('budgets_planning.col_allocated') }}</th>
            <th>{{ t('budgets_planning.col_status') }}</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="year in financialYears" :key="year.id">
            <td>
              {{ year.name }}
              <VChip v-if="year.is_current" size="x-small" color="primary" label class="ms-2">
                {{ t('budgets_planning.current') }}
              </VChip>
            </td>
            <td class="text-caption">{{ year.start_date }} → {{ year.end_date }}</td>
            <td class="text-right">
              {{ year.periods }}
              <!-- Phasing needs periods; a year with none silently has no plan to compare against. -->
              <VTooltip v-if="!year.periods" :text="t('budgets_planning.no_periods_hint')">
                <template #activator="{ props: tip }">
                  <VIcon v-bind="tip" icon="tabler-alert-circle" size="16" color="warning" class="ms-1" />
                </template>
              </VTooltip>
            </td>
            <td class="text-right">{{ year.budgets }}</td>
            <td class="text-right">{{ money(year.allocated) }}</td>
            <td>
              <VChip size="x-small" :color="year.status === 'closed' ? 'grey' : 'success'" label>
                {{ t(`budgets_planning.status_${year.status}`) }}
              </VChip>
            </td>
            <td class="text-right">
              <VBtn
                v-if="year.status !== 'closed'" size="small" variant="text" prepend-icon="tabler-lock"
                @click="closeYear(year)"
              >
                {{ t('budgets_planning.close_year') }}
              </VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VRow>
      <!-- Carry forward -->
      <VCol cols="12" md="6">
        <VCard border class="h-100">
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('budgets_planning.carry_title') }}</VCardTitle>
            <VCardSubtitle>{{ t('budgets_planning.carry_sub') }}</VCardSubtitle>
          </VCardItem>
          <VDivider />
          <VCardText>
            <VRow dense>
              <VCol cols="12" md="6">
                <VSelect
                  v-model="carryForm.from_year_id"
                  :items="financialYears.map(y => ({ title: y.name, value: y.id }))"
                  :label="t('budgets_planning.field_from')"
                  :error-messages="carryForm.errors.from_year_id"
                  variant="outlined" density="compact"
                />
              </VCol>
              <VCol cols="12" md="6">
                <VSelect
                  v-model="carryForm.to_year_id"
                  :items="financialYears.map(y => ({ title: y.name, value: y.id }))"
                  :label="t('budgets_planning.field_to')"
                  :error-messages="carryForm.errors.to_year_id"
                  variant="outlined" density="compact"
                />
              </VCol>
              <VCol cols="12">
                <VRadioGroup v-model="carryForm.mode" inline hide-details>
                  <VRadio :label="t('budgets_planning.mode_allocation')" value="allocation" />
                  <VRadio :label="t('budgets_planning.mode_unspent')" value="unspent" />
                </VRadioGroup>
                <p class="text-caption text-medium-emphasis mt-1 mb-0">
                  {{ carryForm.mode === 'unspent'
                    ? t('budgets_planning.mode_unspent_hint')
                    : t('budgets_planning.mode_allocation_hint') }}
                </p>
              </VCol>
            </VRow>

            <VAlert type="info" variant="tonal" density="compact" class="mt-3">
              {{ t('budgets_planning.carry_notice') }}
            </VAlert>
          </VCardText>
          <VDivider />
          <VCardActions>
            <VSpacer />
            <VBtn
              color="primary" :loading="carryForm.processing"
              :disabled="!carryForm.from_year_id || !carryForm.to_year_id"
              @click="carry"
            >
              {{ t('budgets_planning.carry_action') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VCol>

      <!-- Import -->
      <VCol cols="12" md="6">
        <VCard border class="h-100">
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('budgets_planning.import_title') }}</VCardTitle>
            <VCardSubtitle>{{ t('budgets_planning.import_sub') }}</VCardSubtitle>
          </VCardItem>
          <VDivider />
          <VCardText>
            <!--
              Step one is the file, not the form. Somebody arriving here has a spreadsheet in the
              wrong shape; handing them the right shape first is the whole job.
            -->
            <div class="d-flex align-start gap-3 mb-4 pa-3 rounded template-callout">
              <VIcon icon="tabler-file-spreadsheet" size="28" class="mt-1" />
              <div class="flex-grow-1">
                <div class="font-weight-medium">{{ t('budgets_planning.template_title') }}</div>
                <div class="text-caption text-medium-emphasis">{{ t('budgets_planning.template_sub') }}</div>
              </div>
              <VBtn color="primary" variant="tonal" size="small" prepend-icon="tabler-download" @click="downloadTemplate">
                {{ t('budgets_planning.download_template') }}
              </VBtn>
            </div>

            <VSelect
              v-model="importForm.financial_year_id"
              :items="financialYears.map(y => ({ title: y.name, value: y.id }))"
              :label="t('budgets_planning.field_target_year')"
              :hint="t('budgets_planning.hint_target_year')" persistent-hint
              :error-messages="importForm.errors.financial_year_id"
              variant="outlined" density="compact" class="mb-3"
            />
            <VFileInput
              :label="t('budgets_planning.field_file')"
              accept=".csv,text/csv"
              :hint="t('budgets_planning.hint_file')" persistent-hint
              :error-messages="importForm.errors.file"
              variant="outlined" density="compact" prepend-icon="" prepend-inner-icon="tabler-upload"
              @update:model-value="(f: any) => (importForm.file = Array.isArray(f) ? f[0] : f)"
            />

            <!-- Rows that came in with something replaced by a default. Not failures, but the
                 person who wrote the file is the only one who can tell whether they matter. -->
            <VAlert v-if="warnings.length" type="warning" variant="tonal" density="compact" class="mt-3">
              <div class="font-weight-medium mb-1">{{ t('budgets_planning.warnings_title') }}</div>
              <ul class="ps-4 mb-0">
                <li v-for="(w, i) in warnings" :key="i" class="text-caption">{{ w }}</li>
              </ul>
            </VAlert>

            <VExpansionPanels v-model="instructionsOpen" variant="accordion" class="mt-4">
              <!-- How it behaves -->
              <VExpansionPanel :title="t('budgets_planning.how_title')">
                <VExpansionPanelText>
                  <ul class="ps-4 text-body-2">
                    <li class="mb-2">{{ t('budgets_planning.how_1') }}</li>
                    <li class="mb-2">{{ t('budgets_planning.how_2') }}</li>
                    <li class="mb-2">{{ t('budgets_planning.how_3') }}</li>
                    <li class="mb-2">{{ t('budgets_planning.how_4') }}</li>
                    <li class="mb-2">{{ t('budgets_planning.how_5') }}</li>
                    <li>{{ t('budgets_planning.how_6') }}</li>
                  </ul>
                </VExpansionPanelText>
              </VExpansionPanel>

              <!-- What each column accepts -->
              <VExpansionPanel :title="t('budgets_planning.columns_title')">
                <VExpansionPanelText>
                  <div class="table-scroll">
                    <VTable density="compact">
                      <thead>
                        <tr>
                          <th>{{ t('budgets_planning.col_column') }}</th>
                          <th>{{ t('budgets_planning.col_required') }}</th>
                          <th>{{ t('budgets_planning.col_accepts') }}</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="col in importColumns" :key="col.key">
                          <td><code class="text-caption">{{ col.key }}</code></td>
                          <td>
                            <VChip size="x-small" :color="col.required ? 'error' : 'grey'" label>
                              {{ col.required ? t('budgets_planning.required') : t('budgets_planning.optional') }}
                            </VChip>
                          </td>
                          <td class="text-caption">
                            <template v-if="col.values">
                              <VChip v-for="v in col.values" :key="v" size="x-small" label class="me-1">{{ v }}</VChip>
                              <div class="text-medium-emphasis mt-1">{{ t(`budgets_planning.col_help_${col.key}`) }}</div>
                            </template>
                            <template v-else>{{ t(`budgets_planning.col_help_${col.key}`) }}</template>
                          </td>
                        </tr>
                      </tbody>
                    </VTable>
                  </div>
                </VExpansionPanelText>
              </VExpansionPanel>

              <!-- The category names a file may use, so nobody has to guess a spelling -->
              <VExpansionPanel :title="t('budgets_planning.categories_title')">
                <VExpansionPanelText>
                  <p class="text-body-2 text-medium-emphasis">{{ t('budgets_planning.categories_hint') }}</p>
                  <div v-if="categories.length" class="d-flex flex-wrap gap-1">
                    <VChip v-for="c in categories" :key="c" size="x-small" label>{{ c }}</VChip>
                  </div>
                  <VAlert v-else type="info" variant="tonal" density="compact">
                    {{ t('budgets_planning.categories_none') }}
                  </VAlert>
                </VExpansionPanelText>
              </VExpansionPanel>
            </VExpansionPanels>
          </VCardText>
          <VDivider />
          <VCardActions>
            <VSpacer />
            <VBtn
              color="primary" :loading="importForm.processing"
              :disabled="!importForm.file || !importForm.financial_year_id"
              @click="submitImport"
            >
              {{ t('budgets_planning.import_action') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>

    <ConfirmDialog ref="confirmer" />
  </div>
</template>

<style scoped>
/* The template offer, set apart from the form so it reads as the first step rather than a footnote. */
.template-callout {
  background: rgba(var(--v-theme-primary), 0.06);
  border: 1px solid rgba(var(--v-theme-primary), 0.2);
}

.table-scroll {
  overflow-x: auto;
}
</style>
