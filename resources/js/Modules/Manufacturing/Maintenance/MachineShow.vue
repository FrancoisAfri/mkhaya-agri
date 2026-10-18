<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Rule = { id: string; title: string; kind: string; interval_days: number | null; interval_run_hours: number | null; breakdowns_threshold: number | null
  window_days: number | null; is_active: boolean; last_triggered_at: string | null; progress: Record<string, any> | null; due: string | null
  raised: Array<{ number: string | null; id: string | null; status: string | null; trigger: string }> }

const props = defineProps<{
  machine: { id: string; name: string; centres: Array<{ id: number; code: string; name: string }>; hold_status: string; hold_reason: string | null
    held_at: string | null; requires_return_to_service: boolean; released_at: string | null; release_notes: string | null; run_hours_baseline: number
    asset_url: string; maintenance_cost: number; work_orders: number; open_work_orders: number; downtime_minutes: number; breakdowns: number; run_hours: number }
  rules: Rule[]
  breakdowns: Array<{ id: string; reason: string | null; fault: string | null; started_at: string | null; minutes: number; is_open: boolean; notes: string | null
    work_order: { id: string; number: string; status: string } | null }>
  work_orders: Array<{ id: string; number: string; title: string; type: string; status: string; scheduled_for: string | null; total_cost: number }>
  fault_codes: Array<{ id: number; name: string }>
  people: Array<{ id: number; name: string }>
  can: { manage: boolean; release: boolean; report: boolean }
}>()

const busy = ref(false)
const post = (name: string, data: Record<string, unknown>, done?: () => void) => {
  busy.value = true
  router.post(route(`manufacturing.maintenance.${name}`, props.machine.id), data, { preserveScroll: true, onSuccess: () => done?.(), onFinish: () => { busy.value = false } })
}

const holdDialog = ref(false)
const holdForm = ref({ kind: 'MAINTENANCE', reason: '' })
const releaseDialog = ref(false)
const releaseNotes = ref('')
const breakdownDialog = ref(false)
const breakdown = ref({ work_centre_id: props.machine.centres[0]?.id ?? null, fault_code_id: null as number | null, notes: '', hold: true })

const ruleDialog = ref(false)
const rule = useForm({ title: '', kind: 'TIME', interval_days: 90 as number | null, interval_run_hours: null as number | null, breakdowns_threshold: null as number | null,
  window_days: 30 as number | null, tasks: '', work_order_type: 'preventive', priority: 'medium', assign_to: null as number | null })
const saveRule = () => rule.post(route('manufacturing.maintenance.rules.store', props.machine.id), { preserveScroll: true, onSuccess: () => { ruleDialog.value = false; rule.reset() } })
const toggle = (r: Rule) => router.post(route('manufacturing.maintenance.rules.toggle', r.id), {}, { preserveScroll: true })
const when = (s: string | null) => s ? new Date(s).toLocaleString() : '—'
const holdColor = (s: string) => ({ NONE: 'success', MAINTENANCE: 'warning', SAFETY: 'error' } as Record<string, string>)[s] ?? 'default'
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-start justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <VBtn variant="text" size="small" prepend-icon="tabler-arrow-left" class="mb-1 px-0" :href="route('manufacturing.maintenance.machines.index')">{{ t('manufacturing.maint.machines_title') }}</VBtn>
        <h4 class="text-h4">{{ machine.name }}</h4>
        <div class="text-body-2 text-medium-emphasis">{{ machine.centres.map(c => c.code).join(', ') }}</div>
        <VChip class="mt-2" size="small" label :color="holdColor(machine.hold_status)">{{ t(`manufacturing.maint.hold.${machine.hold_status}`) }}</VChip>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VBtn v-if="can.report" color="error" variant="tonal" prepend-icon="tabler-alert-triangle" @click="breakdownDialog = true">{{ t('manufacturing.maint.report_breakdown') }}</VBtn>
        <VBtn v-if="can.manage && machine.hold_status === 'NONE'" color="warning" variant="tonal" @click="holdDialog = true">{{ t('manufacturing.maint.put_on_hold') }}</VBtn>
        <VBtn v-if="can.release && machine.hold_status !== 'NONE'" color="success" @click="releaseDialog = true">{{ t('manufacturing.maint.return_to_service') }}</VBtn>
        <VBtn variant="text" :href="machine.asset_url">{{ t('manufacturing.maint.asset_work_orders') }}</VBtn>
      </div>
    </div>

    <VAlert v-if="machine.hold_status !== 'NONE'" type="warning" variant="tonal" class="mb-4">{{ t('manufacturing.maint.held_since', { at: when(machine.held_at), reason: machine.hold_reason }) }}</VAlert>

    <VRow class="mb-1">
      <VCol v-for="k in ['run_hours', 'breakdowns', 'downtime_minutes', 'open_work_orders', 'maintenance_cost']" :key="k" cols="6" md="2">
        <VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t(`manufacturing.maint.stat.${k}`) }}</div><div class="text-h5">{{ (machine as any)[k] }}</div></VCardText></VCard>
      </VCol>
    </VRow>

    <VCard border class="mb-4">
      <VCardItem>
        <VCardTitle>{{ t('manufacturing.maint.rules') }}</VCardTitle>
        <VCardSubtitle>{{ t('manufacturing.maint.rules_hint') }}</VCardSubtitle>
        <template #append><VBtn v-if="can.manage" size="small" color="primary" prepend-icon="tabler-plus" @click="ruleDialog = true">{{ t('manufacturing.common.add') }}</VBtn></template>
      </VCardItem>
      <VList>
        <VListItem v-for="r in rules" :key="r.id" :class="{ 'text-disabled': !r.is_active }">
          <VListItemTitle class="d-flex gap-2 align-center flex-wrap">{{ r.title }}
            <VChip size="x-small" label>{{ t(`manufacturing.maint.kind.${r.kind}`) }}</VChip>
            <VChip v-if="r.due" size="x-small" label color="warning">{{ t('manufacturing.maint.due', { why: r.due }) }}</VChip>
          </VListItemTitle>
          <VListItemSubtitle>
            <template v-if="r.kind === 'TIME'">{{ t('manufacturing.maint.every_days', { n: r.interval_days }) }} · {{ t('manufacturing.maint.next', { at: r.progress?.next }) }}</template>
            <template v-else-if="r.kind === 'USAGE'">{{ t('manufacturing.maint.every_hours', { n: r.interval_run_hours }) }} · {{ t('manufacturing.maint.used', { used: r.progress?.used, of: r.progress?.of }) }}</template>
            <template v-else>{{ t('manufacturing.maint.condition', { n: r.breakdowns_threshold, days: r.window_days }) }} · {{ t('manufacturing.maint.so_far', { n: r.progress?.breakdowns }) }}</template>
            <div v-for="w in r.raised" :key="w.number ?? ''" class="text-caption"><a v-if="w.id" :href="route('assets.work-orders.show', w.id)">{{ w.number }}</a> · {{ w.status }} · {{ w.trigger }}</div>
          </VListItemSubtitle>
          <template v-if="can.manage" #append><VBtn size="small" variant="text" @click="toggle(r)">{{ r.is_active ? t('manufacturing.maint.pause') : t('manufacturing.floor.resume') }}</VBtn></template>
        </VListItem>
        <VListItem v-if="!rules.length" :subtitle="t('manufacturing.maint.no_rules')" />
      </VList>
    </VCard>

    <VRow>
      <VCol cols="12" md="6">
        <VCard border>
          <VCardItem><VCardTitle>{{ t('manufacturing.maint.breakdowns') }}</VCardTitle></VCardItem>
          <VList density="compact">
            <VListItem v-for="b in breakdowns" :key="b.id" :title="b.fault ?? b.reason ?? '—'" :subtitle="`${when(b.started_at)} · ${b.minutes} min${b.is_open ? ' · ' + t('manufacturing.floor.ongoing') : ''}`">
              <template v-if="b.work_order" #append><a :href="route('assets.work-orders.show', b.work_order.id)">{{ b.work_order.number }}</a></template>
            </VListItem>
            <VListItem v-if="!breakdowns.length" :subtitle="t('manufacturing.maint.no_breakdowns')" />
          </VList>
        </VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard border>
          <VCardItem><VCardTitle>{{ t('manufacturing.maint.work_orders') }}</VCardTitle></VCardItem>
          <VList density="compact">
            <VListItem v-for="w in work_orders" :key="w.id" :href="route('assets.work-orders.show', w.id)" :title="`${w.number} · ${w.title}`"
                       :subtitle="`${w.type} · ${w.status} · ${w.scheduled_for ?? '—'} · ${w.total_cost.toFixed(2)}`" />
            <VListItem v-if="!work_orders.length" :subtitle="t('manufacturing.maint.no_work_orders')" />
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="holdDialog" max-width="480">
      <VCard :title="t('manufacturing.maint.put_on_hold')" :subtitle="t('manufacturing.maint.hold_hint')">
        <VCardText>
          <VRadioGroup v-model="holdForm.kind" inline><VRadio :label="t('manufacturing.maint.hold.MAINTENANCE')" value="MAINTENANCE" /><VRadio :label="t('manufacturing.maint.hold.SAFETY')" value="SAFETY" /></VRadioGroup>
          <VTextarea v-model="holdForm.reason" rows="2" auto-grow :label="t('manufacturing.orders.reason')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="holdDialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="warning" :loading="busy" :disabled="!holdForm.reason.trim()" @click="post('machines.hold', holdForm, () => { holdDialog = false })">{{ t('manufacturing.maint.put_on_hold') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="releaseDialog" max-width="480">
      <VCard :title="t('manufacturing.maint.return_to_service')" :subtitle="t('manufacturing.maint.release_hint')">
        <VCardText><VTextarea v-model="releaseNotes" rows="3" auto-grow :label="t('manufacturing.maint.release_check')" /></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="releaseDialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="success" :loading="busy" :disabled="machine.requires_return_to_service && !releaseNotes.trim()" @click="post('machines.release', { notes: releaseNotes }, () => { releaseDialog = false })">{{ t('manufacturing.common.confirm') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="breakdownDialog" max-width="520">
      <VCard :title="t('manufacturing.maint.report_breakdown')" :subtitle="t('manufacturing.maint.breakdown_hint')">
        <VCardText>
          <VSelect v-model="breakdown.work_centre_id" :items="machine.centres" item-value="id" :item-title="(c: any) => `${c.code} — ${c.name}`" :label="t('manufacturing.floor.work_centre')" class="mb-2" />
          <VSelect v-model="breakdown.fault_code_id" :items="fault_codes" item-value="id" item-title="name" clearable :label="t('manufacturing.maint.fault_code')" class="mb-2" />
          <VTextarea v-model="breakdown.notes" rows="2" auto-grow :label="t('manufacturing.floor.what_is_wrong')" />
          <VCheckbox v-model="breakdown.hold" density="compact" hide-details :label="t('manufacturing.maint.hold_until_repaired')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="breakdownDialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="error" :loading="busy" @click="post('machines.breakdown', breakdown, () => { breakdownDialog = false })">{{ t('manufacturing.floor.send') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="ruleDialog" max-width="560">
      <VCard :title="t('manufacturing.maint.new_rule')">
        <VCardText>
          <VRow dense>
            <VCol cols="12"><VTextField v-model="rule.title" :error-messages="rule.errors.title" :label="t('manufacturing.maint.rule_title')" /></VCol>
            <VCol cols="12"><VBtnToggle v-model="rule.kind" mandatory divided variant="outlined"><VBtn v-for="k in ['TIME', 'USAGE', 'CONDITION']" :key="k" :value="k">{{ t(`manufacturing.maint.kind.${k}`) }}</VBtn></VBtnToggle></VCol>
            <VCol v-if="rule.kind === 'TIME'" cols="6"><VTextField v-model.number="rule.interval_days" type="number" min="1" :error-messages="rule.errors.interval_days" :label="t('manufacturing.maint.interval_days')" /></VCol>
            <VCol v-if="rule.kind === 'USAGE'" cols="6"><VTextField v-model.number="rule.interval_run_hours" type="number" min="0" step="any" :error-messages="rule.errors.interval_run_hours" :label="t('manufacturing.maint.interval_hours')" /></VCol>
            <template v-if="rule.kind === 'CONDITION'">
              <VCol cols="6"><VTextField v-model.number="rule.breakdowns_threshold" type="number" min="1" :error-messages="rule.errors.breakdowns_threshold" :label="t('manufacturing.maint.threshold')" /></VCol>
              <VCol cols="6"><VTextField v-model.number="rule.window_days" type="number" min="1" :label="t('manufacturing.maint.window_days')" /></VCol>
            </template>
            <VCol cols="6"><VSelect v-model="rule.work_order_type" :items="['preventive', 'inspection']" :label="t('manufacturing.maint.wo_type')" /></VCol>
            <VCol cols="6"><VSelect v-model="rule.priority" :items="['low', 'medium', 'high', 'critical']" :label="t('manufacturing.orders.priority')" /></VCol>
            <VCol cols="12"><VAutocomplete v-model="rule.assign_to" :items="people" item-value="id" item-title="name" clearable :label="t('manufacturing.maint.assign_to')" /></VCol>
            <VCol cols="12"><VTextarea v-model="rule.tasks" rows="4" auto-grow :label="t('manufacturing.maint.tasks')" :hint="t('manufacturing.maint.tasks_hint')" persistent-hint /></VCol>
          </VRow>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="ruleDialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="rule.processing" :disabled="!rule.title" @click="saveRule">{{ t('manufacturing.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
