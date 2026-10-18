<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t, d } = useI18n()

type Column = 'text' | 'number' | 'date' | 'datetime' | 'bool' | 'status'
type Definition = { group: string; periodic: boolean; columns: Record<string, Column> }

const props = defineProps<{
  noCompany?: boolean
  catalogue?: Record<string, Definition>
  groups?: string[]
  report?: string | null
  periodic?: boolean
  from?: string
  to?: string
  siteId?: number | null
  sites?: Array<{ id: number; name: string }>
  rows?: Array<Record<string, any>>
  kpis?: Record<string, any> | null
}>()

const selected = ref(props.report ?? '')
const from = ref(props.from ?? '')
const to = ref(props.to ?? '')
const site = ref<number | null>(props.siteId ?? null)
const search = ref('')

watch(() => props.report, v => { selected.value = v ?? '' })

const definition = computed(() => (props.catalogue ?? {})[selected.value] ?? null)
const columns = computed(() => Object.entries(definition.value?.columns ?? {}))

/** The picker, grouped the way somebody hunting for a report would think about it. */
const grouped = computed(() =>
  (props.groups ?? []).map(group => ({
    group,
    items: Object.entries(props.catalogue ?? {})
      .filter(([, d]) => d.group === group)
      .map(([key]) => key),
  })).filter(g => g.items.length))

const query = computed(() => ({
  report: selected.value,
  from: from.value,
  to: to.value,
  site_id: site.value ?? '',
}))

function reload() {
  router.get('/sheq/insights/reports', query.value, { preserveState: true, preserveScroll: true })
}

const exportHref = computed(() => {
  const p = new URLSearchParams(
    Object.entries(query.value).map(([k, v]) => [k, String(v ?? '')]))
  return `/sheq/insights/reports/export?${p.toString()}`
})

/** Client-side filter over the rows already on screen — the server round trip is for the report. */
const visible = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.rows ?? []
  return (props.rows ?? []).filter(row =>
    Object.values(row).some(v => String(v ?? '').toLowerCase().includes(q)))
})

function format(value: any, kind: Column) {
  if (value === null || value === undefined || value === '') return '—'
  if (kind === 'bool') return value ? t('sheq.reports.yes') : t('sheq.reports.no')
  if (kind === 'date' || kind === 'datetime') {
    try { return d(new Date(value), kind === 'date' ? 'short' : 'long') } catch { return value }
  }
  return value
}

/**
 * A negative "days until" is not a smaller number, it is an overdue one.
 *
 * Rendering -12 in the same grey as 12 is how a table of expiring medicals reads as fine at a
 * glance; the row that needs acting on is the one that must catch the eye.
 */
function overdue(key: string, value: any) {
  return (key === 'days_until' && Number(value) < 0)
      || (key === 'days_overdue' && Number(value) > 0)
      || (key === 'is_low' && value === true)
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.reports.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.reports.subtitle') }}</p>
      </div>

      <VBtn
        v-if="!noCompany && visible.length" :href="exportHref" variant="outlined"
        prepend-icon="mdi-download-outline" style="cursor: pointer"
      >
        {{ t('sheq.reports.export') }}
      </VBtn>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VRow>
        <!-- ── The catalogue ───────────────────────────────────────────────────────────────── -->
        <VCol cols="12" md="3">
          <VCard elevation="0" border rounded="lg">
            <VList density="compact" nav>
              <template v-for="group in grouped" :key="group.group">
                <VListSubheader class="text-uppercase text-caption font-weight-bold">
                  {{ t(`sheq.reports.groups.${group.group}`) }}
                </VListSubheader>
                <VListItem
                  v-for="key in group.items" :key="key"
                  :active="selected === key" color="primary" style="cursor: pointer"
                  @click="selected = key; reload()"
                >
                  <VListItemTitle class="text-body-2">
                    {{ t(`sheq.reports.names.${key}`) }}
                  </VListItemTitle>
                </VListItem>
              </template>
            </VList>
          </VCard>
        </VCol>

        <!-- ── The report ──────────────────────────────────────────────────────────────────── -->
        <VCol cols="12" md="9">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="d-flex align-center justify-space-between flex-wrap ga-2 py-4">
              <div>
                <div class="text-body-1 font-weight-bold">
                  {{ selected ? t(`sheq.reports.names.${selected}`) : '' }}
                </div>
                <div class="text-caption text-medium-emphasis">
                  {{ selected ? t(`sheq.reports.help.${selected}`) : '' }}
                </div>
              </div>
              <VChip size="small" variant="tonal">
                {{ t('sheq.reports.row_count', { n: visible.length }) }}
              </VChip>
            </VCardTitle>

            <VDivider />

            <div class="pa-4">
              <VRow dense class="mb-1">
                <VCol cols="12" sm="6" md="3">
                  <VTextField
                    v-model="from" type="date" variant="outlined" density="compact" hide-details
                    :label="t('sheq.reports.from')" :disabled="periodic === false"
                    @change="reload"
                  />
                </VCol>
                <VCol cols="12" sm="6" md="3">
                  <VTextField
                    v-model="to" type="date" variant="outlined" density="compact" hide-details
                    :label="t('sheq.reports.to')" :disabled="periodic === false"
                    @change="reload"
                  />
                </VCol>
                <VCol cols="12" sm="6" md="3">
                  <VSelect
                    v-model="site" :items="sites ?? []" item-title="name" item-value="id"
                    clearable variant="outlined" density="compact" hide-details
                    :label="t('sheq.reports.all_sites')" @update:model-value="reload"
                  />
                </VCol>
                <VCol cols="12" sm="6" md="3">
                  <VTextField
                    v-model="search" variant="outlined" density="compact" hide-details clearable
                    prepend-inner-icon="mdi-magnify" :label="t('sheq.reports.filter')"
                  />
                </VCol>
              </VRow>

              <!--
                Said plainly rather than by greying the date fields alone: a report of what is on a
                shelf right now has no period, and a user who does not know that reads the ignored
                dates as a bug.
              -->
              <p v-if="periodic === false" class="text-caption text-medium-emphasis mb-0">
                <VIcon icon="mdi-information-outline" size="x-small" class="me-1" />
                {{ t('sheq.reports.not_periodic') }}
              </p>
            </div>

            <VDivider />

            <div v-if="!visible.length" class="text-center py-10 text-medium-emphasis text-body-2">
              {{ rows?.length ? t('sheq.reports.no_match') : t('sheq.reports.empty') }}
            </div>

            <div v-else class="sheq-scroll">
              <VTable density="comfortable" class="text-body-2">
                <thead>
                  <tr>
                    <th
                      v-for="[key, kind] in columns" :key="key" scope="col"
                      :class="kind === 'number' ? 'text-end' : ''"
                    >
                      {{ t(`sheq.reports.columns.${key}`) }}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, i) in visible" :key="i">
                    <td
                      v-for="[key, kind] in columns" :key="key"
                      :class="[kind === 'number' ? 'text-end' : '',
                               overdue(key, row[key]) ? 'text-error font-weight-bold' : '']"
                    >
                      <VChip v-if="kind === 'status' && row[key]" size="x-small" variant="tonal">
                        {{ row[key] }}
                      </VChip>
                      <template v-else>{{ format(row[key], kind) }}</template>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </div>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>

<style scoped>
.sheq-scroll { overflow-x: auto; }
</style>
