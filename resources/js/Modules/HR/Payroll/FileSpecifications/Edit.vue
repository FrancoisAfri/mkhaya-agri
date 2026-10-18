<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const f = (key: string, a?: Record<string, unknown>) => t('bureau.filespec.' + key, a ?? {})

interface Field {
  uuid: string; record_type: string; sequence: number
  name: string; label: string | null; source: string
  position: number | null; length: number | null
  type: string; decimals: number; date_format: string | null
  pad_char: string | null; pad_side: string | null
  uppercase: boolean; required: boolean; default_value: string | null
}
interface Spec {
  uuid: string; code: string; name: string; vendor: string | null; version: number
  effective_from: string; effective_to: string | null
  format: string; delimiter: string | null; line_ending: string
  pad_to_length: boolean; is_active: boolean; notes: string | null; shared: boolean
}

const props = defineProps<{
  specification: Spec
  fields: Field[]
  preview: string
  problems: string[]
  tokens: { run: string[]; row: string[]; computed: string[] }
}>()

const spec = props.specification
const isFixed = computed(() => spec.format === 'fixed')

const byRecord = computed(() => ({
  header: props.fields.filter(x => x.record_type === 'header'),
  detail: props.fields.filter(x => x.record_type === 'detail'),
  trailer: props.fields.filter(x => x.record_type === 'trailer'),
}))

/** The preview split into lines, with a ruler so a column can be counted off against the document. */
const previewLines = computed(() => props.preview.split(/\r?\n/).filter(Boolean))
const widest = computed(() => Math.max(0, ...previewLines.value.map(l => l.length)))
const ruler = computed(() => {
  let tens = '', units = ''
  for (let i = 1; i <= widest.value; i++) {
    tens += i % 10 === 0 ? String((i / 10) % 10) : ' '
    units += String(i % 10)
  }
  return [tens, units]
})

const allTokens = computed(() => [
  ...props.tokens.run.map(x => ({ title: x, props: { subtitle: f('token_run') } })),
  ...props.tokens.row.map(x => ({ title: x, props: { subtitle: f('token_row') } })),
  ...props.tokens.computed.map(x => ({ title: x, props: { subtitle: f('token_computed') } })),
])

// ── Adding and editing fields ───────────────────────────────────────────────
const dialog = ref(false)
const editing = ref<Field | null>(null)
const form = useForm({
  record_type: 'detail', sequence: null as number | null, name: '', label: '', source: '',
  position: null as number | null, length: null as number | null,
  type: 'text', decimals: 0, date_format: '', pad_char: '', pad_side: '',
  uppercase: false, required: false, default_value: '',
})

function add(recordType: string) {
  editing.value = null
  form.reset()
  form.record_type = recordType
  // Continue where the last field ended, which is how a vendor document reads down the page.
  const existing = (byRecord.value as any)[recordType] as Field[]
  const last = existing[existing.length - 1]
  form.position = last?.position && last?.length ? last.position + last.length : 1
  dialog.value = true
}

function edit(field: Field) {
  editing.value = field
  form.defaults({ ...(field as any) })
  form.reset()
  Object.assign(form, field)
  dialog.value = true
}

function save() {
  const done = { onSuccess: () => { dialog.value = false; editing.value = null; form.reset() } }

  if (editing.value) {
    form.put(route('payroll.file-specs.fields.update', [spec.uuid, editing.value.uuid]), done)
  } else {
    form.post(route('payroll.file-specs.fields.store', spec.uuid), done)
  }
}

const confirmDelete = ref<Field | null>(null)
function remove() {
  if (!confirmDelete.value) return
  router.delete(route('payroll.file-specs.fields.destroy', [spec.uuid, confirmDelete.value.uuid]), {
    preserveScroll: true,
    onFinish: () => { confirmDelete.value = null },
  })
}

// ── The next version ────────────────────────────────────────────────────────
const versionDialog = ref(false)
const versionForm = useForm({ effective_from: new Date().toISOString().slice(0, 10) })
function newVersion() {
  versionForm.post(route('payroll.file-specs.version', spec.uuid), {
    onSuccess: () => { versionDialog.value = false },
  })
}
</script>

<template>
  <div class="pa-4" style="max-width:1200px;margin:auto">
    <div class="d-flex align-center ga-3 mb-4">
      <VBtn icon="tabler-arrow-left" aria-label="Back to the file layouts" class="row-action" variant="text" @click="router.get(route('payroll.file-specs.index'))" />
      <div class="flex-grow-1">
        <h1 class="text-h5 font-weight-bold mb-0">{{ spec.name }}</h1>
        <p class="text-caption text-medium-emphasis mb-0">
          {{ spec.code }} · v{{ spec.version }} · {{ spec.effective_from }} → {{ spec.effective_to || f('current') }}
        </p>
      </div>
      <VBtn variant="tonal" prepend-icon="tabler-copy" @click="versionDialog = true">{{ f('new_version') }}</VBtn>
    </div>

    <VAlert v-if="spec.shared" type="info" variant="tonal" density="compact" class="mb-4">
      {{ f('shipped_notice') }}
    </VAlert>

    <VAlert v-if="problems.length" type="warning" variant="tonal" class="mb-4">
      <div class="font-weight-medium mb-1">{{ f('problems') }}</div>
      <div v-for="(p, i) in problems" :key="i" class="text-body-2">{{ p }}</div>
    </VAlert>

    <!-- Seeing the line is the point. A gap or an overlap is invisible in a table of numbers. -->
    <VCard elevation="0" border class="mb-4">
      <VCardTitle class="text-subtitle-1">{{ f('preview') }}</VCardTitle>
      <VCardText>
        <p class="text-caption text-medium-emphasis">{{ f('preview_help') }}</p>
        <div style="overflow-x:auto">
          <pre class="text-caption" style="margin:0;white-space:pre;font-family:monospace;line-height:1.5"><span
            v-if="isFixed" class="text-medium-emphasis">{{ ruler[0] }}
{{ ruler[1] }}
</span>{{ preview }}</pre>
        </div>
      </VCardText>
    </VCard>

    <VCard v-for="rec in (['header', 'detail', 'trailer'] as const)" :key="rec" elevation="0" border class="mb-4">
      <VCardTitle class="d-flex align-center justify-space-between text-subtitle-1">
        <span>{{ f('records.' + rec) }}</span>
        <VBtn v-if="!spec.shared" size="small" variant="tonal" prepend-icon="tabler-plus" @click="add(rec)">
          {{ f('add_field') }}
        </VBtn>
      </VCardTitle>
      <VTable density="compact">
        <thead>
          <tr>
            <th v-if="isFixed">{{ f('at') }}</th>
            <th v-if="isFixed">{{ f('length') }}</th>
            <th>{{ f('field_name') }}</th>
            <th>{{ f('source') }}</th>
            <th>{{ f('type') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="field in byRecord[rec]" :key="field.uuid">
            <td v-if="isFixed">{{ field.position }}</td>
            <td v-if="isFixed">{{ field.length }}</td>
            <td>
              {{ field.name }}
              <VChip v-if="field.required" size="x-small" color="error" variant="tonal" class="ms-1">{{ f('required') }}</VChip>
            </td>
            <td class="text-caption"><code>{{ field.source }}</code></td>
            <td class="text-capitalize">
              {{ field.type }}
              <span v-if="field.type === 'date'" class="text-caption text-medium-emphasis">{{ field.date_format }}</span>
            </td>
            <td class="text-end">
              <VBtn
                v-if="!spec.shared" icon size="small" variant="text" class="row-action"
                :aria-label="`Edit the ${field.name} field`" @click="edit(field)"
              ><VIcon>tabler-pencil</VIcon></VBtn>
              <VBtn
                v-if="!spec.shared" icon size="small" variant="text" color="error" class="row-action"
                :aria-label="`Remove the ${field.name} field`" @click="confirmDelete = field"
              ><VIcon>tabler-trash</VIcon></VBtn>
            </td>
          </tr>
          <tr v-if="!byRecord[rec].length">
            <td :colspan="isFixed ? 6 : 4" class="text-center text-medium-emphasis py-4">{{ f('no_fields') }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="720">
      <VCard>
        <VCardTitle>{{ editing ? f('edit_field') : f('add_field') }}</VCardTitle>
        <VCardText>
          <VRow dense>
            <VCol cols="12" sm="4">
              <VTextField v-model="form.name" :label="f('field_name')" :error-messages="form.errors.name" />
            </VCol>
            <VCol cols="12" sm="8">
              <VCombobox
                v-model="form.source" :items="allTokens" :label="f('source')"
                :hint="f('source_hint')" persistent-hint :error-messages="form.errors.source"
              />
            </VCol>
            <VCol v-if="isFixed" cols="6" sm="3">
              <VTextField v-model.number="form.position" type="number" :label="f('at')" :error-messages="form.errors.position" />
            </VCol>
            <VCol v-if="isFixed" cols="6" sm="3">
              <VTextField v-model.number="form.length" type="number" :label="f('length')" :error-messages="form.errors.length" />
            </VCol>
            <VCol cols="6" sm="3">
              <VSelect v-model="form.type" :items="['text', 'number', 'cents', 'date']" :label="f('type')" />
            </VCol>
            <VCol v-if="form.type === 'date'" cols="6" sm="3">
              <VTextField v-model="form.date_format" :label="f('date_format')" placeholder="Ymd" />
            </VCol>
            <VCol v-if="form.type === 'number'" cols="6" sm="3">
              <VTextField v-model.number="form.decimals" type="number" :label="f('decimals')" />
            </VCol>
            <VCol cols="6" sm="3">
              <VTextField v-model="form.pad_char" :label="f('pad_char')" :placeholder="f('pad_auto')" />
            </VCol>
            <VCol cols="6" sm="3">
              <VSelect v-model="form.pad_side" :items="['', 'left', 'right']" :label="f('pad_side')" />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField v-model="form.default_value" :label="f('default_value')" />
            </VCol>
            <VCol cols="6" sm="3">
              <VSwitch v-model="form.uppercase" :label="f('uppercase')" density="compact" hide-details color="primary" />
            </VCol>
            <VCol cols="6" sm="3">
              <VSwitch v-model="form.required" :label="f('required')" density="compact" hide-details color="error" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('bureau.ui.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">{{ t('bureau.ui.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="versionDialog" max-width="520">
      <VCard>
        <VCardTitle>{{ f('new_version') }}</VCardTitle>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">{{ f('new_version_help') }}</p>
          <VTextField v-model="versionForm.effective_from" type="date" :label="f('effective_from')" :error-messages="versionForm.errors.effective_from" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="versionDialog = false">{{ t('bureau.ui.cancel') }}</VBtn>
          <VBtn color="primary" :loading="versionForm.processing" @click="newVersion">{{ f('start_version') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog
      :model-value="Boolean(confirmDelete)" max-width="460"
      @update:model-value="value => { if (!value) confirmDelete = null }"
    >
      <VCard v-if="confirmDelete">
        <VCardTitle>{{ f('remove_title') }}</VCardTitle>
        <VCardText>{{ f('remove_body', { name: confirmDelete.name }) }}</VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="confirmDelete = null">{{ t('bureau.ui.cancel') }}</VBtn>
          <VBtn color="error" @click="remove">{{ f('remove_confirm') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
