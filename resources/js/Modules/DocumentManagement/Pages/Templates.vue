<script setup lang="ts">
/**
 * The template library, and the editor for one template.
 *
 * A template is a document with holes in it, so the editor puts the field list beside the body
 * rather than behind a menu: an author writing an employment contract needs to see what they can
 * insert while they write, and a picker they have to go and find is a picker they type around.
 *
 * Clicking a field inserts it at the cursor, because retyping `{{employee.start_date}}` by hand is
 * exactly where a typo becomes a placeholder printed in a signed contract.
 */
import { computed, nextTick, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmAction from '../Components/ConfirmAction.vue'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

interface Template {
  uuid: string
  name: string
  description: string | null
  subject_relation: string | null
  category: string | null
  category_uuid: string | null
  folder_uuid: string | null
  filename_pattern: string | null
  is_active: boolean
  body: string | null
  unknown_fields: string[]
}

const props = defineProps<{
  templates: Template[]
  relations: { value: string; label: string }[]
  categories: { uuid: string; name: string }[]
  folders: { uuid: string; name: string; depth: number }[]
  fields: Record<string, Record<string, string>>
}>()

const editing = ref<Template | null>(null)
const creating = ref(false)
const bodyInput = ref<HTMLTextAreaElement | null>(null)

const form = useForm({
  name: '', description: '', subject_relation: null as string | null,
  category: null as string | null, folder: null as string | null,
  filename_pattern: '', body: '', is_active: true,
})

/** The fields available for whatever this template is written about. */
const availableFields = computed(() => {
  const map = props.fields[form.subject_relation ?? ''] ?? props.fields[''] ?? {}

  return Object.entries(map).map(([key, label]) => ({ key, label }))
})

/**
 * Fields the body uses that do not exist.
 *
 * Recomputed while typing rather than only on save: a typo is invisible until somebody generates a
 * contract with the placeholder printed in it, and by then it has been sent.
 */
const unknownInBody = computed(() => {
  const used = [...String(form.body).matchAll(/\{\{\s*([a-z0-9_.]+)\s*\}\}/gi)].map(m => m[1].toLowerCase())
  const known = availableFields.value.map(f => f.key)

  return [...new Set(used.filter(u => !known.includes(u)))]
})

function startCreate() {
  form.reset()
  form.is_active = true
  editing.value = null
  creating.value = true
}

function startEdit(template: Template) {
  form.name = template.name
  form.description = template.description ?? ''
  form.subject_relation = template.subject_relation
  form.category = template.category_uuid
  form.folder = template.folder_uuid
  form.filename_pattern = template.filename_pattern ?? ''
  form.body = template.body ?? ''
  form.is_active = template.is_active
  editing.value = template
  creating.value = false
}

function close() {
  editing.value = null
  creating.value = false
  form.reset()
}

function save() {
  const done = { preserveScroll: true, onSuccess: close }

  editing.value
    ? form.put(route('document-management.templates.update', editing.value.uuid), done)
    : form.post(route('document-management.templates.store'), done)
}

/**
 * Puts a field where the cursor is.
 *
 * Appended when the body has never been focused, which is what happens when somebody picks a field
 * before they start writing — inserting at position zero would push it in front of the heading.
 */
async function insertField(key: string) {
  const input = bodyInput.value
  const token = `{{${key}}}`

  if (!input) {
    form.body += token
    return
  }

  const start = input.selectionStart ?? form.body.length
  const end = input.selectionEnd ?? start

  form.body = form.body.slice(0, start) + token + form.body.slice(end)

  await nextTick()
  input.focus()
  input.setSelectionRange(start + token.length, start + token.length)
}

// ── Generating ──────────────────────────────────────────────────────────────

const generating = ref<Template | null>(null)
const subject = ref<{ id: number; label: string } | null>(null)
const candidates = ref<{ id: number; label: string; subtitle: string | null }[]>([])
const searching = ref(false)
const term = ref('')
const preview = ref<{ html: string; missing: string[]; unknown: string[] } | null>(null)
const allowMissing = ref(false)
const busy = ref(false)

let sequence = 0
let timer: ReturnType<typeof setTimeout> | undefined

function searchSubjects() {
  const relation = generating.value?.subject_relation
  if (!relation) return

  const mine = ++sequence
  searching.value = true
  window.clearTimeout(timer)

  timer = setTimeout(async () => {
    try {
      const url = route('document-management.relations.search')
      const response = await fetch(`${url}?relation=${encodeURIComponent(relation)}&q=${encodeURIComponent(term.value)}`,
        { headers: { Accept: 'application/json' } })
      const body = await response.json()
      if (mine === sequence) candidates.value = body.results ?? []
    } catch {
      if (mine === sequence) candidates.value = []
    } finally {
      if (mine === sequence) searching.value = false
    }
  }, 220)
}

/** What the document would say, before anybody commits to it. */
async function loadPreview() {
  if (!generating.value) return

  preview.value = null
  const url = route('document-management.templates.preview', generating.value.uuid)
  const query = subject.value ? `?subject_id=${subject.value.id}` : ''

  try {
    const response = await fetch(url + query, { headers: { Accept: 'application/json' } })
    preview.value = await response.json()
  } catch {
    preview.value = null
  }
}

watch(subject, loadPreview)

function openGenerate(template: Template) {
  generating.value = template
  subject.value = null
  candidates.value = []
  term.value = ''
  preview.value = null
  allowMissing.value = false
  if (!template.subject_relation) loadPreview()
  else searchSubjects()
}

function generate() {
  if (!generating.value) return

  busy.value = true
  router.post(route('document-management.templates.generate', generating.value.uuid), {
    subject_id: subject.value?.id ?? null,
    allow_missing: allowMissing.value,
  }, {
    onFinish: () => { busy.value = false; generating.value = null },
  })
}

/**
 * The token as an author types it.
 *
 * Built here rather than in the template: a mustache containing the closing braces of another
 * mustache terminates the first one, so `{{ '{' + '{' ... }}` inline is a parse error waiting for
 * whoever writes it.
 */
const tokenFor = (key: string) => `{{${key}}}`

const relationLabel = (value: string | null) =>
  props.relations.find(r => r.value === value)?.label ?? d('template_no_subject')
</script>

<template>
  <div class="pa-4" style="max-width:1300px;margin:auto">
    <div class="d-flex align-center flex-wrap ga-2 mb-1">
      <h1 class="text-h5 font-weight-bold">{{ d('templates_title') }}</h1>
      <VSpacer />
      <VBtn color="primary" prepend-icon="mdi-plus" @click="startCreate">{{ d('template_new') }}</VBtn>
    </div>
    <p class="text-body-2 text-medium-emphasis mb-4">{{ d('templates_subtitle') }}</p>

    <VCard elevation="0" border>
      <div style="overflow-x:auto">
        <VTable density="comfortable">
          <thead>
            <tr>
              <th>{{ d('template_name') }}</th>
              <th>{{ d('template_subject') }}</th>
              <th>{{ d('field_category') }}</th>
              <th class="text-end">{{ d('col_actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="template in templates" :key="template.uuid">
              <td>
                <div class="text-body-2" :class="{ 'text-medium-emphasis': !template.is_active }">
                  {{ template.name }}
                </div>
                <div v-if="template.description" class="text-caption text-medium-emphasis text-truncate" style="max-width:30rem">
                  {{ template.description }}
                </div>
                <!-- A typo in a merge field, said here rather than discovered in a printed
                     contract. Icon and words, not colour alone. -->
                <VChip
                  v-if="template.unknown_fields.length" size="x-small" color="warning" variant="tonal"
                  prepend-icon="mdi-alert-outline" class="mt-1"
                >{{ d('template_unknown_fields', { n: template.unknown_fields.length }) }}</VChip>
              </td>
              <td class="text-body-2 text-medium-emphasis">{{ relationLabel(template.subject_relation) }}</td>
              <td class="text-body-2 text-medium-emphasis">{{ template.category ?? '—' }}</td>
              <td class="text-end">
                <VBtn
                  size="small" variant="tonal" prepend-icon="mdi-file-document-arrow-right-outline"
                  class="me-1" @click="openGenerate(template)"
                >{{ d('template_generate') }}</VBtn>
                <VBtn
                  size="small" variant="text" icon="mdi-pencil-outline"
                  :aria-label="d('template_edit', { name: template.name })" @click="startEdit(template)"
                />
                <ConfirmAction
                  :title="d('templates_delete_title')"
                  :body="d('templates_delete_body', { name: template.name })"
                  :consequence="d('templates_delete_consequence')"
                  :confirm-label="d('delete')"
                  @confirm="router.delete(route('document-management.templates.destroy', template.uuid), { preserveScroll: true })"
                >
                  <template #activator="{ open }">
                    <VBtn
                      size="small" variant="text" icon="mdi-delete-outline" color="error"
                      :aria-label="d('template_delete', { name: template.name })"
                      @click="open"
                    />
                  </template>
                </ConfirmAction>
              </td>
            </tr>
            <tr v-if="!templates.length">
              <td colspan="4" class="text-center text-medium-emphasis py-10">{{ d('templates_empty') }}</td>
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCard>
  </div>

  <!-- ── Editor ───────────────────────────────────────────────────────────── -->
  <VDialog :model-value="creating || editing !== null" fullscreen transition="dialog-bottom-transition" @update:model-value="close">
    <VCard>
      <VToolbar density="comfortable" color="surface">
        <VBtn icon="mdi-close" :aria-label="d('cancel')" @click="close" />
        <VToolbarTitle class="text-body-1">
          {{ editing ? d('template_edit_title', { name: editing.name }) : d('template_new') }}
        </VToolbarTitle>
        <VSpacer />
        <VBtn color="primary" variant="flat" :loading="form.processing" :disabled="!form.name.trim()" @click="save">
          {{ d('save') }}
        </VBtn>
      </VToolbar>

      <VDivider />

      <VCardText class="editor-layout">
        <!-- Settings and body -->
        <div class="d-flex flex-column ga-3">
          <div class="field-row">
            <VTextField v-model="form.name" :label="d('template_name')" :error-messages="form.errors.name" density="comfortable" />
            <VSelect
              v-model="form.subject_relation" density="comfortable" :label="d('template_subject')"
              :items="[{ value: null, title: d('template_no_subject') }, ...relations.map(r => ({ value: r.value, title: r.label }))]"
              :hint="d('template_subject_hint')" persistent-hint
            />
          </div>

          <VTextField v-model="form.description" :label="d('field_description')" density="comfortable" />

          <div class="field-row">
            <VSelect
              v-model="form.category" density="comfortable" :label="d('field_category')"
              :items="[{ value: null, title: d('no_category') }, ...categories.map(c => ({ value: c.uuid, title: c.name }))]"
            />
            <VSelect
              v-model="form.folder" density="comfortable" :label="d('field_folder')"
              :items="[{ value: null, title: d('no_folder') }, ...folders.map(f => ({ value: f.uuid, title: '　'.repeat(f.depth) + f.name }))]"
            />
          </div>

          <VTextField
            v-model="form.filename_pattern" density="comfortable" :label="d('template_filename')"
            :hint="d('template_filename_hint')" persistent-hint
          />

          <!-- A real label, not a placeholder: the field scrolls and the placeholder goes with it. -->
          <label class="text-body-2 font-weight-medium" for="template-body">{{ d('template_body') }}</label>
          <textarea
            id="template-body" ref="bodyInput" v-model="form.body"
            class="body-input" rows="18" spellcheck="true"
          />

          <VAlert v-if="unknownInBody.length" type="warning" variant="tonal" density="compact">
            {{ d('template_unknown_list', { fields: unknownInBody.join(', ') }) }}
          </VAlert>

          <VSwitch v-model="form.is_active" :label="d('template_active')" density="compact" hide-details inset />
        </div>

        <!-- Fields, beside the body rather than behind a menu. -->
        <VCard elevation="0" border class="fields-panel">
          <VCardText class="pb-2">
            <div class="text-body-2 font-weight-medium">{{ d('template_fields') }}</div>
            <div class="text-caption text-medium-emphasis">{{ d('template_fields_hint') }}</div>
          </VCardText>
          <VDivider />
          <VList density="compact" class="py-1">
            <VListItem
              v-for="field in availableFields" :key="field.key"
              @click="insertField(field.key)"
            >
              <VListItemTitle class="text-body-2">{{ field.label }}</VListItemTitle>
              <VListItemSubtitle class="text-caption font-monospace">{{ tokenFor(field.key) }}</VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCardText>
    </VCard>
  </VDialog>

  <!-- ── Generate ─────────────────────────────────────────────────────────── -->
  <VDialog :model-value="generating !== null" max-width="760" @update:model-value="generating = null">
    <VCard v-if="generating">
      <VCardTitle class="text-body-1 font-weight-medium">
        {{ d('template_generate_title', { name: generating.name }) }}
      </VCardTitle>
      <VCardText class="d-flex flex-column ga-3">
        <VAutocomplete
          v-if="generating.subject_relation"
          v-model="subject" :items="candidates" :loading="searching" return-object item-title="label"
          :label="d('template_generate_for', { subject: relationLabel(generating.subject_relation) })"
          :no-data-text="term ? d('link_no_results') : d('link_start_typing')"
          density="comfortable" autofocus
          @update:search="(v: string) => { term = v; searchSubjects() }"
        >
          <template #item="{ props: item, item: entry }">
            <VListItem v-bind="item" :title="entry.raw.label" :subtitle="entry.raw.subtitle ?? undefined" />
          </template>
        </VAutocomplete>

        <!-- The gaps, before anybody commits. A blank where a salary should be gets signed. -->
        <VAlert v-if="preview?.missing?.length" type="warning" variant="tonal" density="compact">
          <div class="text-body-2">{{ d('template_missing', { fields: preview.missing.join(', ') }) }}</div>
          <VCheckbox
            v-model="allowMissing" density="compact" hide-details class="mt-1"
            :label="d('template_generate_anyway')"
          />
        </VAlert>

        <VAlert v-if="preview?.unknown?.length" type="error" variant="tonal" density="compact">
          {{ d('template_unknown_list', { fields: preview.unknown.join(', ') }) }}
        </VAlert>

        <div v-if="preview" class="preview" v-html="preview.html" />
        <VSkeletonLoader v-else-if="subject || !generating.subject_relation" type="paragraph" />
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn variant="text" @click="generating = null">{{ d('cancel') }}</VBtn>
        <VBtn
          color="primary" :loading="busy"
          :disabled="(generating.subject_relation && !subject) || (!!preview?.missing?.length && !allowMissing)"
          @click="generate"
        >{{ d('template_generate') }}</VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>

<style scoped>
.editor-layout {
  display: grid;
  gap: 1rem;
  grid-template-columns: 1fr;
  align-items: start;
}

@media (min-width: 1000px) {
  /* The field list keeps a column of its own once there is room: it is referred to constantly
     while writing, and a panel that moves is a panel that gets closed. */
  .editor-layout { grid-template-columns: minmax(0, 1fr) 20rem; }
}

.fields-panel { position: sticky; inset-block-start: 0.5rem; max-block-size: 70vh; overflow-y: auto; }

.body-input {
  inline-size: 100%;
  min-block-size: 22rem;
  padding: 0.75rem;
  border: 1px solid rgba(127, 127, 127, 0.4);
  border-radius: 6px;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.85rem;
  line-height: 1.6;
  background: transparent;
  color: inherit;
  resize: vertical;
}

.body-input:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 1px;
  border-color: transparent;
}

.font-monospace { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }

/* The preview is the document, so it is set like one rather than like the surrounding page. */
.preview {
  border: 1px solid rgba(127, 127, 127, 0.3);
  border-radius: 6px;
  padding: 1.25rem;
  max-block-size: 22rem;
  overflow-y: auto;
  font-family: Georgia, serif;
  line-height: 1.55;
}

.preview :deep(table) { inline-size: 100%; border-collapse: collapse; }
.preview :deep(th), .preview :deep(td) { border: 1px solid rgba(127,127,127,.35); padding: 4px 6px; text-align: start; }

.field-row { display: flex; gap: 0.75rem; flex-wrap: wrap; }
.field-row > * { flex: 1 1 14rem; min-inline-size: 0; }
</style>
