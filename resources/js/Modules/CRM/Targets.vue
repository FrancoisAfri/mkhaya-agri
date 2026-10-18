<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface MetricMeta { label: string; format: string; icon: string }
interface Row { user_id: number; name: string; period: string; values: Record<string, number> }

const props = defineProps<{ users: { id: number; name: string }[]; targets: Record<string, Record<string, Record<string, number>>>; rows: Row[]; metrics: Record<string, MetricMeta>; periods: string[] }>()

const editorOpen = ref(false); const userId = ref<number | null>(props.users[0]?.id ?? null); const period = ref<string>('monthly'); const confirm = ref()
const snack = ref<{ show: boolean; text: string; color: string }>({ show: false, text: '', color: 'success' })

const form = useForm({ user_id: userId.value, period: period.value, targets: {} as Record<string, number | null> })

function loadTargets() { const tp = props.targets?.[String(userId.value)]?.[period.value] ?? {}; const next: Record<string, number | null> = {}; for (const key of Object.keys(props.metrics)) next[key] = tp[key] ?? null; form.user_id = userId.value; form.period = period.value; form.targets = next }

function openNew() { userId.value = props.users[0]?.id ?? null; period.value = 'monthly'; loadTargets(); editorOpen.value = true }
function editRow(r: Row) { userId.value = r.user_id; period.value = r.period; loadTargets(); editorOpen.value = true }
function deleteRow(r: Row) { confirm.value.open({ title: t('crm_targets_index.delete.title'), message: t('crm_targets_index.delete.message', { period: r.period, name: r.name }), confirmText: t('crm_targets_index.delete.confirm'), confirmColor: 'error' }, () => router.delete(route('crm.targets.destroy'), { data: { user_id: r.user_id, period: r.period }, preserveScroll: true })) }
function save() { form.post(route('crm.targets.store'), { preserveScroll: true, onSuccess: () => { snack.value = { show: true, text: t('crm_targets_index.toast_saved'), color: 'success' }; editorOpen.value = false } }) }

const selectedName = computed(() => props.users.find(u => u.id === userId.value)?.name ?? '')
const metricKeys = computed(() => Object.keys(props.metrics))
const fmt = (v: number | undefined, f: string) => v === undefined || v === null ? '—' : f === 'currency' ? 'R ' + Number(v).toLocaleString('en-ZA', { maximumFractionDigits: 0 }) : Number(v).toLocaleString('en-ZA')

const headers = computed(() => [
  { title: t('crm_targets_index.table.rep'), key: 'name' }, { title: t('crm_targets_index.table.period'), key: 'period' },
  ...metricKeys.value.map(k => ({ title: props.metrics[k].label, key: `values.${k}`, align: 'end' as const, sortable: false })),
  { title: '', key: 'actions', align: 'end' as const, sortable: false },
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3"><div><h5 class="text-h5 font-weight-semibold">{{ t('crm_targets_index.page_title') }}</h5><p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_targets_index.description') }}</p></div><VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">{{ t('crm_targets_index.set_targets') }}</VBtn></div>

    <VCard><VDataTable :headers="headers" :items="rows" :items-per-page="50" density="comfortable" :sort-by="[{ key: 'name', order: 'asc' }]">
      <template #item.period="{ item }"><VChip size="small" :color="item.period === 'weekly' ? 'info' : 'primary'" label class="text-capitalize">{{ item.period }}</VChip></template>
      <template v-for="k in metricKeys" :key="k" #[`item.values.${k}`]="{ item }">{{ fmt(item.values[k], metrics[k].format) }}</template>
      <template #item.actions="{ item }"><VBtn size="x-small" variant="text" icon="tabler-edit" :title="t('crm_targets_index.tips.edit')" @click="editRow(item)" /><VBtn size="x-small" variant="text" icon="tabler-trash" color="error" :title="t('crm_targets_index.tips.delete')" @click="deleteRow(item)" /></template>
      <template #no-data><div class="pa-6 text-center text-medium-emphasis">{{ t('crm_targets_index.no_targets') }}</div></template>
    </VDataTable></VCard>

    <VDialog v-model="editorOpen" max-width="680" persistent><VCard><VCardTitle>{{ t('crm_targets_index.dialog.title') }}</VCardTitle><VCardText>
      <VRow class="mb-1"><VCol cols="12" sm="7"><VSelect v-model="userId" :label="t('crm_targets_index.dialog.rep')" :items="users" item-title="name" item-value="id" @update:model-value="loadTargets" /></VCol><VCol cols="12" sm="5"><VSelect v-model="period" :label="t('crm_targets_index.dialog.period')" :items="periods" class="text-capitalize" @update:model-value="loadTargets" /></VCol></VRow>
      <VAlert type="info" variant="tonal" density="compact" class="mb-3 text-caption">{{ t('crm_targets_index.dialog.info') }}</VAlert>
      <VRow><VCol v-for="(meta, key) in metrics" :key="key" cols="12" sm="6"><VTextField v-model.number="form.targets[key]" type="number" min="0" :label="`${meta.label} (${period})`" :prepend-inner-icon="`tabler-${meta.icon}`" :prefix="meta.format === 'currency' ? 'R' : ''" :placeholder="meta.format === 'currency' ? 'e.g. 500000' : 'e.g. 40'" hide-details /></VCol></VRow>
    </VCardText><VDivider /><VCardActions class="pa-4"><span class="text-caption text-medium-emphasis">{{ t('crm_targets_index.dialog.editing', { period, name: selectedName }) }}</span><VSpacer /><VBtn variant="tonal" @click="editorOpen = false">{{ t('crm_targets_index.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" prepend-icon="tabler-device-floppy" @click="save">{{ t('crm_targets_index.dialog.save') }}</VBtn></VCardActions></VCard></VDialog>

    <ConfirmDialog ref="confirm" />
    <VSnackbar v-model="snack.show" :color="snack.color" location="bottom right" timeout="3000">{{ snack.text }}</VSnackbar>
  </div>
</template>