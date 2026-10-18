<script setup lang="ts">
/**
 * Contract setup: the types, categories and templates every contract is filed under.
 *
 * ContractSettingsController has rendered 'Modules/ContractManagement/Settings/Index' since the
 * module shipped and this file did not exist, so the Settings card on the Configure hub threw
 * "Page not found" — and with no way to add a contract type, the contract form's own type picker
 * had nothing to offer.
 */
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Row = { id: number; name: string; description?: string | null; status: boolean }
type Template = { id: number; name: string; status: boolean }

const props = defineProps<{
  types: Row[]
  categories: Row[]
  templates: Template[]
}>()

/** 'types' | 'categories' | 'templates' — which collection the open dialog is editing. */
const kind = ref<'types' | 'categories' | 'templates'>('types')
const tab = ref('types')
const dialog = ref(false)
const editing = ref<Row | Template | null>(null)
const confirmDialog = ref()

const form = useForm({ name: '', description: '', content: '', status: true })

/*
 * The three collections differ only in whether they carry a description or a body, so one dialog
 * serves all three rather than three near-identical ones. `kind` decides which fields show and
 * which routes the submit goes to.
 */
function openCreate(which: typeof kind.value) {
  kind.value = which
  editing.value = null
  form.reset()
  form.clearErrors()
  dialog.value = true
}

function openEdit(which: typeof kind.value, row: Row | Template) {
  kind.value = which
  editing.value = row
  form.clearErrors()
  form.name = row.name
  form.description = (row as Row).description ?? ''
  form.content = ''
  form.status = row.status
  dialog.value = true
}

function submit() {
  const options = { preserveScroll: true, onSuccess: () => { dialog.value = false } }

  if (editing.value) {
    form.patch(route(`contracts.settings.${kind.value}.update`, editing.value.id), options)
  } else {
    form.post(route(`contracts.settings.${kind.value}.store`), options)
  }
}

function destroy(which: typeof kind.value, row: Row | Template) {
  confirmDialog.value.open(
    {
      title: t(`contracts_settings.${which}.confirm_title`),
      message: t('contracts_settings.confirm_message', { name: row.name }),
      confirmText: t('contracts_settings.confirm_delete'),
      confirmColor: 'error',
      icon: 'tabler-trash',
    },
    () => { router.delete(route(`contracts.settings.${which}.destroy`, row.id), { preserveScroll: true }) },
  )
}
</script>

<template>
  <div class="pa-4">
    <ConfirmDialog ref="confirmDialog" />

    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VAvatar color="primary" variant="tonal" size="48" rounded>
          <VIcon icon="tabler-settings" size="26" />
        </VAvatar>
        <div>
          <h4 class="text-h4 mb-0">{{ t('contracts_settings.page_title') }}</h4>
          <p class="text-caption text-medium-emphasis mb-0">{{ t('contracts_settings.description') }}</p>
        </div>
      </div>
    </div>

    <VCard border>
      <VTabs v-model="tab" grow>
        <VTab value="types">{{ t('contracts_settings.types.tab') }}</VTab>
        <VTab value="categories">{{ t('contracts_settings.categories.tab') }}</VTab>
        <VTab value="templates">{{ t('contracts_settings.templates.tab') }}</VTab>
      </VTabs>

      <VWindow v-model="tab">
        <!-- Types -->
        <VWindowItem value="types">
          <div class="d-flex align-center justify-space-between flex-wrap gap-2 pa-4">
            <p class="text-body-2 text-medium-emphasis mb-0">{{ t('contracts_settings.types.help') }}</p>
            <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate('types')">
              {{ t('contracts_settings.types.add') }}
            </VBtn>
          </div>
          <VTable>
            <thead>
              <tr>
                <th>{{ t('contracts_settings.table.name') }}</th>
                <th>{{ t('contracts_settings.table.description') }}</th>
                <th>{{ t('contracts_settings.table.status') }}</th>
                <th class="text-right">{{ t('contracts_settings.table.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!props.types.length">
                <td colspan="4" class="text-center text-medium-emphasis py-8">
                  {{ t('contracts_settings.types.empty') }}
                </td>
              </tr>
              <tr v-for="row in props.types" :key="row.id">
                <td class="font-weight-medium">{{ row.name }}</td>
                <td class="text-medium-emphasis">{{ row.description || '—' }}</td>
                <td>
                  <VChip size="small" :color="row.status ? 'success' : 'secondary'" variant="tonal">
                    {{ row.status ? t('contracts_settings.active') : t('contracts_settings.inactive') }}
                  </VChip>
                </td>
                <td class="text-right">
                  <VBtn icon variant="text" size="small" :aria-label="t('contracts_settings.table.edit')" @click="openEdit('types', row)">
                    <VIcon icon="tabler-edit" size="18" />
                  </VBtn>
                  <VBtn icon variant="text" size="small" color="error" :aria-label="t('contracts_settings.table.delete')" @click="destroy('types', row)">
                    <VIcon icon="tabler-trash" size="18" />
                  </VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <!-- Categories -->
        <VWindowItem value="categories">
          <div class="d-flex align-center justify-space-between flex-wrap gap-2 pa-4">
            <p class="text-body-2 text-medium-emphasis mb-0">{{ t('contracts_settings.categories.help') }}</p>
            <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate('categories')">
              {{ t('contracts_settings.categories.add') }}
            </VBtn>
          </div>
          <VTable>
            <thead>
              <tr>
                <th>{{ t('contracts_settings.table.name') }}</th>
                <th>{{ t('contracts_settings.table.description') }}</th>
                <th>{{ t('contracts_settings.table.status') }}</th>
                <th class="text-right">{{ t('contracts_settings.table.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!props.categories.length">
                <td colspan="4" class="text-center text-medium-emphasis py-8">
                  {{ t('contracts_settings.categories.empty') }}
                </td>
              </tr>
              <tr v-for="row in props.categories" :key="row.id">
                <td class="font-weight-medium">{{ row.name }}</td>
                <td class="text-medium-emphasis">{{ row.description || '—' }}</td>
                <td>
                  <VChip size="small" :color="row.status ? 'success' : 'secondary'" variant="tonal">
                    {{ row.status ? t('contracts_settings.active') : t('contracts_settings.inactive') }}
                  </VChip>
                </td>
                <td class="text-right">
                  <VBtn icon variant="text" size="small" :aria-label="t('contracts_settings.table.edit')" @click="openEdit('categories', row)">
                    <VIcon icon="tabler-edit" size="18" />
                  </VBtn>
                  <VBtn icon variant="text" size="small" color="error" :aria-label="t('contracts_settings.table.delete')" @click="destroy('categories', row)">
                    <VIcon icon="tabler-trash" size="18" />
                  </VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <!-- Templates -->
        <VWindowItem value="templates">
          <div class="d-flex align-center justify-space-between flex-wrap gap-2 pa-4">
            <p class="text-body-2 text-medium-emphasis mb-0">{{ t('contracts_settings.templates.help') }}</p>
            <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate('templates')">
              {{ t('contracts_settings.templates.add') }}
            </VBtn>
          </div>
          <VTable>
            <thead>
              <tr>
                <th>{{ t('contracts_settings.table.name') }}</th>
                <th>{{ t('contracts_settings.table.status') }}</th>
                <th class="text-right">{{ t('contracts_settings.table.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!props.templates.length">
                <td colspan="3" class="text-center text-medium-emphasis py-8">
                  {{ t('contracts_settings.templates.empty') }}
                </td>
              </tr>
              <tr v-for="row in props.templates" :key="row.id">
                <td class="font-weight-medium">{{ row.name }}</td>
                <td>
                  <VChip size="small" :color="row.status ? 'success' : 'secondary'" variant="tonal">
                    {{ row.status ? t('contracts_settings.active') : t('contracts_settings.inactive') }}
                  </VChip>
                </td>
                <td class="text-right">
                  <VBtn icon variant="text" size="small" :aria-label="t('contracts_settings.table.edit')" @click="openEdit('templates', row)">
                    <VIcon icon="tabler-edit" size="18" />
                  </VBtn>
                  <VBtn icon variant="text" size="small" color="error" :aria-label="t('contracts_settings.table.delete')" @click="destroy('templates', row)">
                    <VIcon icon="tabler-trash" size="18" />
                  </VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VWindowItem>
      </VWindow>
    </VCard>

    <VDialog v-model="dialog" max-width="560">
      <VCard>
        <VCardTitle class="text-h6">
          {{ editing ? t(`contracts_settings.${kind}.edit_title`) : t(`contracts_settings.${kind}.add_title`) }}
        </VCardTitle>
        <VCardText>
          <VTextField
            v-model="form.name"
            :label="t('contracts_settings.form.name')"
            variant="outlined"
            density="comfortable"
            class="mb-3"
            :error-messages="form.errors.name"
          />
          <VTextarea
            v-if="kind !== 'templates'"
            v-model="form.description"
            :label="t('contracts_settings.form.description')"
            variant="outlined"
            density="comfortable"
            rows="2"
            class="mb-3"
            :error-messages="form.errors.description"
          />
          <VTextarea
            v-else
            v-model="form.content"
            :label="t('contracts_settings.form.content')"
            :hint="t('contracts_settings.form.content_hint')"
            persistent-hint
            variant="outlined"
            density="comfortable"
            rows="6"
            class="mb-3"
            :error-messages="form.errors.content"
          />
          <VSwitch
            v-model="form.status"
            :label="t('contracts_settings.form.status')"
            color="primary"
            density="compact"
            hide-details
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('contracts_settings.form.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="submit">
            {{ editing ? t('contracts_settings.form.save') : t('contracts_settings.form.create') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
