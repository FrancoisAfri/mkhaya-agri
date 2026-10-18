<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()

type RatingValue = {
  id: number
  label: string
  value: number
  description: string | null
  color: string | null
  sort_order: number
}
type RatingScale = {
  id: number
  name: string
  description: string | null
  min_score: number
  max_score: number
  is_default: boolean
  is_active: boolean
  values: RatingValue[]
}

const props = defineProps<{ scales: RatingScale[] }>()

const scaleDrawer  = ref(false)
const scaleDelOpen = ref(false)
const scaleDelItem = ref<RatingScale | null>(null)
const scaleEditing = ref<Partial<RatingScale> | null>(null)

const valueDrawer  = ref(false)
const valueDelOpen = ref(false)
const valueDelItem = ref<RatingValue | null>(null)
const valueEditing = ref<Partial<RatingValue> | null>(null)
const activeScale  = ref<RatingScale | null>(null)

function openScale(scale?: RatingScale) {
  scaleEditing.value = scale
    ? { ...scale }
    : { name: '', description: null, min_score: 1, max_score: 5, is_default: false, is_active: true }
  scaleDrawer.value = true
}

function saveScale() {
  if (!scaleEditing.value) return
  const s = scaleEditing.value
  if (s.id) {
    router.patch(route('performance.rating-scales.update', s.id), s, { onSuccess: () => { scaleDrawer.value = false } })
  } else {
    router.post(route('performance.rating-scales.store'), s, { onSuccess: () => { scaleDrawer.value = false } })
  }
}

function openValue(scale: RatingScale, value?: RatingValue) {
  activeScale.value = scale
  valueEditing.value = value
    ? { ...value }
    : { label: '', value: 0, description: null, color: null, sort_order: scale.values.length }
  valueDrawer.value = true
}

function saveValue() {
  if (!valueEditing.value || !activeScale.value) return
  const v = valueEditing.value
  if (v.id) {
    router.patch(route('performance.rating-scales.values.update', [activeScale.value.id, v.id]), v, {
      onSuccess: () => { valueDrawer.value = false },
    })
  } else {
    router.post(route('performance.rating-scales.values.store', activeScale.value.id), v, {
      onSuccess: () => { valueDrawer.value = false },
    })
  }
}

function deleteValue(scale: RatingScale, value: RatingValue) {
  router.delete(route('performance.rating-scales.values.destroy', [scale.id, value.id]), { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('pa.scales.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('pa.scales.subtitle') }}</p>
      </div>
      <VSpacer />
      <VBtn color="primary" prepend-icon="ri-add-line" @click="openScale()">{{ t('pa.scales.new') }}</VBtn>
    </div>

    <VRow>
      <VCol v-if="props.scales.length === 0" cols="12">
        <VCard class="text-center pa-8 text-medium-emphasis">{{ t('pa.scales.empty') }}</VCard>
      </VCol>
      <VCol v-for="scale in props.scales" :key="scale.id" cols="12" md="6" lg="4">
        <VCard class="h-100">
          <VCardTitle class="d-flex align-center">
            <span>{{ scale.name }}</span>
            <VSpacer />
            <VChip v-if="scale.is_default" size="x-small" color="primary" class="mr-1">{{ t('pa.common.default_badge') }}</VChip>
            <VChip size="x-small" :color="scale.is_active ? 'success' : 'default'">
              {{ scale.is_active ? t('pa.common.active') : t('pa.common.inactive') }}
            </VChip>
          </VCardTitle>
          <VCardSubtitle v-if="scale.description">{{ scale.description }}</VCardSubtitle>
          <VCardText>
            <p class="text-caption text-medium-emphasis mb-2">{{ t('pa.scales.range') }}: {{ scale.min_score }} – {{ scale.max_score }}</p>

            <div class="d-flex flex-wrap gap-1 mb-3">
              <VChip
                v-for="v in scale.values"
                :key="v.id"
                size="small"
                :color="v.color ?? 'default'"
                label
                closable
                @click:close="deleteValue(scale, v)"
                @click="openValue(scale, v)"
              >
                {{ v.label }} ({{ v.value }})
              </VChip>
              <VChip size="small" variant="outlined" @click="openValue(scale)" prepend-icon="ri-add-line">
                {{ t('pa.scales.add_value') }}
              </VChip>
            </div>
          </VCardText>
          <VCardActions>
            <VBtn size="small" variant="text" @click="openScale(scale)">{{ t('pa.common.edit') }}</VBtn>
            <VBtn size="small" variant="text" color="error" @click="scaleDelItem = scale; scaleDelOpen = true">{{ t('pa.common.delete') }}</VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>

    <!-- Scale Drawer -->
    <VNavigationDrawer v-model="scaleDrawer" location="right" temporary width="420">
      <div class="pa-4 d-flex align-center border-b">
        <span class="text-h6">{{ scaleEditing?.id ? t('pa.scales.edit') : t('pa.scales.new') }}</span>
        <VSpacer />
        <VBtn icon="ri-close-line" variant="text" size="small" @click="scaleDrawer = false" />
      </div>
      <div class="pa-4" v-if="scaleEditing">
        <VTextField v-model="scaleEditing.name" :label="t('pa.scales.name')" class="mb-3" />
        <VTextField v-model="scaleEditing.description" :label="t('pa.cycles.description')" class="mb-3" />
        <VRow class="mb-3">
          <VCol cols="6"><VTextField v-model.number="scaleEditing.min_score" :label="t('pa.scales.min_score')" type="number" /></VCol>
          <VCol cols="6"><VTextField v-model.number="scaleEditing.max_score" :label="t('pa.scales.max_score')" type="number" /></VCol>
        </VRow>
        <VSwitch v-model="scaleEditing.is_default" :label="t('pa.scales.set_default')" inset color="primary" class="mb-1" />
        <VSwitch v-model="scaleEditing.is_active" :label="t('pa.common.active')" inset color="success" class="mb-4" />
        <div class="d-flex justify-end gap-2">
          <VBtn variant="text" @click="scaleDrawer = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="primary" @click="saveScale">{{ scaleEditing?.id ? t('pa.common.update') : t('pa.common.create') }}</VBtn>
        </div>
      </div>
    </VNavigationDrawer>

    <!-- Value Drawer -->
    <VNavigationDrawer v-model="valueDrawer" location="right" temporary width="380">
      <div class="pa-4 d-flex align-center border-b">
        <span class="text-h6">{{ valueEditing?.id ? t('pa.scales.edit_value') : t('pa.scales.add_value') }}</span>
        <VSpacer />
        <VBtn icon="ri-close-line" variant="text" size="small" @click="valueDrawer = false" />
      </div>
      <div class="pa-4" v-if="valueEditing">
        <VTextField v-model="valueEditing.label" :label="t('pa.scales.value_label')" class="mb-3" />
        <VTextField v-model.number="valueEditing.value" :label="t('pa.scales.value_numeric')" type="number" step="0.5" class="mb-3" />
        <VTextField v-model="valueEditing.description" :label="t('pa.cycles.description')" class="mb-3" />
        <VTextField v-model="valueEditing.color" :label="t('pa.scales.value_color')" class="mb-3" />
        <VTextField v-model.number="valueEditing.sort_order" :label="t('pa.templates.section.sort_order')" type="number" class="mb-4" />
        <div class="d-flex justify-end gap-2">
          <VBtn variant="text" @click="valueDrawer = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="primary" @click="saveValue">{{ valueEditing?.id ? t('pa.common.update') : t('pa.scales.add_value') }}</VBtn>
        </div>
      </div>
    </VNavigationDrawer>

    <!-- Delete Scale confirm -->
    <VDialog v-model="scaleDelOpen" max-width="380">
      <VCard v-if="scaleDelItem">
        <VCardTitle>{{ t('pa.scales.delete') }}</VCardTitle>
        <VCardText>{{ t('pa.cycles.delete_confirm') }} <strong>{{ scaleDelItem.name }}</strong>?</VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="scaleDelOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="error" @click="router.delete(route('performance.rating-scales.destroy', scaleDelItem.id), { onSuccess: () => { scaleDelOpen = false; scaleDelItem = null } })">{{ t('pa.common.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
