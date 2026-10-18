<script setup lang="ts">
import type { UnitNode } from '../Store/store'

interface AssignedUser {
  id: number; first_name: string; surname: string; email: string
  job_function?: string | null; profile_pic?: string | null; initial?: string; isManager?: boolean
}
interface OrgNode extends UnitNode { assigned_users?: AssignedUser[] }

const props = defineProps<{
  node:        OrgNode
  level:       number
  expandedIds: Set<number>
  imgErrors:   Set<number>
  getColor:    (t: string | null) => string
  getInitials: (f: string, l: string) => string
  isLeaf:      (n: OrgNode) => boolean
  allMembers:  (n: OrgNode) => AssignedUser[]
  memberBg:    (i: number, c: string, isMgr: boolean) => string
}>()

const emit = defineEmits<{
  (e: 'toggle',    id: number): void
  (e: 'img-error', id: number): void
}>()

const color   = props.getColor(props.node.type)
const leaf    = props.isLeaf(props.node)
const members = props.allMembers(props.node)
const open    = () => props.expandedIds.has(props.node.id)

const avatarSize = (level: number) => {
  if (level === 0) return '80px'
  if (level === 1) return '64px'
  return '50px'
}
const fontSize = (level: number) => {
  if (level === 0) return '22px'
  if (level === 1) return '17px'
  return '14px'
}
</script>

<template>
  <div style="display:flex; flex-direction:column; align-items:center; padding:0 16px;">

    <!-- Connecteur entrant -->
    <div v-if="level > 0" style="width:2px; height:28px; background:#b0bec5;" />

    <!-- ══ CERCLE MANAGER ══ -->
    <div style="display:flex; flex-direction:column; align-items:center; gap:6px;">
      <VTooltip location="top" :open-delay="80">
        <template #activator="{ props: tp }">
          <div
            v-bind="tp"
            :style="{
              width:        avatarSize(level),
              height:       avatarSize(level),
              borderRadius: '50%',
              background:   color,
              border:       `3px solid ${color}50`,
              boxShadow:    '0 2px 12px rgba(0,0,0,.15)',
              display:      'flex',
              alignItems:   'center',
              justifyContent: 'center',
              fontWeight:   '600',
              fontSize:     fontSize(level),
              color:        '#fff',
              overflow:     'hidden',
              cursor:       'default',
              transition:   'transform .15s',
              flexShrink:   '0',
            }"
          >
            <img
              v-if="node.user?.profile_pic && !imgErrors.has(node.user.id)"
              :src="node.user.profile_pic"
              style="width:100%;height:100%;object-fit:cover;"
              @error="emit('img-error', node.user!.id)"
            />
            <span v-else>{{ node.user ? getInitials(node.user.first_name, node.user.surname) : '?' }}</span>
          </div>
        </template>
        <div v-if="node.user">
          <strong>{{ node.user.first_name }} {{ node.user.surname }}</strong>
          <div style="font-size:11px;opacity:.75">{{ node.user.job_function }}</div>
        </div>
      </VTooltip>

      <!-- Nom + badge -->
      <div style="text-align:center;">
        <div :style="{ fontSize: level===0?'13px':'12px', fontWeight:'600', color:'rgb(var(--v-theme-on-surface))', maxWidth:'110px', overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap' }">
          {{ node.name }}
        </div>
        <span
          v-if="node.type"
          :style="{
            fontSize:      '9px',
            padding:       '1px 7px',
            borderRadius:  '20px',
            fontWeight:    '600',
            textTransform: 'capitalize',
            background:    color+'15',
            color,
            display:       'inline-block',
            marginTop:     '3px',
          }"
        >
          {{ node.type }}
        </span>
      </div>
    </div>

    <!-- ══ FEUILLE : grille de petits cercles ══ -->
    <template v-if="leaf && members.length">
      <div style="width:2px; height:16px; background:#b0bec5;" />
      <div style="display:flex; flex-wrap:wrap; gap:5px; justify-content:center; max-width:400px;">
        <VTooltip
          v-for="(u, i) in members"
          :key="u.id"
          location="top"
          :open-delay="60"
        >
          <template #activator="{ props: tp }">
            <div
              v-bind="tp"
              :style="{
                width:        '34px',
                height:       '34px',
                borderRadius: '50%',
                background:   memberBg(i, color, !!u.isManager),
                border:       '2px solid #fff',
                boxShadow:    '0 1px 6px rgba(0,0,0,.13)',
                display:      'flex',
                alignItems:   'center',
                justifyContent: 'center',
                fontSize:     '10px',
                fontWeight:   '600',
                color:        '#fff',
                overflow:     'hidden',
                cursor:       'pointer',
                transition:   'transform .1s',
                flexShrink:   '0',
              }"
            >
              <img
                v-if="u.profile_pic && !imgErrors.has(u.id)"
                :src="u.profile_pic"
                style="width:100%;height:100%;object-fit:cover;"
                @error="emit('img-error', u.id)"
              />
              <span v-else>{{ getInitials(u.first_name, u.surname) }}</span>
            </div>
          </template>
          <div>
            <strong>{{ u.first_name }} {{ u.surname }}</strong>
            <div v-if="u.job_function" style="font-size:11px;opacity:.75">{{ u.job_function }}</div>
          </div>
        </VTooltip>
      </div>
    </template>

    <!-- ══ NŒUD INTERMÉDIAIRE : bouton expand + enfants ══ -->
    <template v-if="!leaf">
      <div style="width:2px; height:16px; background:#b0bec5;" />

      <button
        :style="{
          width:'22px', height:'22px', borderRadius:'50%',
          border:`2px solid ${color}`, color,
          background:'var(--v-theme-surface, #fff)',
          display:'flex', alignItems:'center', justifyContent:'center',
          cursor:'pointer', fontSize:'13px', fontWeight:'700',
          boxShadow:'0 1px 4px rgba(0,0,0,.12)',
        }"
        @click="emit('toggle', node.id)"
      >
        {{ open() ? '−' : '+' }}
      </button>

      <template v-if="open()">
        <div style="width:2px; height:16px; background:#b0bec5;" />

        <!-- Barre H si plusieurs enfants -->
        <div
          v-if="node.children.length > 1"
          style="width:100%; height:2px; background:#b0bec5;"
        />

        <!-- Ligne d'enfants -->
        <div style="display:flex; align-items:flex-start; justify-content:center;">
          <OrganigramCol
            v-for="child in node.children"
            :key="child.id"
            :node="(child as OrgNode)"
            :level="level + 1"
            :expanded-ids="expandedIds"
            :img-errors="imgErrors"
            :get-color="getColor"
            :get-initials="getInitials"
            :is-leaf="isLeaf"
            :all-members="allMembers"
            :member-bg="memberBg"
            @toggle="emit('toggle', $event)"
            @img-error="emit('img-error', $event)"
          />
        </div>
      </template>
    </template>

  </div>
</template>
