<script setup lang="ts">
import { computed, ref } from 'vue'
import { route as ziggyRoute } from 'ziggy-js'
import { evaluate } from '../useWorkflow'

const route = ziggyRoute

export type Field = {
  key: string; label?: string; type: string; required?: boolean; required_if?: any; visible_if?: any; options?: string[]; default?: any
  help?: string; validation?: Record<string, any>; columns?: Field[]; formula?: { op: string; fields?: string[]; list?: string; columns?: string[] }
  sensitive?: boolean; max_file_mb?: number; min_rows?: number
}

const props = defineProps<{
  fields: Field[]
  errors?: Record<string, string>
  people?: Array<{ id: number; name: string }>
  departments?: Array<{ id: number; name: string }>
  suppliers?: Array<{ id: number; name: string }>
  context?: Record<string, any>
  disabled?: boolean
  prefix?: string
}>()
const values = defineModel<Record<string, any>>({ required: true })

const scope = computed(() => ({ ...(props.context ?? {}), ...values.value }))
const visible = (f: Field) => !f.visible_if || evaluate(f.visible_if, scope.value)
const required = (f: Field) => !!f.required || (!!f.required_if && evaluate(f.required_if, scope.value))
const err = (f: Field) => props.errors?.[`${props.prefix ?? 'values.'}${f.key}`] ?? props.errors?.[f.key]

function calc(f: Field): string {
  const n = (v: any) => (v === '' || v === null || v === undefined || Number.isNaN(Number(v)) ? 0 : Number(v))
  const refs = f.formula?.fields ?? []
  let r = 0
  switch (f.formula?.op) {
    case 'sum': case 'add': r = refs.reduce((c, k) => c + n(values.value[k]), 0); break
    case 'multiply': r = refs.reduce((c, k) => c * n(values.value[k]), 1); break
    case 'subtract': r = refs.slice(1).reduce((c, k) => c - n(values.value[k]), n(values.value[refs[0]])); break
    case 'sum_column': r = (values.value[f.formula?.list ?? ''] ?? []).reduce((c: number, row: any) => {
      const cols = f.formula?.columns ?? []
      return c + (cols.length > 1 ? cols.reduce((p, col) => p * n(row[col]), 1) : n(row[cols[0]]))
    }, 0); break
  }
  return r.toFixed(2)
}

function addRow(f: Field) {
  const rows = [...(values.value[f.key] ?? [])]
  rows.push(Object.fromEntries((f.columns ?? []).map(c => [c.key, c.default ?? null])))
  values.value = { ...values.value, [f.key]: rows }
}
function removeRow(f: Field, i: number) {
  const rows = [...(values.value[f.key] ?? [])]
  rows.splice(i, 1)
  values.value = { ...values.value, [f.key]: rows }
}
function setRow(f: Field, i: number, row: Record<string, any>) {
  const rows = [...(values.value[f.key] ?? [])]
  rows[i] = row
  values.value = { ...values.value, [f.key]: rows }
}
function set(key: string, v: any) { values.value = { ...values.value, [key]: v } }

// Attachments are filed in Document Management as soon as they are chosen; the form keeps their ids.
const uploading = ref<Record<string, boolean>>({})
const uploaded = ref<Record<string, Array<{ id: number; title: string }>>>({})
const uploadError = ref<Record<string, string>>({})
async function upload(f: Field, files: File | File[] | null) {
  const list = Array.isArray(files) ? files : files ? [files] : []
  uploadError.value[f.key] = ''
  for (const file of list) {
    if (f.max_file_mb && file.size > f.max_file_mb * 1024 * 1024) { uploadError.value[f.key] = `${file.name} is larger than ${f.max_file_mb} MB.`; continue }
    uploading.value[f.key] = true
    const body = new FormData()
    body.append('file', file)
    body.append('title', file.name)
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '')
    try {
      const res = await fetch(route('workflow.attachments.upload'), { method: 'POST', body, headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' }, credentials: 'same-origin' })
      const j = await res.json()
      if (!res.ok) { uploadError.value[f.key] = j.message ?? 'The file was refused.'; continue }
      uploaded.value[f.key] = [...(uploaded.value[f.key] ?? []), j]
      set(f.key, [...(values.value[f.key] ?? []), j.id])
    } finally { uploading.value[f.key] = false }
  }
}
function removeFile(f: Field, id: number) {
  uploaded.value[f.key] = (uploaded.value[f.key] ?? []).filter(x => x.id !== id)
  set(f.key, (values.value[f.key] ?? []).filter((x: number) => x !== id))
}
</script>

<template>
  <div class="d-flex flex-column gap-1">
    <template v-for="f in fields" :key="f.key">
      <div v-if="visible(f)">
        <div v-if="f.type === 'heading'" class="mt-3 mb-1">
          <div class="text-subtitle-1 font-weight-medium">{{ f.label }}</div>
          <div v-if="f.help" class="text-body-2 text-medium-emphasis">{{ f.help }}</div>
        </div>

        <VTextField v-else-if="['text', 'email', 'phone', 'url'].includes(f.type)" :model-value="values[f.key]" @update:model-value="set(f.key, $event)"
                    :label="f.label + (required(f) ? ' *' : '')" :type="f.type === 'email' ? 'email' : f.type === 'url' ? 'url' : f.type === 'phone' ? 'tel' : 'text'"
                    :hint="f.help" persistent-hint :error-messages="err(f)" :disabled="disabled" :maxlength="f.validation?.max_length" />
        <VTextarea v-else-if="f.type === 'textarea'" :model-value="values[f.key]" @update:model-value="set(f.key, $event)" :label="f.label + (required(f) ? ' *' : '')"
                   rows="3" auto-grow :hint="f.help" persistent-hint :error-messages="err(f)" :disabled="disabled" />
        <VTextField v-else-if="['number', 'decimal', 'currency'].includes(f.type)" :model-value="values[f.key]" @update:model-value="set(f.key, $event)"
                    :label="f.label + (required(f) ? ' *' : '')" type="number" :step="f.type === 'number' ? 1 : 0.01" :prefix="f.type === 'currency' ? 'R' : undefined"
                    :hint="f.help" persistent-hint :error-messages="err(f)" :disabled="disabled" />
        <VTextField v-else-if="f.type === 'date' || f.type === 'time'" :model-value="values[f.key]" @update:model-value="set(f.key, $event)"
                    :label="f.label + (required(f) ? ' *' : '')" :type="f.type" :hint="f.help" persistent-hint :error-messages="err(f)" :disabled="disabled" />
        <div v-else-if="f.type === 'daterange'" class="d-flex gap-3 flex-wrap">
          <VTextField :model-value="values[f.key]?.[0]" @update:model-value="set(f.key, [$event, values[f.key]?.[1] ?? null])" :label="`${f.label} — from${required(f) ? ' *' : ''}`" type="date" :disabled="disabled" style="min-inline-size: 200px" />
          <VTextField :model-value="values[f.key]?.[1]" @update:model-value="set(f.key, [values[f.key]?.[0] ?? null, $event])" label="to" type="date" :error-messages="err(f)" :disabled="disabled" style="min-inline-size: 200px" />
        </div>
        <VSelect v-else-if="f.type === 'select'" :model-value="values[f.key]" @update:model-value="set(f.key, $event)" :items="f.options ?? []"
                 :label="f.label + (required(f) ? ' *' : '')" :hint="f.help" persistent-hint :error-messages="err(f)" :disabled="disabled" clearable />
        <VAutocomplete v-else-if="f.type === 'multiselect'" :model-value="values[f.key] ?? []" @update:model-value="set(f.key, $event)" :items="f.options ?? []" multiple chips closable-chips
                       :label="f.label + (required(f) ? ' *' : '')" :error-messages="err(f)" :disabled="disabled" />
        <div v-else-if="f.type === 'radio'">
          <div class="text-body-2 mb-1">{{ f.label }}{{ required(f) ? ' *' : '' }}</div>
          <VRadioGroup :model-value="values[f.key]" @update:model-value="set(f.key, $event)" inline :error-messages="err(f)" :disabled="disabled">
            <VRadio v-for="o in f.options ?? []" :key="o" :label="o" :value="o" />
          </VRadioGroup>
        </div>
        <div v-else-if="f.type === 'checkboxes'">
          <div class="text-body-2">{{ f.label }}{{ required(f) ? ' *' : '' }}</div>
          <div class="d-flex flex-wrap gap-x-4">
            <VCheckbox v-for="o in f.options ?? []" :key="o" :label="o" :value="o" :model-value="values[f.key] ?? []" @update:model-value="set(f.key, $event)" density="compact" hide-details :disabled="disabled" />
          </div>
          <div v-if="err(f)" class="text-error text-caption">{{ err(f) }}</div>
        </div>
        <VSwitch v-else-if="f.type === 'yesno'" :model-value="!!values[f.key]" @update:model-value="set(f.key, $event)" :label="f.label" color="primary" inset :hint="f.help" persistent-hint :error-messages="err(f)" :disabled="disabled" />
        <VCheckbox v-else-if="f.type === 'acknowledgement'" :model-value="!!values[f.key]" @update:model-value="set(f.key, $event)" :label="f.label + (required(f) ? ' *' : '')" :error-messages="err(f)" :disabled="disabled" />
        <VAutocomplete v-else-if="f.type === 'user'" :model-value="values[f.key]" @update:model-value="set(f.key, $event)" :items="people ?? []" item-title="name" item-value="id"
                       :label="f.label + (required(f) ? ' *' : '')" :error-messages="err(f)" :disabled="disabled" clearable />
        <VAutocomplete v-else-if="f.type === 'users'" :model-value="values[f.key] ?? []" @update:model-value="set(f.key, $event)" :items="people ?? []" item-title="name" item-value="id"
                       multiple chips closable-chips :label="f.label + (required(f) ? ' *' : '')" :error-messages="err(f)" :disabled="disabled" />
        <VAutocomplete v-else-if="f.type === 'department'" :model-value="values[f.key]" @update:model-value="set(f.key, $event)" :items="departments ?? []" item-title="name" item-value="id"
                       :label="f.label + (required(f) ? ' *' : '')" :error-messages="err(f)" :disabled="disabled" clearable />
        <VAutocomplete v-else-if="f.type === 'supplier'" :model-value="values[f.key]" @update:model-value="set(f.key, $event)" :items="suppliers ?? []" item-title="name" item-value="id"
                       :label="f.label + (required(f) ? ' *' : '')" :error-messages="err(f)" :disabled="disabled" clearable />
        <VTextField v-else-if="f.type === 'calculated'" :model-value="calc(f)" :label="f.label" prefix="R" readonly variant="plain" hint="Calculated" persistent-hint />
        <div v-else-if="f.type === 'attachment'" class="mb-2">
          <VFileInput :label="f.label + (required(f) ? ' *' : '')" multiple show-size :loading="uploading[f.key]" :disabled="disabled"
                      :error-messages="uploadError[f.key] || err(f)" :hint="f.help ?? 'Filed in Document Management; scanned before it is kept.'" persistent-hint
                      prepend-icon="tabler-paperclip" @update:model-value="upload(f, $event)" />
          <div class="d-flex flex-wrap gap-2 mt-1">
            <VChip v-for="u in uploaded[f.key] ?? []" :key="u.id" size="small" closable @click:close="removeFile(f, u.id)">{{ u.title }}</VChip>
          </div>
        </div>
        <div v-else-if="f.type === 'line_items'" class="mb-3">
          <div class="d-flex align-center justify-space-between">
            <div class="text-body-2">{{ f.label }}{{ required(f) ? ' *' : '' }}</div>
            <VBtn size="small" variant="tonal" prepend-icon="tabler-plus" :disabled="disabled" @click="addRow(f)">Add line</VBtn>
          </div>
          <VTable density="compact" class="mt-1">
            <thead><tr><th v-for="c in f.columns ?? []" :key="c.key">{{ c.label }}</th><th /></tr></thead>
            <tbody>
              <tr v-for="(row, i) in values[f.key] ?? []" :key="i">
                <td v-for="c in f.columns ?? []" :key="c.key" style="min-inline-size: 120px">
                  <VTextField :model-value="row[c.key]" @update:model-value="setRow(f, i, { ...row, [c.key]: $event })" density="compact" hide-details variant="underlined"
                              :type="['number', 'decimal', 'currency'].includes(c.type) ? 'number' : c.type === 'date' ? 'date' : 'text'" :disabled="disabled" />
                </td>
                <td><VBtn icon="tabler-trash" size="x-small" variant="text" :disabled="disabled" @click="removeRow(f, i)" /></td>
              </tr>
              <tr v-if="!(values[f.key] ?? []).length"><td :colspan="(f.columns?.length ?? 0) + 1" class="text-medium-emphasis text-body-2">No lines yet.</td></tr>
            </tbody>
          </VTable>
          <div v-if="err(f)" class="text-error text-caption">{{ err(f) }}</div>
        </div>
      </div>
    </template>
  </div>
</template>
