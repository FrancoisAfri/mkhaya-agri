<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import AppTextField from '@/@core/components/app-form-elements/AppTextField.vue'

const { t } = useI18n()
const route = ziggyRoute

type Plan = {
  id: number
  uuid: string
  status: { value: string; label: string; color: string }
  start_date: string
  end_date: string
  completion: number
  hr_person: {
    id: number
    uuid: string
    candidate: { full_name: string; email: string } | null
  }
  created_at: string
}

const props = defineProps<{
  plans: { data: Plan[]; links: any[]; meta: any }
  filters: Record<string, any>
  stats: { total: number; active: number; completed: number; pending: number }
}>()

const search = ref<string>(props.filters?.search ?? '')
const status = ref<string>(props.filters?.status ?? '')

let searchTimer: ReturnType<typeof setTimeout>

function applyFilters() {
  router.get(
    route('onboarding.index'),
    { search: search.value || undefined, status: status.value || undefined },
    { preserveState: true, replace: true },
  )
}

watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(applyFilters, 400)
})

watch(status, applyFilters)

function clearFilters() {
  search.value = ''
  status.value = ''
  router.get(route('onboarding.index'), {}, { replace: true })
}

const statCards = computed(() => [
  {
    label: t('onboarding.total_plans'),
    value: props.stats.total,
    icon:  'tabler-school',
    color: 'primary',
    bg:    'rgba(115,103,240,0.12)',
  },
  {
    label: t('onboarding.active'),
    value: props.stats.active,
    icon:  'tabler-player-play',
    color: 'info',
    bg:    'rgba(0,207,232,0.12)',
  },
  {
    label: t('onboarding.completed'),
    value: props.stats.completed,
    icon:  'tabler-circle-check',
    color: 'success',
    bg:    'rgba(40,199,111,0.12)',
  },
  {
    label: t('onboarding.pending'),
    value: props.stats.pending,
    icon:  'tabler-clock',
    color: 'warning',
    bg:    'rgba(255,159,67,0.12)',
  },
])

const headers = computed(() => [
  { title: t('onboarding.table_new_hire'),   key: 'hr_person',  sortable: false },
  { title: t('onboarding.table_status'),     key: 'status',     sortable: false, width: '130px' },
  { title: t('onboarding.table_progress'),   key: 'completion', sortable: false, width: '180px' },
  { title: t('onboarding.table_start_date'), key: 'start_date', sortable: false, width: '130px' },
  { title: t('onboarding.table_end_date'),   key: 'end_date',   sortable: false, width: '130px' },
])

const rows  = computed(() => props.plans?.data  ?? [])
const links = computed(() => props.plans?.links ?? [])
const meta  = computed(() => props.plans?.meta  ?? {})

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

function goToPage(url: string | null) {
  if (url) router.visit(url, { preserveState: true })
}

const quickFilters = computed(() => [
  { label: t('onboarding.quick_filter_all'),       value: '' },
  { label: t('onboarding.quick_filter_pending'),   value: 'pending',   color: 'warning' },
  { label: t('onboarding.quick_filter_active'),    value: 'active',    color: 'info'    },
  { label: t('onboarding.quick_filter_completed'), value: 'completed', color: 'success' },
  { label: t('onboarding.quick_filter_cancelled'), value: 'cancelled', color: 'error'   },
])
</script>

<template>
  <div class="pa-6">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold mb-1">{{ t('onboarding.title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('onboarding.subtitle') }}</p>
      </div>
    </div>

    <!-- Stat Cards -->
    <VRow class="mb-6">
      <VCol v-for="card in statCards" :key="card.label" cols="12" sm="6" lg="3">
        <VCard elevation="0" border>
          <VCardText class="d-flex align-center gap-4 pa-5">
            <div class="d-flex align-center justify-center rounded-lg flex-shrink-0" :style="{ background: card.bg, width: '52px', height: '52px' }">
              <VIcon :icon="card.icon" :color="card.color" size="28" />
            </div>
            <div>
              <p class="text-body-2 text-medium-emphasis mb-1">{{ card.label }}</p>
              <p class="text-h4 font-weight-bold mb-0" :style="{ color: `var(--v-theme-${card.color})` }">{{ card.value }}</p>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Quick Filter Chips -->
    <div class="d-flex flex-wrap gap-2 mb-5">
      <VChip
        v-for="qf in quickFilters"
        :key="qf.value"
        size="default"
        variant="tonal"
        :color="status === qf.value ? (qf.color ?? 'primary') : 'default'"
        class="cursor-pointer font-weight-medium"
        @click="status = qf.value"
      >
        {{ qf.label }}
      </VChip>
    </div>

    <!-- Filter Bar -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VRow dense align="center">
          <VCol cols="12" md="5">
            <AppTextField
              v-model="search"
              :label="t('onboarding.search_placeholder')"
              prepend-inner-icon="tabler-search"
              clearable
              hide-details
            />
          </VCol>
          <VCol cols="auto" class="ms-auto">
            <VBtn
              v-if="search || status"
              variant="text"
              color="default"
              prepend-icon="tabler-x"
              @click="clearFilters"
            >
              {{ t('onboarding.clear') }}
            </VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard elevation="0" border>
      <VDataTable
        :headers="headers"
        :items="rows"
        item-value="id"
        hide-default-footer
        :items-per-page="20"
        hover
        @click:row="(_: unknown, row: any) => router.visit(route('onboarding.plans.show', [row.item.hr_person.id, row.item.id]))"
      >
        <!-- New Hire -->
        <template #item.hr_person="{ item }">
          <div class="d-flex align-center gap-2">
            <VAvatar size="36" color="primary" variant="tonal">
              <span class="text-caption font-weight-bold">
                {{ (item.hr_person.candidate?.full_name ?? '?')[0].toUpperCase() }}
              </span>
            </VAvatar>
            <div>
              <p class="text-body-2 font-weight-semibold mb-0">
                {{ item.hr_person.candidate?.full_name ?? t('onboarding.unknown') }}
              </p>
              <p class="text-caption text-medium-emphasis mb-0">
                {{ item.hr_person.candidate?.email ?? '' }}
              </p>
            </div>
          </div>
        </template>

        <!-- Status -->
        <template #item.status="{ item }">
          <VChip :color="item.status.color" size="small" label variant="tonal" class="font-weight-medium">
            {{ item.status.label }}
          </VChip>
        </template>

        <!-- Progress -->
        <template #item.completion="{ item }">
          <div class="d-flex align-center gap-2">
            <VProgressLinear
              :model-value="item.completion"
              :color="item.status.value === 'completed' ? 'success' : 'primary'"
              rounded
              height="6"
              style="min-width: 80px;"
            />
            <span class="text-caption font-weight-medium">{{ item.completion }}%</span>
          </div>
        </template>

        <!-- Start Date -->
        <template #item.start_date="{ item }">
          <span class="text-body-2 text-medium-emphasis">{{ formatDate(item.start_date) }}</span>
        </template>

        <!-- End Date -->
        <template #item.end_date="{ item }">
          <span class="text-body-2 text-medium-emphasis">{{ formatDate(item.end_date) }}</span>
        </template>

        <!-- Empty state -->
        <template #no-data>
          <div class="text-center py-12">
            <VIcon icon="tabler-school" size="64" color="medium-emphasis" class="mb-4" />
            <p class="text-body-1 font-weight-medium mb-1">{{ t('onboarding.no_plans') }}</p>
            <p class="text-body-2 text-medium-emphasis mb-0">{{ t('onboarding.no_plans_sub') }}</p>
          </div>
        </template>
      </VDataTable>

      <!-- Pagination -->
      <VDivider v-if="meta.last_page > 1" />
      <div v-if="meta.last_page > 1" class="d-flex align-center justify-space-between pa-4">
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t('onboarding.showing', { from: meta.from ?? 0, to: meta.to ?? 0, total: meta.total }) }}
        </p>
        <div class="d-flex gap-1">
          <VBtn
            v-for="link in links"
            :key="link.label"
            :disabled="!link.url"
            :variant="link.active ? 'flat' : 'text'"
            :color="link.active ? 'primary' : 'default'"
            size="small"
            @click="goToPage(link.url)"
            v-html="link.label"
          />
        </div>
      </div>
    </VCard>
  </div>
</template>
