<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { date, dateTime } from '../Partials/format'

const { t } = useI18n()

defineProps<{
  run: any
  context: any
}>()

/**
 * A frozen run is rendered from its stored payload verbatim, without re-deriving anything. That is
 * the point of freezing it: a quarterly report tabled at a board meeting must read the same next
 * year even as the underlying transactions are legitimately corrected. Rendering it generically
 * also means a new report type needs no new page.
 */
function isScalar(value: unknown): boolean {
  return value === null || ['string', 'number', 'boolean'].includes(typeof value)
}

function label(key: string): string {
  return key.replace(/_/g, ' ').replace(/^./, c => c.toUpperCase())
}

function display(value: unknown): string {
  if (value === null || value === undefined || value === '') return '—'
  // Words, not glyphs: this renders into a board report that gets printed and read aloud,
  // and a tick is neither translatable nor announced.
  if (typeof value === 'boolean') return value ? t('procurement_governance.common.yes') : t('procurement_governance.common.no')

  return String(value)
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <Link
        href="/procurement-governance/reports"
        class="text-caption text-primary text-decoration-none d-inline-flex align-center mb-1"
      >
        <VIcon icon="mdi-arrow-left" size="14" class="me-1" />{{ t('procurement_governance.reports.title') }}
      </Link>
      <h1 class="text-h5 font-weight-bold">{{ run.title }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">
        {{ run.period_label ?? `${date(run.period_start)} – ${date(run.period_end)}` }}
        · {{ t('procurement_governance.reports.generated', { at: dateTime(run.generated_at) }) }}
      </p>
    </div>

    <VAlert type="info" variant="tonal" density="compact" class="mb-4 text-body-2" icon="mdi-lock-check-outline">
      {{ t('procurement_governance.reports.frozen_notice') }}
      <template v-if="run.payload_hash">
        <span class="procgov-hash d-block mt-1">{{ run.payload_hash }}</span>
      </template>
    </VAlert>

    <VCard
      v-for="(section, key) in (run.payload ?? {})" :key="key"
      elevation="0" border rounded="lg" class="mb-4"
    >
      <VCardTitle class="text-body-1 font-weight-bold py-3">{{ label(key as string) }}</VCardTitle>
      <VCardText class="pt-0">
        <!-- Scalar -->
        <div v-if="isScalar(section)" class="text-body-2">{{ display(section) }}</div>

        <!-- A list of records -->
        <div v-else-if="Array.isArray(section)" class="procgov-scroll">
          <div v-if="!section.length" class="text-body-2 text-medium-emphasis">
            {{ t('procurement_governance.common.empty') }}
          </div>
          <VTable v-else-if="typeof section[0] === 'object'" density="compact">
            <thead>
              <tr>
                <th v-for="column in Object.keys(section[0])" :key="column" scope="col">
                  {{ label(column) }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, i) in section" :key="i">
                <td v-for="column in Object.keys(section[0])" :key="column" class="text-body-2">
                  {{ display((row as any)[column]) }}
                </td>
              </tr>
            </tbody>
          </VTable>
          <ul v-else class="text-body-2 ps-4 mb-0">
            <li v-for="(item, i) in section" :key="i">{{ display(item) }}</li>
          </ul>
        </div>

        <!-- A group of figures -->
        <VRow v-else dense>
          <VCol v-for="(value, sub) in section" :key="sub" cols="12" md="6" lg="4">
            <div class="py-1 d-flex justify-space-between ga-3">
              <span class="text-body-2 text-medium-emphasis">{{ label(sub as string) }}</span>
              <span v-if="isScalar(value)" class="text-body-2 font-weight-medium text-end">
                {{ display(value) }}
              </span>
              <span v-else class="text-caption text-medium-emphasis text-end">
                {{ Array.isArray(value)
                  ? t('procurement_governance.reports.rows_n', { n: value.length })
                  : t('procurement_governance.reports.nested') }}
              </span>
            </div>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>
  </div>
</template>

<style scoped>
.procgov-scroll { overflow-x: auto; }
.procgov-hash { word-break: break-all; font-size: 0.7rem; }
</style>
