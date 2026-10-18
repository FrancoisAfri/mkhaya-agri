<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type RuleT = { event: string; is_active: boolean; recipient_permissions: string[]; recipient_user_ids: number[]; notify_responsible: boolean; channels: string[]
  thresholds: Record<string, number>; suppress_hours: number; escalate_after_hours: number | null; escalate_to_permissions: string[] }
type Log = { id: number; event: string; title: string; recipient: string; reason: string; level: number; delivery: Record<string, string>; occurrences: number; error: string | null; sent_at: string | null; resolved_at: string | null }
const props = defineProps<{ rules: RuleT[]; log: Log[]; permissions: string[]; people: Array<{ id: number; name: string }>; can: { configure: boolean } }>()

const editing = ref<string | null>(null)
const form = useForm({ is_active: true, recipient_permissions: [] as string[], recipient_user_ids: [] as number[], notify_responsible: true, channels: ['database'] as string[],
  thresholds: {} as Record<string, number>, suppress_hours: 24, escalate_after_hours: null as number | null, escalate_to_permissions: [] as string[] })
function edit(r: RuleT) {
  editing.value = r.event
  form.defaults({ is_active: r.is_active, recipient_permissions: [...r.recipient_permissions], recipient_user_ids: [...r.recipient_user_ids], notify_responsible: r.notify_responsible,
    channels: [...r.channels], thresholds: { ...r.thresholds }, suppress_hours: r.suppress_hours, escalate_after_hours: r.escalate_after_hours, escalate_to_permissions: [...r.escalate_to_permissions] })
  form.reset()
}
const save = () => form.post(route('warehouse.alerts.rule', editing.value as string), { preserveScroll: true, onSuccess: () => { editing.value = null } })
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString() : '—')
const tone = (s: string) => ({ SENT: 'success', FAILED: 'error', PENDING: 'warning' } as Record<string, string>)[s]
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('warehouse_management.alerts.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.alerts.subtitle') }}</p>
    </div>

    <VCard border class="mb-4" :title="t('warehouse_management.alerts.rules')">
      <div style="overflow-x: auto">
        <VTable density="compact">
          <thead><tr><th>{{ t('warehouse_management.alerts.event') }}</th><th>{{ t('warehouse_management.alerts.who') }}</th><th>{{ t('warehouse_management.alerts.channels') }}</th><th>{{ t('warehouse_management.alerts.quiet') }}</th><th>{{ t('warehouse_management.alerts.escalate') }}</th><th /></tr></thead>
          <tbody>
            <tr v-for="r in rules" :key="r.event" :class="{ 'text-disabled': !r.is_active }">
              <td>{{ t(`warehouse_management.alerts.events.${r.event}`) }}</td>
              <td class="text-caption">{{ [...r.recipient_permissions, r.notify_responsible ? t('warehouse_management.alerts.responsible') : null, r.recipient_user_ids.length ? t('warehouse_management.alerts.named_n', { n: r.recipient_user_ids.length }) : null].filter(Boolean).join(', ') }}</td>
              <td>{{ r.channels.map(c => t(`warehouse_management.alerts.channel.${c}`)).join(', ') }}</td>
              <td>{{ t('warehouse_management.alerts.hours', { n: r.suppress_hours }) }}</td>
              <td>{{ r.escalate_after_hours ? t('warehouse_management.alerts.hours', { n: r.escalate_after_hours }) : '—' }}</td>
              <td><VBtn v-if="can.configure" size="x-small" variant="text" @click="edit(r)">{{ t('warehouse_management.common.edit') }}</VBtn></td>
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCard>

    <VCard border :title="t('warehouse_management.alerts.log')">
      <div style="overflow-x: auto">
        <VTable density="compact">
          <thead><tr><th>{{ t('warehouse_management.alerts.alert') }}</th><th>{{ t('warehouse_management.alerts.to') }}</th><th>{{ t('warehouse_management.alerts.delivery') }}</th><th class="text-end">{{ t('warehouse_management.alerts.times') }}</th><th>{{ t('warehouse_management.alerts.state') }}</th></tr></thead>
          <tbody>
            <tr v-if="!log.length"><td colspan="5" class="text-center text-medium-emphasis py-6">{{ t('warehouse_management.alerts.none') }}</td></tr>
            <tr v-for="l in log" :key="l.id">
              <td><VChip v-if="l.level" size="x-small" color="error" class="me-1">{{ t('warehouse_management.alerts.escalated') }}</VChip>{{ l.title }}<div class="text-caption">{{ t(`warehouse_management.alerts.events.${l.event}`) }} · {{ when(l.sent_at) }}</div></td>
              <td class="text-caption">{{ l.recipient }}<div>{{ t(`warehouse_management.alerts.reason.${l.reason}`) }}</div></td>
              <td><VChip v-for="(s, c) in l.delivery" :key="c" size="x-small" :color="tone(s)" class="me-1">{{ t(`warehouse_management.alerts.channel.${c}`) }}: {{ s }}</VChip><div v-if="l.error" class="text-caption text-error">{{ l.error }}</div></td>
              <td class="text-end">{{ l.occurrences }}</td>
              <td class="text-caption">{{ l.resolved_at ? t('warehouse_management.alerts.resolved', { at: when(l.resolved_at) }) : t('warehouse_management.alerts.open') }}</td>
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCard>

    <VDialog :model-value="!!editing" max-width="640" @update:model-value="v => { if (!v) editing = null }">
      <VCard v-if="editing">
        <VCardTitle>{{ t(`warehouse_management.alerts.events.${editing}`) }}</VCardTitle>
        <VCardText>
          <VSwitch v-model="form.is_active" color="primary" hide-details :label="t('warehouse_management.alerts.active')" />
          <VSelect v-model="form.recipient_permissions" :items="permissions" multiple chips :label="t('warehouse_management.alerts.by_permission')" />
          <VAutocomplete v-model="form.recipient_user_ids" :items="people" item-title="name" item-value="id" multiple chips :label="t('warehouse_management.alerts.named')" />
          <VSwitch v-model="form.notify_responsible" color="primary" hide-details :label="t('warehouse_management.alerts.tell_responsible')" />
          <VSelect v-model="form.channels" :items="[{ value: 'database', title: t('warehouse_management.alerts.channel.database') }, { value: 'mail', title: t('warehouse_management.alerts.channel.mail') }]" multiple chips :label="t('warehouse_management.alerts.channels')" />
          <VRow dense>
            <VCol v-for="(v, k) in form.thresholds" :key="k" cols="6"><VTextField v-model.number="form.thresholds[k]" type="number" min="0" :label="t(`warehouse_management.alerts.threshold.${k}`)" /></VCol>
            <VCol cols="6"><VTextField v-model.number="form.suppress_hours" type="number" min="1" :label="t('warehouse_management.alerts.quiet_hours')" :hint="t('warehouse_management.alerts.quiet_hint')" persistent-hint /></VCol>
            <VCol cols="6"><VTextField v-model.number="form.escalate_after_hours" type="number" min="1" clearable :label="t('warehouse_management.alerts.escalate_hours')" /></VCol>
          </VRow>
          <VSelect v-model="form.escalate_to_permissions" :items="permissions" multiple chips :label="t('warehouse_management.alerts.escalate_to')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="editing = null">{{ t('warehouse_management.common.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" @click="save">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
