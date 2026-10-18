<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Settings = {
  id?: number; work_start_time: string; work_end_time: string
  grace_period_minutes: number; overtime_threshold_minutes: number
  early_departure_threshold_minutes: number
  late_policy_enabled: boolean; overtime_enabled: boolean
  auto_clock_out_enabled: boolean; auto_clock_out_time: string | null
  require_shift_assignment: boolean; allow_manual_entry: boolean
  manager_approval_required: boolean
  working_days_per_week: number; working_hours_per_day: number
  timezone: string; use_employee_work_hours: boolean
  ers_enabled: boolean; ers_token: string | null; ers_api_url: string
  ers_clock_start_time: string; ers_clock_end_time: string
  absent_email_enabled: boolean
  auto_apply_leave_enabled: boolean; auto_apply_days_threshold: number
  auto_deduct_leave: boolean; allow_unpaid_when_annual_done: boolean
}

type NotificationConfig = {
  event: string; enabled: boolean; notify_employee: boolean
  notify_manager: boolean; notify_hr: boolean
  channel: string; delay_minutes: number; custom_message: string | null
}

const props = defineProps<{ settings: Settings; notifications: NotificationConfig[] }>()

const tab = ref('general')

const settingsForm = useForm({ ...props.settings })
const ersForm = useForm({
  ers_enabled: props.settings.ers_enabled, ers_token: props.settings.ers_token ?? '',
  ers_api_url: props.settings.ers_api_url, ers_clock_start_time: props.settings.ers_clock_start_time,
  ers_clock_end_time: props.settings.ers_clock_end_time, absent_email_enabled: props.settings.absent_email_enabled,
  auto_apply_leave_enabled: props.settings.auto_apply_leave_enabled,
  auto_apply_days_threshold: props.settings.auto_apply_days_threshold,
  auto_deduct_leave: props.settings.auto_deduct_leave,
  allow_unpaid_when_annual_done: props.settings.allow_unpaid_when_annual_done,
})
const notifForm = useForm({ notifications: props.notifications.map(n => ({ ...n })) })

function saveSettings() { settingsForm.put('/attendance/settings/general', { preserveScroll: true }) }
function saveErs() { ersForm.put('/attendance/settings/ers', { preserveScroll: true }) }
function testErs() { useForm({}).post('/attendance/settings/ers/test', { preserveScroll: true }) }
function saveNotifications() { notifForm.put('/attendance/settings/notifications', { preserveScroll: true }) }

const eventLabels: Record<string, string> = {
  late_arrival: t('attendance_setting_page.event_late_arrival'),
  absent: t('attendance_setting_page.event_absent'),
  overtime: t('attendance_setting_page.event_overtime'),
  early_departure: t('attendance_setting_page.event_early_departure'),
  missing_clockout: t('attendance_setting_page.event_missing_clockout'),
  weekly_summary: t('attendance_setting_page.event_weekly_summary'),
}

const timezones = ['Africa/Johannesburg', 'Africa/Lagos', 'Africa/Nairobi', 'UTC', 'Europe/London', 'America/New_York']
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('attendance_setting_page.breadcrumb_module') }}</div>
              <h4 class="text-h4 mb-1">{{ t('attendance_setting_page.title') }}</h4>
              <p class="mb-0 text-medium-emphasis">{{ t('attendance_setting_page.subtitle') }}</p>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VTabs v-model="tab" color="primary" class="px-4">
            <VTab value="general"><i class="fas fa-cog me-2"></i>{{ t('attendance_setting_page.tab_general') }}</VTab>
            <VTab value="ers"><i class="fas fa-fingerprint me-2"></i>{{ t('attendance_setting_page.tab_ers') }}</VTab>
            <VTab value="notifications"><i class="fas fa-bell me-2"></i>{{ t('attendance_setting_page.tab_notifications') }}</VTab>
          </VTabs>
          <VDivider />

          <VWindow v-model="tab">

            <VWindowItem value="general">
              <VCardText class="py-5">
                <VRow>
                  <VCol cols="12" md="6">
                    <p class="text-subtitle-1 font-weight-medium mb-4">{{ t('attendance_setting_page.work_hours') }}</p>
                    <VRow dense>
                      <VCol cols="12">
                        <VSwitch v-model="settingsForm.use_employee_work_hours" :label="t('attendance_setting_page.use_employee_work_hours')" color="primary" density="compact" inset :hint="t('attendance_setting_page.use_employee_work_hours_hint')" persistent-hint class="mb-2" />
                      </VCol>
                      <VCol cols="6"><VTextField v-model="settingsForm.work_start_time" :label="t('attendance_setting_page.default_start_time')" type="time" density="compact" :disabled="settingsForm.use_employee_work_hours" /></VCol>
                      <VCol cols="6"><VTextField v-model="settingsForm.work_end_time" :label="t('attendance_setting_page.default_end_time')" type="time" density="compact" :disabled="settingsForm.use_employee_work_hours" /></VCol>
                      <VCol cols="6"><VTextField v-model.number="settingsForm.working_hours_per_day" :label="t('attendance_setting_page.hours_per_day')" type="number" density="compact" :disabled="settingsForm.use_employee_work_hours" /></VCol>
                      <VCol cols="6"><VTextField v-model.number="settingsForm.working_days_per_week" :label="t('attendance_setting_page.days_per_week')" type="number" density="compact" /></VCol>
                      <VCol cols="12"><VSelect v-model="settingsForm.timezone" :items="timezones" :label="t('attendance_setting_page.timezone')" density="compact" /></VCol>
                      <VCol cols="12" v-if="settingsForm.use_employee_work_hours">
                        <VAlert type="info" variant="tonal" density="compact" style="font-size:0.82rem">
                          <i class="fas fa-info-circle me-2"></i>{{ t('attendance_setting_page.employee_hours_active_alert') }}
                        </VAlert>
                      </VCol>
                    </VRow>
                  </VCol>

                  <VCol cols="12" md="6">
                    <p class="text-subtitle-1 font-weight-medium mb-4">{{ t('attendance_setting_page.thresholds') }}</p>
                    <VRow dense>
                      <VCol cols="12"><VTextField v-model.number="settingsForm.grace_period_minutes" :label="t('attendance_setting_page.grace_period')" type="number" density="compact" :hint="t('attendance_setting_page.grace_period_hint')" persistent-hint /></VCol>
                      <VCol cols="12"><VTextField v-model.number="settingsForm.overtime_threshold_minutes" :label="t('attendance_setting_page.overtime_threshold')" type="number" density="compact" :hint="t('attendance_setting_page.overtime_threshold_hint')" persistent-hint /></VCol>
                      <VCol cols="12"><VTextField v-model.number="settingsForm.early_departure_threshold_minutes" :label="t('attendance_setting_page.early_departure_threshold')" type="number" density="compact" /></VCol>
                    </VRow>
                  </VCol>

                  <VCol cols="12">
                    <VDivider class="my-4" />
                    <p class="text-subtitle-1 font-weight-medium mb-4">{{ t('attendance_setting_page.policies') }}</p>
                    <VRow dense>
                      <VCol cols="6" md="4"><VSwitch v-model="settingsForm.late_policy_enabled" :label="t('attendance_setting_page.late_policy')" color="primary" density="compact" inset hide-details /></VCol>
                      <VCol cols="6" md="4"><VSwitch v-model="settingsForm.overtime_enabled" :label="t('attendance_setting_page.overtime_tracking')" color="primary" density="compact" inset hide-details /></VCol>
                      <VCol cols="6" md="4"><VSwitch v-model="settingsForm.require_shift_assignment" :label="t('attendance_setting_page.require_shift')" color="primary" density="compact" inset hide-details /></VCol>
                      <VCol cols="6" md="4"><VSwitch v-model="settingsForm.allow_manual_entry" :label="t('attendance_setting_page.allow_manual_entry')" color="primary" density="compact" inset hide-details /></VCol>
                      <VCol cols="6" md="4"><VSwitch v-model="settingsForm.manager_approval_required" :label="t('attendance_setting_page.manager_approval_required')" color="primary" density="compact" inset hide-details /></VCol>
                      <VCol cols="6" md="4"><VSwitch v-model="settingsForm.auto_clock_out_enabled" :label="t('attendance_setting_page.auto_clock_out')" color="primary" density="compact" inset hide-details /></VCol>
                      <VCol cols="12" md="4" v-if="settingsForm.auto_clock_out_enabled"><VTextField v-model="settingsForm.auto_clock_out_time" :label="t('attendance_setting_page.auto_clock_out_time')" type="time" density="compact" /></VCol>
                    </VRow>
                  </VCol>

                  <VCol cols="12" class="d-flex justify-end mt-4">
                    <VBtn color="primary" :loading="settingsForm.processing" @click="saveSettings"><i class="fas fa-save me-2"></i>{{ t('attendance_setting_page.save_settings') }}</VBtn>
                  </VCol>
                </VRow>
              </VCardText>
            </VWindowItem>

            <VWindowItem value="ers">
              <VCardText class="py-5">
                <VRow>
                  <VCol cols="12">
                    <div class="d-flex align-center gap-3 mb-4">
                      <VSwitch v-model="ersForm.ers_enabled" :label="t('attendance_setting_page.enable_ers')" color="primary" density="compact" inset hide-details />
                      <VChip v-if="ersForm.ers_enabled" color="success" size="small" variant="tonal"><i class="fas fa-circle me-1" style="font-size:8px"></i>{{ t('attendance_setting_page.active') }}</VChip>
                    </div>
                  </VCol>
                  <VCol cols="12" md="8"><VTextField v-model="ersForm.ers_token" :label="t('attendance_setting_page.ers_token')" density="compact" type="password" :hint="t('attendance_setting_page.ers_token_hint')" persistent-hint :disabled="!ersForm.ers_enabled" /></VCol>
                  <VCol cols="12" md="4" class="d-flex align-end"><VBtn variant="tonal" color="info" :disabled="!ersForm.ers_enabled || !ersForm.ers_token" @click="testErs"><i class="fas fa-plug me-2"></i>{{ t('attendance_setting_page.test_connection') }}</VBtn></VCol>
                  <VCol cols="12"><VTextField v-model="ersForm.ers_api_url" :label="t('attendance_setting_page.ers_api_url')" density="compact" :hint="t('attendance_setting_page.ers_api_url_hint')" persistent-hint :disabled="!ersForm.ers_enabled" /></VCol>
                  <VCol cols="6"><VTextField v-model="ersForm.ers_clock_start_time" :label="t('attendance_setting_page.clock_window_start')" type="time" density="compact" :hint="t('attendance_setting_page.clock_window_start_hint')" persistent-hint :disabled="!ersForm.ers_enabled" /></VCol>
                  <VCol cols="6"><VTextField v-model="ersForm.ers_clock_end_time" :label="t('attendance_setting_page.clock_window_end')" type="time" density="compact" :hint="t('attendance_setting_page.clock_window_end_hint')" persistent-hint :disabled="!ersForm.ers_enabled" /></VCol>

                  <VCol cols="12">
                    <VDivider class="my-4" />
                    <p class="text-subtitle-1 font-weight-medium mb-1">{{ t('attendance_setting_page.absence_detection') }}</p>
                    <p class="text-caption text-medium-emphasis mb-4">{{ t('attendance_setting_page.absence_detection_sub') }}</p>
                  </VCol>

                  <VCol cols="12" md="6">
                    <VSwitch v-model="ersForm.absent_email_enabled" :label="t('attendance_setting_page.send_absence_email')" color="primary" density="compact" inset hide-details class="mb-3" />
                    <VSwitch v-model="ersForm.auto_apply_leave_enabled" :label="t('attendance_setting_page.auto_apply_leave')" color="warning" density="compact" inset hide-details class="mb-3" />
                    <VSwitch v-model="ersForm.auto_deduct_leave" :label="t('attendance_setting_page.auto_deduct_leave')" color="error" density="compact" inset hide-details class="mb-3" />
                    <VSwitch v-model="ersForm.allow_unpaid_when_annual_done" :label="t('attendance_setting_page.allow_unpaid_when_exhausted')" color="info" density="compact" inset hide-details />
                  </VCol>

                  <VCol cols="12" md="6">
                    <VTextField v-model.number="ersForm.auto_apply_days_threshold" :label="t('attendance_setting_page.days_before_auto_apply')" type="number" density="compact" :hint="t('attendance_setting_page.days_before_auto_apply_hint')" persistent-hint :disabled="!ersForm.auto_apply_leave_enabled" />
                    <VAlert type="warning" variant="tonal" density="compact" class="mt-4" style="font-size:0.82rem" v-if="ersForm.auto_deduct_leave"><i class="fas fa-exclamation-triangle me-2"></i>{{ t('attendance_setting_page.auto_deduct_warning') }}</VAlert>
                    <VAlert type="info" variant="tonal" density="compact" class="mt-4" style="font-size:0.82rem" v-else-if="ersForm.auto_apply_leave_enabled"><i class="fas fa-info-circle me-2"></i>{{ t('attendance_setting_page.auto_apply_info', { days: ersForm.auto_apply_days_threshold }) }}</VAlert>
                  </VCol>

                  <VCol cols="12" class="d-flex justify-end mt-4"><VBtn color="primary" :loading="ersForm.processing" @click="saveErs"><i class="fas fa-save me-2"></i>{{ t('attendance_setting_page.save_ers_settings') }}</VBtn></VCol>
                </VRow>
              </VCardText>
            </VWindowItem>

            <VWindowItem value="notifications">
              <VCardText class="py-5">
                <VTable hover density="comfortable">
                  <thead>
                    <tr>
                      <th>{{ t('attendance_setting_page.event_col') }}</th>
                      <th>{{ t('attendance_setting_page.enabled_col') }}</th>
                      <th>{{ t('attendance_setting_page.notify_employee_col') }}</th>
                      <th>{{ t('attendance_setting_page.notify_manager_col') }}</th>
                      <th>{{ t('attendance_setting_page.notify_hr_col') }}</th>
                      <th>{{ t('attendance_setting_page.channel_col') }}</th>
                      <th>{{ t('attendance_setting_page.delay_min_col') }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="n in notifForm.notifications" :key="n.event">
                      <td class="font-weight-medium">{{ eventLabels[n.event] ?? n.event }}</td>
                      <td><VSwitch v-model="n.enabled" color="primary" density="compact" inset hide-details /></td>
                      <td><VCheckbox v-model="n.notify_employee" :disabled="!n.enabled" density="compact" hide-details /></td>
                      <td><VCheckbox v-model="n.notify_manager" :disabled="!n.enabled" density="compact" hide-details /></td>
                      <td><VCheckbox v-model="n.notify_hr" :disabled="!n.enabled" density="compact" hide-details /></td>
                      <td><VSelect v-model="n.channel" :items="['email','sms','both'].map(v => ({ title: v, value: v }))" density="compact" hide-details style="min-width:90px" :disabled="!n.enabled" /></td>
                      <td><VTextField v-model.number="n.delay_minutes" type="number" density="compact" hide-details style="max-width:80px" :disabled="!n.enabled" /></td>
                    </tr>
                  </tbody>
                </VTable>
                <div class="d-flex justify-end mt-5"><VBtn color="primary" :loading="notifForm.processing" @click="saveNotifications"><i class="fas fa-save me-2"></i>{{ t('attendance_setting_page.save_notification_settings') }}</VBtn></div>
              </VCardText>
            </VWindowItem>

          </VWindow>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>
