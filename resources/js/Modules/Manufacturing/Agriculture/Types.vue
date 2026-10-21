<script setup lang="ts">
/**
 * Production types as configuration: stages, measurements, custom fields and metric formulas. A
 * subtype starts from its parent and adds to it. Every change is a new version; a cycle keeps the
 * version it started on.
 */
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import PageErrors from '@/Modules/Agri/Components/PageErrors.vue'
import { CATEGORY_ICON, human } from './prd'

const props = defineProps<{
  types: Array<{ id: string; code: string; name: string; category: string; parent: string | null; version: number; is_active: boolean; unit_kind: string
    definition: Record<string, any>; own: { stages: any[]; custom_fields: any[]; metrics: any[] }; versions: Array<{ version: number; note: string; at: string }>; cycles: number }>
  categories: string[]
  variables: string[]
}>()
const open = ref<string | null>(null)
const sub = ref<string | null>(null)
const subForm = useForm({ parent_id: '', code: '', name: '', stages: [] as any[], custom_fields: [] as any[], metrics: [] as any[] })
const startSub = (id: string) => { sub.value = id; subForm.reset(); subForm.parent_id = id }
const saveSub = () => subForm.post('/manufacturing/agriculture/types', { preserveScroll: true, onSuccess: () => { sub.value = null } })
const rev = ref<string | null>(null)
const revForm = useForm({ metrics: [] as any[], change_note: '' })
const startRev = (t: any) => { rev.value = t.id; revForm.metrics = JSON.parse(JSON.stringify(t.own.metrics.length ? t.own.metrics : t.definition.metrics)); revForm.change_note = '' }
const saveRev = () => revForm.post(`/manufacturing/agriculture/types/${rev.value}`, { preserveScroll: true, onSuccess: () => { rev.value = null } })
</script>

<template>
  <div class="pa-4">
    <PageErrors />
    <div class="mb-4">
      <h4 class="text-h4">Production types</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">What each kind of production is measured by. Formulas may use: <code>{{ variables.join(', ') }}</code>.</p>
    </div>
    <VExpansionPanels v-model="open">
      <VExpansionPanel v-for="t in types" :key="t.id" :value="t.id">
        <VExpansionPanelTitle>
          <div class="d-flex align-center gap-3 w-100">
            <VIcon :icon="CATEGORY_ICON[t.category] ?? 'tabler-leaf'" />
            <div><div class="font-weight-medium">{{ t.name }} <span class="text-caption text-medium-emphasis">{{ t.code }}</span></div>
              <div class="text-caption text-medium-emphasis">{{ human(t.category) }}<template v-if="t.parent"> · subtype of {{ t.parent }}</template> · {{ t.cycles }} cycle{{ t.cycles === 1 ? '' : 's' }}</div></div>
            <VSpacer /><VChip size="small" label>v{{ t.version }}</VChip>
          </div>
        </VExpansionPanelTitle>
        <VExpansionPanelText>
          <VRow>
            <VCol cols="12" md="4"><div class="text-subtitle-2 mb-1">Stages</div>
              <div class="d-flex flex-wrap gap-1"><VChip v-for="s in t.definition.stages" :key="s.code" size="small" label>{{ s.name }}</VChip></div>
              <div class="text-subtitle-2 mt-3 mb-1">Custom fields</div>
              <div v-for="f in t.definition.custom_fields" :key="f.code" class="text-body-2">{{ f.name }} <span class="text-caption">({{ f.type }}{{ f.required ? ', required' : '' }})</span></div>
              <div v-if="!t.definition.custom_fields.length" class="text-caption text-medium-emphasis">None</div>
            </VCol>
            <VCol cols="12" md="5"><div class="text-subtitle-2 mb-1">Metrics</div>
              <div v-for="m in t.definition.metrics" :key="m.code" class="text-body-2 mb-1">{{ m.name }} <span class="text-caption">({{ m.unit }})</span><div class="text-caption text-medium-emphasis"><code>{{ m.formula }}</code></div></div>
            </VCol>
            <VCol cols="12" md="3"><div class="text-subtitle-2 mb-1">Versions</div>
              <div v-for="v in t.versions" :key="v.version" class="text-caption">v{{ v.version }} · {{ v.at }} — {{ v.note }}</div>
              <div class="d-flex gap-2 mt-3"><VBtn size="small" variant="tonal" @click="startSub(t.id)">Add subtype</VBtn><VBtn size="small" variant="tonal" @click="startRev(t)">Revise metrics</VBtn></div>
            </VCol>
          </VRow>
        </VExpansionPanelText>
      </VExpansionPanel>
    </VExpansionPanels>

    <VDialog :model-value="!!sub" max-width="760" @update:model-value="v => !v && (sub = null)">
      <VCard title="Add a subtype">
        <VCardText>
          <VRow dense>
            <VCol cols="6"><VTextField v-model="subForm.code" label="Code (CAPITALS)" :error-messages="subForm.errors.code" /></VCol>
            <VCol cols="6"><VTextField v-model="subForm.name" label="Name" :error-messages="subForm.errors.name" /></VCol>
          </VRow>
          <div class="text-subtitle-2 mt-2">Extra custom fields</div>
          <VRow v-for="(f, k) in subForm.custom_fields" :key="`f${k}`" dense>
            <VCol cols="3"><VTextField v-model="f.code" label="Code" density="compact" /></VCol><VCol cols="4"><VTextField v-model="f.name" label="Name" density="compact" /></VCol>
            <VCol cols="3"><VSelect v-model="f.type" :items="['text', 'number', 'date', 'choice', 'boolean']" label="Type" density="compact" /></VCol><VCol cols="2"><VCheckbox v-model="f.required" label="Required" density="compact" /></VCol>
          </VRow>
          <VBtn size="small" variant="text" @click="subForm.custom_fields.push({ code: '', name: '', type: 'text', required: false })">Add field</VBtn>
          <div class="text-subtitle-2 mt-2">Extra stages</div>
          <VRow v-for="(s, k) in subForm.stages" :key="`s${k}`" dense>
            <VCol cols="4"><VTextField v-model="s.code" label="Code" density="compact" /></VCol><VCol cols="8"><VTextField v-model="s.name" label="Name" density="compact" /></VCol>
          </VRow>
          <VBtn size="small" variant="text" @click="subForm.stages.push({ code: '', name: '' })">Add stage</VBtn>
          <div v-if="subForm.errors.metrics || subForm.errors.custom_fields || subForm.errors.stages" class="text-error text-caption">{{ subForm.errors.metrics || subForm.errors.custom_fields || subForm.errors.stages }}</div>
        </VCardText>
        <VCardActions><VSpacer /><VBtn @click="sub = null">Cancel</VBtn><VBtn color="primary" :loading="subForm.processing" @click="saveSub">Add</VBtn></VCardActions>
      </VCard>
    </VDialog>
    <VDialog :model-value="!!rev" max-width="760" @update:model-value="v => !v && (rev = null)">
      <VCard title="Revise metric formulas">
        <VCardText>
          <VAlert type="info" variant="tonal" density="compact" class="mb-3">Cycles already running keep the formulas they started on. New cycles use the new version.</VAlert>
          <VRow v-for="(m, k) in revForm.metrics" :key="k" dense>
            <VCol cols="4"><VTextField v-model="m.name" label="Metric" density="compact" /></VCol>
            <VCol cols="6"><VTextField v-model="m.formula" label="Formula" density="compact" /></VCol>
            <VCol cols="2"><VTextField v-model="m.unit" label="Unit" density="compact" /></VCol>
          </VRow>
          <VTextField v-model="revForm.change_note" label="Why it is changing" :error-messages="revForm.errors.change_note" />
          <div v-if="revForm.errors.metrics" class="text-error text-caption">{{ revForm.errors.metrics }}</div>
        </VCardText>
        <VCardActions><VSpacer /><VBtn @click="rev = null">Cancel</VBtn><VBtn color="primary" :loading="revForm.processing" @click="saveRev">Save new version</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
