<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import ConditionEditor from './components/ConditionEditor.vue'
import DynamicForm from './components/DynamicForm.vue'

const route = ziggyRoute
const props = defineProps<{
  form: { uuid: string; name: string; description: string | null; version: number | null; fields: any[] }
  versions: Array<{ version: number; at: string }>
  types: Record<string, string>; operators: Record<string, string>; patterns: string[]
  can: { ai: boolean }
}>()
const fields = ref<any[]>(JSON.parse(JSON.stringify(props.form.fields)))
const selected = ref<number | null>(fields.value.length ? 0 : null)
const f = computed(() => (selected.value !== null ? fields.value[selected.value] : null))
const save = useForm({ name: props.form.name, description: props.form.description ?? '', fields: [] as any[] })
const preview = ref<Record<string, any>>({})
const keyed = computed(() => fields.value.filter(x => x.key).map(x => ({ key: x.key, label: x.label || x.key })))
const typeItems = computed(() => Object.entries(props.types).map(([value, title]) => ({ value, title })))

function slug(s: string) { return s.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 60) || 'field' }
function add(type = 'text') {
  let k = 'field', i = fields.value.length + 1
  while (fields.value.some(x => x.key === `${k}_${i}`)) i++
  fields.value.push({ key: `${k}_${i}`, label: 'New field', type, required: false, ...(['select', 'multiselect', 'radio', 'checkboxes'].includes(type) ? { options: ['Option 1'] } : {}), ...(type === 'line_items' ? { columns: [{ key: 'item', label: 'Item', type: 'text', required: true }] } : {}) })
  selected.value = fields.value.length - 1
}
function move(i: number, d: number) { const j = i + d; if (j < 0 || j >= fields.value.length) return; const [x] = fields.value.splice(i, 1); fields.value.splice(j, 0, x); selected.value = j }
function remove(i: number) { fields.value.splice(i, 1); selected.value = fields.value.length ? Math.min(i, fields.value.length - 1) : null }
// Editor-only flags (a leading underscore) never reach the saved form.
const clean = (x: any) => Object.fromEntries(Object.entries(x).filter(([k]) => !k.startsWith('_')))
function submit() { save.transform(d => ({ ...d, fields: fields.value.map(clean) })).post(route('workflow.forms.save', props.form.uuid), { preserveScroll: true }) }

const suggesting = ref(false)
async function suggest() {
  suggesting.value = true
  const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '')
  try {
    const r = await fetch(route('workflow.forms.ai_suggest'), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf },
      body: JSON.stringify({ purpose: `${save.name}. ${save.description}` }) })
    const j = await r.json()
    for (const s of j.fields ?? []) if (!fields.value.some(x => x.key === s.key)) fields.value.push(s)
  } finally { suggesting.value = false }
}
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-2" @click="router.get(route('workflow.forms.index'))">Forms</VBtn>
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-3">
      <div class="d-flex gap-3 flex-wrap" style="flex: 1">
        <VTextField v-model="save.name" label="Form name" density="compact" style="max-inline-size: 320px" :error-messages="save.errors.name" />
        <VTextField v-model="save.description" label="Description" density="compact" style="max-inline-size: 420px" />
      </div>
      <div class="d-flex gap-2">
        <VBtn v-if="can.ai" variant="tonal" prepend-icon="tabler-sparkles" :loading="suggesting" @click="suggest">Suggest fields</VBtn>
        <VBtn color="primary" prepend-icon="tabler-device-floppy" :loading="save.processing" @click="submit">Save as version {{ (form.version ?? 0) + 1 }}</VBtn>
      </div>
    </div>
    <VAlert v-if="save.errors.fields" type="error" variant="tonal" density="compact" class="mb-3">{{ save.errors.fields }}</VAlert>
    <VRow>
      <VCol cols="12" md="3">
        <VCard border>
          <VList density="compact">
            <VListItem v-for="(x, i) in fields" :key="i" :active="selected === i" @click="selected = i">
              <VListItemTitle class="text-body-2">{{ x.label || x.key }}{{ x.required ? ' *' : '' }}</VListItemTitle>
              <VListItemSubtitle>{{ types[x.type] ?? x.type }}{{ x.visible_if ? ' · conditional' : '' }}{{ x.sensitive ? ' · sensitive' : '' }}</VListItemSubtitle>
              <template #append>
                <VBtn icon="tabler-chevron-up" size="x-small" variant="text" @click.stop="move(i, -1)" />
                <VBtn icon="tabler-chevron-down" size="x-small" variant="text" @click.stop="move(i, 1)" />
              </template>
            </VListItem>
          </VList>
          <VCardText><VMenu><template #activator="{ props: p }"><VBtn v-bind="p" block variant="tonal" prepend-icon="tabler-plus">Add field</VBtn></template>
            <VList density="compact"><VListItem v-for="t in typeItems" :key="t.value" @click="add(t.value)"><VListItemTitle>{{ t.title }}</VListItemTitle></VListItem></VList></VMenu></VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="5">
        <VCard v-if="f" border>
          <VCardItem><VCardTitle class="text-body-1">Field</VCardTitle><template #append><VBtn icon="tabler-trash" size="small" variant="text" color="error" @click="remove(selected!)" /></template></VCardItem>
          <VCardText class="d-flex flex-column gap-2">
            <VTextField v-model="f.label" label="Label" density="compact" @update:model-value="!f._keyEdited && (f.key = slug($event))" />
            <VTextField v-model="f.key" label="Key (used in conditions)" density="compact" @update:model-value="f._keyEdited = true" />
            <VSelect v-model="f.type" :items="typeItems" label="Type" density="compact" />
            <VTextField v-model="f.help" label="Help text" density="compact" />
            <VCombobox v-if="['select', 'multiselect', 'radio', 'checkboxes'].includes(f.type)" v-model="f.options" label="Options" multiple chips closable-chips density="compact" />
            <div class="d-flex flex-wrap gap-x-4">
              <VCheckbox v-model="f.required" label="Required" density="compact" hide-details />
              <VCheckbox v-model="f.sensitive" label="Sensitive (masked in notices and logs)" density="compact" hide-details />
            </div>
            <template v-if="['number', 'decimal', 'currency'].includes(f.type)">
              <div class="d-flex gap-2">
                <VTextField :model-value="f.validation?.min" type="number" label="Minimum" density="compact" @update:model-value="f.validation = { ...(f.validation ?? {}), min: $event === '' ? undefined : Number($event) }" />
                <VTextField :model-value="f.validation?.max" type="number" label="Maximum" density="compact" @update:model-value="f.validation = { ...(f.validation ?? {}), max: $event === '' ? undefined : Number($event) }" />
              </div>
            </template>
            <template v-if="['text', 'textarea'].includes(f.type)">
              <div class="d-flex gap-2">
                <VTextField :model-value="f.validation?.max_length" type="number" label="Max length" density="compact" @update:model-value="f.validation = { ...(f.validation ?? {}), max_length: $event === '' ? undefined : Number($event) }" />
                <VSelect :model-value="f.validation?.pattern" :items="patterns" label="Must match" clearable density="compact" @update:model-value="f.validation = { ...(f.validation ?? {}), pattern: $event ?? undefined }" />
              </div>
            </template>
            <VTextField v-if="f.type === 'attachment'" v-model.number="f.max_file_mb" type="number" label="Largest file (MB)" density="compact" />
            <template v-if="f.type === 'calculated'">
              <VSelect :model-value="f.formula?.op" :items="[{ title: 'Sum of fields', value: 'sum' }, { title: 'Multiply fields', value: 'multiply' }, { title: 'First minus the rest', value: 'subtract' }, { title: 'Total of a line-item column', value: 'sum_column' }]"
                       label="Calculation" density="compact" @update:model-value="f.formula = { op: $event, fields: [] }" />
              <VSelect v-if="f.formula?.op && f.formula.op !== 'sum_column'" v-model="f.formula.fields" :items="keyed" item-title="label" item-value="key" multiple chips label="Of" density="compact" />
              <template v-if="f.formula?.op === 'sum_column'">
                <VSelect v-model="f.formula.list" :items="fields.filter(x => x.type === 'line_items').map(x => ({ title: x.label, value: x.key }))" label="Line items" density="compact" />
                <VSelect v-model="f.formula.columns" :items="(fields.find(x => x.key === f.formula.list)?.columns ?? []).map((c: any) => ({ title: c.label, value: c.key }))" multiple chips label="Column(s) — several are multiplied per line" density="compact" />
              </template>
            </template>
            <template v-if="f.type === 'line_items'">
              <div class="text-caption text-medium-emphasis">Columns</div>
              <div v-for="(c, ci) in f.columns" :key="ci" class="d-flex gap-2">
                <VTextField v-model="c.label" label="Label" density="compact" hide-details @update:model-value="c.key = slug($event)" />
                <VSelect v-model="c.type" :items="['text', 'number', 'decimal', 'currency', 'date']" label="Type" density="compact" hide-details style="max-inline-size: 130px" />
                <VBtn icon="tabler-x" size="small" variant="text" @click="f.columns.splice(ci, 1)" />
              </div>
              <VBtn size="small" variant="tonal" @click="f.columns.push({ key: `col_${f.columns.length + 1}`, label: 'Column', type: 'text' })">Add column</VBtn>
            </template>
            <div class="text-caption text-medium-emphasis mt-2">Show only when</div>
            <ConditionEditor v-model="f.visible_if" :fields="keyed.filter(k => k.key !== f.key)" :operators="operators" :patterns="patterns" />
            <div class="text-caption text-medium-emphasis mt-2">Required only when</div>
            <ConditionEditor v-model="f.required_if" :fields="keyed.filter(k => k.key !== f.key)" :operators="operators" :patterns="patterns" />
          </VCardText>
        </VCard>
        <VCard v-else border><VCardText class="text-medium-emphasis">Add a field to begin.</VCardText></VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard border>
          <VCardItem><VCardTitle class="text-body-1">Preview</VCardTitle><VCardSubtitle>Conditions apply as you type</VCardSubtitle></VCardItem>
          <VCardText><DynamicForm v-model="preview" :fields="fields" /></VCardText>
        </VCard>
        <div class="text-caption text-medium-emphasis mt-2">Versions: {{ versions.map(v => `v${v.version}`).join(', ') || 'none' }}</div>
      </VCol>
    </VRow>
  </div>
</template>
