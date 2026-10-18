<script lang="ts" setup>
// Per-action-type config fields, shared by the canvas node editor. Edits the
// passed data object in place (it holds `action` + the action's fields).
import { useI18n } from 'vue-i18n'

defineOptions({ name: 'ActionFields' })

const { t } = useI18n()

const props = defineProps<{
  modelValue: Record<string, any>
  actionTypes: Record<string, string>
  aiActions: Record<string, string>
  mergeTagHint: string
}>()

const d = props.modelValue

function onAction() {
  if (d.action === 'notify_user' && !d.assign_to) d.assign_to = 'owner'
  if (d.action === 'ai_action' && !d.ai) d.ai = Object.keys(props.aiActions)[0]
  if (d.action === 'http_request' && !d.method) d.method = 'POST'
}
</script>

<template>
  <VSelect v-model="d.action" :label="t('crm_action_fields.action')" density="compact" class="mb-3"
    :items="Object.entries(actionTypes).map(([v, item]) => ({ title: item, value: v }))" @update:model-value="onAction" />

  <VRow>
    <template v-if="d.action === 'create_task' || d.action === 'escalate'">
      <VCol cols="8"><VTextField v-model="d.subject" :label="t('crm_action_fields.task_subject')" density="compact" /></VCol>
      <VCol cols="4"><VTextField v-model.number="d.due_in_days" type="number" :label="t('crm_action_fields.due_in_days')" density="compact" /></VCol>
    </template>
    <template v-else-if="d.action === 'add_note'">
      <VCol cols="12"><VTextField v-model="d.body" :label="t('crm_action_fields.note_text')" density="compact" /></VCol>
    </template>
    <template v-else-if="d.action === 'update_field'">
      <VCol cols="6"><VTextField v-model="d.field" :label="t('crm_action_fields.field')" density="compact" /></VCol>
      <VCol cols="6"><VTextField v-model="d.value" :label="t('crm_action_fields.new_value')" density="compact" /></VCol>
    </template>
    <template v-else-if="d.action === 'assign_owner'">
      <VCol cols="12"><VTextField v-model.number="d.user_id" type="number" :label="t('crm_action_fields.assign_to_user_id')" density="compact" /></VCol>
    </template>
    <template v-else-if="d.action === 'send_email'">
      <VCol cols="12"><VTextField v-model="d.subject" :label="t('crm_action_fields.email_subject')" density="compact" /></VCol>
      <VCol cols="12"><VTextarea v-model="d.body" :label="t('crm_action_fields.email_body')" rows="3" density="compact" /></VCol>
      <VCol cols="12"><span class="text-caption text-medium-emphasis">{{ t('crm_action_fields.email_hint') }} {{ mergeTagHint }} {{ t('crm_action_fields.email_provider_hint') }}</span></VCol>
    </template>
    <template v-else-if="d.action === 'send_whatsapp'">
      <VCol cols="12"><VTextarea v-model="d.body" :label="t('crm_action_fields.whatsapp_message')" rows="3" density="compact" /></VCol>
      <VCol cols="12"><span class="text-caption text-medium-emphasis">{{ t('crm_action_fields.whatsapp_hint') }}</span></VCol>
    </template>
    <template v-else-if="d.action === 'notify_user'">
      <VCol cols="6"><VSelect v-model="d.assign_to" :label="t('crm_action_fields.notify')" density="compact"
        :items="[{ title: t('crm_action_fields.record_owner'), value: 'owner' }, { title: t('crm_action_fields.specific_user'), value: 'user' }]" /></VCol>
      <VCol v-if="d.assign_to === 'user'" cols="6"><VTextField v-model.number="d.user_id" type="number" :label="t('crm_action_fields.user_id')" density="compact" /></VCol>
      <VCol cols="12"><VTextField v-model="d.title" :label="t('crm_action_fields.notification_title')" density="compact" /></VCol>
      <VCol cols="12"><VTextField v-model="d.body" :label="t('crm_action_fields.message_optional')" density="compact" /></VCol>
    </template>
    <template v-else-if="d.action === 'ai_action'">
      <VCol cols="12"><VSelect v-model="d.ai" :label="t('crm_action_fields.ai_action')" density="compact"
        :items="Object.entries(aiActions).map(([v, item]) => ({ title: item, value: v }))" /></VCol>
      <VCol cols="12"><span class="text-caption text-medium-emphasis">{{ t('crm_action_fields.ai_action_hint') }}</span></VCol>
    </template>
    <template v-else-if="d.action === 'create_deal'">
      <VCol cols="8"><VTextField v-model="d.title" :label="t('crm_action_fields.deal_title')" density="compact" /></VCol>
      <VCol cols="4"><VTextField v-model.number="d.amount" type="number" :label="t('crm_action_fields.amount')" density="compact" /></VCol>
      <VCol cols="12"><span class="text-caption text-medium-emphasis">{{ t('crm_action_fields.create_deal_hint') }}</span></VCol>
    </template>
    <template v-else-if="d.action === 'http_request'">
      <VCol cols="8"><VTextField v-model="d.url" :label="t('crm_action_fields.url_https')" placeholder="https://…" density="compact" /></VCol>
      <VCol cols="4"><VSelect v-model="d.method" :label="t('crm_action_fields.method')" :items="['POST', 'GET']" density="compact" /></VCol>
      <VCol cols="12"><span class="text-caption text-medium-emphasis">{{ t('crm_action_fields.http_request_hint') }}</span></VCol>
    </template>
  </VRow>
</template>
