<script lang="ts" setup>
import { ref, computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Filter { field: string; operator: string; value: string }
interface Kpi {
  id: number; uuid: string; name: string; entity: string; metric: string
  field: string | null; filters: Filter[]; format: string; color: string; value: number; pin_to_dashboard: boolean
}

const props = defineProps<{ kpis: Kpi[]; entities: string[]; columns: Record<string, string[]>; numeric: Record<string, string[]>; metrics: string[]; formats: string[] }>()

const colors = ['primary', 'success', 'info', 'warning', 'error', 'teal', 'purple']
const operators = ['=', '!=', '>', '>=', '<', '<=', 'contains', 'in']

const dialog = ref(false)
const blank = () => ({ id: null as number | null, name: '', entity: 'deal', metric: 'count', field: '', filters: [] as Filter[], format: 'number', color: 'primary', pin_to_dashboard: false })
const form = useForm(blank())

const entityColumns = computed(() => props.columns[form.entity] ?? [])
const entityNumeric = computed(() => props.numeric[form.entity] ?? [])
const needsField = computed(() => form.metric !== 'count')

watch(() => [form.entity, form.metric], () => { if (needsField.value && !entityNumeric.value.includes(form.field ?? '')) form.field = entityNumeric.value[0] ?? '' })

function fmt(k: Kpi): string { const v = k.value ?? 0; if (k.format === 'currency') return 'R ' + v.toLocaleString('en-ZA', { maximumFractionDigits: 0 }); if (k.format === 'percent') return v + '%'; return v.toLocaleString('en-ZA') }

function openCreate() { form.defaults(blank()); form.reset(); dialog.value = true }
function openEdit(k: Kpi) {
  form.defaults({ id: k.id, name: k.name, entity: k.entity, metric: k.metric, field: k.field ?? '', filters: [...k.filters], format: k.format, color: k.color, pin_to_dashboard: k.pin_to_dashboard })
  form.reset(); dialog.value = true
}
function addFilter() { form.filters.push({ field: entityColumns.value[0] ?? '', operator: '=', value: '' }) }
function removeFilter(i: number) { form.filters.splice(i, 1) }
function save() { if (form.id) form.patch(route('crm.metrics.update', form.id), { preserveScroll: true, onSuccess: () => dialog.value = false }); else form.post(route('crm.metrics.store'), { preserveScroll: true, onSuccess: () => dialog.value = false }) }
function remove(k: Kpi) { useForm({}).delete(route('crm.metrics.destroy', k.id), { preserveScroll: true }) }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3"><div><h5 class="text-h5 font-weight-semibold">{{ t('crm_metrics_index.page_title') }}</h5><p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_metrics_index.description') }}</p></div><VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('crm_metrics_index.new_kpi') }}</VBtn></div>

    <VRow>
      <VCol v-for="k in kpis" :key="k.id" cols="12" sm="6" md="4" lg="3">
        <VCard :color="k.color" variant="tonal">
          <VCardText>
            <div class="d-flex justify-space-between align-start">
              <div class="text-body-2 font-weight-medium">{{ k.name }}</div>
              <VMenu>
                <template #activator="{ props: menu }">
                  <VBtn v-bind="menu" size="x-small" variant="text" icon="tabler-dots-vertical" />
                </template>
                <VList density="compact">
                  <VListItem prepend-icon="tabler-edit" title="Edit" @click="openEdit(k)" />
                  <VListItem prepend-icon="tabler-trash" title="Delete" @click="remove(k)" />
                </VList>
              </VMenu>
            </div>
            <div class="text-h4 font-weight-bold mt-2">{{ fmt(k) }}</div>
            <div class="text-caption text-medium-emphasis text-capitalize">
              {{ k.metric }}<span v-if="k.field"> of {{ k.field }}</span> · {{ k.entity }}s
              <span v-if="k.filters.length"> · {{ k.filters.length }} filter(s)</span>
            </div>
            <VChip v-if="k.pin_to_dashboard" size="x-small" label class="mt-2" prepend-icon="tabler-pin">On dashboard</VChip>
          </VCardText>
        </VCard>
      </VCol>
      <VCol v-if="!kpis.length" cols="12">
        <VAlert type="info" variant="tonal">No KPIs yet — create your first metric.</VAlert>
      </VCol>
    </VRow>

    <VDialog v-model="dialog" max-width="600">
      <VCard>
        <VCardTitle>{{ form.id ? 'Edit' : 'New' }} KPI</VCardTitle>
        <VCardText>
          <VTextField v-model="form.name" label="Name" class="mb-3" />
          <VRow>
            <VCol cols="6"><VSelect v-model="form.entity" label="Entity" :items="entities" class="text-capitalize" /></VCol>
            <VCol cols="6"><VSelect v-model="form.metric" label="Metric" :items="metrics" /></VCol>
            <VCol v-if="needsField" cols="6"><VSelect v-model="form.field" label="Field" :items="entityNumeric" /></VCol>
            <VCol cols="6"><VSelect v-model="form.format" label="Format" :items="formats" /></VCol>
            <VCol cols="6"><VSelect v-model="form.color" label="Colour" :items="colors" /></VCol>
          </VRow>

          <div class="text-overline mt-3">Filters</div>
          <div v-for="(f, i) in form.filters" :key="i" class="d-flex gap-2 mb-2 align-center">
            <VSelect v-model="f.field" :items="entityColumns" density="compact" hide-details style="max-width: 170px" />
            <VSelect v-model="f.operator" :items="operators" density="compact" hide-details style="max-width: 110px" />
            <VTextField v-model="f.value" label="Value" density="compact" hide-details />
            <VBtn size="x-small" variant="text" icon="tabler-x" color="error" @click="removeFilter(i)" />
          </div>
          <VBtn size="small" variant="tonal" prepend-icon="tabler-plus" @click="addFilter">Add filter</VBtn>

          <VDivider class="my-4" />
          <VSwitch v-model="form.pin_to_dashboard" color="primary" density="compact" hide-details
            label="Show on the main CRM dashboard" />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="dialog = false">Cancel</VBtn><VBtn color="primary" :loading="form.processing" @click="save">Save</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>