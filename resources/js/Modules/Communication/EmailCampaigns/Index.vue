<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import axios from 'axios'
import { useI18n } from 'vue-i18n'
import KbArticleEditor from '@/@core/components/KbArticleEditor.vue'

const { t } = useI18n()

type Department = { id: number; name: string }

type Template = {
  id: number
  title: string
  subject: string
  from_name: string | null
  from_email: string | null
  body: string
}

type Campaign = {
  id: number
  uuid: string
  title: string
  subject: string
  status: 'draft' | 'scheduled' | 'sending' | 'sent' | 'cancelled'
  audience_type: 'all' | 'department' | 'custom'
  scheduled_at: string | null
  sent_at: string | null
  total_recipients: number
  sent_count: number
  open_count: number
  open_rate: number
  created_at: string
  created_by: string | null
}

const props = defineProps<{
  campaigns: Campaign[]
  departments: Department[]
  templates: Template[]
}>()

const headers = [
  { title: t('email_campaigns.index.table.campaign'),   key: 'title',         sortable: true },
  { title: t('email_campaigns.index.table.status'),     key: 'status',        sortable: true, align: 'center' as const },
  { title: t('email_campaigns.index.table.audience'),   key: 'audience_type', sortable: true },
  { title: t('email_campaigns.index.table.sent'),       key: 'sent_count',    sortable: true, align: 'center' as const },
  { title: t('email_campaigns.index.table.open_rate'),  key: 'open_rate',     sortable: true, align: 'center' as const },
  { title: t('email_campaigns.index.table.date'),       key: 'sent_at',       sortable: true },
  { title: t('email_campaigns.index.table.actions'),    key: 'actions',       sortable: false, align: 'center' as const },
]

const confirmDialog = ref()
const campaigns = ref<Campaign[]>(props.campaigns)
const search    = ref('')
const statusFilter = ref<string>('')

const filteredCampaigns = computed(() => {
  if (!statusFilter.value) return campaigns.value
  return campaigns.value.filter(c => c.status === statusFilter.value)
})

// ── Toast ────────────────────────────────────────────────────────────────────
const snackbar = ref(false)
const snackbarText = ref('')
const snackbarColor = ref<'success' | 'error'>('success')

function showToast(message: string, color: 'success' | 'error' = 'success') {
  snackbarText.value = message
  snackbarColor.value = color
  snackbar.value = true
}

const statusColor: Record<string, string> = {
  draft:     'default',
  scheduled: 'info',
  sending:   'warning',
  sent:      'success',
  cancelled: 'error',
}
const statusIcon: Record<string, string> = {
  draft:     'tabler-pencil',
  scheduled: 'tabler-calendar-time',
  sending:   'tabler-loader',
  sent:      'tabler-circle-check',
  cancelled: 'tabler-x',
}
const audienceLabel: Record<string, string> = {
  all:        t('email_campaigns.index.audience_labels.all'),
  department: t('email_campaigns.index.audience_labels.department'),
  custom:     t('email_campaigns.index.audience_labels.custom'),
}

function formatDate(d: string | null): string {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}

const stats = computed(() => [
  { label: t('email_campaigns.index.stats.total_campaigns'), value: campaigns.value.length, icon: 'tabler-mail', color: 'primary' },
  { label: t('email_campaigns.index.stats.sent'), value: campaigns.value.filter(c => c.status === 'sent').length, icon: 'tabler-circle-check', color: 'success' },
  { label: t('email_campaigns.index.stats.scheduled'), value: campaigns.value.filter(c => c.status === 'scheduled').length, icon: 'tabler-calendar-time', color: 'info' },
  { label: t('email_campaigns.index.stats.drafts'), value: campaigns.value.filter(c => c.status === 'draft').length, icon: 'tabler-pencil', color: 'warning' },
])

// ── Template selection (obligatoire à la création) ────────────────────────────
const selectedTemplate = ref<Template | null>(null)
const templateError = ref(false)

function applyTemplate(tpl: Template) {
  form.value.title       = tpl.title
  form.value.subject     = tpl.subject
  form.value.from_name   = tpl.from_name ?? form.value.from_name
  form.value.from_email  = tpl.from_email ?? form.value.from_email
  form.value.body        = tpl.body
  selectedTemplate.value = tpl
  templateError.value    = false
}

const dialog  = ref(false)
const editing = ref<Campaign | null>(null)
const saving  = ref(false)

const blankForm = () => ({
  title:          '',
  subject:        '',
  from_name:      '',
  from_email:     '',
  reply_to:       '',
  body:           '',
  audience_type:  'all' as 'all' | 'department' | 'custom',
  department_ids: [] as number[],
  custom_emails:  '',
})

const form = ref(blankForm())

function openCreate() {
  editing.value          = null
  selectedTemplate.value = null
  templateError.value    = false
  form.value             = blankForm()
  dialog.value           = true
}

function openEdit(c: Campaign) {
  editing.value          = c
  selectedTemplate.value = null
  templateError.value    = false
  form.value             = blankForm()
  form.value.title       = c.title
  form.value.subject     = c.subject
  form.value.audience_type = c.audience_type
  dialog.value           = true
}

function buildPayload() {
  const filter: Record<string, any> = {}
  if (form.value.audience_type === 'department') filter.department_ids = form.value.department_ids
  if (form.value.audience_type === 'custom') {
    filter.emails = form.value.custom_emails.split(/[\n,]+/).map(e => e.trim()).filter(Boolean)
  }
  return {
    title:           form.value.title,
    subject:         form.value.subject,
    from_name:       form.value.from_name,
    from_email:      form.value.from_email,
    reply_to:        form.value.reply_to || null,
    body:            form.value.body,
    audience_type:   form.value.audience_type,
    audience_filter: Object.keys(filter).length ? filter : null,
  }
}

async function save() {
  // Le choix d'un modèle est obligatoire uniquement à la création
  if (!editing.value && !selectedTemplate.value) {
    templateError.value = true
    return
  }

  saving.value = true
  try {
    if (editing.value) {
      await axios.patch(route('cms.campaigns.update', editing.value.id), buildPayload())
      showToast(t('email_campaigns.toast.updated'))
    } else {
      await axios.post(route('cms.campaigns.store'), buildPayload())
      showToast(t('email_campaigns.toast.created'))
    }
    dialog.value = false
    router.reload({ only: ['campaigns'] })
  } catch (error: any) {
    console.error('Save campaign failed:', error.response?.data ?? error)
    showToast(t('email_campaigns.toast.error'), 'error')
  } finally {
    saving.value = false
  }
}

const actionLoading = ref<number | null>(null)

function sendNow(c: Campaign) {
  confirmDialog.value.open(
    {
      title: t('email_campaigns.index.confirm_send.title'),
      message: t('email_campaigns.index.confirm_send.message', { title: c.title }),
      confirmText: t('email_campaigns.index.confirm_send.confirm'),
      confirmColor: 'warning',
      icon: 'tabler-alert-triangle'
    },
    async () => {
      actionLoading.value = c.id
      try {
        await axios.post(route('cms.campaigns.send', c.id))
        showToast(t('email_campaigns.toast.sent'))
        router.reload({ only: ['campaigns'] })
      } finally {
        actionLoading.value = null
      }
    },
  )
}

async function duplicate(c: Campaign) {
  actionLoading.value = c.id
  try {
    await axios.post(route('cms.campaigns.duplicate', c.id))
    showToast(t('email_campaigns.toast.duplicated'))
    router.reload({ only: ['campaigns'] })
  } finally {
    actionLoading.value = null
  }
}

function destroy(c: Campaign) {
  confirmDialog.value.open(
    {
      title: t('email_campaigns.index.confirm_delete.title'),
      message: t('email_campaigns.index.confirm_delete.message', { title: c.title }),
      confirmText: t('email_campaigns.index.confirm_delete.confirm'),
      confirmColor: 'error',
      icon: 'tabler-trash'
    },
    async () => {
      await axios.delete(route('cms.campaigns.destroy', c.id))
      campaigns.value = campaigns.value.filter(x => x.id !== c.id)
      showToast(t('email_campaigns.toast.deleted'))
    },
  )
}

const scheduleDialog   = ref(false)
const scheduleCampaign = ref<Campaign | null>(null)
const scheduleAt       = ref('')
const scheduling       = ref(false)

function openSchedule(c: Campaign) {
  scheduleCampaign.value = c
  scheduleAt.value       = ''
  scheduleDialog.value   = true
}

async function confirmSchedule() {
  if (!scheduleCampaign.value) return
  scheduling.value = true
  try {
    await axios.post(route('cms.campaigns.schedule', scheduleCampaign.value.id), { scheduled_at: scheduleAt.value })
    scheduleDialog.value = false
    showToast(t('email_campaigns.toast.scheduled'))
    router.reload({ only: ['campaigns'] })
  } finally {
    scheduling.value = false
  }
}
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold mb-1">{{ t('email_campaigns.index.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('email_campaigns.index.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" color="secondary" prepend-icon="tabler-template" :href="route('cms.campaign-templates.index')">
          {{ t('email_campaigns.index.templates_button') }}
        </VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">
          {{ t('email_campaigns.index.new_campaign') }}
        </VBtn>
      </div>
    </div>

    <VRow class="mb-6">
      <VCol v-for="s in stats" :key="s.label" cols="12" sm="6" lg="3">
        <VCard>
          <VCardText class="d-flex align-center gap-4">
            <VAvatar :color="s.color" variant="tonal" rounded size="44">
              <VIcon :icon="s.icon" size="24" />
            </VAvatar>
            <div>
              <div class="text-h5 font-weight-bold">{{ s.value }}</div>
              <div class="text-body-2 text-medium-emphasis">{{ s.label }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard>
      <VCardText class="d-flex align-center justify-space-between flex-wrap gap-3 pb-0">
        <div class="d-flex gap-3 flex-wrap">
          <VTextField v-model="search" prepend-inner-icon="tabler-search" :placeholder="t('email_campaigns.index.search_placeholder')"
            variant="outlined" density="compact" hide-details clearable style="min-width:220px" />
          <VSelect v-model="statusFilter"
            :items="[
              { title: t('email_campaigns.index.status_filter.all'), value: '' },
              { title: t('email_campaigns.index.status_filter.draft'), value: 'draft' },
              { title: t('email_campaigns.index.status_filter.scheduled'), value: 'scheduled' },
              { title: t('email_campaigns.index.status_filter.sending'), value: 'sending' },
              { title: t('email_campaigns.index.status_filter.sent'), value: 'sent' },
              { title: t('email_campaigns.index.status_filter.cancelled'), value: 'cancelled' },
            ]"
            variant="outlined" density="compact" hide-details style="min-width:160px" />
        </div>
      </VCardText>

      <VDataTable :headers="headers" :items="filteredCampaigns" :search="search"
        :sort-by="[{ key: 'created_at', order: 'desc' }]" hover class="text-no-wrap">
        <template #item.title="{ item }">
          <div class="py-1">
            <p class="text-body-1 font-weight-semibold mb-0 text-primary cursor-pointer"
              @click="router.visit(route('cms.campaigns.show', item.id))">{{ item.title }}</p>
            <p class="text-caption text-medium-emphasis mb-0">{{ item.subject }}</p>
          </div>
        </template>

        <template #item.status="{ item }">
          <VChip :color="statusColor[item.status]" size="small" label>
            <VIcon :icon="statusIcon[item.status]" size="14" class="me-1" />
            <span class="text-capitalize">{{ item.status }}</span>
          </VChip>
        </template>

        <template #item.audience_type="{ item }">
          <div class="d-flex align-center gap-1">
            <VIcon icon="tabler-users" size="14" color="medium-emphasis" />
            <span class="text-body-2">{{ audienceLabel[item.audience_type] }}</span>
          </div>
        </template>

        <template #item.sent_at="{ item }">
          <span class="text-body-2">{{ formatDate(item.sent_at ?? item.scheduled_at) }}</span>
        </template>

        <template #item.actions="{ item }">
          <div class="d-flex gap-1 justify-center">
            <VBtn icon size="small" variant="text" color="primary" @click="router.visit(route('cms.campaigns.show', item.id))">
              <VIcon icon="tabler-eye" size="18" />
              <VTooltip activator="parent" location="top">{{ t('email_campaigns.index.table.view') }}</VTooltip>
            </VBtn>
            <VBtn v-if="['draft', 'scheduled'].includes(item.status)" icon size="small" variant="text" @click="openEdit(item)">
              <VIcon icon="tabler-pencil" size="18" />
              <VTooltip activator="parent" location="top">{{ t('email_campaigns.index.table.edit') }}</VTooltip>
            </VBtn>
            <VBtn v-if="item.status === 'draft'" icon size="small" variant="text" color="success"
              :loading="actionLoading === item.id" @click="sendNow(item)">
              <VIcon icon="tabler-send" size="18" />
              <VTooltip activator="parent" location="top">{{ t('email_campaigns.index.table.send_now') }}</VTooltip>
            </VBtn>
            <VBtn v-if="item.status === 'draft'" icon size="small" variant="text" color="info" @click="openSchedule(item)">
              <VIcon icon="tabler-calendar-time" size="18" />
              <VTooltip activator="parent" location="top">{{ t('email_campaigns.index.table.schedule') }}</VTooltip>
            </VBtn>
            <VBtn icon size="small" variant="text" :loading="actionLoading === item.id" @click="duplicate(item)">
              <VIcon icon="tabler-copy" size="18" />
              <VTooltip activator="parent" location="top">{{ t('email_campaigns.index.table.duplicate') }}</VTooltip>
            </VBtn>
            <VBtn v-if="item.status !== 'sending'" icon size="small" variant="text" color="error" @click="destroy(item)">
              <VIcon icon="tabler-trash" size="18" />
              <VTooltip activator="parent" location="top">{{ t('email_campaigns.index.table.delete') }}</VTooltip>
            </VBtn>
          </div>
        </template>

        <template #no-data>
          <div class="text-center py-12">
            <VAvatar color="primary" variant="tonal" size="64" class="mb-4">
              <VIcon icon="tabler-mail-off" size="32" />
            </VAvatar>
            <p class="text-h6 font-weight-semibold mb-1">{{ t('email_campaigns.index.empty.title') }}</p>
            <p class="text-medium-emphasis mb-4">{{ t('email_campaigns.index.empty.description') }}</p>
            <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('email_campaigns.index.empty.button') }}</VBtn>
          </div>
        </template>
      </VDataTable>
    </VCard>
  </div>

  <VDialog v-model="dialog" max-width="800" scrollable persistent>
    <VCard>
      <VCardItem class="border-b">
        <VCardTitle class="text-h6">{{ editing ? t('email_campaigns.dialog.edit_title') : t('email_campaigns.dialog.new_title') }}</VCardTitle>
        <template #append><VBtn icon variant="text" @click="dialog = false"><VIcon icon="tabler-x" /></VBtn></template>
      </VCardItem>

      <VCardText class="pa-6" style="max-height:72vh;overflow-y:auto;">
        <div v-if="!editing && props.templates.length" class="mb-5">
          <p class="text-caption font-weight-semibold text-uppercase mb-2"
             :class="templateError ? 'text-error' : 'text-medium-emphasis'"
             style="letter-spacing:.5px">
            {{ t('email_campaigns.dialog.template_hint') }}
            <span class="text-error">*</span>
          </p>

          <div class="d-flex gap-2 flex-wrap">
            <VChip v-for="tpl in props.templates" :key="tpl.id"
              :variant="selectedTemplate?.id === tpl.id ? 'elevated' : 'tonal'"
              :color="selectedTemplate?.id === tpl.id ? 'primary' : (templateError ? 'error' : 'default')"
              size="small" class="cursor-pointer" @click="applyTemplate(tpl)">
              <VIcon icon="tabler-template" size="14" class="me-1" />{{ tpl.title }}
            </VChip>
          </div>

          <VAlert v-if="templateError" type="error" variant="tonal" density="compact" class="mt-3" icon="tabler-alert-circle">
            {{ t('email_campaigns.dialog.template_required_error') }}
          </VAlert>

          <VDivider class="mt-4" />
        </div>

        <VRow dense>
          <VCol cols="12"><VTextField v-model="form.title" :label="t('email_campaigns.dialog.title_label')" variant="outlined" density="compact" /></VCol>
          <VCol cols="12"><VTextField v-model="form.subject" :label="t('email_campaigns.dialog.subject_label')" variant="outlined" density="compact" /></VCol>
          <VCol cols="12" sm="6"><VTextField v-model="form.from_name" :label="t('email_campaigns.dialog.from_name_label')" variant="outlined" density="compact" /></VCol>
          <VCol cols="12" sm="6"><VTextField v-model="form.from_email" :label="t('email_campaigns.dialog.from_email_label')" type="email" variant="outlined" density="compact" /></VCol>
          <VCol cols="12"><VTextField v-model="form.reply_to" :label="t('email_campaigns.dialog.reply_to_label')" type="email" variant="outlined" density="compact" /></VCol>

          <VCol cols="12">
            <VDivider class="my-2" />
            <p class="text-caption font-weight-semibold text-uppercase text-medium-emphasis mb-3" style="letter-spacing:.5px">{{ t('email_campaigns.dialog.audience_section') }}</p>
            <VBtnToggle v-model="form.audience_type" color="primary" variant="outlined" density="compact" mandatory class="mb-3">
              <VBtn value="all" size="small"><VIcon icon="tabler-users" class="me-1" size="16" />{{ t('email_campaigns.dialog.audience_all') }}</VBtn>
              <VBtn value="department" size="small"><VIcon icon="tabler-building" class="me-1" size="16" />{{ t('email_campaigns.dialog.audience_department') }}</VBtn>
              <VBtn value="custom" size="small"><VIcon icon="tabler-list" class="me-1" size="16" />{{ t('email_campaigns.dialog.audience_custom') }}</VBtn>
            </VBtnToggle>

            <VAutocomplete v-if="form.audience_type === 'department'" v-model="form.department_ids"
              :items="departments" item-title="name" item-value="id"
              :label="t('email_campaigns.dialog.departments_label')" multiple chips closable-chips variant="outlined" density="compact" />

            <VTextarea v-if="form.audience_type === 'custom'" v-model="form.custom_emails"
              :label="t('email_campaigns.dialog.custom_emails_label')" variant="outlined" density="compact" rows="4"
              :placeholder="t('email_campaigns.dialog.custom_emails_placeholder')" />
          </VCol>

          <VCol cols="12">
            <VDivider class="my-2" />
            <p class="text-caption font-weight-semibold text-uppercase text-medium-emphasis mb-2" style="letter-spacing:.5px">{{ t('email_campaigns.dialog.body_section') }}</p>
            <KbArticleEditor v-model="form.body" :placeholder="t('email_campaigns.dialog.body_placeholder')" />
          </VCol>
        </VRow>
      </VCardText>

      <VDivider />
      <VCardActions class="pa-4">
        <VSpacer />
        <VBtn variant="text" @click="dialog = false">{{ t('email_campaigns.dialog.cancel') }}</VBtn>
        <VBtn color="primary" variant="elevated" :loading="saving" @click="save">
          {{ editing ? t('email_campaigns.dialog.save_changes') : t('email_campaigns.dialog.create_campaign') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <VDialog v-model="scheduleDialog" max-width="420">
    <VCard>
      <VCardItem class="border-b"><VCardTitle class="text-h6">{{ t('email_campaigns.schedule_dialog.title') }}</VCardTitle></VCardItem>
      <VCardText class="pa-6">
        <p class="text-body-2 text-medium-emphasis mb-4">{{ t('email_campaigns.schedule_dialog.description', { title: scheduleCampaign?.title }) }}</p>
        <VTextField v-model="scheduleAt" type="datetime-local" :label="t('email_campaigns.schedule_dialog.label')" variant="outlined" density="compact" />
      </VCardText>
      <VDivider />
      <VCardActions class="pa-4">
        <VSpacer />
        <VBtn variant="text" @click="scheduleDialog = false">{{ t('email_campaigns.schedule_dialog.cancel') }}</VBtn>
        <VBtn color="primary" variant="elevated" :loading="scheduling" :disabled="!scheduleAt" @click="confirmSchedule">
          {{ t('email_campaigns.schedule_dialog.confirm') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <VSnackbar v-model="snackbar" :color="snackbarColor" timeout="3000" location="top end">
    {{ snackbarText }}
    <template #actions>
      <VBtn icon="tabler-x" variant="text" @click="snackbar = false" />
    </template>
  </VSnackbar>

  <ConfirmDialog ref="confirmDialog" />
</template>
