<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import TiptapEditor from '@/@core/components/TiptapEditor.vue'

const { t } = useI18n()
const route = ziggyRoute

/**
 * Project Management's own contract templates.
 *
 * This library is PM's, not Contract Management's. The modules are sold separately, so a company
 * that bought only this one still has to be able to write and keep the terms it subcontracts on.
 * Where they own both, Contract Management appears as somewhere to import *from* — a copy taken
 * once, owned here afterwards.
 */
const props = defineProps<{
  templates: Array<Record<string, any>>
  canImport: boolean
  importable: Array<{ id: string; name: string }>
}>()

const SOURCE_COLOUR: Record<string, string> = {
  own: 'primary', seeded: 'teal', imported: 'deep-purple',
}

/**
 * What a template may ask to have filled in when a contract is drafted.
 *
 * Built here rather than written into the markup: a literal `{{name}}` inside a Vue interpolation
 * is parsed as an interpolation, and the template will not compile.
 */
const PLACEHOLDERS = ['contractor_name', 'project_name', 'contract_value', 'start_date']
  .map(name => `{{${name}}}`)

// ── Write or edit ────────────────────────────────────────────────────────────
const dialog = ref(false)
const editing = ref<Record<string, any> | null>(null)
const form = useForm({ name: '', summary: '', category: '', body: '', is_active: true })

function openNew() {
  editing.value = null
  form.reset()
  dialog.value = true
}

async function openEdit(template: Record<string, any>) {
  editing.value = template
  form.reset()
  form.name = template.name
  form.summary = template.summary ?? ''
  form.category = template.category ?? ''
  form.is_active = template.is_active
  form.body = ''
  dialog.value = true

  // Bodies are large, so they are not shipped with the list — fetched only when one is opened.
  const response = await fetch(route('project-management.contracts.templates.show', template.id), {
    headers: { Accept: 'application/json' },
    credentials: 'same-origin',
  }).catch(() => null)

  if (response?.ok) {
    const payload = await response.json().catch(() => null)
    form.body = payload?.body ?? ''
  }
}

function save() {
  if (editing.value) {
    form.patch(route('project-management.contracts.templates.update', editing.value.id), {
      preserveScroll: true,
      onSuccess: () => { dialog.value = false; editing.value = null },
    })
  } else {
    form.post(route('project-management.contracts.templates.store'), {
      preserveScroll: true,
      onSuccess: () => { dialog.value = false },
    })
  }
}

/**
 * Deleting asks in the app rather than through `confirm()`.
 *
 * A native dialog ignores the theme, cannot be translated by vue-i18n, and reads as a browser
 * warning rather than part of this screen.
 */
const deleteTarget = ref<Record<string, any> | null>(null)
const deleting = ref(false)

function confirmRemove(template: Record<string, any>) {
  deleteTarget.value = template
}

function remove() {
  if (!deleteTarget.value) return

  deleting.value = true

  router.delete(route('project-management.contracts.templates.destroy', deleteTarget.value.id), {
    preserveScroll: true,
    onFinish: () => { deleting.value = false; deleteTarget.value = null },
  })
}

// ── Import from Contract Management ──────────────────────────────────────────
const importDialog = ref(false)
const importForm = useForm({ source: '' })

function doImport() {
  importForm.post(route('project-management.contracts.templates.import'), {
    preserveScroll: true,
    onSuccess: () => { importDialog.value = false; importForm.reset() },
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div>
        <h4 class="text-h4">{{ t('project_management.contract_templates.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t('project_management.contract_templates.subtitle') }}
        </p>
      </div>
      <div class="d-flex gap-2">
        <!--
          Only where Contract Management is actually in the plan. PM's own rule: an integration's
          absence is a missing button, never a broken one.
        -->
        <VBtn
          v-if="props.canImport" variant="tonal" prepend-icon="tabler-download"
          @click="importDialog = true"
        >
          {{ t('project_management.contract_templates.import') }}
        </VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">
          {{ t('project_management.contract_templates.add') }}
        </VBtn>
      </div>
    </div>

    <VCard border>
      <VTable v-if="props.templates.length" density="comfortable">
        <thead>
          <tr>
            <th>{{ t('project_management.contract_templates.col_name') }}</th>
            <th>{{ t('project_management.contract_templates.col_category') }}</th>
            <th>{{ t('project_management.contract_templates.col_source') }}</th>
            <th>{{ t('project_management.contractors.col_status') }}</th>
            <th class="text-end">{{ t('project_management.contract_templates.col_actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="tpl in props.templates" :key="tpl.id">
            <td>
              <div class="font-weight-medium">{{ tpl.name }}</div>
              <div v-if="tpl.summary" class="text-caption text-medium-emphasis">{{ tpl.summary }}</div>
            </td>
            <td>{{ tpl.category ?? '—' }}</td>
            <td>
              <VChip :color="SOURCE_COLOUR[tpl.source] ?? 'grey'" size="x-small" variant="tonal">
                {{ t('project_management.contract_templates.source_' + tpl.source) }}
              </VChip>
            </td>
            <td>
              <VChip :color="tpl.is_active ? 'success' : 'grey'" size="x-small" variant="tonal">
                {{ tpl.is_active
                  ? t('project_management.contract_templates.active')
                  : t('project_management.contract_templates.inactive') }}
              </VChip>
            </td>
            <td class="text-end text-no-wrap">
              <!-- Icon-only controls carry their own accessible name; the icon is decorative. -->
              <VBtn
                icon size="small" variant="text"
                :aria-label="t('project_management.actions.edit') + ': ' + tpl.name"
                @click="openEdit(tpl)"
              >
                <VIcon icon="tabler-edit" aria-hidden="true" />
                <VTooltip activator="parent" location="top">
                  {{ t('project_management.actions.edit') }}
                </VTooltip>
              </VBtn>
              <VBtn
                icon size="small" variant="text" color="error"
                :aria-label="t('project_management.actions.delete') + ': ' + tpl.name"
                @click="confirmRemove(tpl)"
              >
                <VIcon icon="tabler-trash" aria-hidden="true" />
                <VTooltip activator="parent" location="top">
                  {{ t('project_management.actions.delete') }}
                </VTooltip>
              </VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
      <VCardText v-else class="text-center text-medium-emphasis py-10">
        <p class="mb-2">{{ t('project_management.contract_templates.none') }}</p>
        <p class="text-caption mb-0">{{ t('project_management.contract_templates.none_hint') }}</p>
      </VCardText>
    </VCard>

    <!-- ── Write / edit ───────────────────────────────────────────────────── -->
    <VDialog v-model="dialog" max-width="900" scrollable>
      <VCard>
        <VCardItem>
          <VCardTitle>
            {{ editing
              ? t('project_management.contract_templates.edit')
              : t('project_management.contract_templates.add') }}
          </VCardTitle>
          <VCardSubtitle>{{ t('project_management.contract_templates.form_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VRow dense class="mb-2">
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.name"
                :label="t('project_management.contract_templates.field_name')"
                :error-messages="form.errors.name"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="form.category"
                :label="t('project_management.contract_templates.field_category')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="2" class="d-flex align-center">
              <VSwitch
                v-model="form.is_active"
                :label="t('project_management.contract_templates.active')"
                density="compact" hide-details
              />
            </VCol>
            <VCol cols="12">
              <VTextField
                v-model="form.summary"
                :label="t('project_management.contract_templates.field_summary')"
                variant="outlined" density="compact"
              />
            </VCol>
          </VRow>

          <div class="text-caption text-medium-emphasis mb-1">
            {{ t('project_management.contract_templates.field_body') }}
          </div>
          <TiptapEditor v-model="form.body" :disabled="form.processing" />

          <VAlert type="info" variant="tonal" density="compact" class="mt-3">
            {{ t('project_management.contract_templates.placeholders_hint') }}
            <div class="mt-1 d-flex flex-wrap gap-2">
              <code v-for="p in PLACEHOLDERS" :key="p">{{ p }}</code>
            </div>
          </VAlert>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!form.name.trim()" @click="save">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Confirm delete ─────────────────────────────────────────────────── -->
    <VDialog :model-value="deleteTarget !== null" max-width="460" @update:model-value="deleteTarget = null">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.actions.delete') }}</VCardTitle>
        </VCardItem>
        <VCardText>
          {{ t('project_management.contracts.confirm_delete_template', { name: deleteTarget?.name ?? '' }) }}
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="deleteTarget = null">
            {{ t('project_management.actions.cancel') }}
          </VBtn>
          <VBtn color="error" :loading="deleting" @click="remove">
            {{ t('project_management.actions.delete') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Import ─────────────────────────────────────────────────────────── -->
    <VDialog v-model="importDialog" max-width="560">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.contract_templates.import') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.contract_templates.import_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VSelect
            v-model="importForm.source"
            :items="props.importable.map(i => ({ title: i.name, value: i.id }))"
            :label="t('project_management.contract_templates.choose_source')"
            :no-data-text="t('project_management.contract_templates.nothing_to_import')"
            :error-messages="importForm.errors.source"
            variant="outlined" density="compact"
          />
          <p class="text-caption text-medium-emphasis mt-3 mb-0">
            {{ t('project_management.contract_templates.import_is_a_copy') }}
          </p>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="importDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn
            color="primary" :loading="importForm.processing" :disabled="!importForm.source"
            @click="doImport"
          >
            {{ t('project_management.contract_templates.import_it') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
