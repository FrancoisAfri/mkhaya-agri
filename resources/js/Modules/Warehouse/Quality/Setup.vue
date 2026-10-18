<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Check = { name: string; kind: string; uom: string | null; lower: number | null; upper: number | null; critical: boolean }
type Plan = { id: string; code: string; name: string; stage: string; item: string | null; item_title: string | null; sampling_rule: string; sample_size: number | null; sample_pct: number | null; requires_certificate: boolean; is_active: boolean; checks: Check[] }
type Defect = { id: number; code: string; name: string; severity: string; is_active: boolean }
const props = defineProps<{ plans: Plan[]; defect_codes: Defect[]; stages: string[]; sampling_rules: string[]; severities: string[]; can: { manage: boolean } }>()

const editing = ref<string | null>(null)
const dialog = ref(false)
const blank = () => ({ code: '', name: '', stage: 'RETURN', item: null as string | null, sampling_rule: 'FIXED', sample_size: 1 as number | null, sample_pct: null as number | null,
  requires_certificate: false, is_active: true, checks: [] as Check[] })
const form = useForm(blank())
const items = ref<Array<{ id: string; title: string }>>([])
async function searchItems(q: string) { items.value = (await axios.get(route('warehouse.products.search'), { params: { q } })).data }
function open(p?: Plan) {
  form.defaults(blank()); form.reset()
  editing.value = p?.id ?? null
  if (p) {
    Object.assign(form, { code: p.code, name: p.name, stage: p.stage, item: p.item, sampling_rule: p.sampling_rule, sample_size: p.sample_size, sample_pct: p.sample_pct,
      requires_certificate: p.requires_certificate, is_active: p.is_active, checks: p.checks.map(c => ({ ...c })) })
    items.value = p.item ? [{ id: p.item, title: p.item_title ?? '' }] : []
  }
  dialog.value = true
}
function save() {
  const done = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  editing.value ? form.patch(route('warehouse.quality.plans.update', editing.value), done) : form.post(route('warehouse.quality.plans.store'), done)
}
const defect = useForm({ code: '', name: '', severity: 'MINOR', is_active: true })
const toggleDefect = (d: Defect) => router.patch(route('warehouse.quality.defects.update', d.id), { ...d, is_active: !d.is_active }, { preserveScroll: true })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <div class="text-caption"><a :href="route('warehouse.quality.index')">{{ t('warehouse_management.quality.title') }}</a></div>
        <h4 class="text-h4">{{ t('warehouse_management.cards.quality_setup.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.quality.setup_subtitle') }}</p>
      </div>
      <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="open()">{{ t('warehouse_management.quality.new_plan') }}</VBtn>
    </div>
    <RefusalAlert />
    <VRow>
      <VCol cols="12" md="8">
        <VCard border :title="t('warehouse_management.quality.plans')">
          <VCardText v-if="!plans.length" class="text-medium-emphasis">{{ t('warehouse_management.quality.no_plans') }}</VCardText>
          <VList v-else lines="two">
            <VListItem v-for="p in plans" :key="p.id" :class="p.is_active ? '' : 'text-disabled'" @click="can.manage && open(p)">
              <VListItemTitle>{{ p.code }} · {{ p.name }} <VChip size="x-small" class="ms-1">{{ t(`warehouse_management.inspection_stage.${p.stage}`) }}</VChip></VListItemTitle>
              <VListItemSubtitle>{{ p.item_title ?? t('warehouse_management.quality.every_product') }} ·
                {{ t(`warehouse_management.sampling_rule.${p.sampling_rule}`, { n: p.sample_size ?? 1, pct: p.sample_pct ?? 0 }) }} ·
                {{ t('warehouse_management.quality.checks', { n: p.checks.length }) }}</VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard border :title="t('warehouse_management.quality.defect_codes')">
          <VList density="compact">
            <VListItem v-for="d in defect_codes" :key="d.id" :class="d.is_active ? '' : 'text-disabled'">
              <VListItemTitle>{{ d.code }} · {{ d.name }}</VListItemTitle>
              <VListItemSubtitle>{{ t(`warehouse_management.defect_severity.${d.severity}`) }}</VListItemSubtitle>
              <template v-if="can.manage" #append><VBtn size="x-small" variant="text" @click="toggleDefect(d)">{{ d.is_active ? t('warehouse_management.counts.pause') : t('warehouse_management.counts.resume') }}</VBtn></template>
            </VListItem>
          </VList>
          <VCardText v-if="can.manage" class="d-flex flex-column gap-2">
            <VTextField v-model="defect.code" density="compact" :label="t('warehouse_management.common.code')" :error-messages="defect.errors.code" />
            <VTextField v-model="defect.name" density="compact" :label="t('warehouse_management.common.name')" />
            <VSelect v-model="defect.severity" :items="severities.map(s => ({ value: s, title: t(`warehouse_management.defect_severity.${s}`) }))" density="compact" />
            <VBtn variant="tonal" @click="defect.post(route('warehouse.quality.defects.store'), { preserveScroll: true, onSuccess: () => defect.reset() })">{{ t('warehouse_management.common.add') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="dialog" max-width="760">
      <VCard :title="editing ? t('warehouse_management.quality.edit_plan') : t('warehouse_management.quality.new_plan')">
        <VCardText class="d-flex flex-column gap-3">
          <div class="d-flex gap-2"><VTextField v-model="form.code" :label="t('warehouse_management.common.code')" :error-messages="form.errors.code" style="max-width: 160px" />
            <VTextField v-model="form.name" :label="t('warehouse_management.common.name')" :error-messages="form.errors.name" /></div>
          <VSelect v-model="form.stage" :items="stages.map(s => ({ value: s, title: t(`warehouse_management.inspection_stage.${s}`) }))" :label="t('warehouse_management.quality.stage')" />
          <VAutocomplete v-model="form.item" :items="items" item-value="id" clearable :label="t('warehouse_management.quality.product')" :hint="t('warehouse_management.quality.product_hint')" persistent-hint @update:search="searchItems" />
          <div class="d-flex gap-2">
            <VSelect v-model="form.sampling_rule" :items="sampling_rules.map(s => ({ value: s, title: t(`warehouse_management.sampling_rule_name.${s}`) }))" :label="t('warehouse_management.quality.sampling')" />
            <VTextField v-if="form.sampling_rule === 'FIXED'" v-model.number="form.sample_size" type="number" min="1" :label="t('warehouse_management.quality.sample_size')" :error-messages="form.errors.sample_size" />
            <VTextField v-if="form.sampling_rule === 'PERCENT'" v-model.number="form.sample_pct" type="number" min="0" max="100" :label="t('warehouse_management.quality.sample_pct')" :error-messages="form.errors.sample_pct" />
          </div>
          <div class="d-flex gap-4"><VSwitch v-model="form.requires_certificate" hide-details color="primary" :label="t('warehouse_management.quality.certificate')" />
            <VSwitch v-model="form.is_active" hide-details color="primary" :label="t('warehouse_management.quality.active')" /></div>
          <div class="text-subtitle-2">{{ t('warehouse_management.quality.check_list') }}</div>
          <div v-for="(c, i) in form.checks" :key="i" class="d-flex gap-2 align-center flex-wrap">
            <VTextField v-model="c.name" density="compact" hide-details :label="t('warehouse_management.quality.check')" />
            <VSelect v-model="c.kind" :items="[{ value: 'ATTRIBUTE', title: t('warehouse_management.quality.pass_fail') }, { value: 'MEASURED', title: t('warehouse_management.quality.measured') }]" density="compact" hide-details style="max-width: 150px" />
            <template v-if="c.kind === 'MEASURED'">
              <VTextField v-model.number="c.lower" type="number" density="compact" hide-details style="max-width: 90px" :label="t('warehouse_management.quality.min')" />
              <VTextField v-model.number="c.upper" type="number" density="compact" hide-details style="max-width: 90px" :label="t('warehouse_management.quality.max')" />
              <VTextField v-model="c.uom" density="compact" hide-details style="max-width: 70px" :label="t('warehouse_management.quality.unit')" />
            </template>
            <VCheckbox v-model="c.critical" hide-details density="compact" :label="t('warehouse_management.quality.critical')" />
            <VBtn icon="tabler-x" size="x-small" variant="text" :aria-label="t('warehouse_management.common.remove')" @click="form.checks.splice(i, 1)" />
          </div>
          <VBtn variant="text" prepend-icon="tabler-plus" class="align-self-start" @click="form.checks.push({ name: '', kind: 'ATTRIBUTE', uom: null, lower: null, upper: null, critical: false })">{{ t('warehouse_management.quality.add_check') }}</VBtn>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
