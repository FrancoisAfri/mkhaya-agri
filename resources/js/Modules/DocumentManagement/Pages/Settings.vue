<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmAction from '../Components/ConfirmAction.vue'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.settings_page.' + key, a ?? {})

const props = defineProps<{
  settings: {
    max_file_size_mb: number; max_files_per_upload: number
    allowed_extensions: string[]; blocked_extensions: string[]
    naming_pattern: string | null; auto_name: boolean; require_description: boolean
    expiry_reminders_enabled: boolean
    sharing_enabled: boolean
    share_max_days: number
    ai_enabled: boolean
    ai_include_confidential: boolean
    watermark_shares_by_default: boolean
    watermark_previews: boolean
    watermark_confidential_previews: boolean
    download_request_days: number
    lock_hours: number
    auto_delete_enabled: boolean
    disposal_grace_days: number
    expiry_reminder_days: number[]
    expiry_notify_roles: string[]
  }
  roles: string[]
  defaults: string[]
  never_allowed: string[]
  categories: Category[]
  retention_bases: { value: string; label: string }[]
  workflows?: { uuid: string; name: string }[]
}>()

interface Category {
  uuid: string; name: string; description: string | null
  colour: string | null; icon: string | null
  is_system: boolean; is_active: boolean; sort_order: number
  documents: number; can_delete: boolean
  retention_years: number | null
  retention_basis: string | null
  retention_note: string | null
  archive_after_years: number | null
  auto_archive: boolean
  auto_delete: boolean

  // The rules that belong to this kind of document rather than to the repository.
  number_pattern: string | null
  number_next: number
  default_workflow: string | null
  workflow_required: boolean
  fields: CategoryField[]
}

interface CategoryField {
  key: string
  label: string
  type: string
  options: string[]
  hint: string | null
  required: boolean
}

const form = useForm({ ...props.settings })
const showNever = ref(false)

// ── Categories ──────────────────────────────────────────────────────────────

const editing = ref<Category | null>(null)
const creating = ref(false)

const categoryForm = useForm({
  name: '', description: '', colour: '#6B7280', sort_order: 50,
  // Empty string rather than null: an emptied number field gives '', and the server reads that as
  // "clear the schedule". Sending null here would instead leave the old rule in place.
  retention_years: '' as number | string,
  retention_basis: '' as string,
  retention_note: '',
  archive_after_years: '' as number | string,
  auto_archive: false,
  auto_delete: false,
  number_pattern: '',
  number_next: 1,
  default_workflow: '',
  workflow_required: false,
  fields: [] as CategoryField[],
})

function startCreate() {
  categoryForm.reset()
  categoryForm.colour = '#6B7280'
  categoryForm.sort_order = 50
  creating.value = true
}

/** How long a schedule keeps things, in words, for the list. */
function retentionLabel(category: Category): string | null {
  if (!category.retention_years) return null

  const basis = props.retention_bases.find(b => b.value === category.retention_basis)

  return d('retention_summary', { years: category.retention_years, basis: basis?.label ?? '' })
}

function startEdit(category: Category) {
  categoryForm.name = category.name
  categoryForm.description = category.description ?? ''
  categoryForm.colour = category.colour ?? '#6B7280'
  categoryForm.sort_order = category.sort_order
  categoryForm.retention_years = category.retention_years ?? ''
  categoryForm.retention_basis = category.retention_basis ?? ''
  categoryForm.retention_note = category.retention_note ?? ''
  categoryForm.archive_after_years = category.archive_after_years ?? ''
  categoryForm.auto_archive = category.auto_archive
  categoryForm.auto_delete = category.auto_delete
  categoryForm.number_pattern = category.number_pattern ?? ''
  categoryForm.number_next = category.number_next ?? 1
  categoryForm.default_workflow = category.default_workflow ?? ''
  categoryForm.workflow_required = category.workflow_required ?? false
  // Copied rather than referenced, so cancelling an edit does not leave the list showing changes
  // that were never saved.
  categoryForm.fields = (category.fields ?? []).map(f => ({ ...f, options: [...(f.options ?? [])] }))
  editing.value = category
}

/** A key derived from the label, so nobody has to invent one — but editable, because it is what
 *  every recorded value is stored under and a rename must not silently orphan them. */
function keyFor(label: string): string {
  return label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 60) || 'field'
}

function addField() {
  categoryForm.fields.push({ key: '', label: '', type: 'text', options: [], hint: null, required: false })
}

function saveCategory() {
  const done = {
    preserveScroll: true,
    onSuccess: () => { creating.value = false; editing.value = null; categoryForm.reset() },
  }

  editing.value
    ? categoryForm.put(route('document-management.categories.update', editing.value.uuid), done)
    : categoryForm.post(route('document-management.categories.store'), done)
}

/**
 * Switching a category off leaves it on everything already filed under it.
 *
 * Which is the point: an organisation that stops using "Fax Cover Sheet" still has forty of them,
 * and renaming history to suit a settings change would be the wrong kind of tidy.
 */
function toggleActive(category: Category) {
  router.put(route('document-management.categories.update', category.uuid),
    { is_active: !category.is_active }, { preserveScroll: true })
}

function removeCategory(category: Category) {
  router.delete(route('document-management.categories.destroy', category.uuid), { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4" style="max-width:900px;margin:auto">
    <h1 class="text-h5 font-weight-bold mb-1">{{ d('title') }}</h1>
    <p class="text-body-2 text-medium-emphasis">{{ d('subtitle') }}</p>

    <VCard elevation="0" border class="mb-4">
      <VCardTitle class="text-subtitle-1">{{ d('limits') }}</VCardTitle>
      <VCardText class="field-row">
        <VTextField
          v-model.number="form.max_file_size_mb" type="number" :label="d('max_size')"
          :hint="d('max_size_hint')" persistent-hint :error-messages="form.errors.max_file_size_mb"
        />
        <VTextField
          v-model.number="form.max_files_per_upload" type="number" :label="d('max_count')"
          :hint="d('max_count_hint')" persistent-hint :error-messages="form.errors.max_files_per_upload"
        />
      </VCardText>
    </VCard>

    <VCard elevation="0" border class="mb-4">
      <VCardTitle class="text-subtitle-1">{{ d('types') }}</VCardTitle>
      <VCardSubtitle class="pb-2">{{ d('types_help') }}</VCardSubtitle>
      <VCardText class="d-flex flex-column ga-3">
        <VCombobox
          v-model="form.allowed_extensions" :items="defaults" :label="d('allowed')"
          multiple chips closable-chips :hint="d('allowed_hint')" persistent-hint
        />
        <VCombobox
          v-model="form.blocked_extensions" :label="d('blocked')"
          multiple chips closable-chips :hint="d('blocked_hint')" persistent-hint
        />

        <!-- Shown so the list reads as deliberate rather than as an oversight. -->
        <div>
          <VBtn size="small" variant="text" @click="showNever = !showNever">
            {{ showNever ? d('hide_never') : d('show_never') }}
          </VBtn>
          <VAlert v-if="showNever" type="info" variant="tonal" density="compact" class="mt-2">
            <p class="text-body-2 mb-2">{{ d('never_help') }}</p>
            <div class="d-flex flex-wrap ga-1">
              <VChip v-for="e in never_allowed" :key="e" size="x-small" variant="tonal">.{{ e }}</VChip>
            </div>
          </VAlert>
        </div>
      </VCardText>
    </VCard>

    <VCard elevation="0" border class="mb-4">
      <VCardTitle class="text-subtitle-1">{{ d('naming') }}</VCardTitle>
      <VCardText class="d-flex flex-column ga-3">
        <VSwitch v-model="form.auto_name" :label="d('auto_name')" density="compact" hide-details inset />
        <VTextField
          v-model="form.naming_pattern" :label="d('pattern')" :disabled="!form.auto_name"
          :hint="d('pattern_hint')" persistent-hint placeholder="DOC-{year}-{seq}"
        />
        <VSwitch v-model="form.require_description" :label="d('require_description')" density="compact" hide-details inset />
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn color="primary" :loading="form.processing"
          @click="form.put(route('document-management.settings.update'), { preserveScroll: true })">
          {{ d('save') }}
        </VBtn>
      </VCardActions>
    </VCard>

    <!-- ── External sharing ───────────────────────────────────────────────── -->
    <VCard elevation="0" border class="mb-4">
      <VCardTitle class="text-subtitle-1">{{ d('sharing') }}</VCardTitle>
      <VCardText class="d-flex flex-column ga-3">
        <!-- Stated before the switch, not after it is on. Turning this on is the moment this
             repository becomes able to serve its contents to people with no account. -->
        <VAlert type="warning" variant="tonal" density="compact">
          {{ d('sharing_warning') }}
        </VAlert>

        <VSwitch
          v-model="form.sharing_enabled" density="compact" hide-details inset
          :label="d('sharing_enabled')"
        />

        <VTextField
          v-model.number="form.share_max_days" type="number" min="1" max="365"
          :label="d('share_max_days')" density="comfortable" style="max-width:12rem"
          :disabled="!form.sharing_enabled"
          :hint="d('share_max_days_hint')" persistent-hint
          :error-messages="form.errors.share_max_days"
        />

        <VSwitch
          v-model="form.watermark_shares_by_default" density="compact" hide-details inset
          :disabled="!form.sharing_enabled" :label="d('watermark_default')"
        />
        <div class="text-caption text-medium-emphasis">{{ d('watermark_default_hint') }}</div>

        <!--
          The other watermark, and a different question. That one makes a forwarded file traceable
          to whoever it was sent to; this one makes a photograph of a screen traceable to whoever
          was sitting in front of it.
        -->
        <VDivider class="my-3" />

        <VSwitch
          v-model="form.watermark_previews" density="compact" hide-details inset
          :label="d('watermark_previews')"
        />
        <div class="text-caption text-medium-emphasis">{{ d('watermark_previews_hint') }}</div>

        <VSwitch
          v-model="form.watermark_confidential_previews" density="compact" hide-details inset
          :disabled="form.watermark_previews" :label="d('watermark_confidential')"
        />

        <VDivider class="my-3" />

        <!--
          A number field wide enough for three digits is not wide enough for the label above it,
          and Vuetify answers that by clipping the label rather than the box. The sentence goes in a
          hint underneath, where it has the width to be read.
        -->
        <VTextField
          v-model.number="form.download_request_days" type="number" min="1" max="365"
          :label="d('downloads_days')" density="comfortable" style="max-width:22rem"
          :hint="d('downloads_days_hint')" persistent-hint
          :error-messages="form.errors.download_request_days"
        />
        <VTextField
          v-model.number="form.lock_hours" type="number" min="1" max="720"
          :label="d('lock_hours')" density="comfortable" style="max-width:22rem" class="mt-2"
          :hint="d('lock_hours_hint')" persistent-hint
          :error-messages="form.errors.lock_hours"
        />
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn
          color="primary" :loading="form.processing"
          @click="form.put(route('document-management.settings.update'), { preserveScroll: true })"
        >{{ d('save') }}</VBtn>
      </VCardActions>
    </VCard>

    <!-- ── Automatic disposal ─────────────────────────────────────────────── -->
    <VCard elevation="0" border class="mb-4">
      <VCardTitle class="text-subtitle-1">{{ d('disposal_automation') }}</VCardTitle>
      <VCardText class="d-flex flex-column ga-3">
        <VAlert type="warning" variant="tonal" density="compact">
          {{ d('auto_delete_warning') }}
        </VAlert>

        <VSwitch
          v-model="form.auto_delete_enabled" density="compact" hide-details inset color="warning"
          :label="d('auto_delete_enabled')"
        />

        <VTextField
          v-model.number="form.disposal_grace_days" type="number" min="0" max="90"
          :label="d('disposal_grace_days')" density="comfortable" style="max-width:12rem"
          :disabled="!form.auto_delete_enabled"
          :hint="d('disposal_grace_days_hint')" persistent-hint
        />
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn
          color="primary" :loading="form.processing"
          @click="form.put(route('document-management.settings.update'), { preserveScroll: true })"
        >{{ d('save') }}</VBtn>
      </VCardActions>
    </VCard>

    <!-- ── Assistant ──────────────────────────────────────────────────────── -->
    <VCard elevation="0" border class="mb-4">
      <VCardTitle class="text-subtitle-1">{{ d('ai') }}</VCardTitle>
      <VCardText class="d-flex flex-column ga-3">
        <!-- Named before the switch: this sends document contents to a third party, which is a
             disclosure decision rather than a convenience one. -->
        <VAlert type="warning" variant="tonal" density="compact">
          {{ d('ai_warning') }}
        </VAlert>

        <VSwitch
          v-model="form.ai_enabled" density="compact" hide-details inset
          :label="d('ai_enabled')"
        />

        <VSwitch
          v-model="form.ai_include_confidential" density="compact" hide-details inset
          :disabled="!form.ai_enabled" :label="d('ai_include_confidential')"
        />
        <div class="text-caption text-medium-emphasis">{{ d('ai_include_confidential_hint') }}</div>
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn
          color="primary" :loading="form.processing"
          @click="form.put(route('document-management.settings.update'), { preserveScroll: true })"
        >{{ d('save') }}</VBtn>
      </VCardActions>
    </VCard>

    <!-- ── Expiry reminders ───────────────────────────────────────────────── -->
    <VCard elevation="0" border class="mb-4">
      <VCardTitle class="text-subtitle-1">{{ d('expiry') }}</VCardTitle>
      <VCardText class="d-flex flex-column ga-3">
        <p class="text-body-2 text-medium-emphasis mb-0">{{ d('expiry_hint') }}</p>

        <VSwitch
          v-model="form.expiry_reminders_enabled" density="compact" hide-details inset
          :label="d('expiry_enabled')"
        />

        <!-- Days, not dates. The right lead time depends on what the renewal costs — a B-BBEE
             re-verification takes months, a driver's licence an afternoon. -->
        <VCombobox
          v-model="form.expiry_reminder_days" :label="d('expiry_days')" multiple chips closable-chips
          :disabled="!form.expiry_reminders_enabled" density="comfortable"
          :hint="d('expiry_days_hint')" persistent-hint
          :error-messages="form.errors.expiry_reminder_days"
        />

        <VSelect
          v-model="form.expiry_notify_roles" :items="roles" multiple chips closable-chips
          :disabled="!form.expiry_reminders_enabled" density="comfortable"
          :label="d('expiry_roles')" :hint="d('expiry_roles_hint')" persistent-hint
        />
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn
          color="primary" :loading="form.processing"
          @click="form.put(route('document-management.settings.update'), { preserveScroll: true })"
        >{{ d('save') }}</VBtn>
      </VCardActions>
    </VCard>

    <!-- ── Categories ─────────────────────────────────────────────────────── -->
    <VCard elevation="0" border class="mb-4">
      <VCardTitle class="text-subtitle-1 d-flex align-center ga-2">
        {{ d('categories') }}
        <VSpacer />
        <VBtn size="small" variant="tonal" prepend-icon="mdi-plus" @click="startCreate">
          {{ d('category_add') }}
        </VBtn>
      </VCardTitle>
      <VCardText class="pt-0">
        <p class="text-body-2 text-medium-emphasis mb-3">{{ d('categories_hint') }}</p>

        <VTable density="comfortable">
          <thead>
            <tr>
              <th>{{ d('category_name') }}</th>
              <th class="text-end">{{ d('category_documents') }}</th>
              <th class="text-end">{{ d('category_active') }}</th>
              <th class="text-end">{{ d('category_actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="category in categories" :key="category.uuid">
              <td>
                <div class="d-flex align-center ga-2">
                  <span
                    class="colour-dot"
                    :style="{ background: category.colour ?? '#6B7280' }"
                    aria-hidden="true"
                  />
                  <div>
                    <div class="text-body-2" :class="{ 'text-medium-emphasis': !category.is_active }">
                      {{ category.name }}
                    </div>
                    <div v-if="category.description" class="text-caption text-medium-emphasis">
                      {{ category.description }}
                    </div>
                    <div v-if="retentionLabel(category)" class="text-caption d-flex align-center ga-1 mt-1">
                      <VIcon size="14" icon="mdi-delete-clock-outline" />
                      <span>{{ retentionLabel(category) }}</span>
                    </div>
                  </div>
                  <!-- Said plainly, so the missing delete button explains itself. -->
                  <VChip v-if="category.is_system" size="x-small" variant="tonal" label>
                    {{ d('category_built_in') }}
                  </VChip>
                </div>
              </td>
              <td class="text-end text-medium-emphasis">{{ category.documents }}</td>
              <td class="text-end">
                <VSwitch
                  :model-value="category.is_active" density="compact" hide-details inset
                  :aria-label="d('category_toggle', { name: category.name })"
                  class="d-inline-flex" @update:model-value="toggleActive(category)"
                />
              </td>
              <td class="text-end">
                <VBtn
                  size="x-small" variant="text" icon="mdi-pencil-outline"
                  :aria-label="d('category_edit', { name: category.name })" @click="startEdit(category)"
                />
                <VTooltip v-if="!category.can_delete" location="top">
                  <template #activator="{ props: tip }">
                    <span v-bind="tip">
                      <!-- Labelled even though it is disabled: a screen reader still reaches it,
                           and the tooltip explaining why is not announced on its own. -->
                      <VBtn
                        size="x-small" variant="text" icon="mdi-delete-outline" disabled
                        :aria-label="d('category_delete', { name: category.name })"
                      />
                    </span>
                  </template>
                  {{ category.is_system ? d('category_protected') : d('category_in_use', { n: category.documents }) }}
                </VTooltip>
                <ConfirmAction
                  v-else
                  :title="d('categories_delete_title')"
                  :body="d('categories_delete_body', { name: category.name })"
                  :consequence="d('categories_delete_consequence')"
                  :confirm-label="d('delete')"
                  @confirm="removeCategory(category)"
                >
                  <template #activator="{ open }">
                    <VBtn
                      size="x-small" variant="text" icon="mdi-delete-outline" color="error"
                      :aria-label="d('category_delete', { name: category.name })" @click="open"
                    />
                  </template>
                </ConfirmAction>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCardText>
    </VCard>
  </div>

  <VDialog :model-value="creating || editing !== null" max-width="640" @update:model-value="creating = false; editing = null">
    <VCard>
      <VCardTitle class="text-body-1 font-weight-medium">
        {{ editing ? d('category_edit_title') : d('category_add') }}
      </VCardTitle>
      <VCardText class="d-flex flex-column ga-3">
        <VTextField
          v-model="categoryForm.name" :label="d('category_name')" autofocus density="comfortable"
          :error-messages="categoryForm.errors.name"
        />
        <VTextarea v-model="categoryForm.description" :label="d('category_description')" rows="2" density="comfortable" />
        <div class="d-flex ga-3 align-center">
          <VTextField
            v-model="categoryForm.colour" type="color" :label="d('category_colour')"
            density="comfortable" style="max-width:8rem"
          />
          <VTextField
            v-model.number="categoryForm.sort_order" type="number" :label="d('category_order')"
            density="comfortable" :hint="d('category_order_hint')" persistent-hint
          />
        </div>

        <VDivider />

        <div>
          <div class="text-body-2 font-weight-medium">{{ d('retention_title') }}</div>
          <!-- Said before the fields, because a retention period is the one setting on this page
               that decides whether documents continue to exist. -->
          <div class="text-caption text-medium-emphasis mb-2">{{ d('retention_hint') }}</div>

          <div class="d-flex ga-3 align-start flex-wrap">
            <VTextField
              v-model="categoryForm.retention_years" type="number" min="1"
              :label="d('retention_years')" density="comfortable" style="max-width:9rem"
              :hint="d('retention_years_hint')" persistent-hint
              :error-messages="categoryForm.errors.retention_years"
            />
            <VSelect
              v-model="categoryForm.retention_basis" :items="retention_bases"
              item-title="label" item-value="value" :label="d('retention_basis')"
              density="comfortable" style="min-width:12rem" clearable
              :disabled="!categoryForm.retention_years"
              :error-messages="categoryForm.errors.retention_basis"
            />
          </div>

          <VTextField
            v-model="categoryForm.retention_note" :label="d('retention_note')"
            density="comfortable" class="mt-2" :hint="d('retention_note_hint')" persistent-hint
          />

          <VDivider class="my-3" />

          <div class="text-body-2 font-weight-medium mb-1">{{ d('automation_title') }}</div>
          <div class="text-caption text-medium-emphasis mb-2">{{ d('automation_hint') }}</div>

          <VTextField
            v-model="categoryForm.archive_after_years" type="number" min="1"
            :label="d('archive_after_years')" density="comfortable" style="max-width:11rem"
            :hint="d('archive_after_years_hint')" persistent-hint
          />

          <VSwitch
            v-model="categoryForm.auto_archive" density="compact" hide-details inset class="mt-2"
            :disabled="!categoryForm.archive_after_years" :label="d('auto_archive')"
          />

          <VSwitch
            v-model="categoryForm.auto_delete" density="compact" hide-details inset color="warning"
            :label="d('auto_delete')"
          />
          <!-- Said next to the switch, because this is the only setting on the page that can end
               with a document gone. -->
          <div class="text-caption text-medium-emphasis">{{ d('auto_delete_hint') }}</div>
        </div>

        <!-- ── Numbering ─────────────────────────────────────────────── -->
        <VDivider class="my-4" />
        <div class="text-subtitle-2 mb-2">{{ d('numbering') }}</div>

        <VTextField
          v-model="categoryForm.number_pattern" density="comfortable"
          :label="d('numbering_pattern')" :hint="d('numbering_pattern_hint')" persistent-hint
          :error-messages="categoryForm.errors.number_pattern"
          placeholder="CTR-{YYYY}-{####}"
        />
        <VTextField
          v-if="categoryForm.number_pattern"
          v-model.number="categoryForm.number_next" type="number" min="1" density="comfortable"
          class="mt-2" style="max-width:12rem"
          :label="d('numbering_next')" :hint="d('numbering_next_hint')" persistent-hint
        />

        <!-- ── Approval ──────────────────────────────────────────────── -->
        <template v-if="workflows?.length">
          <VDivider class="my-4" />
          <div class="text-subtitle-2 mb-2">{{ d('category_workflow') }}</div>

          <VSelect
            v-model="categoryForm.default_workflow" density="comfortable" clearable
            :label="d('category_workflow_default')" :hint="d('category_workflow_hint')" persistent-hint
            :items="workflows.map(w => ({ value: w.uuid, title: w.name }))"
          />
          <VSwitch
            v-model="categoryForm.workflow_required" density="compact" hide-details inset class="mt-2"
            :disabled="!categoryForm.default_workflow" :label="d('category_workflow_required')"
          />
        </template>

        <!-- ── Extra fields ──────────────────────────────────────────── -->
        <VDivider class="my-4" />
        <div class="text-subtitle-2 mb-1">{{ d('category_fields') }}</div>
        <div class="text-caption text-medium-emphasis mb-2">{{ d('category_fields_hint') }}</div>

        <div v-for="(field, index) in categoryForm.fields" :key="index" class="mb-3">
          <div class="d-flex ga-2 align-center">
            <VTextField
              v-model="field.label" density="compact" hide-details :label="d('category_field_label')"
              @blur="field.key = field.key || keyFor(field.label)"
            />
            <VSelect
              v-model="field.type" density="compact" hide-details style="max-width:9rem"
              :label="d('category_field_type')"
              :items="[
                { value: 'text', title: d('field_type_text') },
                { value: 'number', title: d('field_type_number') },
                { value: 'date', title: d('field_type_date') },
                { value: 'boolean', title: d('field_type_boolean') },
                { value: 'select', title: d('field_type_select') },
              ]"
            />
            <VCheckbox v-model="field.required" density="compact" hide-details :label="d('checklists_required')" />
            <VBtn icon="mdi-close" size="x-small" variant="text" @click="categoryForm.fields.splice(index, 1)" />
          </div>
          <!-- The choices, where the field is one. Comma-separated because a list of three words
               does not deserve a repeater of its own. -->
          <VTextField
            v-if="field.type === 'select'"
            :model-value="field.options.join(', ')" density="compact" hide-details class="mt-1"
            :label="d('category_field_options')"
            @update:model-value="(v: string) => field.options = v.split(',').map(o => o.trim()).filter(Boolean)"
          />
        </div>

        <VBtn size="small" variant="text" prepend-icon="mdi-plus" @click="addField">
          {{ d('checklists_add_item') }}
        </VBtn>
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn variant="text" @click="creating = false; editing = null">{{ d('cancel') }}</VBtn>
        <VBtn
          color="primary" :loading="categoryForm.processing"
          :disabled="!categoryForm.name.trim()" @click="saveCategory"
        >{{ d('save') }}</VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>

<style scoped>
.field-row { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-start; }
.field-row > * { flex: 1 1 12rem; min-width: 0; }

/* A colour is how somebody picks the invoice out of forty rows, so it is shown rather than named. */
.colour-dot {
  display: inline-block;
  inline-size: 10px;
  block-size: 10px;
  border-radius: 50%;
  flex: 0 0 auto;
}
</style>
