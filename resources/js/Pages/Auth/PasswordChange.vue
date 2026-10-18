<script lang="ts" setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import './login.css'

const form = useForm({
  current_password: '',
  password: '',
  password_confirmation: '',
})

const showCurrent = ref(false)
const showNew     = ref(false)
const showConfirm = ref(false)

function submit() {
  form.post('/password/change', {
    onSuccess: () => form.reset(),
  })
}
</script>

<script lang="ts">
import AuthLayout from '@/Layouts/AuthLayout.vue'
export default { layout: AuthLayout }
</script>

<template>
  <div class="authentication-bg">
    <div id="tsparticles" class="absolute inset-0"></div>

    <div class="container-fluid">
      <div class="row">

        <!-- ── Left: form ─────────────────────────────────────────────────── -->
        <div class="col-12 col-md-6 login-left">
          <div class="w-100" style="max-width: 420px">

            <!-- Logo -->
            <div class="text-center mb-4">
              <a href="https://mkhayamk.co.za/" style="text-decoration:none;color:inherit">
                Powered by
                <img src="/images/logo_default.png" alt="Logo" style="height:150px;display:block;margin:0 auto" />
              </a>
            </div>

            <!-- Icon + title -->
            <div class="text-center mb-4">
              <div style="
                display:inline-flex;align-items:center;justify-content:center;
                width:70px;height:70px;border-radius:50%;
                background:linear-gradient(135deg,#ffd700 0%,#e6c200 100%);
                margin-bottom:14px;
                box-shadow:0 8px 24px rgba(255,215,0,.4)
              ">
                <i class="bi bi-key-fill" style="font-size:30px;color:#5e2b8c"></i>
              </div>
              <h3 class="login-title mb-2">Password Expired</h3>
              <p style="color:#fff;font-size:0.9rem;margin-bottom:0">
                Your password has expired. Please set a new password to continue.
              </p>
            </div>

            <!-- Global error -->
            <div v-if="form.errors.current_password || form.errors.password" class="alert alert-danger py-2 small mb-3">
              <i class="bi bi-exclamation-triangle-fill me-2"></i>
              {{ form.errors.current_password || form.errors.password }}
            </div>

            <form @submit.prevent="submit">

              <!-- Current password -->
              <div class="mb-3">
                <label style="color:#ffd700;font-weight:600;font-size:0.85rem;display:block;margin-bottom:6px">
                  Current Password
                </label>
                <div class="input-group">
                  <input
                    :type="showCurrent ? 'text' : 'password'"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.current_password }"
                    v-model="form.current_password"
                    autocomplete="current-password"
                    placeholder="Enter current password"
                    style="background:rgba(255,255,255,0.92);color:#000;border-right:none;border-radius:8px 0 0 8px"
                  />
                  <div
                    class="input-group-text"
                    style="background:rgba(255,255,255,0.92);border-left:none;border-radius:0 8px 8px 0;cursor:pointer"
                    @click="showCurrent = !showCurrent"
                  >
                    <i :class="showCurrent ? 'bi bi-eye-slash-fill' : 'bi bi-eye-fill'" style="color:#555"></i>
                  </div>
                </div>
                <div v-if="form.errors.current_password" class="text-danger small mt-1">
                  {{ form.errors.current_password }}
                </div>
              </div>

              <!-- New password -->
              <div class="mb-3">
                <label style="color:#ffd700;font-weight:600;font-size:0.85rem;display:block;margin-bottom:6px">
                  New Password
                </label>
                <div class="input-group">
                  <input
                    :type="showNew ? 'text' : 'password'"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.password }"
                    v-model="form.password"
                    autocomplete="new-password"
                    placeholder="Min. 8 characters"
                    style="background:rgba(255,255,255,0.92);color:#000;border-right:none;border-radius:8px 0 0 8px"
                  />
                  <div
                    class="input-group-text"
                    style="background:rgba(255,255,255,0.92);border-left:none;border-radius:0 8px 8px 0;cursor:pointer"
                    @click="showNew = !showNew"
                  >
                    <i :class="showNew ? 'bi bi-eye-slash-fill' : 'bi bi-eye-fill'" style="color:#555"></i>
                  </div>
                </div>
                <div v-if="form.errors.password" class="text-danger small mt-1">
                  {{ form.errors.password }}
                </div>
              </div>

              <!-- Confirm password -->
              <div class="mb-4">
                <label style="color:#ffd700;font-weight:600;font-size:0.85rem;display:block;margin-bottom:6px">
                  Confirm New Password
                </label>
                <div class="input-group">
                  <input
                    :type="showConfirm ? 'text' : 'password'"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.password_confirmation }"
                    v-model="form.password_confirmation"
                    autocomplete="new-password"
                    placeholder="Repeat new password"
                    style="background:rgba(255,255,255,0.92);color:#000;border-right:none;border-radius:8px 0 0 8px"
                  />
                  <div
                    class="input-group-text"
                    style="background:rgba(255,255,255,0.92);border-left:none;border-radius:0 8px 8px 0;cursor:pointer"
                    @click="showConfirm = !showConfirm"
                  >
                    <i :class="showConfirm ? 'bi bi-eye-slash-fill' : 'bi bi-eye-fill'" style="color:#555"></i>
                  </div>
                </div>
              </div>

              <!-- Submit -->
              <button
                type="submit"
                class="btn btn-primary btn-block w-100 fs-5"
                :disabled="form.processing"
              >
                <span v-if="form.processing">
                  <span class="spinner-border spinner-border-sm me-2"></span>Saving…
                </span>
                <span v-else>
                  <i class="bi bi-shield-lock-fill me-2"></i>Set New Password
                </span>
              </button>

            </form>

            <!-- Info note -->
            <div class="mt-4 p-3 rounded" style="background:rgba(255,215,0,0.08);border:1px solid rgba(255,215,0,0.25)">
              <p class="small mb-0" style="color:#ffd700">
                <i class="bi bi-info-circle me-1"></i>
                Passwords must be at least 8 characters and cannot match any of your last 5 passwords.
              </p>
            </div>

          </div>
        </div>

        <!-- ── Right: Carousel ───────────────────────────────────────────── -->
        <div class="col-12 col-md-6 login-right">
          <div class="top-content text-center mb-4">
            <h4 class="text-white">The Future Does Not Wait, Neither Should Your Business</h4>
            <p class="text-white">
              At Mkhaya, we believe technology should do more than support your business.
              It should accelerate it.
            </p>
          </div>
          <div class="bottom-carousel">
            <div id="pwdChangeCarousel" class="carousel slide w-100" data-bs-ride="carousel">
              <div class="carousel-inner rounded">
                <div class="carousel-item active">
                  <a href="https://mkhayamk.co.za/" target="_blank">
                    <img src="/images/IT Support.jpeg" class="d-block w-100" alt="IT Support" />
                    <div class="carousel-overlay"><span>Visit Website</span></div>
                  </a>
                </div>
                <div class="carousel-item">
                  <a href="https://mkhayamk.co.za/website-design-services/" target="_blank">
                    <img src="/images/Website.jpeg" class="d-block w-100" alt="Website" />
                    <div class="carousel-overlay"><span>Visit Website</span></div>
                  </a>
                </div>
                <div class="carousel-item">
                  <a href="https://mkhayamk.co.za/software-engineering/" target="_blank">
                    <img src="/images/Custom Solutions.jpeg" class="d-block w-100" alt="Custom Solutions" />
                    <div class="carousel-overlay"><span>Visit Website</span></div>
                  </a>
                </div>
                <div class="carousel-item">
                  <a href="https://mkhayamk.co.za/mkhaya-move/" target="_blank">
                    <img src="/images/Mkhaya Move.jpeg" class="d-block w-100" alt="Mkhaya Move" />
                    <div class="carousel-overlay"><span>Visit Website</span></div>
                  </a>
                </div>
              </div>
              <button class="carousel-control-prev" type="button" data-bs-target="#pwdChangeCarousel" data-bs-slide="prev">
                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                <span class="visually-hidden">Previous</span>
              </button>
              <button class="carousel-control-next" type="button" data-bs-target="#pwdChangeCarousel" data-bs-slide="next">
                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                <span class="visually-hidden">Next</span>
              </button>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</template>
