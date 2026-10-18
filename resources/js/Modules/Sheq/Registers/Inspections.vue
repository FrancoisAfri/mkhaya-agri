<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'
import RegisterTable from '@/Modules/Sheq/Partials/RegisterTable.vue'

const { t } = useI18n()

const props = defineProps<{
  schedules: Record<string, any>[]
  completed: Record<string, any>[]
  templates?: { uuid: string; label: string }[]
  sites?: { id: number; name: string }[]
  canManage?: boolean
  canPerform?: boolean
  noCompany?: boolean
}>()

const scheduling = ref(false)

const schedule = useForm({
  template_uuid: null as string | null,
  site_id: null as number | null,
  frequency_days: 30,
  next_due: new Date().toISOString().slice(0, 10),
})

const createSchedule = () => schedule.post('/sheq/inspections/schedules', {
  onSuccess: () => { scheduling.value = false; schedule.reset() },
})

const statusColour = (status: string) => ({
  DRAFT: 'grey', OPEN: 'warning', APPROVED: 'success', ACTIVE: 'success', ISSUED: 'info',
  IN_PROGRESS: 'info', COMPLETED: 'success', CLOSED: 'grey', SUPERSEDED: 'grey',
  SUSPENDED: 'error', REJECTED: 'error', PUBLISHED: 'success', WITHDRAWN: 'error',
  VALID: 'success', EXPIRED: 'error', BLOCKED: 'error', COMPLIANT: 'success',
  EXPIRING: 'warning', PENDING: 'warning', TRIAGED: 'info',
}[status] ?? 'grey')
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-5">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.registers.inspections.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.registers.inspections.subtitle') }}</p>
      </div>
      <div class="d-flex align-center gap-2">
        <VBtn variant="tonal" prepend-icon="mdi-clipboard-list-outline" href="/sheq/inspections/templates">
          {{ t('sheq.inspections.templates_title') }}
        </VBtn>
        <VBtn
          v-if="canManage" color="primary" prepend-icon="mdi-calendar-plus"
          :disabled="!templates?.length" @click="scheduling = true"
        >{{ t('sheq.inspections.new_schedule') }}</VBtn>
      </div>
    </div>

    <!-- A schedule needs a template. Said plainly rather than offering a form that cannot submit. -->
    <VAlert v-if="canManage && !templates?.length" type="info" variant="tonal" class="mb-4">
      {{ t('sheq.inspections.need_template_first') }}
      <Link href="/sheq/inspections/templates" class="text-primary">
        {{ t('sheq.inspections.build_one') }}
      </Link>
    </VAlert>

    <NoCompanyNotice :show="!!noCompany" />

    <RegisterTable
      :title="t('sheq.registers.inspections.schedules')" :rows="schedules"
      :subtitle="t('sheq.registers.inspections.schedules_hint')"
      :search-keys="['template', 'site_name']"
      :empty-hint="t('sheq.registers.inspections.empty_schedules')"
      :columns="[
        { key: 'template', label: t('sheq.registers.col.template') },
        { key: 'site_name', label: t('sheq.registers.col.site'), kind: 'muted' },
        { key: 'frequency_days', label: t('sheq.registers.col.every_days'), kind: 'muted' },
        { key: 'last_completed', label: t('sheq.registers.col.last_done'), kind: 'muted' },
        { key: 'next_due', label: t('sheq.registers.col.next_due'), kind: 'flag', flagField: 'overdue' },
      ]"
    >
      <template #row-actions="{ row }">
        <VBtn
          v-if="canPerform && row.is_active" size="small" variant="tonal" color="primary"
          :href="`/sheq/inspections/${row.uuid}/perform`"
        >{{ t('sheq.inspections.do_it') }}</VBtn>
      </template>
    </RegisterTable>

    <VDialog v-model="scheduling" max-width="640">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-semibold">
          {{ t('sheq.inspections.new_schedule') }}
        </VCardTitle>
        <VCardText>
          <VSelect
            v-model="schedule.template_uuid" :items="templates ?? []"
            item-title="label" item-value="uuid"
            :label="t('sheq.registers.col.template')" variant="outlined" density="comfortable"
            :error-messages="schedule.errors.template_uuid" class="mb-3"
          />
          <VSelect
            v-model="schedule.site_id" :items="sites ?? []" item-title="name" item-value="id"
            clearable :label="t('sheq.registers.col.site')" variant="outlined" density="comfortable"
            :error-messages="schedule.errors.site_id" class="mb-3"
          />
          <VRow>
            <VCol cols="6">
              <VTextField
                v-model.number="schedule.frequency_days" type="number"
                :label="t('sheq.registers.col.every_days')" variant="outlined" density="comfortable"
                :error-messages="schedule.errors.frequency_days"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="schedule.next_due" type="date"
                :label="t('sheq.registers.col.next_due')" variant="outlined" density="comfortable"
                :error-messages="schedule.errors.next_due"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="scheduling = false">{{ t('sheq.action.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="schedule.processing"
                :disabled="schedule.processing" @click="createSchedule">
            {{ t('sheq.registers.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <RegisterTable
      :title="t('sheq.registers.inspections.completed')" :rows="completed"
      :search-keys="['reference', 'template', 'site_name']"
      :empty-hint="t('sheq.registers.inspections.empty_completed')"
      :columns="[
        { key: 'reference', label: t('sheq.registers.col.reference'), width: '140px' },
        { key: 'template', label: t('sheq.registers.col.template') },
        { key: 'site_name', label: t('sheq.registers.col.site'), kind: 'muted' },
        { key: 'score_percent', label: t('sheq.registers.col.score'), kind: 'muted' },
        { key: 'failed_count', label: t('sheq.registers.col.failed') },
        { key: 'status', label: t('sheq.registers.col.status'), kind: 'chip', chipColour: (r) => statusColour(r.status) },
      ]"
    />
  </div>
</template>
