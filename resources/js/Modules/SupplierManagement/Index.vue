<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
interface Supplier {
  id: number
  uuid: string
  supplier_number: string | null
  company_name: string
  company_registration_number: string | null
  email: string | null
  is_active: number
  risk_band: string | null
  last_appraisal_score: number | string | null
  last_monitored_at: string | null
  contacts_count: number
  products_count: number
}

interface PaginatedData {
  data: Supplier[]
  links: any[]
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  suppliers: PaginatedData
  filters: { status?: string; company_name?: string; registration?: string; risk_band?: string }
  stats: { total: number; active: number; pending: number; refused: number; elevated_risk: number; unverified_active: number }
  riskMix: { band: string; count: number }[]
  growth: { month: string; count: number }[]
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const companyName = ref<string>(props.filters.company_name ?? '')
const registration = ref<string>(props.filters.registration ?? '')
const statusFilter = ref<string>(props.filters.status ?? '')
const riskFilter = ref<string>(props.filters.risk_band ?? '')
const confirmDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null)

// ── Constants ──────────────────────────────────────────────────────────────────
const statusLabel: Record<number, string> = {
  0: t('supplier_index.statuses.pending'),
  1: t('supplier_index.statuses.active'),
  2: t('supplier_index.statuses.refused'),
  3: t('supplier_index.statuses.pending_kyc'),
}

const statusColor: Record<number, string> = {
  0: 'warning',
  1: 'success',
  2: 'error',
  3: 'info',
}

const statusOptions = [
  { title: t('supplier_index.filters.all'), value: '' },
  { title: t('supplier_index.statuses.pending'), value: '0' },
  { title: t('supplier_index.statuses.active'), value: '1' },
  { title: t('supplier_index.statuses.refused'), value: '2' },
  { title: t('supplier_index.statuses.pending_kyc'), value: '3' },
]

const riskColour: Record<string, string> = {
  critical: 'error', high: 'error', moderate: 'warning', low: 'success', unrated: 'default',
}

const riskOptions = computed(() => [
  { title: t('supplier_index.filters.all_risk'), value: '' },
  ...['critical', 'high', 'moderate', 'low', 'unrated'].map(b => ({
    title: t('supplier_index.risk.' + b), value: b,
  })),
])

/** How a score reads at a glance. A band, not a colour alone — "poor" must differ from "not scored". */
function scoreColour(score: number | string | null) {
  if (score === null || score === '') return 'default'
  const n = Number(score)
  if (n >= 80) return 'success'
  if (n >= 60) return 'info'
  if (n >= 40) return 'warning'
  return 'error'
}

/*
 * Two charts drawn as plain SVG. A charting library for one bar row and twelve columns is a
 * dependency to keep current in exchange for nothing a reader could not already see.
 */
const riskTotal = computed(() => props.riskMix.reduce((sum, r) => sum + r.count, 0))

const growthBars = computed(() => {
  const max = Math.max(...props.growth.map(g => g.count), 1)
  return props.growth.map(g => ({ ...g, height: Math.round((g.count / max) * 100) }))
})

// ── Methods ────────────────────────────────────────────────────────────────────
function applyFilters(): void {
  router.get(
    route('suppliers.index'),
    {
      company_name: companyName.value,
      registration: registration.value,
      status: statusFilter.value,
      risk_band: riskFilter.value,
    },
    { preserveState: true, replace: true }
  )
}

// A supplier is addressed by uuid, so every action on one takes that, not the row id.
function activate(uuid: string): void {
  router.post(route('suppliers.activate', uuid), {}, { preserveScroll: true })
}

function refuse(uuid: string): void {
  router.post(route('suppliers.refuse', uuid), {}, { preserveScroll: true })
}

function destroy(uuid: string): void {
  confirmDialog.value?.open(
    {
      title: t('supplier_index.actions.delete_confirm_title'),
      message: t('supplier_index.actions.delete_confirm_message'),
      confirmText: t('supplier_index.actions.delete_confirm_button'),
      confirmColor: 'error',
      icon: 'tabler-trash',
    },
    () => {
      router.delete(route('suppliers.destroy', uuid), { preserveScroll: true })
    }
  )
}

function goToTrash(): void {
  router.get(route('suppliers.trash'))
}

function goToCreate(): void {
  router.get(route('suppliers.create'))
}

</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <h1 class="text-h4 mb-0">{{ t('supplier_index.page_title') }}</h1>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-trash" @click="goToTrash">
          {{ t('supplier_index.trash') }}
        </VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="goToCreate">
          {{ t('supplier_index.new_supplier') }}
        </VBtn>
      </div>
    </div>

    <!--
      The directory was a list and nothing else: readable for a minute without telling you whether
      anything on it needed doing. These answer what somebody actually opens it with.
    -->
    <VRow class="mb-1">
      <VCol cols="6" md="2">
        <VCard elevation="0" border rounded="lg"><VCardText class="py-3">
          <div class="text-caption text-medium-emphasis">{{ t('supplier_index.stats.total') }}</div>
          <div class="text-h5">{{ stats.total }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="2">
        <VCard elevation="0" border rounded="lg"><VCardText class="py-3">
          <div class="text-caption text-medium-emphasis">{{ t('supplier_index.stats.active') }}</div>
          <div class="text-h5 text-success">{{ stats.active }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="2">
        <!-- Clickable, because a count somebody must act on should take them to the work. -->
        <VCard elevation="0" border rounded="lg" link @click="statusFilter = '0'; applyFilters()">
          <VCardText class="py-3">
            <div class="text-caption text-medium-emphasis">{{ t('supplier_index.stats.pending') }}</div>
            <div class="text-h5" :class="stats.pending ? 'text-warning' : ''">{{ stats.pending }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" md="2">
        <VCard elevation="0" border rounded="lg" link @click="riskFilter = 'high'; applyFilters()">
          <VCardText class="py-3">
            <div class="text-caption text-medium-emphasis">{{ t('supplier_index.stats.elevated_risk') }}</div>
            <div class="text-h5" :class="stats.elevated_risk ? 'text-error' : ''">{{ stats.elevated_risk }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <!--
          Active suppliers nobody has ever checked. A pending supplier with no verification is
          expected; an active one is money going out against evidence nobody collected.
        -->
        <VCard elevation="0" border rounded="lg" :color="stats.unverified_active ? 'error' : undefined"
               :variant="stats.unverified_active ? 'tonal' : undefined">
          <VCardText class="py-3">
            <div class="text-caption">{{ t('supplier_index.stats.unverified_active') }}</div>
            <div class="d-flex align-center justify-space-between">
              <div class="text-h5">{{ stats.unverified_active }}</div>
              <VBtn v-if="stats.unverified_active" size="x-small" variant="text"
                    :href="route('suppliers.monitoring.index')">
                {{ t('supplier_index.stats.open_monitoring') }}
              </VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mb-3">
      <VCol cols="12" md="6">
        <VCard elevation="0" border rounded="lg" class="h-100">
          <VCardText>
            <div class="text-caption text-medium-emphasis mb-2">{{ t('supplier_index.charts.risk_mix') }}</div>
            <div v-if="!riskTotal" class="text-caption text-medium-emphasis">{{ t('supplier_index.charts.no_risk_data') }}</div>
            <template v-else>
              <!-- One stacked bar: the question is proportion, and proportion is what a bar shows. -->
              <div class="d-flex rounded overflow-hidden mb-2" style="height:14px">
                <div v-for="r in riskMix" :key="r.band" :class="`bg-${riskColour[r.band]}`"
                     :style="{ width: ((r.count / riskTotal) * 100) + '%' }"
                     :title="`${t('supplier_index.risk.' + r.band)}: ${r.count}`" />
              </div>
              <div class="d-flex flex-wrap ga-3">
                <div v-for="r in riskMix" :key="r.band" class="d-flex align-center ga-1">
                  <span class="d-inline-block rounded-circle" :class="`bg-${riskColour[r.band]}`" style="width:8px;height:8px" />
                  <span class="text-caption">{{ t('supplier_index.risk.' + r.band) }}</span>
                  <span class="text-caption font-weight-medium">{{ r.count }}</span>
                </div>
              </div>
            </template>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard elevation="0" border rounded="lg" class="h-100">
          <VCardText>
            <div class="text-caption text-medium-emphasis mb-2">{{ t('supplier_index.charts.growth') }}</div>
            <!-- Months with nobody onboarded are a real zero, not a gap, so they keep their column. -->
            <div class="d-flex align-end ga-1" style="height:72px">
              <div v-for="g in growthBars" :key="g.month" class="flex-grow-1 d-flex flex-column align-center justify-end"
                   :title="`${g.month}: ${g.count}`" style="height:100%">
                <div class="w-100 rounded-t bg-primary" :style="{ height: Math.max(g.height, 2) + '%', opacity: g.count ? 0.85 : 0.18 }" />
              </div>
            </div>
            <div class="d-flex justify-space-between mt-1">
              <span class="text-caption text-medium-emphasis">{{ growth[0]?.month }}</span>
              <span class="text-caption text-medium-emphasis">{{ growth[growth.length - 1]?.month }}</span>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Filters -->
    <VCard border class="mb-4">
      <VCardText class="d-flex flex-wrap gap-3">
        <VTextField
          v-model="companyName"
          density="compact"
          variant="outlined"
          hide-details
          :placeholder="t('supplier_index.filters.company_name')"
          style="max-width:240px"
          @keyup.enter="applyFilters"
        />
        <VTextField
          v-model="registration"
          density="compact"
          variant="outlined"
          hide-details
          :placeholder="t('supplier_index.filters.registration')"
          style="max-width:200px"
          @keyup.enter="applyFilters"
        />
        <VSelect
          v-model="statusFilter"
          :items="statusOptions"
          density="compact"
          variant="outlined"
          hide-details
          :label="t('supplier_index.filters.status')"
          style="max-width:180px"
          @update:model-value="applyFilters"
        />
        <VSelect
          v-model="riskFilter"
          :items="riskOptions"
          density="compact"
          variant="outlined"
          hide-details
          :label="t('supplier_index.filters.risk')"
          style="max-width:180px"
          @update:model-value="applyFilters"
        />
        <VBtn variant="tonal" @click="applyFilters">
          {{ t('supplier_index.filters.filter') }}
        </VBtn>
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('supplier_index.table.number') }}</th>
            <th>{{ t('supplier_index.table.company') }}</th>
            <th>{{ t('supplier_index.table.email') }}</th>
            <th>{{ t('supplier_index.table.status') }}</th>
            <th>{{ t('supplier_index.table.risk') }}</th>
            <th class="text-right">{{ t('supplier_index.table.last_score') }}</th>
            <th>{{ t('supplier_index.table.contacts') }}</th>
            <th class="text-right">{{ t('supplier_index.table.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!suppliers.data.length">
            <td colspan="7" class="text-center text-medium-emphasis py-8">
              {{ t('supplier_index.table.no_suppliers') }}
            </td>
          </tr>
          <tr v-for="s in suppliers.data" :key="s.id">
            <td>{{ s.supplier_number ?? '—' }}</td>
            <!--
              A real link, not a clickable cell. This used to be a bare <td> with a click handler and
              a pointer cursor: not focusable, no role, so tabbing through the directory never
              reached it and a keyboard user could not open a supplier from the list at all.
            -->
            <td class="font-weight-medium">
              <Link
                :href="route('suppliers.show', s.uuid)"
                class="supplier-link"
              >
                {{ s.company_name }}
              </Link>
            </td>
            <td>{{ s.email ?? '—' }}</td>
            <td>
              <VChip
                size="small"
                :color="statusColor[s.is_active]"
                variant="tonal"
              >
                {{ statusLabel[s.is_active] }}
              </VChip>
            </td>
            <td>
              <!--
                Unrated is shown as its own thing rather than left blank: an empty cell reads as
                "nothing to worry about", and it means nobody has looked.
              -->
              <VChip size="small" variant="tonal" :color="riskColour[s.risk_band ?? 'unrated']">
                {{ t('supplier_index.risk.' + (s.risk_band ?? 'unrated')) }}
              </VChip>
            </td>
            <td class="text-right">
              <!-- The directory is where somebody decides who to place work with, and it said
                   nothing about how the last work went. -->
              <VChip v-if="s.last_appraisal_score !== null && s.last_appraisal_score !== ''"
                     size="small" variant="tonal" :color="scoreColour(s.last_appraisal_score)">
                {{ Math.round(Number(s.last_appraisal_score) * 10) / 10 }}
              </VChip>
              <span v-else class="text-caption text-medium-emphasis">{{ t('supplier_index.table.never_appraised') }}</span>
            </td>
            <td>{{ s.contacts_count }}</td>
            <td class="text-right">
              <VBtn
                icon
                size="small"
                :aria-label="t('supplier_index.actions.activate')"
                :title="t('supplier_index.actions.activate')"
                variant="text"
                color="success"
                @click="activate(s.uuid)"
              >
                <VIcon icon="tabler-check" />
              </VBtn>
              <VBtn
                icon
                size="small"
                :aria-label="t('supplier_index.actions.refuse')"
                :title="t('supplier_index.actions.refuse')"
                variant="text"
                color="warning"
                @click="refuse(s.uuid)"
              >
                <VIcon icon="tabler-x" />
              </VBtn>
              <VBtn
                icon
                size="small"
                :aria-label="t('supplier_index.actions.delete')"
                :title="t('supplier_index.actions.delete')"
                variant="text"
                color="error"
                @click="destroy(s.uuid)"
              >
                <VIcon icon="tabler-trash" />
              </VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <ConfirmDialog ref="confirmDialog" />
  </div>
</template>

<style scoped>
/*
  The supplier name is the way into a record, so it has to look like a link and show where the
  keyboard is. The focus ring is the part that matters: without it, tabbing through the directory
  moves an invisible cursor.
*/
.supplier-link {
  color: inherit;
  text-decoration: none;
}

.supplier-link:hover {
  color: rgb(var(--v-theme-primary));
  text-decoration: underline;
}

.supplier-link:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
  border-radius: 2px;
}
</style>
