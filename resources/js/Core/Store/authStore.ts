import { defineStore } from 'pinia'
import { router } from '@inertiajs/vue3'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user:            null as any,
    isAuthenticated: false,
    _interval:       null as ReturnType<typeof setInterval> | null,
  }),

  actions: {
    setUser(user: any) {
      this.user            = user
      this.isAuthenticated = !!user
    },

    logout() {
      this.user            = null
      this.isAuthenticated = false
      this.stopAuthCheck()
      router.post(route('auth.logout'))
    },

    checkAuth() {
      if (!this.isAuthenticated) {
        this.stopAuthCheck()
        router.visit('/login')
      }
    },

    // ✅ Démarre la vérification toutes les 5s
    startAuthCheck(seconds = 5) {
      this.stopAuthCheck()
      this._interval = setInterval(() => {
        // Recharge uniquement la prop auth depuis Laravel
        router.reload({
          only: ['auth'],
          onSuccess: () => {
            // Le navigate listener dans app.ts met à jour le store
            // Si auth.user est null → checkAuth redirige vers /login
            this.checkAuth()
          },
        })
      }, seconds * 1000)
    },

    // ✅ Arrête l'interval (appeler dans onUnmounted ou au logout)
    stopAuthCheck() {
      if (this._interval) {
        clearInterval(this._interval)
        this._interval = null
      }
    },
  },
})
