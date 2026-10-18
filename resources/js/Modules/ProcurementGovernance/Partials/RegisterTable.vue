<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import StatusChip from './StatusChip.vue'
import { count, date, money, score } from './format'

const { t } = useI18n()

export type Column = {
  key: string
  label: string
  /**
   * How the cell renders. `status` uses StatusChip so colour is never the only signal; `money`,
   * `date` and `score` all render null as an em dash rather than as zero (see format.ts).
   */
  kind?: 'text' | 'status' | 'money' | 'date' | 'score' | 'count' | 'muted' | 'link'
  align?: 'start' | 'end'
  /** For `link`: builds the href from the row. */
  href?: (row: Record<string, any>) => string
  /** For `money`: which field holds the currency, defaulting to the row's own. */
  currencyKey?: string
  width?: string
  /** Hidden below md — for columns that matter but must not force horizontal scroll on a phone. */
  secondary?: boolean
}

const props = withDefaults(defineProps<{
  rows: Record<string, any>[]
  columns: Column[]
  title?: string
  subtitle?: string
  searchKeys?: string[]
  emptyHint?: string
  /** Highlights rows needing attention — an overdue item, a failed check. */
  rowFlag?: (row: Record<string, any>) => boolean
  rowKey?: string
}>(), { rowKey: 'uuid' })

const search = ref('')

const filtered = computed(() => {
  if (!search.value) return props.rows

  const q = search.value.toLowerCase()
  const keys = props.searchKeys ?? props.columns.map(c => c.key)

  return props.rows.filter(row => keys.some(k => String(row[k] ?? '').toLowerCase().includes(q)))
})

function cell(row: Record<string, any>, column: Column): string {
  const value = row[column.key]

  switch (column.kind) {
    case 'money': return money(value, row[column.currencyKey ?? 'currency'] ?? 'ZAR')
    case 'date': return date(value)
    case 'score': return score(value)
    case 'count': return count(value)
    default: return value === null || value === undefined || value === '' ? '—' : String(value)
  }
}
</script>

<template>
  <VCard elevation="0" border rounded="lg">
    <VCardTitle v-if="title || searchKeys !== undefined" class="d-flex align-center justify-space-between flex-wrap ga-2 py-3">
      <div v-if="title" class="min-width-0">
        <div class="text-body-1 font-weight-bold">{{ title }}</div>
        <div v-if="subtitle" class="text-caption text-medium-emphasis">{{ subtitle }}</div>
      </div>
      <VTextField
        v-if="rows.length"
        v-model="search"
        density="compact"
        variant="outlined"
        prepend-inner-icon="mdi-magnify"
        hide-details
        clearable
        style="max-inline-size: 280px"
        :label="t('procurement_governance.common.search')"
      />
    </VCardTitle>

    <!-- An empty register says what would fill it, rather than leaving a blank panel that reads as
         a broken page. -->
    <div v-if="!rows.length" class="text-center py-10 px-4 text-medium-emphasis">
      <VIcon icon="mdi-tray-remove" size="32" class="mb-2" />
      <div class="text-body-2">{{ emptyHint ?? t('procurement_governance.common.empty') }}</div>
    </div>

    <div v-else-if="!filtered.length" class="text-center py-10 px-4 text-medium-emphasis">
      <VIcon icon="mdi-magnify-close" size="32" class="mb-2" />
      <div class="text-body-2">{{ t('procurement_governance.common.no_matches', { term: search }) }}</div>
    </div>

    <!-- Wide registers scroll inside their own card. Without this the whole page scrolls
         horizontally on a phone, which is the single most common way a data table breaks a
         layout. -->
    <div v-else class="procgov-table-scroll">
      <VTable density="comfortable">
        <thead>
          <tr>
            <th
              v-for="column in columns"
              :key="column.key"
              :style="column.width ? `width: ${column.width}` : undefined"
              :class="[
                column.align === 'end' ? 'text-end' : 'text-start',
                column.secondary ? 'd-none d-md-table-cell' : '',
              ]"
              scope="col"
            >
              {{ column.label }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="row in filtered"
            :key="row[rowKey] ?? JSON.stringify(row)"
            :class="{ 'procgov-row-flagged': rowFlag?.(row) }"
          >
            <td
              v-for="column in columns"
              :key="column.key"
              :class="[
                column.align === 'end' ? 'text-end' : 'text-start',
                column.kind === 'muted' ? 'text-medium-emphasis' : '',
                column.secondary ? 'd-none d-md-table-cell' : '',
              ]"
            >
              <StatusChip v-if="column.kind === 'status'" :status="row[column.key]" />

              <a
                v-else-if="column.kind === 'link' && column.href"
                :href="column.href(row)"
                class="text-primary font-weight-medium procgov-cell-link"
              >{{ cell(row, column) }}</a>

              <span v-else>{{ cell(row, column) }}</span>
            </td>
          </tr>
        </tbody>
      </VTable>
    </div>
  </VCard>
</template>

<style scoped>
.procgov-table-scroll { overflow-x: auto; }

.min-width-0 { min-inline-size: 0; }

.procgov-table-scroll :deep(tbody tr) { transition: background-color 150ms ease; }

.procgov-table-scroll :deep(tbody tr:hover) {
  background-color: rgba(var(--v-theme-primary), 0.04);
}

/* A left rule rather than a background tint: the row still reads normally, and the marker survives
   a colour-blind reader and a monochrome print of the register. */
.procgov-row-flagged td:first-child {
  box-shadow: inset 3px 0 0 0 rgb(var(--v-theme-warning));
}

.procgov-cell-link { text-decoration: none; cursor: pointer; }
.procgov-cell-link:hover { text-decoration: underline; }
.procgov-cell-link:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: 2px; }

@media (prefers-reduced-motion: reduce) {
  .procgov-table-scroll :deep(tbody tr) { transition: none; }
}
</style>
