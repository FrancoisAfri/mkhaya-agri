<script setup lang="ts">
import { ref, computed } from 'vue'
import type { UnitNode } from '../Store/store'
import '../css/style.css'

interface AssignedUser {
  id:               number
  first_name:       string
  surname:          string
  email:            string
  job_function?:    string | null
  profile_pic?: string | null
  initial:          string
}

interface UnitNodeWithMembers extends UnitNode {
  assigned_users?: AssignedUser[]
}

const props = defineProps<{
  nodes:    UnitNodeWithMembers[]
  getColor: (type: string | null) => string
  level?:   number
}>()

// ── Expand state ──
const expandedIds = ref<Set<number>>(new Set())

const toggle = (id: number) => {
  if (expandedIds.value.has(id)) expandedIds.value.delete(id)
  else expandedIds.value.add(id)
}

const isExpanded = (id: number) => expandedIds.value.has(id)

const initExpand = (nodes: UnitNodeWithMembers[]) => {
  nodes.forEach(n => {
    expandedIds.value.add(n.id)
    if (n.children?.length) initExpand(n.children as UnitNodeWithMembers[])
  })
}
initExpand(props.nodes)

const scrollEl = ref<HTMLElement | null>(null)
const canScrollLeft  = ref(false)
const canScrollRight = ref(false)

const updateScrollBtns = () => {
  const el = scrollEl.value
  if (!el) return
  canScrollLeft.value  = el.scrollLeft > 4
  canScrollRight.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 4
}

const scrollBy = (dir: 'left' | 'right') => {
  const el = scrollEl.value
  if (!el) return
  el.scrollBy({ left: dir === 'left' ? -280 : 280, behavior: 'smooth' })
}

const onScrollElMounted = (el: HTMLElement | null) => {
  scrollEl.value = el
  if (el) {
    updateScrollBtns()
    el.addEventListener('scroll', updateScrollBtns)
    new ResizeObserver(updateScrollBtns).observe(el)
  }
}

// ── Members modal state ──
const modalOpen = ref(false)
const modalNode = ref<UnitNodeWithMembers | null>(null)

const openMembersModal = (node: UnitNodeWithMembers) => {
  modalNode.value = node
  modalOpen.value = true
}

const closeMembersModal = () => {
  modalOpen.value = false
  setTimeout(() => { modalNode.value = null }, 200)
}

const modalMembers = computed(() => {
  if (!modalNode.value) return []
  const members: Array<AssignedUser & { isManager?: boolean }> = []

  if (modalNode.value.user) {
    members.push({
      id:              modalNode.value.user.id,
      first_name:      modalNode.value.user.first_name,
      surname:         modalNode.value.user.surname,
      email:           modalNode.value.user.email,
      job_function:    modalNode.value.user.job_function,
      profile_pic: modalNode.value.user.profile_pic,
      initial:         modalNode.value.user.initial ?? '',
      isManager:       true,
    })
  }

  if (modalNode.value.assigned_users?.length) {
    modalNode.value.assigned_users.forEach(u => {
      if (!members.find(m => m.id === u.id)) members.push(u)
    })
  }

  return members
})

// ── Helpers ──
const getInitial = (u: { first_name?: string | null; surname?: string | null; initial?: string | null }) =>
  u.initial || `${u.first_name?.[0] ?? ''}${u.surname?.[0] ?? ''}`.toUpperCase() || '?'

const imgErrors = ref<Set<number>>(new Set())
const onImgError = (id: number) => imgErrors.value.add(id)

const currentLevel = computed(() => props.level ?? 0)
const isRoot       = computed(() => currentLevel.value === 0)

// Total member count for a node (manager + assigned, deduped)
const memberCount = (node: UnitNodeWithMembers) => {
  let count = 0
  if (node.user) count++
  if (node.assigned_users?.length) count += node.assigned_users.length
  if (node.user && node.assigned_users?.find(u => u.id === node.user!.id)) count--
  return count
}
</script>

<template>
  <!-- ══ ROOT LEVEL: scroll wrapper + left/right buttons ══ -->
  <div v-if="isRoot" class="tree-scroll-container">

    <!-- Left scroll button -->
    <button
      v-show="canScrollLeft"
      class="scroll-nav-btn scroll-nav-btn--left"
      @click="scrollBy('left')"
    >
      <i class="fa-solid fa-chevron-left" />
    </button>

    <!-- Scrollable area -->
    <div
      class="tree-scroll-outer"
      :ref="(el) => onScrollElMounted(el as HTMLElement | null)"
    >
      <div class="tree-scroll-inner">
        <div class="tree-level">
          <div v-for="node in nodes" :key="node.id" class="branch-col">
            <div class="connector-top-placeholder" />

            <!-- Card -->
            <div class="org-card" :style="{ '--unit-color': getColor(node.type) }">
              <div class="card-stripe" />
              <div class="card-body">
                <div v-if="node.user" class="card-avatar" :style="{ background: getColor(node.type) }">
                  <img
                    v-if="node.user.profile_pic && !imgErrors.has(node.user.id * 10)"
                    :src="node.user.profile_pic"
                    class="avatar-img"
                    @error="onImgError(node.user.id * 10)"
                  />
                  <span v-else>{{ getInitial(node.user) }}</span>
                </div>
                <div v-else class="card-avatar card-avatar--empty" :style="{ background: getColor(node.type) + '33' }">
                  <i class="fa-regular fa-building" />
                </div>

                <div class="card-name">{{ node.name }}</div>
                <span v-if="node.type" class="card-badge">{{ node.type }}</span>

                <div v-if="node.user" class="card-manager">
                  <i class="fa-solid fa-crown" :style="{ color: getColor(node.type) }" />
                  <span>{{ node.user.first_name }} {{ node.user.surname }}</span>
                </div>
              </div>
            </div>

            <!-- Expand / collapse -->
            <template v-if="node.children?.length">
              <div class="connector-mid" />
              <button
                class="expand-btn"
                :style="{ background: getColor(node.type) }"
                @click="toggle(node.id)"
              >
                <i :class="isExpanded(node.id) ? 'fa-solid fa-minus' : 'fa-solid fa-plus'" />
              </button>

              <template v-if="isExpanded(node.id)">
                <div class="connector-mid" />
                <div class="h-bar-wrap">
                  <div class="h-bar" :style="{ borderColor: getColor(node.type) + '80' }" />
                </div>
                <TreeGraph
                  :nodes="(node.children as UnitNodeWithMembers[])"
                  :get-color="getColor"
                  :level="currentLevel + 1"
                />
              </template>
            </template>
            <div v-else class="connector-bottom-pad" />
          </div>
        </div>
      </div>
    </div>

    <!-- Right scroll button -->
    <button
      v-show="canScrollRight"
      class="scroll-nav-btn scroll-nav-btn--right"
      @click="scrollBy('right')"
    >
      <i class="fa-solid fa-chevron-right" />
    </button>
  </div>

  <!-- ══ NON-ROOT: plain row, no scroll wrapper ══ -->
  <template v-else>
    <div class="tree-level">
      <div v-for="node in nodes" :key="node.id" class="branch-col">
        <div class="connector-top" />

        <!-- Card -->
        <div class="org-card" :style="{ '--unit-color': getColor(node.type) }">
          <div class="card-stripe" />
          <div class="card-body">
            <div v-if="node.user" class="card-avatar" :style="{ background: getColor(node.type) }">
              <img
                v-if="node.user.profile_pic && !imgErrors.has(node.user.id * 10)"
                :src="node.user.profile_pic"
                class="avatar-img"
                @error="onImgError(node.user.id * 10)"
              />
              <span v-else>{{ getInitial(node.user) }}</span>
            </div>
            <div v-else class="card-avatar card-avatar--empty" :style="{ background: getColor(node.type) + '33' }">
              <i class="fa-regular fa-building" />
            </div>

            <div class="card-name">{{ node.name }}</div>
            <span v-if="node.type" class="card-badge">{{ node.type }}</span>

            <div v-if="node.user" class="card-manager">
              <i class="fa-solid fa-crown" :style="{ color: getColor(node.type) }" />
              <span>{{ node.user.first_name }} {{ node.user.surname }}</span>
            </div>
          </div>

          <!-- CHILD LEVELS: member count pill → opens modal -->
          <div class="card-footer">
            <button
              v-if="memberCount(node) > 0"
              class="members-count-btn"
              :style="{
                background:  getColor(node.type) + '15',
                borderColor: getColor(node.type) + '40',
                color:       getColor(node.type),
              }"
              @click.stop="openMembersModal(node)"
            >
              <i class="fa-solid fa-users" style="font-size:10px" />
              <span>{{ memberCount(node) }} member{{ memberCount(node) > 1 ? 's' : '' }}</span>
              <i class="fa-solid fa-chevron-right" style="font-size:8px; opacity:.6" />
            </button>
            <div v-else class="card-empty">
              <i class="fa-regular fa-user-slash" />
              <span>No members</span>
            </div>
          </div>
        </div>

        <!-- Expand / collapse -->
        <template v-if="node.children?.length">
          <div class="connector-mid" />
          <button
            class="expand-btn"
            :style="{ background: getColor(node.type) }"
            @click="toggle(node.id)"
          >
            <i :class="isExpanded(node.id) ? 'fa-solid fa-minus' : 'fa-solid fa-plus'" />
          </button>

          <template v-if="isExpanded(node.id)">
            <div class="connector-mid" />
            <div class="h-bar-wrap">
              <div class="h-bar" :style="{ borderColor: getColor(node.type) + '80' }" />
            </div>
            <TreeGraph
              :nodes="(node.children as UnitNodeWithMembers[])"
              :get-color="getColor"
              :level="currentLevel + 1"
            />
          </template>
        </template>
        <div v-else class="connector-bottom-pad" />
      </div>
    </div>
  </template>

  <!-- ══ Members Modal ══ -->
  <Teleport to="body">
    <Transition name="modal-fade">
      <div v-if="modalOpen && modalNode" class="modal-backdrop" @click.self="closeMembersModal">
        <div class="modal-panel" :style="{ '--modal-color': getColor(modalNode.type) }">

          <div class="modal-header">
            <div class="modal-header-left">
              <div class="modal-icon" :style="{ background: getColor(modalNode.type) + '20' }">
                <i class="fa-solid fa-users" :style="{ color: getColor(modalNode.type) }" />
              </div>
              <div>
                <p class="modal-title">{{ modalNode.name }}</p>
                <p class="modal-subtitle">
                  <span
                    class="modal-badge"
                    :style="{ background: getColor(modalNode.type) + '18', color: getColor(modalNode.type) }"
                  >{{ modalNode.type }}</span>
                  {{ modalMembers.length }} membre{{ modalMembers.length > 1 ? 's' : '' }}
                </p>
              </div>
            </div>
            <button class="modal-close" @click="closeMembersModal">
              <i class="fa-solid fa-xmark" />
            </button>
          </div>

          <div class="modal-body">
            <div
              v-for="(member, index) in modalMembers"
              :key="member.id"
              class="member-row"
              :class="{ 'member-row--manager': member.isManager }"
            >
              <div
                class="member-avatar"
                :style="{
                  background:  member.isManager ? getColor(modalNode.type) : getColor(modalNode.type) + 'bb',
                  borderColor: getColor(modalNode.type),
                }"
              >
                <img
                  v-if="member.profile_pic && !imgErrors.has(member.id)"
                  :src="member.profile_pic"
                  class="avatar-img"
                  @error="onImgError(member.id)"
                />
                <span v-else>{{ getInitial(member) }}</span>
              </div>

              <div class="member-info">
                <div class="member-name-row">
                  <span class="member-name">{{ member.first_name }} {{ member.surname }}</span>
                  <span
                    v-if="member.isManager"
                    class="manager-tag"
                    :style="{ background: getColor(modalNode.type) + '18', color: getColor(modalNode.type) }"
                  >
                    <i class="fa-solid fa-crown" style="font-size:8px" /> Manager
                  </span>
                </div>
                <span v-if="member.job_function" class="member-role">{{ member.job_function }}</span>
                <span v-else class="member-email">{{ member.email }}</span>
              </div>

              <span class="member-index">{{ index + 1 }}</span>
            </div>

            <div v-if="modalMembers.length === 0" class="modal-empty">
              <i class="fa-regular fa-user-slash" style="font-size:28px; opacity:.3" />
              <p>No members assigned</p>
            </div>
          </div>

        </div>
      </div>
    </Transition>
  </Teleport>
</template>


