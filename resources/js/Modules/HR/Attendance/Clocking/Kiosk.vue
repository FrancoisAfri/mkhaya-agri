<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type ClockingEvent = {
  id: number
  type: string
  time: string
  source: string
}

type Employee = {
  id: number
  full_name: string
  avatar_url: string | null
} | null

defineProps<{
  employee: Employee
  today: string
  clockState: string | null
  openBreak: any
  todayClockings: ClockingEvent[]
}>()

const employeeCode = ref('')
const clocking     = ref(false)
const actionType   = ref<'clock-in' | 'clock-out' | 'break'>('clock-in')

function doAction(type: 'clock-in' | 'clock-out' | 'break') {
  clocking.value = true
  actionType.value = type
  const routeMap = {
    'clock-in':  'attendance.clocking.clock-in',
    'clock-out': 'attendance.clocking.clock-out',
    'break':     'attendance.clocking.break',
  }
  router.post(route(routeMap[type]), { employee_code: employeeCode.value }, {
    onFinish: () => { clocking.value = false },
  })
}

const clockStateColor = (s: string | null) => s === 'clocked_in' ? 'success' : s === 'on_break' ? 'warning' : 'default'
const clockStateLabel = (s: string | null) => {
  if (s === 'clocked_in') return 'Clocked In'
  if (s === 'on_break')   return 'On Break'
  if (s === 'clocked_out')return 'Clocked Out'
  return 'Not Clocked In'
}
</script>

<template>
  <VContainer class="d-flex flex-column align-center justify-center pa-6" style="min-height: 80vh; max-width: 600px;">

    <div class="text-center mb-6 w-100">
      <h4 class="text-h4 mb-1">Attendance Kiosk</h4>
      <p class="text-medium-emphasis">{{ today }}</p>
    </div>

    <!-- Employee Identified -->
    <template v-if="employee">
      <VCard elevation="0" border class="w-100 mb-5">
        <VCardText class="pa-6 text-center">
          <VAvatar color="primary" size="72" class="mb-3">
            <VImg v-if="employee.avatar_url" :src="employee.avatar_url" />
            <VIcon v-else icon="tabler-user" size="36" />
          </VAvatar>
          <h5 class="text-h5 mb-1">{{ employee.full_name }}</h5>
          <VChip :color="clockStateColor(clockState)" variant="tonal">{{ clockStateLabel(clockState) }}</VChip>
        </VCardText>
      </VCard>

      <VRow class="w-100 mb-5">
        <VCol cols="12" v-if="clockState !== 'clocked_in' && clockState !== 'on_break'">
          <VBtn block color="success" size="x-large" prepend-icon="tabler-clock-play" :loading="clocking && actionType === 'clock-in'" @click="doAction('clock-in')">
            Clock In
          </VBtn>
        </VCol>
        <VCol cols="12" v-if="clockState === 'clocked_in'">
          <VBtn block color="warning" size="x-large" prepend-icon="tabler-coffee" :loading="clocking && actionType === 'break'" @click="doAction('break')">
            Start Break
          </VBtn>
        </VCol>
        <VCol cols="12" v-if="clockState === 'on_break'">
          <VBtn block color="info" size="x-large" prepend-icon="tabler-coffee-off" :loading="clocking && actionType === 'break'" @click="doAction('break')">
            End Break
          </VBtn>
        </VCol>
        <VCol cols="12" v-if="clockState === 'clocked_in' || clockState === 'on_break'">
          <VBtn block color="error" size="x-large" prepend-icon="tabler-clock-stop" :loading="clocking && actionType === 'clock-out'" @click="doAction('clock-out')">
            Clock Out
          </VBtn>
        </VCol>
      </VRow>

      <!-- Today's events -->
      <VCard v-if="todayClockings.length" elevation="0" border class="w-100">
        <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Today's Events</VCardTitle>
        <VDivider />
        <VList density="compact">
          <VListItem v-for="c in todayClockings" :key="c.id" :title="c.time" :subtitle="c.source">
            <template #prepend>
              <VChip :color="c.type === 'clock_in' ? 'success' : c.type === 'clock_out' ? 'error' : 'warning'" size="x-small" variant="tonal" class="me-3 text-capitalize">
                {{ c.type.replace('_', ' ') }}
              </VChip>
            </template>
          </VListItem>
        </VList>
      </VCard>
    </template>

    <!-- Employee Lookup -->
    <template v-else>
      <VCard elevation="0" border class="w-100">
        <VCardText class="pa-6">
          <VTextField
            v-model="employeeCode"
            label="Employee Code / Badge Number"
            variant="outlined"
            density="comfortable"
            prepend-inner-icon="tabler-id-badge"
            autofocus
            @keydown.enter="router.get(route('attendance.clocking.kiosk'), { code: employeeCode })"
          />
          <VBtn
            block
            color="primary"
            size="large"
            class="mt-3"
            :disabled="!employeeCode"
            @click="router.get(route('attendance.clocking.kiosk'), { code: employeeCode })"
          >
            Identify Employee
          </VBtn>
        </VCardText>
      </VCard>
    </template>

  </VContainer>
</template>
