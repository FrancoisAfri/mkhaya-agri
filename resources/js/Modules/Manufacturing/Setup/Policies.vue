<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Policy = {
  id: number; factory: string | null; factory_id: string | null; is_company_default: boolean
  scrap_tolerance_percent: number | null; yield_tolerance_percent: number | null
  cost_variance_tolerance_percent: number | null; require_qc_before_putaway: boolean
  allow_negative_stock: boolean; allow_over_issue: boolean; require_bom_approval: boolean
  escalate_after_hours: number
}
type Limit = {
  id: string; document_type: string; factory: string | null; min_amount: number
  max_amount: number | null; required_permission: string; escalate_after_hours: number | null
  is_active: boolean
}

const props = defineProps<{
  policies: Policy[]
  limits: Limit[]
  factories: Array<{ id: string; code: string; name: string }>
  limit_document_types: string[]
  approvers: Array<{ value: string; key: string; holders: number }>
}>()

// '' is the company default that factories inherit until they set their own.
const scope = ref<string>('')

const current = computed(() => props.policies.find(p => (p.factory_id ?? '') === scope.value) ?? null)

const form = useForm({
  factory_uuid: null as string | null,
  scrap_tolerance_percent: null as number | null,
  yield_tolerance_percent: null as number | null,
  cost_variance_tolerance_percent: null as number | null,
  require_qc_before_putaway: true,
  allow_negative_stock: false,
  allow_over_issue: false,
  require_bom_approval: true,
  escalate_after_hours: 24,
})

function load() {
  const p = current.value
  form.factory_uuid = scope.value || null
  form.scrap_tolerance_percent = p?.scrap_tolerance_percent ?? null
  form.yield_tolerance_percent = p?.yield_tolerance_percent ?? null
  form.cost_variance_tolerance_percent = p?.cost_variance_tolerance_percent ?? null
  form.require_qc_before_putaway = p?.require_qc_before_putaway ?? true
  form.allow_negative_stock = p?.allow_negative_stock ?? false
  form.allow_over_issue = p?.allow_over_issue ?? false
  form.require_bom_approval = p?.require_bom_approval ?? true
  form.escalate_after_hours = p?.escalate_after_hours ?? 24
  form.clearErrors()
}

watch([scope, () => props.policies], load, { immediate: true })

function save() {
  form.post(route('manufacturing.setup.policies.save'), { preserveScroll: true })
}

const scopeItems = computed(() => [
  { title: t('manufacturing.policies.company_default'), value: '' },
  ...props.factories.map(f => ({ title: `${f.code} — ${f.name}`, value: f.id })),
])

// ── Approval bands ──────────────────────────────────────────────────────────
const limitForm = useForm({
  factory_uuid: null as string | null, document_type: 'PRODUCTION_ORDER', min_amount: 0,
  max_amount: null as number | null, required_permission: '', escalate_after_hours: null as number | null,
})

// A permission in words; a code nobody has a label for still shows, as the code.
const approverLabel = (code: string) => {
  const a = props.approvers.find(x => x.value === code)
  return a?.key ? t(`manufacturing.approver.${a.key}`) : code
}
const approverItems = computed(() => props.approvers.map(a => ({
  value: a.value, title: approverLabel(a.value),
  subtitle: a.holders ? t('manufacturing.policies.holders', { n: a.holders }, a.holders) : t('manufacturing.policies.no_holders'),
  holders: a.holders,
})))
const chosen = computed(() => props.approvers.find(a => a.value === limitForm.required_permission) ?? null)

// The band read back as a sentence before it is added.
const summary = computed(() => {
  if (!limitForm.required_permission) return ''
  const factory = props.factories.find(f => f.id === limitForm.factory_uuid)
  return t('manufacturing.policies.summary', {
    from: money(Number(limitForm.min_amount) || 0),
    to: limitForm.max_amount ? t('manufacturing.policies.summary_to', { n: money(Number(limitForm.max_amount)) }) : t('manufacturing.policies.summary_above'),
    where: factory ? t('manufacturing.policies.summary_where', { f: factory.name }) : t('manufacturing.policies.summary_where_all'),
    who: approverLabel(limitForm.required_permission),
  })
})

function addLimit() {
  limitForm.post(route('manufacturing.setup.policies.limits.store'), {
    preserveScroll: true, onSuccess: () => limitForm.reset(),
  })
}

function removeLimit(l: Limit) {
  router.delete(route('manufacturing.setup.policies.limits.destroy', l.id), { preserveScroll: true })
}

const money = (n: number) => n.toLocaleString(undefined, { minimumFractionDigits: 2 })
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('manufacturing.policies.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.policies.subtitle') }}</p>
    </div>

    <VCard border class="mb-4">
      <VCardItem>
        <VCardTitle>{{ t('manufacturing.policies.tolerances') }}</VCardTitle>
        <template #append>
          <VSelect v-model="scope" :items="scopeItems" density="compact" hide-details style="min-width: 260px"
                   :label="t('manufacturing.policies.applies_to')" />
        </template>
      </VCardItem>
      <VCardText>
        <!-- An empty tolerance means "none configured", which is not the same as 0 ("must be
             exact"). Said here because collapsing the two flags every order ever produced. -->
        <VAlert type="info" variant="tonal" density="compact" class="mb-4">
          {{ t('manufacturing.policies.blank_hint') }}
        </VAlert>
        <VRow>
          <VCol cols="12" md="4">
            <VTextField v-model.number="form.scrap_tolerance_percent" type="number" min="0" max="100" step="any"
                        suffix="%" :error-messages="form.errors.scrap_tolerance_percent"
                        :label="t('manufacturing.policies.scrap')" />
          </VCol>
          <VCol cols="12" md="4">
            <VTextField v-model.number="form.yield_tolerance_percent" type="number" min="0" max="100" step="any"
                        suffix="%" :error-messages="form.errors.yield_tolerance_percent"
                        :label="t('manufacturing.policies.yield')" />
          </VCol>
          <VCol cols="12" md="4">
            <VTextField v-model.number="form.cost_variance_tolerance_percent" type="number" min="0" max="100" step="any"
                        suffix="%" :error-messages="form.errors.cost_variance_tolerance_percent"
                        :label="t('manufacturing.policies.cost_variance')" />
          </VCol>
          <VCol cols="12" md="4">
            <VTextField v-model.number="form.escalate_after_hours" type="number" min="1" max="720"
                        :error-messages="form.errors.escalate_after_hours"
                        :label="t('manufacturing.policies.escalate_after')" suffix="h" />
          </VCol>
        </VRow>
        <div class="d-flex flex-column">
          <VSwitch v-model="form.require_bom_approval" color="primary" hide-details
                   :label="t('manufacturing.policies.require_bom_approval')" />
          <VSwitch v-model="form.require_qc_before_putaway" color="primary" hide-details
                   :label="t('manufacturing.policies.require_qc')" />
          <VSwitch v-model="form.allow_negative_stock" color="warning" hide-details
                   :label="t('manufacturing.policies.allow_negative')" />
          <VSwitch v-model="form.allow_over_issue" color="warning" hide-details
                   :label="t('manufacturing.policies.allow_over_issue')" />
        </div>
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn color="primary" :loading="form.processing" @click="save">{{ t('manufacturing.common.save') }}</VBtn>
      </VCardActions>
    </VCard>

    <VCard border>
      <VCardItem>
        <VCardTitle>{{ t('manufacturing.policies.limits') }}</VCardTitle>
        <VCardSubtitle>{{ t('manufacturing.policies.limits_hint') }}</VCardSubtitle>
      </VCardItem>
      <VTable v-if="limits.length" density="comfortable">
        <thead>
          <tr>
            <th>{{ t('manufacturing.numbering.document') }}</th>
            <th>{{ t('manufacturing.numbering.scope') }}</th>
            <th class="text-end">{{ t('manufacturing.policies.from') }}</th>
            <th class="text-end">{{ t('manufacturing.policies.up_to') }}</th>
            <th>{{ t('manufacturing.policies.permission') }}</th>
            <th>{{ t('manufacturing.policies.escalate') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="l in limits" :key="l.id">
            <td>{{ t(`manufacturing.document_type.${l.document_type}`) }}</td>
            <td>{{ l.factory ?? t('manufacturing.policies.factory_all') }}</td>
            <td class="text-end">{{ money(l.min_amount) }}</td>
            <!-- A null ceiling means "and everything above", not "no limit configured". -->
            <td class="text-end">{{ l.max_amount === null ? t('manufacturing.policies.and_above') : money(l.max_amount) }}</td>
            <td>{{ approverLabel(l.required_permission) }}</td>
            <td>{{ l.escalate_after_hours ? t('manufacturing.policies.hours', { n: l.escalate_after_hours }) : '—' }}</td>
            <td class="text-end">
              <VBtn icon="tabler-trash" variant="text" size="small" :aria-label="t('manufacturing.common.remove')"
                    @click="removeLimit(l)" />
            </td>
          </tr>
        </tbody>
      </VTable>
      <VCardText v-else class="text-medium-emphasis">{{ t('manufacturing.policies.no_limits') }}</VCardText>

      <VCardText>
        <VDivider class="mb-4" />
        <div class="text-subtitle-1 font-weight-medium mb-1">{{ t('manufacturing.policies.new_band') }}</div>
        <p class="text-body-2 text-medium-emphasis mb-4">
          {{ t(`manufacturing.document_type.${limit_document_types[0]}`) }} — {{ t('manufacturing.policies.limits_applies') }}
        </p>
        <VRow>
          <VCol cols="12" md="6">
            <div class="text-caption text-medium-emphasis mb-1">{{ t('manufacturing.policies.order_value') }}</div>
            <div class="d-flex gap-3">
              <VTextField v-model.number="limitForm.min_amount" type="number" min="0" step="any"
                          :error-messages="limitForm.errors.min_amount" :label="t('manufacturing.policies.from')" />
              <VTextField v-model.number="limitForm.max_amount" type="number" min="0" step="any"
                          :error-messages="limitForm.errors.max_amount" :label="t('manufacturing.policies.up_to')"
                          :hint="t('manufacturing.policies.blank_ceiling')" persistent-hint />
            </div>
          </VCol>
          <VCol cols="12" md="6">
            <VSelect v-model="limitForm.factory_uuid" clearable :items="factories" item-value="id"
                     :item-title="(f: any) => `${f.code} — ${f.name}`" :placeholder="t('manufacturing.policies.factory_all')"
                     persistent-placeholder :hint="t('manufacturing.policies.factory_hint')" persistent-hint
                     :label="t('manufacturing.numbering.factory')" />
          </VCol>
          <VCol cols="12" md="6">
            <VSelect v-model="limitForm.required_permission" :items="approverItems"
                     :error-messages="limitForm.errors.required_permission" :label="t('manufacturing.policies.permission')"
                     :hint="t('manufacturing.policies.approver_hint')" persistent-hint>
              <template #item="{ props: itemProps, item }">
                <VListItem v-bind="itemProps" :subtitle="item.raw.subtitle">
                  <template #append>
                    <VIcon v-if="!item.raw.holders" icon="tabler-alert-triangle" color="warning" size="18" />
                  </template>
                </VListItem>
              </template>
            </VSelect>
          </VCol>
          <VCol cols="12" md="6">
            <VTextField v-model.number="limitForm.escalate_after_hours" type="number" min="1" max="720"
                        :label="t('manufacturing.policies.escalate_after')" suffix="h"
                        :hint="t('manufacturing.policies.escalate_hint')" persistent-hint />
          </VCol>
        </VRow>
        <VAlert v-if="chosen && !chosen.holders" type="warning" variant="tonal" density="compact" class="mt-4">
          {{ t('manufacturing.policies.no_holders') }}
        </VAlert>
        <VAlert v-else-if="summary" type="info" variant="tonal" density="compact" class="mt-4">{{ summary }}</VAlert>
        <div class="d-flex justify-end mt-4">
          <VBtn color="primary" prepend-icon="tabler-plus" :loading="limitForm.processing" :disabled="!limitForm.required_permission"
                @click="addLimit">{{ t('manufacturing.policies.add_limit') }}</VBtn>
        </div>
      </VCardText>
    </VCard>
  </div>
</template>
