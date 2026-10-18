<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import axios from 'axios'
import KbArticleEditor from '@/@core/components/KbArticleEditor.vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Template = {
  id: number
  uuid: string
  title: string
  subject: string
  from_name: string | null
  from_email: string | null
  body: string
  created_by: string | null
  created_at: string
}

const props = defineProps<{
  templates: Template[]
}>()

const templates = ref<Template[]>(props.templates)
const confirmDialog = ref()

const headers = [
  { title: t('email_templates.table.template'),    key: 'title',      sortable: true },
  { title: t('email_templates.table.subject'),     key: 'subject',    sortable: true },
  { title: t('email_templates.table.from'),        key: 'from_name',  sortable: true },
  { title: t('email_templates.table.created_by'),  key: 'created_by', sortable: true },
  { title: t('email_templates.table.date'),        key: 'created_at', sortable: true },
  { title: t('email_templates.table.actions'),     key: 'actions',    sortable: false, align: 'center' as const },
]

const search = ref('')

const dialog  = ref(false)
const editing = ref<Template | null>(null)
const saving  = ref(false)
const preview = ref<Template | null>(null)
const previewDialog = ref(false)


const blankForm = () => ({
  title:      '',
  subject:    '',
  from_name:  '',
  from_email: '',
  body:       '',
})
const form = ref(blankForm())

function openCreate() {
  editing.value = null
  form.value    = blankForm()
  dialog.value  = true
}

function openEdit(tpl: Template) {
  editing.value       = tpl
  form.value.title    = tpl.title
  form.value.subject  = tpl.subject
  form.value.from_name  = tpl.from_name  ?? ''
  form.value.from_email = tpl.from_email ?? ''
  form.value.body     = tpl.body
  dialog.value        = true
}

function openPreview(tpl: Template) {
  preview.value       = tpl
  previewDialog.value = true
}

async function save() {
  saving.value = true
  try {
    if (editing.value) {
      await axios.patch(route('cms.campaign-templates.update', editing.value.id), form.value)
    } else {
      await axios.post(route('cms.campaign-templates.store'), form.value)
    }
    dialog.value = false
    router.reload({ only: ['templates'] })
  } finally {
    saving.value = false
  }
}

function destroy(tpl: Template) {
  confirmDialog.value.open(
    {
      title: t('email_templates.confirm_delete.title'),
      message: t('email_templates.confirm_delete.message', { title: tpl.title }),
      confirmText: t('email_templates.confirm_delete.confirm'),
      confirmColor: 'error',
      icon: 'tabler-trash'
    },
    async () => {
      await axios.delete(route('cms.campaign-templates.destroy', tpl.id))
      templates.value = templates.value.filter(x => x.id !== tpl.id)
    },
  )
}

function formatDate(d: string): string {
  return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<template>
  <div>

    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-6">
      <div>
        <div class="d-flex align-center gap-2 mb-1">
          <VBtn icon size="x-small" variant="text" color="default"
            @click="router.visit(route('cms.campaigns.index'))">
            <VIcon icon="tabler-arrow-left" />
          </VBtn>
          <VBreadcrumbs :items="[
            { title: t('email_templates.breadcrumb.campaigns'), href: route('cms.campaigns.index') },
            { title: t('email_templates.breadcrumb.templates') },
          ]" density="compact" class="pa-0" />
        </div>
        <h4 class="text-h4 font-weight-bold mb-1">{{ t('email_templates.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('email_templates.subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">
        {{ t('email_templates.new_template') }}
      </VBtn>
    </div>

    <VRow class="mb-6">
      <VCol cols="12" sm="4">
        <VCard>
          <VCardText class="d-flex align-center gap-4">
            <VAvatar color="primary" variant="tonal" rounded size="44">
              <VIcon icon="tabler-template" size="24" />
            </VAvatar>
            <div>
              <div class="text-h5 font-weight-bold">{{ templates.length }}</div>
              <div class="text-body-2 text-medium-emphasis">{{ t('email_templates.stats.total_templates') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard>
      <VCardText class="pb-0">
        <VTextField
          v-model="search"
          prepend-inner-icon="tabler-search"
          :placeholder="t('email_templates.search_placeholder')"
          variant="outlined"
          density="compact"
          hide-details
          clearable
          style="max-width:260px"
        />
      </VCardText>

      <VDataTable
        :headers="headers"
        :items="templates"
        :search="search"
        :sort-by="[{ key: 'created_at', order: 'desc' }]"
        hover
        class="text-no-wrap"
      >
        <template #item.title="{ item }">
          <div class="py-1">
            <p class="text-body-1 font-weight-semibold mb-0">{{ item.title }}</p>
          </div>
        </template>

        <template #item.from_name="{ item }">
          <span v-if="item.from_name" class="text-body-2">
            {{ item.from_name }}
            <span v-if="item.from_email" class="text-medium-emphasis">&lt;{{ item.from_email }}&gt;</span>
          </span>
          <span v-else class="text-disabled">—</span>
        </template>

        <template #item.created_at="{ item }">
          <span class="text-body-2">{{ formatDate(item.created_at) }}</span>
        </template>

        <template #item.actions="{ item }">
          <div class="d-flex gap-1 justify-center">
            <VBtn icon size="small" variant="text" color="primary" @click="openPreview(item)">
              <VIcon icon="tabler-eye" size="18" />
              <VTooltip activator="parent" location="top">{{ t('email_templates.table.preview') }}</VTooltip>
            </VBtn>
            <VBtn icon size="small" variant="text" @click="openEdit(item)">
              <VIcon icon="tabler-pencil" size="18" />
              <VTooltip activator="parent" location="top">{{ t('email_templates.table.edit') }}</VTooltip>
            </VBtn>
            <VBtn icon size="small" variant="text" color="error" @click="destroy(item)">
              <VIcon icon="tabler-trash" size="18" />
              <VTooltip activator="parent" location="top">{{ t('email_templates.table.delete') }}</VTooltip>
            </VBtn>
          </div>
        </template>

        <template #no-data>
          <div class="text-center py-12">
            <VAvatar color="primary" variant="tonal" size="64" class="mb-4">
              <VIcon icon="tabler-template" size="32" />
            </VAvatar>
            <p class="text-h6 font-weight-semibold mb-1">{{ t('email_templates.empty.title') }}</p>
            <p class="text-medium-emphasis mb-4">{{ t('email_templates.empty.description') }}</p>
            <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('email_templates.empty.button') }}</VBtn>
          </div>
        </template>
      </VDataTable>
    </VCard>

  </div>

  <VDialog v-model="dialog" max-width="800" scrollable persistent>
    <VCard>
      <VCardItem class="border-b">
        <VCardTitle class="text-h6">
          {{ editing ? t('email_templates.dialog.edit_title') : t('email_templates.dialog.new_title') }}
        </VCardTitle>
        <template #append>
          <VBtn icon variant="text" @click="dialog = false">
            <VIcon icon="tabler-x" />
          </VBtn>
        </template>
      </VCardItem>

      <VCardText class="pa-6" style="max-height:72vh;overflow-y:auto;">
        <VRow dense>
          <VCol cols="12">
            <VTextField v-model="form.title" :label="t('email_templates.dialog.name_label')" variant="outlined" density="compact" />
          </VCol>
          <VCol cols="12">
            <VTextField v-model="form.subject" :label="t('email_templates.dialog.subject_label')" variant="outlined" density="compact" />
          </VCol>
          <VCol cols="12" sm="6">
            <VTextField v-model="form.from_name" :label="t('email_templates.dialog.from_name_label')" variant="outlined" density="compact" />
          </VCol>
          <VCol cols="12" sm="6">
            <VTextField v-model="form.from_email" :label="t('email_templates.dialog.from_email_label')" type="email" variant="outlined" density="compact" />
          </VCol>
          <VCol cols="12">
            <VDivider class="my-2" />
            <p class="text-caption font-weight-semibold text-uppercase text-medium-emphasis mb-2"
              style="letter-spacing:.5px">{{ t('email_templates.dialog.body_section') }}</p>
            <KbArticleEditor v-model="form.body" :placeholder="t('email_templates.dialog.body_placeholder')" />
          </VCol>
        </VRow>
      </VCardText>

      <VDivider />
      <VCardActions class="pa-4">
        <VSpacer />
        <VBtn variant="text" @click="dialog = false">{{ t('email_templates.dialog.cancel') }}</VBtn>
        <VBtn color="primary" variant="elevated" :loading="saving" @click="save">
          {{ editing ? t('email_templates.dialog.save_changes') : t('email_templates.dialog.create_template') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <VDialog v-model="previewDialog" max-width="680" scrollable>
    <VCard v-if="preview">
      <VCardItem class="border-b">
        <VCardTitle class="text-h6">{{ preview.title }}</VCardTitle>
        <template #append>
          <VBtn icon variant="text" @click="previewDialog = false">
            <VIcon icon="tabler-x" />
          </VBtn>
        </template>
      </VCardItem>
      <VCardText class="pa-6">
        <div class="mb-4 pb-4" style="border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
          <p class="text-caption mb-1"><strong>{{ t('email_templates.preview_dialog.subject') }}:</strong> {{ preview.subject }}</p>
          <p class="text-caption mb-0" v-if="preview.from_name">
            <strong>{{ t('email_templates.preview_dialog.from') }}:</strong> {{ preview.from_name }}
            <span v-if="preview.from_email">&lt;{{ preview.from_email }}&gt;</span>
          </p>
        </div>
        <!-- eslint-disable vue/no-v-html -->
        <div v-html="preview.body" />
      </VCardText>
      <VDivider />
      <VCardActions class="pa-4">
        <VSpacer />
        <VBtn variant="text" @click="previewDialog = false">{{ t('email_templates.preview_dialog.close') }}</VBtn>
        <VBtn color="primary" variant="tonal" prepend-icon="tabler-pencil" @click="openEdit(preview!); previewDialog = false">
          {{ t('email_templates.preview_dialog.edit_template') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <ConfirmDialog ref="confirmDialog" />
</template>
