<script setup lang="ts">
import { ref } from 'vue'
import type { UnitNode } from '../Store/store'
import '../css/style.css'
import OrganigramCol from './OrganigramCol.vue'

interface AssignedUser {
  id: number; first_name: string; surname: string
  email: string; job_function?: string | null
  profile_pic?: string | null; initial?: string
}
interface OrgNode extends UnitNode { assigned_users?: AssignedUser[] }

const props = defineProps<{
  nodes:    OrgNode[]
  getColor: (type: string | null) => string
}>()

const expandedIds = ref<Set<number>>(new Set())
const imgErrors   = ref<Set<number>>(new Set())

const initExpand = (nodes: OrgNode[]) =>
  nodes.forEach(n => { expandedIds.value.add(n.id); if (n.children?.length) initExpand(n.children as OrgNode[]) })
initExpand(props.nodes)

const toggle = (id: number) => {
  if (expandedIds.value.has(id)) expandedIds.value.delete(id)
  else expandedIds.value.add(id)
}

const getInitials = (f: string, l: string) => `${f?.[0]??''}${l?.[0]??''}`.toUpperCase() || '?'
const isLeaf      = (n: OrgNode) => !n.children?.length
const allMembers  = (node: OrgNode) => {
  const list: Array<AssignedUser & { isManager?: boolean }> = []
  if (node.user) list.push({ id: node.user.id, first_name: node.user.first_name, surname: node.user.surname, email: node.user.email ?? '', job_function: node.user.job_function, profile_pic: node.user.profile_pic, isManager: true })
  node.assigned_users?.forEach(u => { if (!list.find(m => m.id === u.id)) list.push(u) })
  return list
}
const memberBg = (i: number, color: string, isMgr: boolean) =>
  isMgr ? color : `hsl(${(i * 53 + 180) % 360},50%,55%)`
</script>

<template>
  <div class="tree-scroll-container">
    <div class="tree-scroll-outer">
      <div class="tree-scroll-inner">
        <div style="display:flex; justify-content:center;">
          <OrganigramCol
            v-for="node in nodes"
            :key="node.id"
            :node="(node as OrgNode)"
            :level="0"
            :expanded-ids="expandedIds"
            :img-errors="imgErrors"
            :get-color="getColor"
            :get-initials="getInitials"
            :is-leaf="isLeaf"
            :all-members="allMembers"
            :member-bg="memberBg"
            @toggle="toggle"
            @img-error="imgErrors.add($event)"
          />
        </div>
      </div>
    </div>
  </div>
</template>
