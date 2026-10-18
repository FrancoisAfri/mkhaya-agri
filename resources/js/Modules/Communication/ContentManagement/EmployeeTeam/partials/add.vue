<script setup lang="ts">
import { watch, computed, nextTick } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useSpotlightStore } from '../Store/store'
import { useSpotlightForm, typeItems, type UnitItem } from '../Store/Usespotlightform'
import { useI18n } from 'vue-i18n'
import type { UserProperties } from '../Store/type'

const { t } = useI18n()

interface GroupedUnits {
  divisions?:   UnitItem[]
  departments?: UnitItem[]
  teams?:       UnitItem[]
  sections?:    UnitItem[]
  units?:       UnitItem[]
}

const props = defineProps<{
  companyUnits?: GroupedUnits | null
  employees?:    UserProperties[]
}>()

const model  = defineModel<boolean>('isDrawerOpen')
const store  = useSpotlightStore()
const page   = usePage()
const errors = computed(() => page.props.errors as Record<string, string>)

const flatUnits = computed<UnitItem[]>(() => [
  ...(props.companyUnits?.divisions   ?? []),
  ...(props.companyUnits?.departments ?? []),
  ...(props.companyUnits?.teams       ?? []),
  ...(props.companyUnits?.sections    ?? []),
  ...(props.companyUnits?.units       ?? []),
])

const {
  title, description, type, month, employeeId,
  imageFile, imagePreview,
  levels, selectedUnitPath,
  isAnniversary, maxMonth, employeeItems, canSubmit,
  onLevelSelect, handleImage, removeImage,
  reset, buildFormData,
} = useSpotlightForm(
  () => flatUnits.value,
  () => props.employees ?? [],
)

function getLevelTypeConfig(level: { children: UnitItem[] }) {
  const t = level.children[0]?.type ?? ''
  const map: Record<string, { label: string; mdiIcon: string; color: string }> = {
    division:   { label: 'Division',   mdiIcon: 'tabler-building', color: '#6366f1' },
    department: { label: 'Department', mdiIcon: 'tabler-sitemap',         color: '#0d9488' },
    team:       { label: 'Team',       mdiIcon: 'tabler-users',   color: '#f59e0b' },
    section:    { label: 'Section',    mdiIcon: 'tabler-layout-dashboard',  color: '#ec4899' },
    unit:       { label: 'Unit',       mdiIcon: 'tabler-cube',    color: '#64748b' },
  }
  return map[t] ?? { label: t || 'Unit', mdiIcon: 'tabler-cube', color: '#64748b' }
}

watch(model, (open) => {
  if (!open && !store.isLoading) nextTick(() => reset())
})

function submit() {
  store.store(buildFormData('add'), {
    onSuccess: () => {
      model.value = false
      nextTick(() => reset())
    },
  })
}
</script>

<template>
  <VNavigationDrawer v-model="model" temporary location="end" width="440">
    <div class="d-flex align-center justify-space-between px-5 py-4"
      style="border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
      <div class="d-flex align-center gap-3">
        <div class="d-flex align-center justify-center rounded-lg flex-shrink-0"
          style="width:40px;height:40px;background:#fef9ee">
          <VIcon icon="tabler-star" color="warning" size="20" />
        </div>
        <div>
          <p class="text-body-1 font-weight-semibold mb-0">{{ t('spotlight_add.title') }}</p>
          <p class="text-caption text-medium-emphasis mb-0">{{ t('spotlight_add.subtitle') }}</p>
        </div>
      </div>
      <VBtn icon="tabler-x" variant="text" size="small" :disabled="store.isLoading" @click="model = false" />
    </div>

    <div class="pa-5 d-flex flex-column gap-4" style="overflow-y:auto">
      <div>
        <p class="text-caption font-weight-semibold text-medium-emphasis text-uppercase mb-2" style="letter-spacing:.5px">
          {{ t('spotlight_add.type_label') }}
        </p>
        <div class="d-flex gap-2">
          <VCard
            v-for="te in typeItems" :key="te.value"
            :variant="type === te.value ? 'tonal' : 'outlined'"
            :color="type === te.value ? te.color : 'default'"
            class="flex-1 pa-3 text-center"
            :style="{ cursor: store.isLoading ? 'not-allowed' : 'pointer', opacity: store.isLoading ? 0.6 : 1 }"
            @click="!store.isLoading && (type = te.value)"
          >
            <VIcon :icon="te.icon" :color="type === te.value ? te.color : ''" size="20" />
            <p class="text-caption font-weight-medium mb-0 mt-1">{{ t(`spotlight_add.type_items.${te.title.toLowerCase()}`) }}</p>
          </VCard>
        </div>
      </div>

      <VTextField
        v-model="title" :label="t('spotlight_add.title_label')" variant="outlined" density="compact"
        :placeholder="t('spotlight_add.title_placeholder')"
        prepend-inner-icon="tabler-text-size"
        :error-messages="errors.title"
        :disabled="store.isLoading"
      />

      <VTextField
        v-model="month" :label="t('spotlight_add.month_label')" type="date" variant="outlined" density="compact"
        :max="maxMonth" prepend-inner-icon="tabler-calendar"
        :error-messages="errors.month"
        :disabled="store.isLoading"
      />

      <VAutocomplete
        v-model="employeeId" :items="employeeItems"
        :label="isAnniversary ? t('spotlight_add.employee_required') : t('spotlight_add.employee_label')"
        variant="outlined" density="compact" prepend-inner-icon="tabler-user"
        clearable :placeholder="t('spotlight_add.employee_placeholder')"
        :error-messages="errors.employee_id"
        :disabled="store.isLoading"
      />

      <template v-if="!isAnniversary">
        <p class="text-caption font-weight-semibold text-medium-emphasis text-uppercase mb-0" style="letter-spacing:.5px">
          {{ t('spotlight_add.unit_label') }}
        </p>
        <template v-if="levels.length > 0">
          <div v-for="(level, index) in levels" :key="index">
            <div class="d-flex align-center gap-2 mb-1">
              <i v-if="index > 0" class="fa-solid fa-arrow-turn-down-right"
                style="font-size:10px;color:#94a3b8;margin-left:4px" />
              <span class="text-caption font-weight-medium" :style="{ color: getLevelTypeConfig(level).color }">
                {{ getLevelTypeConfig(level).label }}
              </span>
            </div>
            <VSelect
              :model-value="level.selectedId" :items="level.children"
              item-title="name" item-value="id"
              :label="t('spotlight_add.select_unit', { level: getLevelTypeConfig(level).label })"
              :prepend-inner-icon="getLevelTypeConfig(level).mdiIcon"
              variant="outlined" density="compact" clearable
              :disabled="store.isLoading"
              @update:model-value="onLevelSelect(index, $event)"
            />
          </div>
        </template>
        <p v-else class="text-caption text-medium-emphasis">{{ t('spotlight_add.no_units') }}</p>
        <p v-if="selectedUnitPath" class="text-caption text-primary mt-n2">
          <VIcon icon="tabler-sitemap" size="12" class="me-1" />{{ selectedUnitPath }}
        </p>
      </template>

      <div>
        <p class="text-caption font-weight-semibold text-medium-emphasis text-uppercase mb-2" style="letter-spacing:.5px">
          {{ t('spotlight_add.description_label') }}
        </p>
        <VTextarea
          v-model="description" variant="outlined" density="compact" rows="4"
          :placeholder="t('spotlight_add.description_placeholder')"
          :error-messages="errors.description"
          :disabled="store.isLoading"
        />
      </div>

      <div>
        <p class="text-caption font-weight-semibold text-medium-emphasis text-uppercase mb-2" style="letter-spacing:.5px">
          {{ t('spotlight_add.photo_label') }}
        </p>
        <template v-if="!imagePreview">
          <label
            class="spotlight-drop-zone d-flex flex-column align-center justify-center gap-2 rounded-lg pa-6"
            :style="{ cursor: store.isLoading ? 'not-allowed' : 'pointer', opacity: store.isLoading ? 0.5 : 1 }"
          >
            <VIcon icon="tabler-photo-plus" size="32" style="opacity:.35" />
            <span class="text-caption text-medium-emphasis">{{ t('spotlight_add.photo_formats') }}</span>
            <input type="file" accept=".jpg,.jpeg,.png,.webp" class="sr-only"
              :disabled="store.isLoading" @change="handleImage" />
          </label>
        </template>
        <template v-else>
          <div class="rounded-lg overflow-hidden position-relative">
            <img :src="imagePreview" alt="Preview" style="width:100%;max-height:200px;object-fit:cover" />
            <div class="position-absolute" style="bottom:8px;right:8px;display:flex;gap:6px">
              <label>
                <VBtn size="small" color="white" variant="flat" :disabled="store.isLoading">
                  <VIcon icon="tabler-pencil" size="14" class="me-1" />{{ t('spotlight_add.change') }}
                </VBtn>
                <input type="file" accept=".jpg,.jpeg,.png,.webp" class="sr-only"
                  :disabled="store.isLoading" @change="handleImage" />
              </label>
              <VBtn size="small" color="error" variant="flat"
                :disabled="store.isLoading" @click="removeImage">
                <VIcon icon="tabler-trash" size="14" class="me-1" />{{ t('spotlight_add.remove') }}
              </VBtn>
            </div>
          </div>
        </template>
      </div>
    </div>

    <template #append>
      <div class="d-flex gap-3 pa-5"
        style="border-top:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
        <VBtn variant="tonal" color="secondary" class="flex-grow-1"
          :disabled="store.isLoading" @click="model = false">
          {{ t('spotlight_add.cancel') }}
        </VBtn>
        <VBtn color="warning" class="flex-grow-1"
          :disabled="!canSubmit" :loading="store.isLoading" @click="submit">
          <template #loader>
            <VProgressCircular indeterminate size="16" width="2" color="white" class="me-2" />
            <span>{{ t('spotlight_add.saving') }}</span>
          </template>
          <VIcon icon="tabler-star-plus" size="16" class="me-2" />{{ t('spotlight_add.publish') }}
        </VBtn>
      </div>
    </template>
  </VNavigationDrawer>
</template>

<style scoped>
.spotlight-drop-zone { border: 2px dashed rgba(var(--v-border-color), 0.45); transition: border-color .2s; }
.spotlight-drop-zone:hover { border-color: rgb(var(--v-theme-warning)); }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0,0,0,0); }
</style>
