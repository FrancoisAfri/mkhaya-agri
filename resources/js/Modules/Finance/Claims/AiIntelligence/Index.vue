<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface HighRiskClaim {
  id: number; reference_number: string; employee: string; title: string
  category: string; total_amount: number; status: string
  risk_score: number; risk_level: string
  ai_flags: string[]; is_duplicate: boolean; is_inflated: boolean
  is_splitting: boolean; missing_receipt: boolean; out_of_policy: boolean
  explanation: string | null; claim_date: string
}

interface Stats {
  total_analysed: number; high_risk: number; duplicates: number
  inflated: number; splitting: number; missing_receipts: number
}

const props = defineProps<{ highRiskClaims: HighRiskClaim[]; stats: Stats }>()

const selected = ref<HighRiskClaim | null>(null)
const drawer   = ref(false)
const analysing = ref(false)

function openDetail(claim: HighRiskClaim) { selected.value = claim; drawer.value = true }

function analyseAll() {
  analysing.value = true
  router.post(route('claims.ai.analyse-all'), {}, {
    onFinish: () => { analysing.value = false }
  })
}

const riskColor = (r: string) => ({ critical: 'error', high: 'deep-orange', medium: 'warning', low: 'success' })[r] ?? 'grey'
const fmt = (n: number) => 'R ' + n.toLocaleString('en-ZA', { minimumFractionDigits: 2 })

const statCards = computed(()=> [
  { label: t('claims_ai.stats.analysed'),        value: props.stats.total_analysed,  icon: 'tabler-database-check', color: 'primary' },
  { label: t('claims_ai.stats.high_critical'),    value: props.stats.high_risk,       icon: 'tabler-alert-circle',  color: 'error' },
  { label: t('claims_ai.stats.duplicates'),       value: props.stats.duplicates,      icon: 'tabler-copy',  color: 'orange' },
  { label: t('claims_ai.stats.inflated'),         value: props.stats.inflated,        icon: 'tabler-trending-up',   color: 'deep-orange' },
  { label: t('claims_ai.stats.splitting'),        value: props.stats.splitting,       icon: 'tabler-git-branch',    color: 'red' },
  { label: t('claims_ai.stats.missing_receipts'), value: props.stats.missing_receipts, icon: 'tabler-receipt-off', color: 'warning' },
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('claims_ai.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('claims_ai.description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-robot" :loading="analysing" @click="analyseAll">{{ t('claims_ai.analyse_all') }}</VBtn>
    </div>

    <!-- Stats -->
    <VRow dense class="mb-4">
      <VCol v-for="stat in statCards" :key="stat.label" cols="6" sm="4" md="2">
        <VCard variant="tonal" :color="stat.color" class="pa-3 text-center" elevation="0">
          <VIcon :color="stat.color" size="24" class="mb-1">{{ stat.icon }}</VIcon>
          <div class="text-h6 font-weight-bold">{{ stat.value }}</div>
          <div class="text-caption">{{ stat.label }}</div>
        </VCard>
      </VCol>
    </VRow>

    <!-- High-risk table -->
    <VCard>
      <VCardTitle class="pa-4 text-body-1 font-weight-bold d-flex align-center ga-2">
        <VIcon color="error">tabler-shield-exclamation</VIcon> {{ t('claims_ai.high_risk_claims.title') }}
      </VCardTitle>
      <VDivider />
      <template v-if="!highRiskClaims.length">
        <div class="text-center pa-12 text-medium-emphasis">
          <VIcon size="56" class="mb-3 opacity-30">tabler-robot</VIcon>
          <div>{{ t('claims_ai.high_risk_claims.empty') }}</div>
        </div>
      </template>
      <VDataTable v-else
        :items="highRiskClaims"
        :headers="[
          { title: t('claims_ai.high_risk_claims.table.reference'),  key: 'reference_number' },
          { title: t('claims_ai.high_risk_claims.table.employee'),   key: 'employee' },
          { title: t('claims_ai.high_risk_claims.table.amount'),     key: 'total_amount' },
          { title: t('claims_ai.high_risk_claims.table.risk_score'), key: 'risk_score' },
          { title: t('claims_ai.high_risk_claims.table.risk_level'), key: 'risk_level' },
          { title: t('claims_ai.high_risk_claims.table.flags'),      key: 'flags', sortable: false },
          { title: t('claims_ai.high_risk_claims.table.status'),     key: 'status' },
          { title: t('claims_ai.high_risk_claims.table.date'),       key: 'claim_date' },
          { title: '',           key: 'actions', sortable: false },
        ]"
        density="comfortable"
        hover
      >
        <template #item.reference_number="{ item }">
          <a :href="route('claims.my.show', { claim: item.id })" class="text-primary text-decoration-none font-weight-medium">{{ item.reference_number }}</a>
        </template>
        <template #item.total_amount="{ item }">
          <span class="font-weight-bold">{{ fmt(item.total_amount) }}</span>
        </template>
        <template #item.risk_score="{ item }">
          <VProgressLinear :model-value="item.risk_score" :color="riskColor(item.risk_level)" height="8" rounded class="w-75 d-inline-block mr-1" />
          <span class="text-caption">{{ item.risk_score }}</span>
        </template>
        <template #item.risk_level="{ item }">
          <VChip :color="riskColor(item.risk_level)" size="x-small" variant="tonal">{{ item.risk_level }}</VChip>
        </template>
        <template #item.flags="{ item }">
          <div class="d-flex ga-1 flex-wrap">
            <VChip v-if="item.is_duplicate"    color="error"  size="x-small" variant="tonal">{{ t('claims_ai.flags.dup') }}</VChip>
            <VChip v-if="item.is_inflated"     color="orange" size="x-small" variant="tonal">{{ t('claims_ai.flags.inflated') }}</VChip>
            <VChip v-if="item.is_splitting"    color="deep-orange" size="x-small" variant="tonal">{{ t('claims_ai.flags.split') }}</VChip>
            <VChip v-if="item.missing_receipt" color="warning" size="x-small" variant="tonal">{{ t('claims_ai.flags.no_receipt') }}</VChip>
            <VChip v-if="item.out_of_policy"   color="error"  size="x-small" variant="tonal">{{ t('claims_ai.flags.policy') }}</VChip>
          </div>
        </template>
        <template #item.actions="{ item }">
          <VBtn icon="tabler-info-circle" size="x-small" variant="text" @click="openDetail(item)" />
        </template>
      </VDataTable>
    </VCard>

    <!-- Detail Drawer -->
    <VNavigationDrawer v-model="drawer" location="right" :width="420" temporary>
      <div v-if="selected" class="pa-4">
        <div class="d-flex align-center justify-space-between mb-4">
          <span class="text-body-1 font-weight-bold">{{ selected.reference_number }}</span>
          <VBtn icon="tabler-x" variant="text" size="small" @click="drawer = false" />
        </div>
        <div class="d-flex align-center ga-3 mb-4">
          <VProgressCircular :model-value="selected.risk_score" :color="riskColor(selected.risk_level)" size="64" width="6">
            <span class="font-weight-bold">{{ selected.risk_score }}</span>
          </VProgressCircular>
          <div>
            <div class="font-weight-bold" :class="`text-${riskColor(selected.risk_level)}`">{{ t('claims_ai.drawer.risk_level', { level: selected.risk_level?.toUpperCase() }) }}</div>
            <div class="text-caption">{{ selected.employee }}</div>
            <div class="text-caption font-weight-bold">{{ fmt(selected.total_amount) }}</div>
          </div>
        </div>
        <div class="d-flex ga-2 flex-wrap mb-4">
          <VChip v-if="selected.is_duplicate"    color="error"  size="small" variant="tonal" prepend-icon="tabler-copy">{{ t('claims_ai.flags.duplicate') }}</VChip>
          <VChip v-if="selected.is_inflated"     color="orange" size="small" variant="tonal" prepend-icon="tabler-trending-up">{{ t('claims_ai.flags.inflated') }}</VChip>
          <VChip v-if="selected.is_splitting"    color="deep-orange" size="small" variant="tonal" prepend-icon="tabler-git-branch">{{ t('claims_ai.flags.splitting') }}</VChip>
          <VChip v-if="selected.missing_receipt" color="warning" size="small" variant="tonal" prepend-icon="tabler-receipt-off">{{ t('claims_ai.flags.missing_receipt') }}</VChip>
          <VChip v-if="selected.out_of_policy"   color="error"  size="small" variant="tonal" prepend-icon="tabler-shield-exclamation">{{ t('claims_ai.flags.out_of_policy') }}</VChip>
        </div>
        <VCard variant="outlined" class="pa-3">
          <div class="text-caption font-weight-bold mb-2">{{ t('claims_ai.drawer.ai_explanation') }}</div>
          <pre class="text-caption" style="white-space:pre-wrap;font-size:11px">{{ selected.explanation || t('claims_ai.drawer.no_explanation') }}</pre>
        </VCard>
        <div class="mt-4">
          <VBtn block :href="route('claims.my.show', { claim: selected.id })" prepend-icon="tabler-external-link" variant="outlined">{{ t('claims_ai.drawer.view_full_claim') }}</VBtn>
        </div>
      </div>
    </VNavigationDrawer>
  </div>
</template>
