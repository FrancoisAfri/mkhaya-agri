<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { VForm } from 'vuetify/components/VForm'
import AppDrawerHeaderSection from '@/@core/components/AppDrawerHeaderSection.vue'
import type { HolidayProperties, BlankHolidayForm } from '../types/types'
import { useHolidayStore } from '../Store/holidaysStore'
import { useI18n } from "vue-i18n";

interface Props {
  isDrawerOpen: boolean
  countries:    { id: number; name: string }[]
  prefillDate?: string | null
}

const { t, locale } = useI18n()

const props  = defineProps<Props>()
const emit   = defineEmits<{ (e: 'update:isDrawerOpen', v: boolean): void }>()
const store  = useHolidayStore()
const refForm = ref<InstanceType<typeof VForm> | null>(null)
const loading = ref(false)

// ── Blank form ──
const blank = (): BlankHolidayForm => ({
  name:        '',
  isRecurring: true,
  year:        null,
  month:       '01',
  day:         '01',
  country_id:  null,
})

const form    = ref<BlankHolidayForm>(blank())
const editId  = ref<number | null>(null)
const isEdit  = computed(() => editId.value !== null)

// Month days lookup
const daysInMonth = computed(() => {
  const m   = parseInt(form.value.month)
  const max = [31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][m - 1] ?? 31
  return Array.from({ length: max }, (_, i) => String(i + 1).padStart(2, '0'))
})

const months = computed(() => {
  const _ = locale.value
  return [
    { value: '01', label: t('months_list.january') },
    { value: '02', label: t('months_list.february') },
    { value: '03', label: t('months_list.march') },
    { value: '04', label: t('months_list.april') },
    { value: '05', label: t('months_list.may') },
    { value: '06', label: t('months_list.june') },
    { value: '07', label: t('months_list.july') },
    { value: '08', label: t('months_list.august') },
    { value: '09', label: t('months_list.september') },
    { value: '10', label: t('months_list.october') },
    { value: '11', label: t('months_list.november') },
    { value: '12', label: t('months_list.december') },
  ]
})

const yearOptions = computed(() => {
  const cur = new Date().getFullYear()
  return Array.from({ length: 10 }, (_, i) => cur + i - 2)
})

// ── Open for edit ──
const openEdit = (h: HolidayProperties) => {
  editId.value         = h.id
  const [mm, dd]       = h.day.split('-')
  form.value           = {
    name:        h.name,
    isRecurring: h.year === null,
    year:        h.year,
    month:       mm,
    day:         dd,
    country_id:  h.country_id,
  }
  emit('update:isDrawerOpen', true)
}

// ── Open for new (optional prefill date) ──
const openNew = (date?: string | null) => {
  editId.value = null
  form.value   = blank()
  if (date) {
    const [, mm, dd] = date.split('-')
    form.value.month = mm
    form.value.day   = dd
  }
  emit('update:isDrawerOpen', true)
}

defineExpose({ openEdit, openNew })

// Reset when drawer closes
watch(() => props.isDrawerOpen, val => {
  if (!val) {
    editId.value = null
    form.value   = blank()
    refForm.value?.resetValidation()
  }
})

// ── Submit ──
const handleSubmit = async () => {
  const { valid } = await refForm.value!.validate()
  if (!valid) return

  const mmdd = `${form.value.month}-${form.value.day}`

  loading.value = true

  const payload = {
    name:       form.value.name,
    day:        mmdd,
    year:       form.value.isRecurring ? null : form.value.year,
    country_id: form.value.country_id,
  }

  if (isEdit.value) {
    router.put(`/settings/public-holidays/${editId.value}`, payload, {
      preserveScroll: true,
      onSuccess: (page) => {
        const fresh = (page.props.allHolidays as HolidayProperties[]).find(h => h.id === editId.value)
        if (fresh) store.updateHoliday(fresh)
        emit('update:isDrawerOpen', false)
      },
      onFinish: () => { loading.value = false },
    })
  } else {
    router.post('/settings/public-holidays/store', payload, {
      preserveScroll: true,
      onSuccess: (page) => {
        const all    = page.props.allHolidays as HolidayProperties[]
        const newest = all[all.length - 1]
        if (newest) store.addHoliday(newest)
        emit('update:isDrawerOpen', false)
      },
      onFinish: () => { loading.value = false },
    })
  }
}

// ── Delete ──
const handleDelete = () => {
  if (!editId.value) return
  loading.value = true

  router.delete(`/settings/public-holidays/${editId.value}`, {
    preserveScroll: true,
    onSuccess: () => {
      store.removeHoliday(editId.value!)
      emit('update:isDrawerOpen', false)
    },
    onFinish: () => { loading.value = false },
  })
}

const onClose = () => emit('update:isDrawerOpen', false)
</script>

<template>
  <VNavigationDrawer
    :model-value="props.isDrawerOpen"
    temporary
    location="end"
    width="380"
    :border="0"
    class="scrollable-content"
    @update:model-value="emit('update:isDrawerOpen', $event)"
  >
    <!-- Header -->
    <AppDrawerHeaderSection
      :title="isEdit ? t('public_holiday_page.edit_holiday') : t('public_holiday_page.add_holiday')"
      @cancel="onClose"
    >
      <template #beforeClose>
        <IconBtn v-if="isEdit" :disabled="loading" @click="handleDelete">
          <VIcon size="18" icon="bx-trash" />
        </IconBtn>
      </template>
    </AppDrawerHeaderSection>

    <VDivider />

    <div class="pa-5" style="overflow-y: auto; max-height: calc(100vh - 70px)">
      <VForm ref="refForm" @submit.prevent="handleSubmit">
        <VRow>

          <!-- Type toggle -->
          <VCol cols="12">
            <p class="text-body-2 font-weight-medium mb-2">{{ t('public_holiday_page.holiday_types') }} </p>
            <VBtnToggle
              v-model="form.isRecurring"
              mandatory
              density="compact"
              rounded="lg"
              class="w-100"
            >
              <VBtn :value="true" class="flex-1" prepend-icon="bx-repeat">
                {{ t('recurring') }}
              </VBtn>
              <VBtn :value="false" class="flex-1" prepend-icon="bx-calendar-event">
                {{ t('specific_year') }}
              </VBtn>
            </VBtnToggle>
            <p class="text-caption text-medium-emphasis mt-1">
              {{ form.isRecurring ? t('holiday_message.recurring') : t('holiday_message.specific') }}
            </p>
          </VCol>

          <!-- Year (only if specific) -->
          <VCol v-if="!form.isRecurring" cols="12">
            <VSelect
              v-model="form.year"
              :items="yearOptions"
              :label="t('month')"
              :placeholder="t('placelhoder.month')"
              :rules="[v => !!v || t('this_field_is_required')]"
              variant="outlined"
              density="compact"
            />
          </VCol>

          <!-- Holiday Name -->
          <VCol cols="12">
            <VTextField
              v-model="form.name"
              :label="t('public_holiday_page.holiday_name')"
              :placeholder="t('placelhoder.name')"
              :rules="[v => !!v || t('this_field_is_required')]"
              variant="outlined"
              density="compact"
            />
          </VCol>

          <!-- Month + Day -->
          <VCol cols="6">
            <VSelect
              v-model="form.month"
              :items="months"
              item-title="label"
              item-value="value"
              :label="t('placelhoder.month')"
              :rules="[v => !!v || t('this_field_is_required')]"
              variant="outlined"
              density="compact"
            />
          </VCol>

          <VCol cols="6">
            <VSelect
              v-model="form.day"
              :items="daysInMonth"
              :label="t('placelhoder.day')"
              :rules="[v => !!v || t('this_field_is_required')]"
              variant="outlined"
              density="compact"
            />
          </VCol>

          <!-- Country -->
          <VCol cols="12">
            <VSelect
              v-model="form.country_id"
              :items="countries"
              item-title="name"
              item-value="id"
              :label="t('country')"
              :placeholder="t('placeholder.country')"
              :rules="[v => !!v || t('this_field_is_required')]"
              variant="outlined"
              density="compact"
            />
          </VCol>

          <!-- Actions -->
          <VCol cols="12" class="d-flex gap-3 pt-2">
            <VBtn
              type="submit"
              :loading="loading"
              :prepend-icon="isEdit ? 'bx-save' : 'bx-plus'"
              class="flex-1"
            >
              {{ isEdit ? t('update') : t('public_holiday_page.add_holiday') }}
            </VBtn>
            <VBtn variant="outlined" color="secondary" @click="onClose">
              {{ t('cancel') }}
            </VBtn>
          </VCol>

        </VRow>
      </VForm>
    </div>
  </VNavigationDrawer>
</template>
