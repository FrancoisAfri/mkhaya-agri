<script setup lang="ts">
import { reactive, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '../Partials/NoCompanyNotice.vue'
import RegisterTable, { type Column } from '../Partials/RegisterTable.vue'
import { date, daysUntil, humanise } from '../Partials/format'

const { t } = useI18n()

const props = defineProps<{
  documents: any[]
  filters: Record<string, any>
  categories: { code: string; name: string; default_confidentiality: string }[]
  levels: string[]
  expiring: any[]
  noCompany?: boolean
}>()

const filters = reactive({
  search: props.filters.search ?? '',
  category: props.filters.category ?? null,
  confidentiality: props.filters.confidentiality ?? null,
})

let timer: ReturnType<typeof setTimeout> | undefined
watch(filters, () => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    router.get('/procurement-governance/documents', { ...filters }, {
      preserveState: true, preserveScroll: true, replace: true,
    })
  }, 300)
})

const columns: Column[] = [
  {
    key: 'title',
    label: t('procurement_governance.common.title'),
    kind: 'link',
    href: row => `/procurement-governance/documents/${row.uuid}`,
  },
  { key: 'category_name', label: t('procurement_governance.common.category'), secondary: true },
  { key: 'confidentiality', label: t('procurement_governance.documents.confidentiality'), kind: 'status', width: '160px' },
  { key: 'owner', label: t('procurement_governance.documents.owner'), secondary: true },
  { key: 'link_count', label: t('procurement_governance.documents.links'), kind: 'count', align: 'end', width: '80px' },
  { key: 'version_count', label: t('procurement_governance.documents.versions'), kind: 'count', align: 'end', width: '90px' },
  { key: 'expires_at', label: t('procurement_governance.common.expires'), kind: 'date', width: '130px' },
]

/** Expired, or expiring within a month — the two states this register exists to surface. */
function flag(row: any): boolean {
  const days = daysUntil(row.expires_at)
  return days !== null && days <= 30
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('procurement_governance.documents.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('procurement_governance.documents.subtitle') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VAlert
        v-if="expiring.length" type="warning" variant="tonal"
        class="mb-4" icon="mdi-calendar-alert"
      >
        <div class="text-body-2 font-weight-medium mb-1">
          {{ t('procurement_governance.documents.expiring_soon', { n: expiring.length }) }}
        </div>
        <div class="text-body-2">
          {{ expiring.slice(0, 4).map(d => `${d.title} (${date(d.expires_at)})`).join(' · ') }}
          <template v-if="expiring.length > 4"> …</template>
        </div>
      </VAlert>

      <VCard elevation="0" border rounded="lg" class="mb-4">
        <VCardText class="d-flex flex-wrap ga-3 py-3">
          <VTextField
            v-model="filters.search" :label="t('procurement_governance.common.search')"
            prepend-inner-icon="mdi-magnify" clearable
            density="compact" variant="outlined" hide-details style="max-inline-size: 280px"
          />
          <VSelect
            v-model="filters.category"
            :items="categories.map(c => ({ title: c.name, value: c.code }))" clearable
            :label="t('procurement_governance.common.category')"
            density="compact" variant="outlined" hide-details style="max-inline-size: 240px"
          />
          <VSelect
            v-model="filters.confidentiality"
            :items="levels.map(l => ({ title: humanise(l), value: l }))" clearable
            :label="t('procurement_governance.documents.confidentiality')"
            density="compact" variant="outlined" hide-details style="max-inline-size: 220px"
          />
        </VCardText>
      </VCard>

      <!-- The register only ever lists what this reader may see; the filter happens server-side
           against confidentiality, explicit grants and permissions together. -->
      <RegisterTable
        :rows="documents"
        :columns="columns"
        :search-keys="['title', 'category_name', 'owner']"
        :empty-hint="t('procurement_governance.documents.empty')"
        :row-flag="flag"
      />
    </template>
  </div>
</template>
