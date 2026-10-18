import { defineStore } from 'pinia'

export interface UnitUser {
  id:            number
  first_name:    string
  surname:       string
  email:         string
  job_function?: string | null
  profile_pic?:  string | null
  initial?:      string | null
}

export interface UnitNode {
  id:         number
  name:       string
  type:       string | null
  parent_id:  number | null
  sort_order: number
  user_id?:   number | null
  user?:      UnitUser | null
  children:   UnitNode[]
}

export const useOrganisationStore = defineStore('organisation', {
  state: () => ({
    tree:        [] as UnitNode[],
    companyId:   1,
    unitTypes:   [] as string[],
    users:       [] as UnitUser[],
    snackbar:    false,
    snackMsg:    '',
    snackColor:  'success' as 'success' | 'error' | 'warning',
    loading:     false,

    isAddOpen:     false,
    addParentId:   null as number | null,
    addParentName: '',

    isEditOpen:  false,
    editNode:    null as UnitNode | null,

    isDeleteOpen: false,
    deleteNode:   null as UnitNode | null,
  }),

  actions: {
    setTree(tree: UnitNode[], companyId: number, unitTypes: string[], users: UnitUser[]) {
      this.tree      = this.decodeTreePics(tree)
      this.companyId = companyId
      this.unitTypes = unitTypes
      this.users     = this.decodeUsersPics(users)
    },

    // ── Décode profile_pic dans tous les nodes du tree (récursif) ──
    decodeTreePics(nodes: UnitNode[]): UnitNode[] {
      return nodes.map(node => ({
        ...node,
        user: node.user
          ? { ...node.user, profile_pic: this.decodeProfilePic(node.user.profile_pic) }
          : null,
        children: this.decodeTreePics(node.children),
      }))
    },

    // ── Décode profile_pic dans la liste des users ──
    decodeUsersPics(users: UnitUser[]): UnitUser[] {
      return users.map(u => ({
        ...u,
        profile_pic: this.decodeProfilePic(u.profile_pic),
      }))
    },

    // ── Décode une valeur profile_pic brute → data URL ──
    decodeProfilePic(raw: string | null | undefined): string | null {
      if (!raw) return null
      // Déjà une data URL
      if (raw.startsWith('data:')) return raw
      try {
        const decoded = JSON.parse(raw)
        if (decoded?.data && decoded?.mime) {
          return `data:${decoded.mime};base64,${decoded.data}`
        }
        return null
      } catch {
        return null
      }
    },

    openAdd(parentId: number | null, parentName = '') {
      this.addParentId   = parentId
      this.addParentName = parentName
      this.isAddOpen     = true
    },
    closeAdd() { this.isAddOpen = false },

    openEdit(node: UnitNode) { this.editNode = node; this.isEditOpen = true },
    closeEdit() { this.isEditOpen = false; this.editNode = null },

    openDelete(node: UnitNode) { this.deleteNode = node; this.isDeleteOpen = true },
    closeDelete() { this.isDeleteOpen = false; this.deleteNode = null },

    updateNodeInTree(id: number, changes: Partial<UnitNode>) {
      const update = (nodes: UnitNode[]): UnitNode[] =>
        nodes.map(n => n.id === id
          ? { ...n, ...changes }
          : { ...n, children: update(n.children) }
        )
      this.tree = update(this.tree)
    },

    removeNodeFromTree(id: number) {
      const remove = (nodes: UnitNode[]): UnitNode[] =>
        nodes.filter(n => n.id !== id).map(n => ({ ...n, children: remove(n.children) }))
      this.tree = remove(this.tree)
    },

    showSnack(msg: string, color: 'success' | 'error' | 'warning' = 'success') {
      this.snackMsg   = msg
      this.snackColor = color
      this.snackbar   = true
    },
  },
})
