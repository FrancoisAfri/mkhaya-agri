<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import FullCalendar from '@fullcalendar/vue3'
import dayGridPlugin from '@fullcalendar/daygrid'
import interactionPlugin from '@fullcalendar/interaction'
import listPlugin from '@fullcalendar/list'
import type { CalendarOptions, EventClickArg } from '@fullcalendar/core'
import { useHolidayStore } from './Store/holidaysStore'
import HolidayEventHandler from './partials/HolidayEventHandler.vue'
import type { HolidayProperties } from './types/types'
import AppDateTimePicker from '@/@core/components/app-form-elements/AppDateTimePicker.vue'
import { useResponsiveLeftSidebar } from '@/@core/composable/useResponsiveSidebar'
import { useI18n } from 'vue-i18n'


// ── Props ──────────────────────────────────────────────────────────────────
const props = defineProps<{
  allHolidays: HolidayProperties[]
  countries:   { id: number; name: string }[]
}>()
const { t } = useI18n()

// ── Store ──────────────────────────────────────────────────────────────────
const store = useHolidayStore()
watch(() => props.allHolidays, v => store.setHolidays(v), { immediate: true })

// ── Sidebar state ──────────────────────────────────────────────────────────
const { isLeftSidebarOpen } = useResponsiveLeftSidebar()
const isHandlerOpen = ref(false)
const handlerRef    = ref<InstanceType<typeof HolidayEventHandler> | null>(null)

// ── Calendar ref ───────────────────────────────────────────────────────────
const refCalendar = ref()

// ── Event click → open edit drawer ────────────────────────────────────────
const onEventClick = (info: EventClickArg) => {
  info.jsEvent.preventDefault()
  handlerRef.value?.openEdit(info.event.extendedProps.raw as HolidayProperties)
  isHandlerOpen.value = true
}

// ── Date click → open add drawer with prefilled date ──────────────────────
const onDateClick = (info: { dateStr: string }) => {
  handlerRef.value?.openNew(info.dateStr)
  isHandlerOpen.value = true
}

// ── Country filter ─────────────────────────────────────────────────────────
const selectedCountryId = computed({
  get: () => store.selectedCountryId,
  set: v  => { store.selectedCountryId = v },
})

// ── Type filter ────────────────────────────────────────────────────────────
const allTypesChecked = computed({
  get: () => store.selectedTypes.length === 2,
  set: v  => { store.selectedTypes = v ? ['recurring', 'specific'] : [] },
})

// ── Jump to date ───────────────────────────────────────────────────────────
const jumpToDate = (date: string) => {
  refCalendar.value?.getApi().gotoDate(date)
}

// ── FullCalendar options ───────────────────────────────────────────────────
const calendarOptions = computed<CalendarOptions>(() => ({
  plugins:    [dayGridPlugin, interactionPlugin, listPlugin],
  initialView: 'dayGridMonth',

  headerToolbar: {
    start:  'drawerToggler,prev,next,today',
    center: 'title',
    end:    'dayGridMonth,listMonth',
  },

  events:      store.calendarEvents,
  editable:    false,
  selectable:  false,
  dayMaxEvents: 3,
  navLinks:    true,
  eventClick:  onEventClick,
  dateClick:   onDateClick,

  customButtons: {
    drawerToggler: {
      text:  '☰',
      click: () => { isLeftSidebarOpen.value = !isLeftSidebarOpen.value },
    },
  },

  eventDidMount(info) {
    const type = info.event.extendedProps.type as string
    const label = type === 'recurring'
      ? 'Recurring every year'
      : `${info.event.start?.getFullYear()} only`
    info.el.title = `${info.event.title} — ${label}`
  },
}))
</script>

<template>
  <div class="core-holidays-index p-3">
    <!-- Page header -->
    <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4 px-1 ">
      <div>
        <h3 class="text-h5 font-weight-semibold mb-1">{{ t('public_holiday_page.title') }}</h3>
        <p class="text-medium-emphasis mb-0 text-body-2">
          {{ t('public_holiday_page.subtitle') }}
        </p>
      </div>
      <VBreadcrumbs
        :items="[
          { title: t('home'), href: '/', disabled: false },
          { title: t('settings'), disabled: false },
          { title: t('public_holiday_page.title'), disabled: true },
        ]"
        density="compact"
        class="pa-0"
      >
        <template #divider>
          <i class="fa-solid fa-chevron-right" style="font-size:11px" />
        </template>
      </VBreadcrumbs>
    </div>

    <!-- Calendar card -->
    <VCard>
      <VLayout style="z-index: 0">

        <!-- Left sidebar -->
        <VNavigationDrawer
          v-model="isLeftSidebarOpen"
          width="280"
          absolute
          touchless
          location="start"
          class="calendar-add-event-drawer"
          :temporary="$vuetify.display.mdAndDown"
        >
          <div class="pa-5 pb-3">
            <VBtn
              block
              prepend-icon="bx-plus"
              @click="() => { handlerRef?.openNew(); isHandlerOpen = true }"
            >
              {{ t('public_holiday_page.add_holiday') }}
            </VBtn>
          </div>

          <VDivider />

          <!-- Inline date picker -->
          <div class="d-flex align-center justify-center pa-2">
            <AppDateTimePicker
              id="holiday-date-picker"
              :model-value="new Date().toJSON().slice(0, 10)"
              :config="{ inline: true }"
              class="calendar-date-picker"
              @update:model-value="jumpToDate"
            />
          </div>

          <VDivider />

          <!-- Country filter -->
          <div class="pa-5 pb-4">
            <p class="text-overline text-medium-emphasis mb-3">{{ t('public_holiday_page.country_filter') }}</p>
            <VSelect
              v-model="selectedCountryId"
              :items="[{ id: null, name: 'All Countries' }, ...countries]"
              item-title="name"
              item-value="id"
              density="compact"
              variant="outlined"
              hide-details
              clearable
              placeholder="All Countries"
            />
          </div>

          <VDivider />

          <!-- Type filter -->
          <div class="pa-5">
            <p class="text-overline text-medium-emphasis mb-3">{{ t('public_holiday_page.holiday_types') }}</p>

            <div class="d-flex flex-column gap-y-1 calendars-checkbox">
              <VCheckbox
                v-model="allTypesChecked"
                label="View all"
                hide-details
                density="compact"
              />
              <VCheckbox
                v-model="store.selectedTypes"
                value="recurring"
                color="success"
                hide-details
                density="compact"
              >
                <template #label>
                  <div class="d-flex align-center gap-2">
                    <span
                      class="rounded-circle d-inline-block"
                      style="width:10px;height:10px;background:#10b981;flex-shrink:0"
                    />
                    <span>{{ t('public_holiday_page.recurring') }}</span>
                  </div>
                </template>
              </VCheckbox>
              <VCheckbox
                v-model="store.selectedTypes"
                value="specific"
                color="warning"
                hide-details
                density="compact"
              >
                <template #label>
                  <div class="d-flex align-center gap-2">
                    <span
                      class="rounded-circle d-inline-block"
                      style="width:10px;height:10px;background:#f59e0b;flex-shrink:0"
                    />
                    <span>{{ t('public_holiday_page.specific') }}</span>
                  </div>
                </template>
              </VCheckbox>
            </div>

            <div
              class="mt-4 rounded-lg pa-3"
              style="border:1px solid rgba(var(--v-border-color),var(--v-border-opacity));background:rgba(var(--v-theme-surface-variant),0.4)"
            >
              <p class="text-caption font-weight-semibold mb-2">{{ t('public_holiday_page.legend') }}</p>
              <div class="d-flex align-center gap-2 mb-1">
                <span class="rounded-circle" style="width:10px;height:10px;background:#10b981;display:inline-block;flex-shrink:0" />
                <span class="text-caption text-medium-emphasis">{{ t('public_holiday_page.recurring_legend') }}</span>
              </div>
              <div class="d-flex align-center gap-2">
                <span class="rounded-circle" style="width:10px;height:10px;background:#f59e0b;display:inline-block;flex-shrink:0" />
                <span class="text-caption text-medium-emphasis">{{ t('public_holiday_page.specific_legend') }}</span>
              </div>
            </div>
          </div>
        </VNavigationDrawer>

        <!-- Main calendar area -->
        <VMain>
          <VCard flat>
            <FullCalendar ref="refCalendar" :options="calendarOptions" />
          </VCard>
        </VMain>

      </VLayout>
    </VCard>

    <!-- Add / Edit drawer -->
    <HolidayEventHandler
      ref="handlerRef"
      v-model:is-drawer-open="isHandlerOpen"
      :countries="countries"
    />

    <!-- Snackbar -->
    <VSnackbar
      v-model="store.snackbar"
      :color="store.snackColor"
      location="bottom end"
      :timeout="3000"
    >
      <div class="d-flex align-center gap-2">
        <i :class="store.snackColor === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'" />
        {{ store.snackMsg }}
      </div>
    </VSnackbar>
  </div>
</template>

<style lang="scss">
@use "@core/scss/template/libs/full-calendar";

.calendars-checkbox {
  .v-label {
    color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity));
    opacity: var(--v-high-emphasis-opacity);
  }
}

.calendar-add-event-drawer {
  &.v-navigation-drawer:not(.v-navigation-drawer--temporary) {
    border-end-start-radius: 0.375rem;
    border-start-start-radius: 0.375rem;
  }
  &.v-navigation-drawer--temporary:not(.v-navigation-drawer--active) {
    transform: translateX(-110%) !important;
  }
}

.calendar-date-picker {
  display: none;

  + .flatpickr-input + .flatpickr-calendar.inline {
    border: none;
    box-shadow: none;
    .flatpickr-months { border-block-end: none; }
  }
  & ~ .flatpickr-calendar .flatpickr-weekdays {
    margin-block: 0 4px;
  }
}

@media screen and (max-width: 1279px) {
  .calendar-add-event-drawer { border-width: 0; }
}
</style>

<style lang="scss" scoped>
.v-layout {
  overflow: visible !important;
  .v-card { overflow: visible; }
}
</style>
