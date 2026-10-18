<template>
  <VApp>
    <VAppBar color="primary" density="compact" elevation="0" border>
      <VAppBarTitle>{{ t('fleet_mobile_inspection.page_title') }}</VAppBarTitle>
      <template #append>
        <VBtn icon="ri-check-line" :loading="saving" @click="submit" />
      </template>
    </VAppBar>

    <VMain class="bg-grey-lighten-4">
      <VContainer class="pa-3" style="max-width:600px;">

        <!-- GPS Status -->
        <VAlert
          :type="gpsReady ? 'success' : 'warning'"
          density="compact"
          class="mb-3"
          :text="gpsReady
            ? t('fleet_mobile_inspection.location', { lat: coords.latitude?.toFixed(5), lng: coords.longitude?.toFixed(5) })
            : locating ? t('fleet_mobile_inspection.getting_location') : t('fleet_mobile_inspection.no_gps')"
        />

        <!-- Odometer -->
        <VCard class="mb-3">
          <VCardTitle class="text-subtitle-1 pa-3 pb-0">{{ t('fleet_mobile_inspection.odometer_title') }}</VCardTitle>
          <VCardText>
            <VTextField
              v-model="form.odometer_reading"
              type="number"
              suffix="km"
              :label="t('fleet_mobile_inspection.odometer_label')"
              variant="outlined"
              density="compact"
              :error-messages="errors.odometer_reading"
              required
            />
          </VCardText>
        </VCard>

        <!-- Condition checklist -->
        <VCard class="mb-3">
          <VCardTitle class="text-subtitle-1 pa-3 pb-0">{{ t('fleet_mobile_inspection.condition_title') }}</VCardTitle>
          <VCardText>
            <VRow dense>
              <VCol v-for="item in conditionItems" :key="item.key" cols="6">
                <VCheckbox
                  v-model="form.condition[item.key]"
                  :label="item.label"
                  density="compact"
                  hide-details
                />
              </VCol>
            </VRow>
            <VTextField
              v-model="form.notes"
              :label="t('fleet_mobile_inspection.notes_label')"
              variant="outlined"
              density="compact"
              rows="2"
              auto-grow
              class="mt-3"
            />
          </VCardText>
        </VCard>

        <!-- Camera capture -->
        <VCard class="mb-3">
          <VCardTitle class="text-subtitle-1 pa-3 pb-0">
            {{ t('fleet_mobile_inspection.photos_title') }}
            <VChip size="x-small" class="ms-2" color="primary">{{ photos.length }}/6</VChip>
          </VCardTitle>
          <VCardText>
            <div class="d-flex flex-wrap gap-2 mb-3">
              <div
                v-for="(photo, i) in photos"
                :key="i"
                class="position-relative"
                style="width:80px;height:80px;"
              >
                <img
                  :src="photo.preview"
                  alt="inspection photo"
                  style="width:80px;height:80px;object-fit:cover;border-radius:8px;"
                />
                <VBtn
                  icon="ri-close-line"
                  size="x-small"
                  color="error"
                  class="position-absolute"
                  style="top:-4px;right:-4px;"
                  @click="removePhoto(i)"
                />
              </div>

              <label
                v-if="photos.length < 6"
                class="d-flex align-center justify-center rounded-lg border-dashed"
                style="width:80px;height:80px;cursor:pointer;border:2px dashed #bbb;"
              >
                <VIcon icon="ri-camera-line" size="28" color="grey-darken-1" />
                <input
                  type="file"
                  accept="image/*"
                  capture="environment"
                  class="d-none"
                  @change="addPhoto"
                />
              </label>
            </div>

            <p class="text-caption text-medium-emphasis">
              {{ t('fleet_mobile_inspection.photos_hint') }}
            </p>
          </VCardText>
        </VCard>

        <!-- Submit -->
        <VBtn
          block
          color="primary"
          size="large"
          :loading="saving"
          :disabled="!form.odometer_reading"
          @click="submit"
        >
          {{ t('fleet_mobile_inspection.submit') }}
        </VBtn>

      </VContainer>
    </VMain>

    <VSnackbar v-model="snack.show" :color="snack.color" timeout="3000">
      {{ snack.text }}
    </VSnackbar>
  </VApp>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import axios from 'axios'
import { useGeolocation } from '@vueuse/core'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{ bookingId: number }>()

// ── GPS ──────────────────────────────────────────────────────────────────────
const { coords } = useGeolocation({ immediate: true })

const locating = ref(true)
const gpsReady = computed(() =>
  coords.value.latitude !== Infinity && coords.value.latitude !== null
)

// Arrêter le loading GPS après 5 secondes max
setTimeout(() => { locating.value = false }, 5000)

// Détecter quand les coordonnées arrivent
const stopWatch = watch(coords, (val) => {
  if (val.latitude !== Infinity && val.latitude !== null) {
    locating.value = false
    stopWatch()
  }
})

// ── Condition checklist ──────────────────────────────────────────────────────
const conditionItems = computed(() => [
  { key: 'lights_ok',         label: t('fleet_mobile_inspection.checks.lights_ok') },
  { key: 'tyres_ok',          label: t('fleet_mobile_inspection.checks.tyres_ok') },
  { key: 'wipers_ok',         label: t('fleet_mobile_inspection.checks.wipers_ok') },
  { key: 'fuel_ok',           label: t('fleet_mobile_inspection.checks.fuel_ok') },
  { key: 'brakes_ok',         label: t('fleet_mobile_inspection.checks.brakes_ok') },
  { key: 'spare_present',     label: t('fleet_mobile_inspection.checks.spare_present') },
  { key: 'jack_present',      label: t('fleet_mobile_inspection.checks.jack_present') },
  { key: 'documents_present', label: t('fleet_mobile_inspection.checks.documents_present') },
])

// ── Form ─────────────────────────────────────────────────────────────────────
const form = reactive({
  odometer_reading: '',
  notes: '',
  condition: {
    lights_ok: true,
    tyres_ok: true,
    wipers_ok: true,
    fuel_ok: true,
    brakes_ok: true,
    spare_present: true,
    jack_present: true,
    documents_present: true,
  } as Record<string, boolean>,
})

const errors = reactive({
  odometer_reading: '',
})

// ── Photos ────────────────────────────────────────────────────────────────────
interface Photo { file: File; preview: string }
const photos = ref<Photo[]>([])

function addPhoto(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  if (photos.value.length >= 6) return
  photos.value.push({ file, preview: URL.createObjectURL(file) })
  input.value = ''
}

function removePhoto(index: number) {
  URL.revokeObjectURL(photos.value[index].preview)
  photos.value.splice(index, 1)
}

// ── Submit ────────────────────────────────────────────────────────────────────
const saving = ref(false)
const snack = reactive({ show: false, text: '', color: 'success' })

function validate(): boolean {
  errors.odometer_reading = ''
  if (!form.odometer_reading) {
    errors.odometer_reading = t('fleet_mobile_inspection.errors.odometer_required')
    return false
  }
  if (Number(form.odometer_reading) <= 0) {
    errors.odometer_reading = t('fleet_mobile_inspection.errors.odometer_invalid')
    return false
  }
  return true
}

async function submit() {
  if (!validate()) return

  saving.value = true
  try {
    const fd = new FormData()
    fd.append('odometer_reading', form.odometer_reading)
    fd.append('notes', form.notes)
    fd.append('condition', JSON.stringify(form.condition))
    if (gpsReady.value) {
      fd.append('latitude',  String(coords.value.latitude))
      fd.append('longitude', String(coords.value.longitude))
    }
    photos.value.forEach((p, i) => fd.append(`photos[${i}]`, p.file))

    await axios.post(`/fleet/bookings/${props.bookingId}/inspections`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })

    snack.text = t('fleet_mobile_inspection.success')
    snack.color = 'success'
    snack.show = true

    setTimeout(() => window.history.back(), 1500)
  } catch (err: any) {
    snack.text = err.response?.data?.message ?? t('fleet_mobile_inspection.failed')
    snack.color = 'error'
    snack.show = true
  } finally {
    saving.value = false
  }
}
</script>
