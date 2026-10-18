<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'

type Person = {
  id: number
  name: string
  job_title: string
  department: string | null
  email: string
  avatar: string | null
  start_date: string | null
}

type LeaveBalance = {
  type_id: number
  type_name: string
  color: string
  balance: number | null
  taken: number
}

type PendingLeave = {
  id: number
  type: string | null
  color: string
  start_date: string | null
  end_date: string | null
  days: number
  status: string
}

type Payslip = {
  id: number
  uuid: string
  period_start: string | null
  period_end: string | null
  net_pay: number
  status: string
  run_name: string | null
}

type Ticket = {
  id: number
  ticket_number: string
  subject: string
  status: string | null
  status_color: string
  created_at: string
}

type Announcement = {
  id: number
  title: string
  summary: string
  created_at: string
}

type Asset = {
  id: number
  name: string
  asset_tag: string | null
  assigned_at: string | null
}

const props = defineProps<{
  person: Person | null
  leave_balances: LeaveBalance[]
  pending_leaves: PendingLeave[]
  recent_payslips: Payslip[]
  open_tickets: Ticket[]
  announcements: Announcement[]
  assigned_assets: Asset[]
}>()

// ── Helpers ───────────────────────────────────────────────────────────────────

function fmtDate(d: string | null): string {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}

function fmtCurrency(n: number): string {
  return new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR', minimumFractionDigits: 2 }).format(n)
}

function leaveUsedPct(b: LeaveBalance): number {
  const total = (b.balance ?? 0) + b.taken
  if (total === 0) return 0
  return Math.min(100, Math.round((b.taken / total) * 100))
}

const leaveStatusColor: Record<string, string> = {
  pending:  'warning',
  approved: 'success',
  rejected: 'error',
  cancelled:'default',
}

const initials = computed(() => {
  if (!props.person) return '?'
  return props.person.name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase()
})
</script>

<template>
  <div>

    <!-- ── Hero / Profile card ──────────────────────────────────────────────── -->
    <VCard flat class="mb-6" style="background:linear-gradient(135deg,#7367f0 0%,#9e95f5 100%);border-radius:12px;overflow:hidden;">
      <VCardText class="pa-8">
        <VRow align="center">
          <VCol cols="12" md="auto">
            <VAvatar size="80" color="white" style="border:3px solid rgba(255,255,255,0.5)">
              <img v-if="person?.avatar" :src="person.avatar" alt="Avatar" style="width:100%;height:100%;object-fit:cover;" />
              <span v-else class="text-h5 font-weight-bold" style="color:#7367f0">{{ initials }}</span>
            </VAvatar>
          </VCol>
          <VCol cols="12" md="auto">
            <h2 class="text-h4 font-weight-bold text-white mb-1">{{ person?.name ?? 'Welcome' }}</h2>
            <p class="mb-0 text-white" style="opacity:.9">{{ person?.job_title }}</p>
            <p v-if="person?.department" class="mb-0 text-white text-body-2" style="opacity:.75">
              <VIcon icon="tabler-building" size="14" class="me-1" />{{ person.department }}
            </p>
          </VCol>
          <VSpacer />
          <VCol cols="12" md="auto" class="d-flex flex-wrap gap-2 justify-end">
            <VBtn color="white" variant="elevated" prepend-icon="tabler-calendar-plus"
              style="color:#7367f0;font-weight:600"
              :href="route('hr.leave.create')">
              Apply for Leave
            </VBtn>
            <VBtn color="white" variant="outlined" prepend-icon="tabler-ticket"
              class="text-white"
              :href="route('helpdesk.tickets.create')">
              Raise a Ticket
            </VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VRow>

      <!-- ── Leave Balances ──────────────────────────────────────────────────── -->
      <VCol cols="12">
        <VCard elevation="0" border>
          <VCardItem class="pa-5 pb-0">
            <VCardTitle class="text-h6 font-weight-semibold">Leave Balances</VCardTitle>
            <template #append>
              <VBtn variant="text" size="small" color="primary" :href="route('hr.leave.index')">
                View all leave
                <VIcon icon="tabler-arrow-right" size="16" class="ms-1" />
              </VBtn>
            </template>
          </VCardItem>

          <VCardText class="pa-5">
            <VRow v-if="leave_balances.length">
              <VCol
                v-for="lb in leave_balances"
                :key="lb.type_id"
                cols="6" sm="4" md="3" lg="2"
              >
                <div class="text-center">
                  <VProgressCircular
                    :model-value="leaveUsedPct(lb)"
                    :color="lb.color"
                    size="72"
                    width="6"
                    class="mb-2"
                  >
                    <div>
                      <p class="text-h6 font-weight-bold mb-0" :style="{ color: lb.color }">
                        {{ lb.balance !== null ? lb.balance : '—' }}
                      </p>
                      <p class="text-caption text-disabled mb-0" style="font-size:9px">days</p>
                    </div>
                  </VProgressCircular>
                  <p class="text-caption font-weight-medium mb-0 text-truncate" style="max-width:100px;margin:0 auto">
                    {{ lb.type_name }}
                  </p>
                  <p class="text-caption text-disabled mb-0">{{ lb.taken }} taken</p>
                </div>
              </VCol>
            </VRow>
            <div v-else class="text-center text-medium-emphasis py-6">
              <VIcon icon="tabler-calendar-off" size="40" class="mb-2 text-disabled d-block mx-auto" />
              <p class="text-body-2">No leave balances found. Contact HR to set up your leave profile.</p>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- ── Recent Leave Requests ─────────────────────────────────────────── -->
      <VCol cols="12" md="6">
        <VCard elevation="0" border class="h-100">
          <VCardItem class="pa-5 pb-0">
            <VCardTitle class="text-h6 font-weight-semibold">Recent Leave Requests</VCardTitle>
            <template #append>
              <VBtn variant="text" size="small" color="primary" :href="route('hr.leave.index')">View all</VBtn>
            </template>
          </VCardItem>
          <VCardText class="pa-5">
            <template v-if="pending_leaves.length">
              <div
                v-for="lr in pending_leaves"
                :key="lr.id"
                class="d-flex align-center gap-3 py-2"
                style="border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity))"
              >
                <div class="rounded-circle flex-shrink-0 d-flex align-center justify-center"
                  :style="{ width:'10px', height:'10px', background: lr.color }" />
                <div class="flex-1">
                  <p class="text-body-2 font-weight-medium mb-0">{{ lr.type }}</p>
                  <p class="text-caption text-disabled mb-0">
                    {{ fmtDate(lr.start_date) }} – {{ fmtDate(lr.end_date) }} · {{ lr.days }}d
                  </p>
                </div>
                <VChip :color="leaveStatusColor[lr.status] ?? 'default'" size="x-small" label class="text-capitalize">
                  {{ lr.status }}
                </VChip>
              </div>
            </template>
            <div v-else class="text-center text-medium-emphasis py-6">
              <VIcon icon="tabler-calendar-check" size="36" class="mb-2 text-disabled d-block mx-auto" />
              <p class="text-caption">No recent leave requests</p>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- ── Open Helpdesk Tickets ──────────────────────────────────────────── -->
      <VCol cols="12" md="6">
        <VCard elevation="0" border class="h-100">
          <VCardItem class="pa-5 pb-0">
            <VCardTitle class="text-h6 font-weight-semibold">My Open Tickets</VCardTitle>
            <template #append>
              <VBtn variant="text" size="small" color="primary" :href="route('helpdesk.tickets.index')">View all</VBtn>
            </template>
          </VCardItem>
          <VCardText class="pa-5">
            <template v-if="open_tickets.length">
              <div
                v-for="t in open_tickets"
                :key="t.id"
                class="d-flex align-center gap-3 py-2 cursor-pointer"
                style="border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity))"
                @click="router.visit(route('helpdesk.tickets.show', t.id))"
              >
                <VAvatar size="32" color="primary" variant="tonal" class="flex-shrink-0">
                  <VIcon icon="tabler-ticket" size="16" />
                </VAvatar>
                <div class="flex-1 min-width-0">
                  <p class="text-body-2 font-weight-medium mb-0 text-truncate">{{ t.subject }}</p>
                  <p class="text-caption text-disabled mb-0">{{ t.ticket_number }} · {{ t.created_at }}</p>
                </div>
                <VChip :color="t.status_color" size="x-small" label>{{ t.status }}</VChip>
              </div>
            </template>
            <div v-else class="text-center text-medium-emphasis py-6">
              <VIcon icon="tabler-circle-check" size="36" class="mb-2 text-disabled d-block mx-auto" />
              <p class="text-caption">No open tickets — all is well!</p>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- ── Recent Payslips ────────────────────────────────────────────────── -->
      <VCol cols="12" md="6">
        <VCard elevation="0" border class="h-100">
          <VCardItem class="pa-5 pb-0">
            <VCardTitle class="text-h6 font-weight-semibold">Recent Payslips</VCardTitle>
            <template #append>
              <VBtn variant="text" size="small" color="primary" href="/payroll/my-payslips">View all</VBtn>
            </template>
          </VCardItem>
          <VCardText class="pa-5">
            <template v-if="recent_payslips.length">
              <div
                v-for="ps in recent_payslips"
                :key="ps.id"
                class="d-flex align-center gap-3 py-2"
                style="border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity))"
              >
                <VAvatar size="36" color="success" variant="tonal" class="flex-shrink-0">
                  <VIcon icon="tabler-receipt" size="18" />
                </VAvatar>
                <div class="flex-1">
                  <p class="text-body-2 font-weight-medium mb-0">{{ ps.run_name ?? `${fmtDate(ps.period_start)} – ${fmtDate(ps.period_end)}` }}</p>
                  <p class="text-caption text-disabled mb-0">
                    {{ fmtDate(ps.period_start) }} – {{ fmtDate(ps.period_end) }}
                  </p>
                </div>
                <div class="text-end">
                  <p class="text-body-2 font-weight-bold text-success mb-0">{{ fmtCurrency(ps.net_pay) }}</p>
                  <p class="text-caption text-disabled mb-0 text-capitalize">{{ ps.status }}</p>
                </div>
              </div>
            </template>
            <div v-else class="text-center text-medium-emphasis py-6">
              <VIcon icon="tabler-receipt-off" size="36" class="mb-2 text-disabled d-block mx-auto" />
              <p class="text-caption">No payslips available yet</p>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- ── Assigned Assets ────────────────────────────────────────────────── -->
      <VCol cols="12" md="6">
        <VCard elevation="0" border class="h-100">
          <VCardItem class="pa-5 pb-0">
            <VCardTitle class="text-h6 font-weight-semibold">Assigned Assets</VCardTitle>
            <template #append>
              <VBtn variant="text" size="small" color="primary" href="/assets">View all</VBtn>
            </template>
          </VCardItem>
          <VCardText class="pa-5">
            <VRow v-if="assigned_assets.length" dense>
              <VCol
                v-for="asset in assigned_assets"
                :key="asset.id"
                cols="12" sm="6"
              >
                <VCard elevation="0" border class="pa-3">
                  <div class="d-flex align-center gap-3">
                    <div class="d-flex align-center justify-center rounded-lg flex-shrink-0"
                      style="width:40px;height:40px;background:#f3f1ff;">
                      <VIcon icon="tabler-device-laptop" size="20" color="#7367f0" />
                    </div>
                    <div class="min-width-0">
                      <p class="text-body-2 font-weight-medium mb-0 text-truncate">{{ asset.name }}</p>
                      <p class="text-caption text-disabled mb-0">{{ asset.asset_tag ?? 'No tag' }}</p>
                    </div>
                  </div>
                </VCard>
              </VCol>
            </VRow>
            <div v-else class="text-center text-medium-emphasis py-6">
              <VIcon icon="tabler-device-laptop-off" size="36" class="mb-2 text-disabled d-block mx-auto" />
              <p class="text-caption">No assets assigned to you</p>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- ── Announcements ──────────────────────────────────────────────────── -->
      <VCol cols="12">
        <VCard elevation="0" border>
          <VCardItem class="pa-5 pb-0">
            <VCardTitle class="text-h6 font-weight-semibold">Latest Announcements</VCardTitle>
            <template #append>
              <VBtn variant="text" size="small" color="primary" href="/cms/announcements">View all</VBtn>
            </template>
          </VCardItem>
          <VCardText class="pa-5">
            <VRow v-if="announcements.length">
              <VCol
                v-for="a in announcements"
                :key="a.id"
                cols="12" sm="6" md="3"
              >
                <VCard elevation="0" border class="h-100 pa-4" style="cursor:default">
                  <div class="d-flex align-center justify-center rounded-lg mb-3"
                    style="width:40px;height:40px;background:#fff8ee;">
                    <VIcon icon="tabler-speakerphone" size="20" color="#FF9F43" />
                  </div>
                  <p class="text-body-2 font-weight-semibold mb-1">{{ a.title }}</p>
                  <p class="text-caption text-medium-emphasis mb-2 line-clamp-2">{{ a.summary }}</p>
                  <p class="text-caption text-disabled mb-0">{{ a.created_at }}</p>
                </VCard>
              </VCol>
            </VRow>
            <div v-else class="text-center text-medium-emphasis py-6">
              <VIcon icon="tabler-speakerphone" size="36" class="mb-2 text-disabled d-block mx-auto" />
              <p class="text-caption">No recent announcements</p>
            </div>
          </VCardText>
        </VCard>
      </VCol>

    </VRow>
  </div>
</template>

<style scoped>
.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.min-width-0 { min-width: 0; }
</style>
