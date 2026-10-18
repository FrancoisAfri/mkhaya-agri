<script setup lang="ts">
import { reactive, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Step = { id: number; type: string; subject_id: number; ref: string; level: number; name: string; value: number; due_at: string; overdue: boolean; escalated: boolean; levels: Array<{ level: number; name: string; status: string }> }
type Level = { name: string; permission: string; min_value: number }
type Policy = { type: string; label: string; default_permission: string; policy: { levels: Level[]; due_hours: number; escalate_to_permissions: string[]; is_active: boolean } | null }
const props = defineProps<{
  inbox: Step[]
  policies: Policy[]
  delegations: Array<{ id: number; from: string; to: string; types: string[] | null; starts_at: string; ends_at: string; reason: string; mine: boolean }>
  subjects: Array<{ value: string; title: string }>
  permissions: string[]
  people: Array<{ id: number; name: string }>
  me: number
  can: { manage: boolean }
}>()

const opts = { preserveScroll: true }
const notes = reactive<Record<number, string>>({})
const decide = (s: Step, decision: string) => router.post(route('warehouse.approvals.decide', s.id), { decision, note: notes[s.id] ?? null }, opts)
const when = (iso: string) => new Date(iso).toLocaleString()

const editing = ref<string | null>(null)
const policy = useForm({ levels: [] as Level[], due_hours: 24, escalate_to_permissions: [] as string[], is_active: true })
function edit(p: Policy) {
  editing.value = p.type
  policy.defaults({ levels: (p.policy?.levels ?? [{ name: 'Approver', permission: p.default_permission, min_value: 0 }]).map(l => ({ ...l })),
    due_hours: p.policy?.due_hours ?? 24, escalate_to_permissions: [...(p.policy?.escalate_to_permissions ?? [])], is_active: p.policy?.is_active ?? true })
  policy.reset()
}
const savePolicy = () => policy.post(route('warehouse.approvals.policy', editing.value as string), { ...opts, onSuccess: () => { if (!Object.keys(policy.errors).length) editing.value = null } })

const delegating = ref(false)
const delegation = useForm({ from_user_id: props.me as number | null, to_user_id: null as number | null, types: [] as string[], starts_at: '', ends_at: '', reason: '' })
const saveDelegation = () => delegation.transform(d => ({ ...d, types: d.types.length ? d.types : null })).post(route('warehouse.approvals.delegate'), { ...opts, onSuccess: () => { delegating.value = false; delegation.reset() } })
const revoke = (id: number) => router.post(route('warehouse.approvals.revoke', id), {}, opts)
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('warehouse_management.approvals.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.approvals.subtitle') }}</p>
    </div>

    <VCard border class="mb-4" :title="t('warehouse_management.approvals.waiting')">
      <VCardText v-if="!inbox.length" class="text-medium-emphasis">{{ t('warehouse_management.approvals.nothing') }}</VCardText>
      <VList v-else lines="three">
        <VListItem v-for="s in inbox" :key="s.id">
          <VListItemTitle>{{ subjects.find(x => x.value === s.type)?.title }} {{ s.ref }} · {{ s.name }}
            <VChip v-if="s.overdue" size="x-small" color="error" class="ms-1">{{ t('warehouse_management.approvals.overdue') }}</VChip>
            <VChip v-if="s.escalated" size="x-small" color="warning" class="ms-1">{{ t('warehouse_management.alerts.escalated') }}</VChip></VListItemTitle>
          <VListItemSubtitle>{{ t('warehouse_management.approvals.value', { v: s.value.toFixed(2) }) }} · {{ t('warehouse_management.approvals.due', { at: when(s.due_at) }) }}
            · <span v-for="l in s.levels" :key="l.level" class="me-2">{{ l.level }}. {{ l.name }} ({{ t(`warehouse_management.approvals.status.${l.status}`) }})</span></VListItemSubtitle>
          <div class="d-flex gap-2 mt-2 align-center flex-wrap">
            <VTextField v-model="notes[s.id]" density="compact" hide-details style="max-width: 320px" :label="t('warehouse_management.adjustments.note')" />
            <VBtn size="small" color="primary" @click="decide(s, 'approve')">{{ t('warehouse_management.approvals.approve') }}</VBtn>
            <VBtn size="small" variant="tonal" color="error" :disabled="!notes[s.id]" @click="decide(s, 'reject')">{{ t('warehouse_management.approvals.reject') }}</VBtn>
          </div>
        </VListItem>
      </VList>
    </VCard>

    <VRow>
      <VCol cols="12" md="6">
        <VCard border :title="t('warehouse_management.approvals.policies')">
          <VList density="compact">
            <VListItem v-for="p in policies" :key="p.type" :title="p.label"
                       :subtitle="p.policy ? p.policy.levels.map((l, i) => `${i + 1}. ${l.name} (${l.permission}) ≥ ${l.min_value}`).join(' → ') : t('warehouse_management.approvals.single', { p: p.default_permission })">
              <template #append><VBtn v-if="can.manage" size="x-small" variant="text" @click="edit(p)">{{ t('warehouse_management.common.edit') }}</VBtn></template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard border>
          <VCardItem>
            <VCardTitle>{{ t('warehouse_management.approvals.delegations') }}</VCardTitle>
            <template #append><VBtn size="small" variant="tonal" @click="delegating = true">{{ t('warehouse_management.approvals.delegate') }}</VBtn></template>
          </VCardItem>
          <VCardText v-if="!delegations.length" class="text-medium-emphasis">{{ t('warehouse_management.approvals.no_delegations') }}</VCardText>
          <VList v-else density="compact">
            <VListItem v-for="d in delegations" :key="d.id" :title="`${d.from} → ${d.to}`" :subtitle="`${when(d.starts_at)} – ${when(d.ends_at)} · ${d.types ? d.types.join(', ') : t('warehouse_management.approvals.all_kinds')} · ${d.reason}`">
              <template #append><VBtn v-if="d.mine || can.manage" size="x-small" variant="text" color="error" @click="revoke(d.id)">{{ t('warehouse_management.approvals.end') }}</VBtn></template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <VDialog :model-value="!!editing" max-width="680" @update:model-value="v => { if (!v) editing = null }">
      <VCard>
        <VCardTitle>{{ policies.find(p => p.type === editing)?.label }}</VCardTitle>
        <VCardText>
          <VAlert v-if="(policy.errors as any).refused" type="error" variant="tonal" class="mb-3">{{ (policy.errors as any).refused }}</VAlert>
          <p class="text-caption">{{ t('warehouse_management.approvals.levels_hint') }}</p>
          <VRow v-for="(l, i) in policy.levels" :key="i" dense>
            <VCol cols="4"><VTextField v-model="l.name" density="compact" :label="t('warehouse_management.approvals.level_name', { n: i + 1 })" /></VCol>
            <VCol cols="4"><VSelect v-model="l.permission" :items="permissions" density="compact" :label="t('warehouse_management.approvals.permission')" /></VCol>
            <VCol cols="3"><VTextField v-model.number="l.min_value" type="number" min="0" density="compact" :label="t('warehouse_management.approvals.from_value')" /></VCol>
            <VCol cols="1"><VBtn v-if="policy.levels.length > 1" icon="mdi-close" size="small" variant="text" :aria-label="t('warehouse_management.common.remove')" @click="policy.levels.splice(i, 1)" /></VCol>
          </VRow>
          <VBtn v-if="policy.levels.length < 5" size="small" variant="tonal" class="mb-3" @click="policy.levels.push({ name: '', permission: permissions[0], min_value: policy.levels[policy.levels.length - 1]?.min_value ?? 0 })">{{ t('warehouse_management.approvals.add_level') }}</VBtn>
          <VTextField v-model.number="policy.due_hours" type="number" min="1" :label="t('warehouse_management.approvals.due_hours')" />
          <VSelect v-model="policy.escalate_to_permissions" :items="permissions" multiple chips :label="t('warehouse_management.alerts.escalate_to')" />
          <VSwitch v-model="policy.is_active" color="primary" hide-details :label="t('warehouse_management.alerts.active')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="editing = null">{{ t('warehouse_management.common.cancel') }}</VBtn><VBtn color="primary" :loading="policy.processing" @click="savePolicy">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="delegating" max-width="520">
      <VCard>
        <VCardTitle>{{ t('warehouse_management.approvals.delegate') }}</VCardTitle>
        <VCardText>
          <VAlert v-if="(delegation.errors as any).refused" type="error" variant="tonal" class="mb-3">{{ (delegation.errors as any).refused }}</VAlert>
          <VAutocomplete v-if="can.manage" v-model="delegation.from_user_id" :items="people" item-title="name" item-value="id" :label="t('warehouse_management.approvals.whose')" />
          <VAutocomplete v-model="delegation.to_user_id" :items="people.filter(p => p.id !== delegation.from_user_id)" item-title="name" item-value="id" :label="t('warehouse_management.approvals.to_whom')" />
          <VSelect v-model="delegation.types" :items="subjects" multiple chips :label="t('warehouse_management.approvals.kinds')" :hint="t('warehouse_management.approvals.kinds_hint')" persistent-hint />
          <VRow dense class="mt-1">
            <VCol cols="6"><VTextField v-model="delegation.starts_at" type="datetime-local" :label="t('warehouse_management.approvals.from')" /></VCol>
            <VCol cols="6"><VTextField v-model="delegation.ends_at" type="datetime-local" :label="t('warehouse_management.approvals.until')" :error-messages="delegation.errors.ends_at" /></VCol>
          </VRow>
          <VTextField v-model="delegation.reason" :label="t('warehouse_management.approvals.reason')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="delegating = false">{{ t('warehouse_management.common.cancel') }}</VBtn><VBtn color="primary" :loading="delegation.processing" :disabled="!delegation.to_user_id" @click="saveDelegation">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
