<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Schedule = {
  id: number; uuid: string; title: string; description: string | null; maintenance_type: string
  status: { value: string; label: string; color: string }; vendor_name: string | null
  estimated_cost: string | null; actual_cost: string | null; due_date: string | null
  started_at: string | null; completed_at: string | null; next_due_date: string | null
  recurrence_days: number | null; notes: string | null
  assigned_to: { id: number; name: string } | null; created_at: string
}

const props = defineProps<{
  asset: { uuid: string; name: string; asset_tag: string | null }
  schedule: Schedule
}>()

const showCompleteDialog = ref(false)
const completeForm = useForm({ actual_cost: '', action_taken: '', condition_after: '', notes: '' })
function submitComplete() { completeForm.post(route('assets.maintenance.complete', [props.asset.uuid, props.schedule.uuid]), { onSuccess: () => { showCompleteDialog.value = false; completeForm.reset() } }) }

const conditionItems = computed(() => [
  { title: t('assets_create.conditions.new'), value: 'new' },
  { title: t('assets_create.conditions.good'), value: 'good' },
  { title: t('assets_create.conditions.fair'), value: 'fair' },
  { title: t('assets_create.conditions.poor'), value: 'poor' },
  { title: t('assets_create.conditions.damaged'), value: 'damaged' },
])

function formatDate(val: string | null) { if (!val) return '—'; return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' }) }
function formatCurrency(val: string | null) { if (val == null) return '—'; return new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR', maximumFractionDigits: 0 }).format(Number(val)) }
const isPending = ['scheduled', 'in_progress'].includes(props.schedule.status.value)
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center gap-2 mb-5">
      <VBtn icon="tabler-arrow-left" variant="text" size="small" @click="router.visit(route('assets.maintenance.index'))" />
      <span class="text-body-2 text-medium-emphasis cursor-pointer" @click="router.visit(route('assets.maintenance.index'))">{{ t('assets_maintenance_show.breadcrumb_maintenance') }}</span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span class="text-body-2 text-medium-emphasis cursor-pointer" @click="router.visit(route('assets.show', asset.uuid))">{{ asset.name }}</span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span class="text-body-2 font-weight-medium">{{ schedule.title }}</span>
    </div>

    <div class="d-flex align-start justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <div class="d-flex align-center gap-2 mb-1 flex-wrap">
          <h4 class="text-h4 font-weight-bold">{{ schedule.title }}</h4>
          <VChip :color="schedule.status.color" size="small" label variant="tonal" class="font-weight-medium">{{ schedule.status.label }}</VChip>
        </div>
        <p class="text-medium-emphasis mb-0">{{ asset.name }}<span v-if="asset.asset_tag"> · {{ asset.asset_tag }}</span></p>
      </div>
      <VBtn v-if="isPending" color="success" prepend-icon="tabler-circle-check" @click="showCompleteDialog = true">{{ t('assets_maintenance_show.mark_complete') }}</VBtn>
    </div>

    <VRow>
      <VCol cols="12" md="8">
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="pa-5 pb-3 text-body-1 font-weight-semibold">{{ t('assets_maintenance_show.details.title') }}</VCardTitle>
          <VCardText class="pa-5 pt-0">
            <p v-if="schedule.description" class="text-body-2 text-medium-emphasis mb-4">{{ schedule.description }}</p>
            <VRow>
              <VCol cols="6" sm="4"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_maintenance_show.details.type') }}</p><p class="text-body-2 font-weight-medium mb-0 text-capitalize">{{ schedule.maintenance_type?.replace('_', ' ') }}</p></VCol>
              <VCol cols="6" sm="4"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_maintenance_show.details.due_date') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ formatDate(schedule.due_date) }}</p></VCol>
              <VCol cols="6" sm="4"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_maintenance_show.details.assigned_to') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ schedule.assigned_to?.name ?? '—' }}</p></VCol>
              <VCol cols="6" sm="4"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_maintenance_show.details.vendor') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ schedule.vendor_name ?? '—' }}</p></VCol>
              <VCol cols="6" sm="4"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_maintenance_show.details.estimated_cost') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ formatCurrency(schedule.estimated_cost) }}</p></VCol>
              <VCol cols="6" sm="4"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_maintenance_show.details.actual_cost') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ formatCurrency(schedule.actual_cost) }}</p></VCol>
              <VCol v-if="schedule.recurrence_days" cols="6" sm="4"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_maintenance_show.details.recurrence') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ t('assets_maintenance_show.details.every_days', { days: schedule.recurrence_days }) }}</p></VCol>
              <VCol v-if="schedule.next_due_date" cols="6" sm="4"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_maintenance_show.details.next_due') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ formatDate(schedule.next_due_date) }}</p></VCol>
              <VCol v-if="schedule.notes" cols="12"><VDivider class="mb-4" /><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_maintenance_show.details.notes') }}</p><p class="text-body-2 mb-0">{{ schedule.notes }}</p></VCol>
            </VRow>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard elevation="0" border>
          <VCardTitle class="pa-5 pb-3 text-body-1 font-weight-semibold">{{ t('assets_maintenance_show.timeline.title') }}</VCardTitle>
          <VCardText class="pa-5 pt-0">
            <div class="d-flex flex-column gap-3">
              <div class="d-flex justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('assets_maintenance_show.timeline.scheduled') }}</span><span class="text-body-2">{{ formatDate(schedule.created_at) }}</span></div>
              <div v-if="schedule.started_at" class="d-flex justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('assets_maintenance_show.timeline.started') }}</span><span class="text-body-2">{{ formatDate(schedule.started_at) }}</span></div>
              <div v-if="schedule.completed_at" class="d-flex justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('assets_maintenance_show.timeline.completed') }}</span><span class="text-body-2 text-success">{{ formatDate(schedule.completed_at) }}</span></div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="showCompleteDialog" max-width="520">
      <VCard>
        <VCardTitle class="pa-5 pb-3 text-body-1 font-weight-semibold">{{ t('assets_maintenance_show.dialog.title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-5">
          <VForm @submit.prevent="submitComplete">
            <VRow>
              <VCol cols="12"><VTextarea v-model="completeForm.action_taken" :label="t('assets_maintenance_show.dialog.action_taken')" rows="3" :error-messages="completeForm.errors.action_taken" variant="outlined" /></VCol>
              <VCol cols="12" md="6"><VTextField v-model="completeForm.actual_cost" :label="t('assets_maintenance_show.dialog.actual_cost')" type="number" :error-messages="completeForm.errors.actual_cost" density="comfortable" variant="outlined" /></VCol>
              <VCol cols="12" md="6"><VSelect v-model="completeForm.condition_after" :items="conditionItems" item-title="title" item-value="value" :label="t('assets_maintenance_show.dialog.condition_after')" :error-messages="completeForm.errors.condition_after" density="comfortable" variant="outlined" clearable /></VCol>
              <VCol cols="12"><VTextarea v-model="completeForm.notes" :label="t('assets_maintenance_show.dialog.notes')" rows="2" :error-messages="completeForm.errors.notes" variant="outlined" /></VCol>
            </VRow>
          </VForm>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4 gap-2"><VSpacer /><VBtn variant="text" @click="showCompleteDialog = false">{{ t('assets_maintenance_show.dialog.cancel') }}</VBtn><VBtn color="success" :loading="completeForm.processing" @click="submitComplete">{{ t('assets_maintenance_show.dialog.complete') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
