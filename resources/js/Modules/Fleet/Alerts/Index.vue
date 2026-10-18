<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Row = Record<string, string | number | null>

const props = defineProps<{
  collectionOverdue: Row[]
  returnOverdue: Row[]
  incidents: Row[]
  serviceDue: Row[]
  docAlerts: Row[]
  warrantyAlerts: Row[]
  permitAlerts: Row[]
}>()

const tab = ref('collectionOverdue')

const sections = computed(() => [
  { key: 'collectionOverdue', label: t('fleet_alerts_index.sections.collection_overdue'),   icon: 'tabler-car-off',            color: 'error' },
  { key: 'returnOverdue',     label: t('fleet_alerts_index.sections.return_overdue'),       icon: 'tabler-car',               color: 'error' },
  { key: 'incidents',         label: t('fleet_alerts_index.sections.incidents'),            icon: 'tabler-alert-triangle',     color: 'warning' },
  { key: 'serviceDue',        label: t('fleet_alerts_index.sections.service_due'),          icon: 'tabler-tool',               color: 'warning' },
  { key: 'docAlerts',         label: t('fleet_alerts_index.sections.documents_expiring'),   icon: 'tabler-file-alert',         color: 'orange' },
  { key: 'warrantyAlerts',    label: t('fleet_alerts_index.sections.warranty_expired'),     icon: 'tabler-shield-x',           color: 'deep-orange' },
  { key: 'permitAlerts',      label: t('fleet_alerts_index.sections.permits_expired'),      icon: 'tabler-certificate-off',    color: 'deep-orange' },
] as const)

function currentRows(): Row[] {
  return (props as any)[tab.value] ?? []
}

function colKeys(rows: Row[]): string[] {
  if (!rows.length) return []
  return Object.keys(rows[0])
}

function printSection() {
  window.print()
}

function totalAlerts() {
  return [props.collectionOverdue, props.returnOverdue, props.incidents, props.serviceDue, props.docAlerts, props.warrantyAlerts, props.permitAlerts]
    .reduce((sum, arr) => sum + arr.length, 0)
}

function chipColor(row: Row, col: string): string {
  const val = String(row[col] ?? '').toLowerCase()
  if (val.includes('expir') || val.includes('critical') || val === 'error') return 'error'
  if (val.includes('soon') || val === 'warning') return 'warning'
  return 'success'
}
</script>

<style>
@media print {
  .no-print { display: none !important; }
  .v-navigation-drawer, .v-app-bar { display: none !important; }
  body { background: white !important; }
}
</style>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-4">
      <div class="d-flex align-center ga-2">
        <VIcon color="error" size="28">tabler-bell-ringing</VIcon>
        <div>
          <h1 class="text-h5 font-weight-bold">{{ t('fleet_alerts_index.page_title') }}</h1>
          <p class="text-caption text-medium-emphasis">
            {{ t('fleet_alerts_index.alert_count', { count: totalAlerts() }) }}
          </p>
        </div>
      </div>
      <VBtn class="no-print" prepend-icon="tabler-printer" variant="tonal" @click="printSection">
        {{ t('fleet_alerts_index.print') }}
      </VBtn>
    </div>

    <!-- Alert summary chips -->
    <div class="d-flex flex-wrap ga-2 mb-4 no-print">
      <VChip
        v-for="s in sections" :key="s.key"
        :color="(props as any)[s.key].length ? s.color : 'grey'"
        variant="tonal"
        :prepend-icon="s.icon"
        @click="tab = s.key"
        :class="{ 'elevation-2': tab === s.key }"
      >
        {{ s.label }}
        <template #append>
          <VBadge :content="(props as any)[s.key].length" :color="(props as any)[s.key].length ? s.color : 'grey'" inline />
        </template>
      </VChip>
    </div>

    <!-- Tab navigation -->
    <div class="no-print">
      <VTabs v-model="tab" color="primary" class="mb-0">
        <VTab v-for="s in sections" :key="s.key" :value="s.key">
          <VIcon start size="16">{{ s.icon }}</VIcon>
          {{ s.label }}
          <VBadge v-if="(props as any)[s.key].length" :content="(props as any)[s.key].length" inline :color="s.color" class="ml-1" />
        </VTab>
      </VTabs>
      <VDivider />
    </div>

    <!-- Print heading for all sections -->
    <div v-for="s in sections" :key="s.key" class="print-section">
      <div class="d-none d-print-block">
        <h2 class="text-h6 mt-6 mb-2">{{ s.label }}</h2>
        <VDivider class="mb-2" />
        <VTable v-if="(props as any)[s.key].length" density="compact">
          <thead>
            <tr>
              <th v-for="col in colKeys((props as any)[s.key])" :key="col">{{ col }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, ri) in (props as any)[s.key]" :key="ri">
              <td v-for="col in colKeys((props as any)[s.key])" :key="col">{{ row[col] ?? '—' }}</td>
            </tr>
          </tbody>
        </VTable>
        <p v-else class="text-medium-emphasis">{{ t('fleet_alerts_index.no_alerts_category') }}</p>
      </div>
    </div>

    <!-- Screen view (tab window) -->
    <VWindow v-model="tab" class="no-print">
      <VWindowItem v-for="s in sections" :key="s.key" :value="s.key">
        <VCard variant="flat">
          <VCardText class="pa-0">
            <div v-if="!(props as any)[s.key].length" class="pa-8 text-center text-medium-emphasis">
              <VIcon size="48" :color="s.color" class="mb-2">{{ s.icon }}</VIcon>
              <div>{{ t('fleet_alerts_index.no_alerts_section', { section: s.label.toLowerCase() }) }}</div>
            </div>
            <VTable v-else density="comfortable">
              <thead>
                <tr>
                  <th v-for="col in colKeys((props as any)[s.key])" :key="col">{{ col }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, ri) in (props as any)[s.key]" :key="ri">
                  <td v-for="col in colKeys((props as any)[s.key])" :key="col">
                    <VChip v-if="col === 'Status' || col === 'Severity' || col === 'Priority'" size="x-small"
                      :color="chipColor(row, col)"
                      label>
                      {{ row[col] }}
                    </VChip>
                    <span v-else>{{ row[col] ?? '—' }}</span>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VCard>
      </VWindowItem>
    </VWindow>
  </div>
</template>
