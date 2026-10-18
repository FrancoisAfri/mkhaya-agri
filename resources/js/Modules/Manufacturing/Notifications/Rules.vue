<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type AlertRule = {
  id: number; event_type: string; meaning: string; is_active: boolean; factory_id: number | null
  recipient_permissions: string[]; recipient_roles: string[]; recipient_user_ids: number[]; notify_responsible: boolean
  channels: string[]; lead_hours: number; suppress_hours: number; escalate_after_hours: number | null; escalate_to_permissions: string[]
}
type Option = { id: number; name: string }

const props = defineProps<{
  rules: AlertRule[]
  options: { permissions: string[]; roles: string[]; people: Option[]; factories: Option[]; events: string[] }
  can: { manage: boolean }
}>()

const factoryName = (id: number | null) => id === null ? t('manufacturing.alerts.all_factories') : (props.options.factories.find(f => f.id === id)?.name ?? id)
const grouped = computed(() => props.options.events.map(e => ({ event: e, rules: props.rules.filter(r => r.event_type === e) })))
const dialog = ref(false)
const form = useForm({
  id: null as number | null, event_type: '', factory_id: null as number | null, is_active: true, recipient_permissions: [] as string[],
  recipient_roles: [] as string[], recipient_user_ids: [] as number[], notify_responsible: true, channels: ['database'] as string[],
  lead_hours: 24, suppress_hours: 24, escalate_after_hours: null as number | null, escalate_to_permissions: [] as string[],
})
const edit = (r: AlertRule) => {
  Object.assign(form, { ...r })
  dialog.value = true
}
const override = (r: AlertRule) => {
  Object.assign(form, { ...r, id: null, factory_id: props.options.factories[0]?.id ?? null })
  dialog.value = true
}
const save = () => form.post(route('manufacturing.notifications.rules.save'), { preserveScroll: true, onSuccess: () => { dialog.value = false } })
const remove = (r: AlertRule) => router.delete(route('manufacturing.notifications.rules.destroy', r.id), { preserveScroll: true })
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('manufacturing.alerts.rules_title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.alerts.rules_subtitle') }}</p>
    </div>

    <VCard v-for="g in grouped" :key="g.event" border class="mb-3">
      <VCardItem>
        <VCardTitle>{{ t(`manufacturing.alerts.event.${g.event}`) }}</VCardTitle>
        <VCardSubtitle>{{ g.rules[0]?.meaning }}</VCardSubtitle>
      </VCardItem>
      <VTable density="compact">
        <thead><tr>
          <th>{{ t('manufacturing.alerts.factory') }}</th><th>{{ t('manufacturing.alerts.recipients') }}</th><th>{{ t('manufacturing.alerts.channels') }}</th>
          <th>{{ t('manufacturing.alerts.timing') }}</th><th>{{ t('manufacturing.alerts.escalation') }}</th><th />
        </tr></thead>
        <tbody>
          <tr v-for="r in g.rules" :key="r.id" :class="{ 'text-disabled': !r.is_active }">
            <td>{{ factoryName(r.factory_id) }}<VChip v-if="!r.is_active" size="x-small" class="ms-1">{{ t('manufacturing.alerts.off') }}</VChip></td>
            <td>
              <div class="text-caption">{{ r.recipient_permissions.join(', ') || '—' }}</div>
              <div v-if="r.recipient_roles.length" class="text-caption">{{ t('manufacturing.alerts.roles') }}: {{ r.recipient_roles.join(', ') }}</div>
              <div v-if="r.recipient_user_ids.length" class="text-caption">{{ t('manufacturing.alerts.named', { n: r.recipient_user_ids.length }) }}</div>
              <div v-if="r.notify_responsible" class="text-caption">{{ t('manufacturing.alerts.plus_responsible') }}</div>
            </td>
            <td>{{ r.channels.map(c => t(`manufacturing.alerts.channel.${c}`)).join(', ') }}</td>
            <td class="text-caption">{{ t('manufacturing.alerts.timing_line', { lead: r.lead_hours, quiet: r.suppress_hours }) }}</td>
            <td class="text-caption">{{ r.escalate_after_hours ? t('manufacturing.alerts.escalate_line', { hours: r.escalate_after_hours, to: r.escalate_to_permissions.join(', ') }) : '—' }}</td>
            <td class="text-end text-no-wrap">
              <template v-if="can.manage">
                <VBtn size="small" variant="text" @click="edit(r)">{{ t('manufacturing.alerts.edit') }}</VBtn>
                <VBtn v-if="r.factory_id === null && options.factories.length" size="small" variant="text" @click="override(r)">{{ t('manufacturing.alerts.for_factory') }}</VBtn>
                <VBtn v-if="r.factory_id !== null" size="small" variant="text" color="error" @click="remove(r)">{{ t('manufacturing.alerts.remove') }}</VBtn>
              </template>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="720">
      <VCard>
        <VCardItem><VCardTitle>{{ form.event_type ? t(`manufacturing.alerts.event.${form.event_type}`) : '' }}</VCardTitle></VCardItem>
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6"><VSelect v-model="form.factory_id" :items="options.factories" item-title="name" item-value="id" :disabled="!!form.id" clearable
              :label="t('manufacturing.alerts.factory')" :hint="t('manufacturing.alerts.factory_hint')" persistent-hint /></VCol>
            <VCol cols="12" md="6"><VSwitch v-model="form.is_active" :label="t('manufacturing.alerts.active')" hide-details /></VCol>
            <VCol cols="12"><VAutocomplete v-model="form.recipient_permissions" :items="options.permissions" multiple chips closable-chips :label="t('manufacturing.alerts.permissions')" :error-messages="form.errors.recipient_permissions" /></VCol>
            <VCol cols="12" md="6"><VAutocomplete v-model="form.recipient_roles" :items="options.roles" multiple chips closable-chips :label="t('manufacturing.alerts.roles')" /></VCol>
            <VCol cols="12" md="6"><VAutocomplete v-model="form.recipient_user_ids" :items="options.people" item-title="name" item-value="id" multiple chips closable-chips :label="t('manufacturing.alerts.people')" /></VCol>
            <VCol cols="12" md="6"><VCheckbox v-model="form.notify_responsible" :label="t('manufacturing.alerts.responsible')" hide-details /></VCol>
            <VCol cols="12" md="6">
              <div class="d-flex gap-4">
                <VCheckbox v-model="form.channels" value="database" :label="t('manufacturing.alerts.channel.database')" hide-details />
                <VCheckbox v-model="form.channels" value="mail" :label="t('manufacturing.alerts.channel.mail')" hide-details />
              </div>
            </VCol>
            <VCol cols="12" md="4"><VTextField v-model.number="form.lead_hours" type="number" :label="t('manufacturing.alerts.lead_hours')" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model.number="form.suppress_hours" type="number" :label="t('manufacturing.alerts.suppress_hours')" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model.number="form.escalate_after_hours" type="number" clearable :label="t('manufacturing.alerts.escalate_after')" /></VCol>
            <VCol cols="12"><VAutocomplete v-model="form.escalate_to_permissions" :items="options.permissions" multiple chips closable-chips :label="t('manufacturing.alerts.escalate_to')" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('manufacturing.alerts.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="form.processing" @click="save">{{ t('manufacturing.alerts.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
