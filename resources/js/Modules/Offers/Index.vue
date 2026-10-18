<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type OfferRow = {
  id: number; uuid: string
  status: { value: string; label: string; color: string }
  position_title: string
  department: string | null
  salary_amount: string | null
  salary_currency: string
  sent_at: string | null
  accepted_at: string | null
  candidate: { id: number; full_name: string; email: string } | null
  job: { id: number; title: string } | null
  created_at: string
}

const props = defineProps<{
  offers: { data: OfferRow[]; links: any[]; meta: any }
  filters: Record<string, any>
  stats: { total: number; draft: number; sent: number; accepted: number }
}>()

const status = ref<string>(props.filters?.status ?? '')

function applyFilters() {
  router.get(route('offers.index'), { status: status.value || undefined }, { preserveState: true, replace: true })
}

watch(status, applyFilters)

const rows  = computed(() => props.offers?.data  ?? [])
const links = computed(() => props.offers?.links ?? [])
const meta  = computed(() => props.offers?.meta  ?? {})

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

function formatSalary(amount: string | null, currency: string): string {
  if (!amount) return '—'
  return `${currency} ${Number(amount).toLocaleString('en-ZA')} p.m.`
}

function goToPage(url: string | null) {
  if (url) router.visit(url, { preserveState: true })
}

const statusItems = computed(() => [
  { title: t('offers_pages.index.filter.all'),              value: '' },
  { title: t('offers_pages.index.filter.draft'),            value: 'draft' },
  { title: t('offers_pages.index.filter.pending_approval'), value: 'pending_approval' },
  { title: t('offers_pages.index.filter.approved'),         value: 'approved' },
  { title: t('offers_pages.index.filter.sent'),             value: 'sent' },
  { title: t('offers_pages.index.filter.accepted'),         value: 'accepted' },
  { title: t('offers_pages.index.filter.declined'),         value: 'declined' },
  { title: t('offers_pages.index.filter.expired'),          value: 'expired' },
])

const headers = computed(() => [
  { title: t('offers_pages.index.table.candidate'), key: 'candidate',       sortable: false },
  { title: t('offers_pages.index.table.position'),  key: 'position_title',  sortable: false },
  { title: t('offers_pages.index.table.salary'),    key: 'salary',          sortable: false, width: '180px' },
  { title: t('offers_pages.index.table.status'),    key: 'status',          sortable: false, width: '140px' },
  { title: t('offers_pages.index.table.sent'),      key: 'sent_at',         sortable: false, width: '120px' },
  { title: t('offers_pages.index.table.actions'),   key: 'actions',         sortable: false, width: '80px' },
])
</script>

<template>
  <div class="pa-6">
    <!-- Page header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold mb-1">{{ t('offers_pages.index.title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('offers_pages.index.subtitle') }}</p>
      </div>
    </div>

    <!-- Stat cards -->
    <VRow class="mb-5">
      <VCol cols="6" md="3">
        <VCard elevation="0" border>
          <VCardText class="pa-4 text-center">
            <p class="text-h5 font-weight-bold text-primary mb-0">{{ stats.total }}</p>
            <p class="text-caption text-medium-emphasis mb-0">{{ t('offers_pages.index.stats.total') }}</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard elevation="0" border class="cursor-pointer" @click="status = 'draft'">
          <VCardText class="pa-4 text-center">
            <p class="text-h5 font-weight-bold text-warning mb-0">{{ stats.draft }}</p>
            <p class="text-caption text-medium-emphasis mb-0">{{ t('offers_pages.index.stats.draft') }}</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard elevation="0" border class="cursor-pointer" @click="status = 'sent'">
          <VCardText class="pa-4 text-center">
            <p class="text-h5 font-weight-bold text-info mb-0">{{ stats.sent }}</p>
            <p class="text-caption text-medium-emphasis mb-0">{{ t('offers_pages.index.stats.sent') }}</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard elevation="0" border class="cursor-pointer" @click="status = 'accepted'">
          <VCardText class="pa-4 text-center">
            <p class="text-h5 font-weight-bold text-success mb-0">{{ stats.accepted }}</p>
            <p class="text-caption text-medium-emphasis mb-0">{{ t('offers_pages.index.stats.accepted') }}</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Filter bar -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VRow dense>
          <VCol cols="12" md="3">
            <VSelect
              v-model="status"
              :items="statusItems"
              item-title="title"
              item-value="value"
              :label="t('offers_pages.index.filter.status')"
              hide-details
              density="comfortable"
              variant="outlined"
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard elevation="0" border>
      <VDataTable
        :headers="headers"
        :items="rows"
        hide-default-footer
        hover
        item-value="id"
        @click:row="(_: unknown, row: any) => router.visit(route('offers.show', [row.item.job?.id, row.item.id]))"
      >
        <template #item.candidate="{ item }">
          <div class="d-flex align-center gap-2">
            <VAvatar size="30" color="primary" variant="tonal">
              <span class="text-caption font-weight-bold">
                {{ (item.candidate?.full_name ?? '?')[0].toUpperCase() }}
              </span>
            </VAvatar>
            <div>
              <p class="text-body-2 font-weight-medium mb-0">
                {{ item.candidate?.full_name ?? '—' }}
              </p>
              <p class="text-caption text-medium-emphasis mb-0">
                {{ item.candidate?.email ?? '' }}
              </p>
            </div>
          </div>
        </template>

        <template #item.position_title="{ item }">
          <div>
            <p class="text-body-2 font-weight-medium mb-0">{{ item.position_title }}</p>
            <p v-if="item.department" class="text-caption text-medium-emphasis mb-0">
              {{ item.department }}
            </p>
          </div>
        </template>

        <template #item.salary="{ item }">
          <span class="text-body-2">
            {{ formatSalary(item.salary_amount, item.salary_currency) }}
          </span>
        </template>

        <template #item.status="{ item }">
          <VChip :color="item.status.color" size="small" variant="tonal" label>
            {{ item.status.label }}
          </VChip>
        </template>

        <template #item.sent_at="{ item }">
          <span class="text-body-2 text-medium-emphasis">{{ formatDate(item.sent_at) }}</span>
        </template>

        <template #item.actions="{ item }">
          <VBtn
            icon="tabler-eye"
            size="small"
            variant="text"
            color="default"
            @click.stop="router.visit(route('offers.show', [item.job?.id, item.id]))"
          />
        </template>

        <template #no-data>
          <div class="text-center py-12">
            <VIcon icon="tabler-file-text" size="64" color="medium-emphasis" class="mb-4" />
            <p class="text-body-1 font-weight-medium mb-1">{{ t('offers_pages.index.table.no_data') }}</p>
            <p class="text-body-2 text-medium-emphasis mb-0">
              {{ t('offers_pages.index.table.no_data_subtitle') }}
            </p>
          </div>
        </template>
      </VDataTable>

      <VDivider v-if="meta.last_page > 1" />
      <div v-if="meta.last_page > 1" class="d-flex align-center justify-space-between pa-4">
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t('offers_pages.index.table.showing', { from: meta.from, to: meta.to, total: meta.total }) }}
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
