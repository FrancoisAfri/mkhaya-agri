<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Schedule {
  id: number; name: string; report_type: string; frequency: string; format: string
  recipients: string; is_active: boolean; last_sent_at: string | null
  next_run_at: string | null; created_by: string
}

const props = defineProps<{ schedules: Schedule[] }>()

const REPORT_TYPES = computed(()=> [
  { value: 'all_claims',    title: t('claims_scheduled_reports.dialog.report_type') },
  { value: 'by_employee',   title: t('claims_reports.tabs.by_employee') },
  { value: 'by_category',   title: t('claims_reports.tabs.by_category') },
  { value: 'by_department', title: t('claims_reports.tabs.by_department') },
  { value: 'high_risk',     title: t('claims_reports.tabs.high_risk') },
  { value: 'violations',    title: t('claims_reports.tabs.policy_violations') },
  { value: 'travel',        title: t('claims_reports.tabs.travel') },
])

const dialog      = ref(false)
const deleteDialog = ref(false)
const editTarget  = ref<Schedule | null>(null)
const deleteId    = ref<number | null>(null)

const form = useForm({
  name:        '',
  report_type: 'all_claims',
  frequency:   'weekly',
  format:      'csv',
  recipients:  '',
})

function openNew() {
  editTarget.value = null
  form.reset()
  dialog.value = true
}

function openEdit(schedule: Schedule) {
  editTarget.value  = schedule
  form.name         = schedule.name
  form.report_type  = schedule.report_type
  form.frequency    = schedule.frequency
  form.format       = schedule.format
  form.recipients   = schedule.recipients
  dialog.value      = true
}

function save() {
  if (editTarget.value) {
    form.patch(route('claims.reports.schedules.update', { schedule: editTarget.value.id }), {
      onSuccess: () => { dialog.value = false; form.reset() },
    })
  } else {
    form.post(route('claims.reports.schedules.store'), {
      onSuccess: () => { dialog.value = false; form.reset() },
    })
  }
}

function confirmDelete(id: number) { deleteId.value = id; deleteDialog.value = true }
function doDelete() {
  if (!deleteId.value) return
  router.delete(route('claims.reports.schedules.destroy', { schedule: deleteId.value }), {
    onSuccess: () => { deleteDialog.value = false; deleteId.value = null },
  })
}

function toggleActive(schedule: Schedule) {
  router.patch(route('claims.reports.schedules.update', { schedule: schedule.id }),
    { is_active: !schedule.is_active }, { preserveScroll: true })
}

const freqColor = (f: string) => ({ daily: 'success', weekly: 'primary', monthly: 'warning' })[f] ?? 'grey'
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('claims_scheduled_reports.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('claims_scheduled_reports.description') }}</p>
      </div>
      <div class="d-flex ga-2">
        <VBtn variant="outlined" prepend-icon="tabler-chart-bar" :href="route('claims.reports.index')">{{ t('claims_scheduled_reports.all_reports') }}</VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">{{ t('claims_scheduled_reports.new_schedule') }}</VBtn>
      </div>
    </div>

    <VCard v-if="!schedules.length" variant="outlined" class="text-center pa-12">
      <VIcon size="56" class="mb-3 opacity-30">tabler-calendar-clock</VIcon>
      <div class="text-h6 mb-2">{{ t('claims_scheduled_reports.empty_title') }}</div>
      <p class="text-body-2 text-medium-emphasis mb-4">
        {{ t('claims_scheduled_reports.empty_description') }}
      </p>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">{{ t('claims_scheduled_reports.create_first') }}</VBtn>
    </VCard>

    <VRow v-else dense>
      <VCol v-for="schedule in schedules" :key="schedule.id" cols="12" sm="6" lg="4">
        <VCard :class="schedule.is_active ? '' : 'opacity-60'" height="100%">
          <VCardText class="pa-4">
            <div class="d-flex align-start justify-space-between mb-3">
              <div>
                <div class="text-body-1 font-weight-bold">{{ schedule.name }}</div>
                <div class="text-caption text-medium-emphasis mt-0.5">{{ t('claims_scheduled_reports.by', { creator: schedule.created_by }) }}</div>
              </div>
              <VSwitch :model-value="schedule.is_active" density="compact" hide-details color="success"
                class="mt-0" @change="toggleActive(schedule)" />
            </div>

            <div class="d-flex flex-wrap ga-1 mb-3">
              <VChip size="x-small" color="primary" variant="tonal">
                {{ REPORT_TYPES.find(rt => rt.value === schedule.report_type)?.title ?? schedule.report_type }}
              </VChip>
              <VChip size="x-small" :color="freqColor(schedule.frequency)" variant="tonal">
                {{ schedule.frequency }}
              </VChip>
              <VChip size="x-small" variant="outlined">{{ schedule.format.toUpperCase() }}</VChip>
            </div>

            <div class="text-caption text-medium-emphasis mb-1">
              <VIcon size="12" class="mr-1">tabler-mail</VIcon>
              {{ schedule.recipients }}
            </div>
            <div class="text-caption text-medium-emphasis mb-1">
              <VIcon size="12" class="mr-1">tabler-clock</VIcon>
              {{ t('claims_scheduled_reports.next', { date: schedule.next_run_at ?? '—' }) }}
            </div>
            <div class="text-caption text-medium-emphasis">
              <VIcon size="12" class="mr-1">tabler-send</VIcon>
              {{ t('claims_scheduled_reports.last_sent', { date: schedule.last_sent_at ?? t('claims_scheduled_reports.never') }) }}
            </div>
          </VCardText>

          <VDivider />
          <VCardActions class="pa-2">
            <VBtn size="small" variant="text" prepend-icon="tabler-pencil" @click="openEdit(schedule)">{{ t('claims_scheduled_reports.edit') }}</VBtn>
            <VSpacer />
            <VBtn size="small" variant="text" color="error" icon="tabler-trash" @click="confirmDelete(schedule.id)" />
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="dialog" max-width="520" persistent>
      <VCard :title="editTarget ? t('claims_scheduled_reports.dialog.edit_title') : t('claims_scheduled_reports.dialog.new_title')">
        <VCardText>
          <VTextField v-model="form.name" :label="t('claims_scheduled_reports.dialog.name')" variant="outlined" density="compact"
            :error-messages="form.errors.name" class="mb-3" />
          <VSelect v-model="form.report_type" :items="REPORT_TYPES" item-value="value" item-title="title"
            :label="t('claims_scheduled_reports.dialog.report_type')" variant="outlined" density="compact" :error-messages="form.errors.report_type" class="mb-3" />
          <VRow dense>
            <VCol cols="6">
              <VSelect v-model="form.frequency"
                :items="[{value:'daily',title:t('claims_scheduled_reports.dialog.frequency_daily')},{value:'weekly',title:t('claims_scheduled_reports.dialog.frequency_weekly')},{value:'monthly',title:t('claims_scheduled_reports.dialog.frequency_monthly')}]"
                item-value="value" item-title="title" :label="t('claims_scheduled_reports.dialog.frequency')" variant="outlined" density="compact"
                :error-messages="form.errors.frequency" />
            </VCol>
            <VCol cols="6">
              <VSelect v-model="form.format"
                :items="[{value:'csv',title:t('claims_scheduled_reports.dialog.format_csv')},{value:'pdf',title:t('claims_scheduled_reports.dialog.format_pdf')}]"
                item-value="value" item-title="title" :label="t('claims_scheduled_reports.dialog.format')" variant="outlined" density="compact" />
            </VCol>
          </VRow>
          <VTextarea v-model="form.recipients" :label="t('claims_scheduled_reports.dialog.recipients')"
            variant="outlined" density="compact" rows="2" :error-messages="form.errors.recipients" class="mt-3"
            :hint="t('claims_scheduled_reports.dialog.recipients_hint')" persistent-hint />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="dialog = false">{{ t('claims_scheduled_reports.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">
            {{ editTarget ? t('claims_scheduled_reports.dialog.update') : t('claims_scheduled_reports.dialog.create') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="deleteDialog" max-width="380">
      <VCard :title="t('claims_scheduled_reports.delete_title')">
        <VCardText>{{ t('claims_scheduled_reports.delete_message') }}</VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="deleteDialog = false">{{ t('claims_scheduled_reports.cancel') }}</VBtn>
          <VBtn color="error" @click="doDelete">{{ t('claims_scheduled_reports.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
