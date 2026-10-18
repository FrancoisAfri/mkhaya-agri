<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Column = {
  key: string
  label: string
  /** 'chip' colours by `chipColour`, 'flag' shows a warning when the row's flag field is true. */
  kind?: 'text' | 'chip' | 'flag' | 'muted'
  chipColour?: (row: Record<string, any>) => string
  flagField?: string
  width?: string
}

const props = defineProps<{
  title: string
  subtitle?: string
  rows: Record<string, any>[]
  columns: Column[]
  searchKeys?: string[]
  emptyHint?: string
  /**
   * Where a row goes when it is clicked.
   *
   * Registers listed records and stopped there: an assessment could be added and then nothing
   * could be done with it, because the row led nowhere. Given here rather than per table so every
   * register gains it at once, and left undefined by registers that genuinely have no detail
   * screen — a row that looks clickable and is not is worse than one that does not.
   */
  rowHref?: (row: Record<string, any>) => string | null
}>()

const hrefFor = (row: Record<string, any>) => props.rowHref ? props.rowHref(row) : null

const search = ref('')

const filtered = computed(() => {
  if (!search.value) return props.rows
  const q = search.value.toLowerCase()
  const keys = props.searchKeys ?? props.columns.map(c => c.key)

  return props.rows.filter(row =>
    keys.some(k => String(row[k] ?? '').toLowerCase().includes(q)))
})
</script>

<template>
  <VCard elevation="0" border rounded="lg" class="mb-4">
    <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-2">
      <div>
        <div class="text-body-1 font-weight-semibold">{{ title }}</div>
        <div v-if="subtitle" class="text-caption text-medium-emphasis">{{ subtitle }}</div>
      </div>
      <VTextField
        v-if="rows.length" v-model="search" density="compact" variant="outlined"
        prepend-inner-icon="mdi-magnify" hide-details clearable style="max-inline-size: 260px"
        :label="t('sheq.registers.search')"
      />
    </VCardTitle>

    <!-- An empty register says what would fill it, rather than leaving a blank panel that reads
         as a broken page. -->
    <div v-if="!rows.length" class="text-center py-10 px-4 text-medium-emphasis">
      <VIcon icon="mdi-tray-remove" size="32" class="mb-2" />
      <div class="text-body-2">{{ emptyHint ?? t('sheq.registers.empty') }}</div>
    </div>

    <VTable v-else density="comfortable">
      <thead>
        <tr>
          <th v-for="col in columns" :key="col.key" scope="col" :style="col.width ? `width:${col.width}` : ''">
            {{ col.label }}
          </th>
          <th v-if="$slots['row-actions']" scope="col" class="text-end">
            <span class="d-sr-only">{{ t('sheq.action.actions') }}</span>
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="(row, index) in filtered"
          :key="row.uuid ?? index"
          :class="hrefFor(row) ? 'register-row--link' : ''"
          :tabindex="hrefFor(row) ? 0 : undefined"
          :role="hrefFor(row) ? 'link' : undefined"
          @click="hrefFor(row) && router.visit(hrefFor(row)!)"
          @keydown.enter="hrefFor(row) && router.visit(hrefFor(row)!)"
        >
          <td v-for="col in columns" :key="col.key">
            <template v-if="col.kind === 'chip'">
              <VChip :color="col.chipColour ? col.chipColour(row) : undefined"
                     size="x-small" variant="tonal">
                {{ row[col.key] ?? '—' }}
              </VChip>
            </template>

            <template v-else-if="col.kind === 'flag'">
              <span :class="row[col.flagField ?? ''] ? 'text-error font-weight-medium' : ''">
                {{ row[col.key] ?? '—' }}
              </span>
              <!-- Not colour alone (SHEQ-A11Y-3): the icon and the text carry it too. -->
              <VIcon v-if="row[col.flagField ?? '']" icon="mdi-alert" color="error"
                     size="14" class="ms-1" />
            </template>

            <span v-else-if="col.kind === 'muted'" class="text-body-2 text-medium-emphasis">
              {{ row[col.key] ?? '—' }}
            </span>

            <span v-else class="text-body-2">{{ row[col.key] ?? '—' }}</span>
          </td>
          <!-- Optional per-row action, so a register can offer the next step without every table
               growing a bespoke column. -->
          <td v-if="$slots['row-actions']" class="text-end">
            <slot name="row-actions" :row="row" />
          </td>
        </tr>
        <tr v-if="!filtered.length">
          <td :colspan="columns.length + ($slots['row-actions'] ? 1 : 0)"
              class="text-center py-6 text-medium-emphasis">
            {{ t('sheq.registers.no_match') }}
          </td>
        </tr>
      </tbody>
    </VTable>
  </VCard>
</template>

<style scoped>
/* A row that navigates has to look like it does, and keep a visible focus ring. */
.register-row--link { cursor: pointer; }
.register-row--link:hover { background: rgba(var(--v-theme-on-surface), 0.04); }
.register-row--link:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: -2px; }
</style>
