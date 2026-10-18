<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Opt = { id: number; name: string }
const props = defineProps<{
  requirements: Array<{ id: string; kind: string; label: string; is_mandatory: boolean; applies_to: string }>
  training: Array<{ person: string; requirement: string; kind: string; mandatory: boolean; status: string; expires_on: string | null; work_centre: string | null }>
  candidates: null | { operation: string; people: Array<{ id: number; name: string; eligible: boolean; checks: Array<{ requirement: string; met: boolean; detail: string | null; mandatory: boolean; expires_on: string | null }> }> }
  open_operations: Array<{ id: string; name: string }>
  options: null | { skills: Opt[]; certifications: Opt[]; competencies: Opt[]; operations: Opt[]; work_centres: Opt[] }
  can: { manage: boolean }
}>()

const operation = ref<string | null>(null)
const lookup = () => router.get(route('manufacturing.workforce.qualifications'), { operation: operation.value || undefined }, { preserveState: true, preserveScroll: true })
const remove = (id: string) => router.delete(route('manufacturing.workforce.qualifications.destroy', id), { preserveScroll: true })

const dialog = ref(false)
const form = useForm({ kind: 'SKILL', reference_id: null as number | null, routing_operation_id: null as number | null, work_centre_id: null as number | null, min_level: null as number | null, is_mandatory: true })
const refs = () => props.options ? ({ SKILL: props.options.skills, CERTIFICATION: props.options.certifications, COMPETENCY: props.options.competencies } as Record<string, Opt[]>)[form.kind] : []
const save = () => form.post(route('manufacturing.workforce.qualifications.store'), { preserveScroll: true, onSuccess: () => { dialog.value = false; form.reset() } })
const statusColor = (s: string) => ({ MISSING: 'error', EXPIRED: 'error', EXPIRING: 'warning' } as Record<string, string>)[s] ?? 'default'
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.labour.qual_title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.labour.qual_subtitle') }}</p>
      </div>
      <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="dialog = true">{{ t('manufacturing.labour.add_requirement') }}</VBtn>
    </div>

    <VRow>
      <VCol cols="12" lg="6">
        <VCard border class="mb-4">
          <VCardItem><VCardTitle>{{ t('manufacturing.labour.requirements') }}</VCardTitle></VCardItem>
          <VList density="compact">
            <VListItem v-for="r in requirements" :key="r.id" :title="r.label" :subtitle="`${t(`manufacturing.labour.kind.${r.kind}`)} · ${r.applies_to}${r.is_mandatory ? '' : ' · ' + t('manufacturing.labour.advisory')}`">
              <template v-if="can.manage" #append><IconBtn size="small" color="error" @click="remove(r.id)"><VIcon icon="tabler-trash" /></IconBtn></template>
            </VListItem>
            <VListItem v-if="!requirements.length" :subtitle="t('manufacturing.labour.no_requirements')" />
          </VList>
        </VCard>

        <VCard border>
          <VCardItem><VCardTitle>{{ t('manufacturing.labour.who_can') }}</VCardTitle></VCardItem>
          <VCardText class="d-flex gap-2">
            <VAutocomplete v-model="operation" :items="open_operations" item-value="id" item-title="name" clearable hide-details :label="t('manufacturing.floor.operation')" />
            <VBtn color="primary" @click="lookup">{{ t('manufacturing.trace.find') }}</VBtn>
          </VCardText>
          <VList v-if="candidates" density="compact">
            <VListSubheader>{{ candidates.operation }}</VListSubheader>
            <VListItem v-for="p in candidates.people" :key="p.id" :title="p.name">
              <template #append><VChip size="x-small" label :color="p.eligible ? 'success' : 'error'">{{ p.eligible ? t('manufacturing.labour.eligible') : t('manufacturing.labour.not_eligible') }}</VChip></template>
              <VListItemSubtitle>
                <span v-for="(c, i) in p.checks" :key="i" :class="c.met ? '' : 'text-error'">{{ c.requirement }}{{ c.detail ? ` (${c.detail})` : '' }}{{ i < p.checks.length - 1 ? ' · ' : '' }}</span>
              </VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
      <VCol cols="12" lg="6">
        <VCard border>
          <VCardItem><VCardTitle>{{ t('manufacturing.labour.training') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.labour.training_hint') }}</VCardSubtitle></VCardItem>
          <VTable density="compact">
            <thead><tr><th>{{ t('manufacturing.labour.person') }}</th><th>{{ t('manufacturing.labour.requirement') }}</th><th>{{ t('manufacturing.bom.status') }}</th><th>{{ t('manufacturing.quality.expires_on') }}</th></tr></thead>
            <tbody>
              <tr v-for="(n, i) in training" :key="i">
                <td>{{ n.person }}<div class="text-caption text-medium-emphasis">{{ n.work_centre }}</div></td>
                <td>{{ n.requirement }}</td>
                <td><VChip size="x-small" label :color="statusColor(n.status)">{{ t(`manufacturing.labour.need.${n.status}`) }}</VChip></td>
                <td>{{ n.expires_on ?? '—' }}</td>
              </tr>
              <tr v-if="!training.length"><td colspan="4" class="text-center text-medium-emphasis py-6">{{ t('manufacturing.labour.no_training') }}</td></tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-if="options" v-model="dialog" max-width="520">
      <VCard :title="t('manufacturing.labour.add_requirement')">
        <VCardText>
          <VBtnToggle v-model="form.kind" mandatory divided variant="outlined" class="mb-3">
            <VBtn v-for="k in ['SKILL', 'CERTIFICATION', 'COMPETENCY']" :key="k" :value="k">{{ t(`manufacturing.labour.kind.${k}`) }}</VBtn>
          </VBtnToggle>
          <VAutocomplete v-model="form.reference_id" :items="refs()" item-value="id" item-title="name" :label="t(`manufacturing.labour.kind.${form.kind}`)" class="mb-2" />
          <VTextField v-if="form.kind === 'SKILL'" v-model.number="form.min_level" type="number" min="1" max="10" :label="t('manufacturing.labour.min_level')" class="mb-2" />
          <VAutocomplete v-model="form.routing_operation_id" :items="options.operations" item-value="id" item-title="name" clearable :label="t('manufacturing.labour.for_operation')" class="mb-2" />
          <VSelect v-model="form.work_centre_id" :items="options.work_centres" item-value="id" item-title="name" clearable :label="t('manufacturing.labour.or_centre')" class="mb-2" />
          <VSwitch v-model="form.is_mandatory" color="primary" hide-details :label="t('manufacturing.labour.mandatory')" />
          <p class="text-caption text-medium-emphasis mt-2">{{ t('manufacturing.labour.source_note') }}</p>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!form.reference_id || (!form.routing_operation_id && !form.work_centre_id)" @click="save">{{ t('manufacturing.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
