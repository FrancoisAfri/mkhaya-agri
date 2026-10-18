<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'
import AddEntryDialog from '@/Modules/Sheq/Partials/AddEntryDialog.vue'

const { t, d } = useI18n()

type Item = { id: number; uuid: string; code: string; name: string; category: string | null
  lifespan_months: number | null; requires_size: boolean }

const props = defineProps<{
  items: Item[]
  issues: Array<Record<string, any>>
  due: Array<Record<string, any>>
  lowStock: Array<Record<string, any>>
  people: Array<{ id: number; name: string }>
  canManage?: boolean
  noCompany?: boolean
}>()

const itemOptions = computed(() => props.items.map(i => ({
  title: i.code ? `${i.name} (${i.code})` : i.name, value: i.id,
})))

const personOptions = computed(() => props.people.map(p => ({ title: p.name, value: p.id })))

// A size is asked for only when the item chosen needs one. The field used to sit there disabled
// and greyed on every other item, which reads as something broken rather than as not applicable.
const needsSize = (values: Record<string, unknown>) =>
  props.items.find(i => i.id === values.ppe_item_id)?.requires_size === true

const overdue = computed(() => props.due.filter(d => d.overdue).length)

const formatDate = (v: string | null) => { if (!v) return '—'; try { return d(new Date(v), 'short') } catch { return v } }
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.ppe.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.ppe.subtitle') }}</p>
      </div>
      <div class="d-flex ga-2">
        <VBtn
          v-if="canManage" variant="outlined" prepend-icon="mdi-cog-outline"
          href="/sheq/settings/ppe" style="cursor: pointer"
        >
          {{ t('sheq.ppe.manage') }}
        </VBtn>
        <!-- Hidden rather than disabled when the catalogue is empty: the alert below says where
             to go, and a dead button beside it says nothing. -->
        <AddEntryDialog
          v-if="items.length"
          :title="t('sheq.ppe.issue')" action="/sheq/ppe/issue"
          :button-label="t('sheq.ppe.issue')" :note="t('sheq.ppe.issue_note')"
          :fields="[
            { key: 'ppe_item_id', label: t('sheq.ppe.item'), type: 'select' as const,
              items: itemOptions, required: true },
            { key: 'hr_person_id', label: t('sheq.ppe.issued_to'), type: 'select' as const,
              items: personOptions, required: true },
            { key: 'size', label: t('sheq.ppe.size'), showIf: needsSize,
              hint: t('sheq.ppe.size_hint') },
            { key: 'quantity', label: t('sheq.ppe.quantity'), type: 'number' as const, default: 1 },
            { key: 'serial_number', label: t('sheq.ppe.serial'), hint: t('sheq.ppe.serial_hint') },
            { key: 'note', label: t('sheq.registers.col.note'), type: 'textarea' as const, cols: 12 },
          ]"
        />
      </div>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VAlert v-if="overdue" type="warning" variant="tonal" class="mb-4" role="status" aria-live="polite">
        {{ overdue }} {{ t('sheq.ppe.due').toLowerCase() }}
      </VAlert>

      <!--
        The dead end this page used to be: an issue form whose only dropdown was empty, with no
        way in to fill it. Say where to go rather than leaving somebody hunting.
      -->
      <VAlert
        v-if="!items.length" type="info" variant="tonal" class="mb-4"
        icon="mdi-information-outline"
      >
        {{ t('sheq.ppe.empty_catalogue') }}
        <template v-if="canManage" #append>
          <VBtn size="small" variant="tonal" href="/sheq/settings/ppe" style="cursor: pointer">
            {{ t('sheq.ppe.manage') }}
          </VBtn>
        </template>
      </VAlert>


      <VRow>
        <VCol cols="12" md="7">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">{{ t('sheq.ppe.title') }}</VCardTitle>
            <VDivider />
            <div v-if="!issues.length" class="text-center py-10 text-medium-emphasis">
              <VIcon icon="mdi-hard-hat" size="32" class="mb-2" />
              <div class="text-body-2">{{ t('sheq.ppe.none') }}</div>
            </div>
            <VTable v-else density="compact">
              <thead><tr>
                <th>{{ t('sheq.ppe.title') }}</th><th>Person</th>
                <th>{{ t('sheq.ppe.size') }}</th><th>{{ t('sheq.ppe.replace_due') }}</th>
              </tr></thead>
              <tbody>
                <tr v-for="i in issues" :key="i.uuid">
                  <td class="text-body-2">{{ i.item }}</td>
                  <td class="text-body-2">{{ i.person }}</td>
                  <td class="text-caption">{{ i.size || '—' }}</td>
                  <td class="text-caption">{{ formatDate(i.replace_due) }}</td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>

        <VCol cols="12" md="5">
          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">{{ t('sheq.ppe.due') }}</VCardTitle>
            <VDivider />
            <div v-if="!due.length" class="pa-4 text-body-2 text-medium-emphasis">Nothing due.</div>
            <VTable v-else density="compact">
              <tbody>
                <tr v-for="r in due" :key="r.uuid">
                  <td class="text-body-2">{{ r.item }}</td>
                  <td class="text-caption">{{ r.person }}</td>
                  <td style="width: 100px">
                    <VChip size="x-small" :color="r.overdue ? 'error' : 'warning'" variant="tonal">
                      {{ formatDate(r.replace_due) }}
                    </VChip>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>

          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">{{ t('sheq.ppe.low_stock') }}</VCardTitle>
            <VDivider />
            <div v-if="!lowStock.length" class="pa-4 text-body-2 text-medium-emphasis">Stock is fine.</div>
            <VTable v-else density="compact">
              <tbody>
                <tr v-for="(s, i) in lowStock" :key="i">
                  <td class="text-body-2">{{ s.name }}<span v-if="s.size" class="text-caption"> ({{ s.size }})</span></td>
                  <td class="text-caption text-medium-emphasis">{{ s.quantity_on_hand }} / {{ s.reorder_level }}</td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>

<style scoped>
.row-hover { cursor: pointer; transition: background-color 200ms ease; }
.row-hover:hover { background-color: rgba(var(--v-theme-on-surface), 0.04); }
.row-hover:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: -2px; }
.min-width-0 { min-width: 0; }
@media (prefers-reduced-motion: reduce) { .row-hover { transition: none; } }
</style>
