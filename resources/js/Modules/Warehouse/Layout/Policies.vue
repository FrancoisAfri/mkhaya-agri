<script setup lang="ts">
import { reactive } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Version = { id: number; version: number; status: string; changes: Record<string, { from: any; to: any }>; note: string | null; by: string | null; at: string; activated_by: string | null; activated_at: string | null }
const props = defineProps<{
  warehouse: { id: string; code: string; name: string }
  current: Record<string, any>
  rules: Record<string, [string, ...any[]]>
  versions: Version[]
  check: { errors: Record<string, string>; changes: Record<string, { from: any; to: any }> } | null
  can: { manage: boolean }
}>()

const values = reactive<Record<string, any>>(Object.fromEntries(Object.entries(props.current).map(([k, v]) => [k, Array.isArray(v) ? v.join(', ') : v])))
const form = useForm({ policies: {} as Record<string, any>, note: '', check_only: false })
const send = (checkOnly: boolean) => form.transform(d => ({ ...d, policies: { ...values }, check_only: checkOnly }))
  .post(route('warehouse.layout.warehouses.policies.draft', props.warehouse.id), { preserveScroll: true, preserveState: true })
const act = (v: Version, action: string) => router.post(route('warehouse.layout.warehouses.policies.act', [props.warehouse.id, v.id]), { action }, { preserveScroll: true })
const show = (v: any) => (v === null || v === undefined ? '—' : typeof v === 'boolean' ? (v ? t('warehouse_management.common.yes') : t('warehouse_management.common.no')) : Array.isArray(v) ? v.join(', ') : String(v))
const tone = (s: string) => ({ ACTIVE: 'success', DRAFT: 'warning', SUPERSEDED: undefined, DISCARDED: 'error' } as Record<string, string | undefined>)[s]
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('warehouse_management.policies.title', { code: warehouse.code }) }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.policies.subtitle') }}</p>
    </div>

    <VCard border class="mb-4">
      <VCardText>
        <VRow dense>
          <VCol v-for="(rule, key) in rules" :key="key" cols="12" sm="6" md="4">
            <VSelect v-if="rule[0] === 'enum'" v-model="values[key]" :items="rule[1]" :disabled="!can.manage" :label="t(`warehouse_management.policy.${key}`)"
                     :error-messages="check?.errors?.[key]" density="compact" />
            <VSwitch v-else-if="rule[0] === 'bool'" v-model="values[key]" :disabled="!can.manage" color="primary" density="compact" hide-details="auto"
                     :label="t(`warehouse_management.policy.${key}`)" :error-messages="check?.errors?.[key]" />
            <VTextField v-else-if="rule[0] === 'days_list' || rule[0] === 'gln'" v-model="values[key]" :disabled="!can.manage" density="compact" clearable
                        :label="t(`warehouse_management.policy.${key}`)" :hint="t(`warehouse_management.policies.hint_${rule[0]}`)" persistent-hint :error-messages="check?.errors?.[key]" />
            <VTextField v-else v-model.number="values[key]" type="number" :min="rule[1]" :max="rule[2]" :step="rule[0] === 'int' ? 1 : 'any'" :disabled="!can.manage" density="compact"
                        :clearable="!!rule[3]" :label="t(`warehouse_management.policy.${key}`)" :hint="t('warehouse_management.policies.range', { min: rule[1], max: rule[2] })" persistent-hint
                        :error-messages="check?.errors?.[key]" />
          </VCol>
        </VRow>
        <VAlert v-if="check && !Object.keys(check.errors).length" type="info" variant="tonal" class="mt-3" role="status">
          <div v-if="!Object.keys(check.changes).length">{{ t('warehouse_management.policies.no_change') }}</div>
          <div v-for="(c, k) in check.changes" :key="k">{{ t(`warehouse_management.policy.${k}`) }}: {{ show(c.from) }} → {{ show(c.to) }}</div>
        </VAlert>
        <VAlert v-if="(form.errors as any).refused" type="error" variant="tonal" class="mt-3">{{ (form.errors as any).refused }}</VAlert>
        <div v-if="can.manage" class="d-flex gap-2 mt-3 flex-wrap align-center">
          <VTextField v-model="form.note" density="compact" hide-details style="max-width: 420px" :label="t('warehouse_management.policies.why')" />
          <VBtn variant="tonal" @click="send(true)">{{ t('warehouse_management.policies.check') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="send(false)">{{ t('warehouse_management.policies.save_draft') }}</VBtn>
        </div>
      </VCardText>
    </VCard>

    <VCard border :title="t('warehouse_management.policies.history')">
      <VCardText v-if="!versions.length" class="text-medium-emphasis">{{ t('warehouse_management.policies.no_history') }}</VCardText>
      <VList v-else lines="three">
        <VListItem v-for="v in versions" :key="v.id">
          <VListItemTitle>{{ t('warehouse_management.policies.version', { n: v.version }) }} <VChip size="x-small" :color="tone(v.status)" class="ms-1">{{ t(`warehouse_management.policies.status.${v.status}`) }}</VChip>
            <span class="text-caption ms-2">{{ v.by }} · {{ new Date(v.at).toLocaleString() }}<template v-if="v.activated_at"> · {{ t('warehouse_management.policies.activated', { who: v.activated_by, at: new Date(v.activated_at).toLocaleString() }) }}</template></span></VListItemTitle>
          <VListItemSubtitle>
            <span v-if="v.note">{{ v.note }} · </span>
            <span v-for="(c, k) in v.changes" :key="k" class="me-3">{{ t(`warehouse_management.policy.${k}`) }}: {{ show(c.from) }} → {{ show(c.to) }}</span>
          </VListItemSubtitle>
          <template v-if="can.manage" #append>
            <VBtn v-if="v.status === 'DRAFT'" size="small" color="primary" class="me-1" @click="act(v, 'activate')">{{ t('warehouse_management.policies.activate') }}</VBtn>
            <VBtn v-if="v.status === 'DRAFT'" size="small" variant="text" @click="act(v, 'discard')">{{ t('warehouse_management.policies.discard') }}</VBtn>
            <VBtn v-if="v.status === 'SUPERSEDED'" size="small" variant="tonal" @click="act(v, 'restore')">{{ t('warehouse_management.policies.restore') }}</VBtn>
          </template>
        </VListItem>
      </VList>
    </VCard>
  </div>
</template>
