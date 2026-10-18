<script lang="ts" setup>
import { useForm, Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import './login.css'

/**
 * Asking for a reset link.
 *
 * The answer is the same whether the address is registered or not — anything else turns this form
 * into a way of finding out which email addresses have accounts here.
 */
const form = useForm({ email: '' })

const status = computed(() => (usePage().props as any).flash?.status as string | undefined)

function submit() {
  form.post('/forgot-password', { onSuccess: () => form.reset('email') })
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

            <div class="text-center mb-4">
              <a href="https://mkhayamk.co.za/" style="text-decoration:none;color:inherit">
                Powered by
                <img src="/images/logo_default.png" alt="Logo" style="height:150px;display:block;margin:0 auto" />
              </a>
            </div>

            <div class="text-center mb-4">
              <div style="
                display:inline-flex;align-items:center;justify-content:center;
                width:70px;height:70px;border-radius:50%;
                background:linear-gradient(135deg,#ffd700 0%,#e6c200 100%);
                margin-bottom:14px;
                box-shadow:0 8px 24px rgba(255,215,0,.4)
              ">
                <i class="bi bi-envelope-fill" style="font-size:30px;color:#5e2b8c"></i>
              </div>
              <h3 class="login-title mb-2">Forgot your password?</h3>
              <p style="color:#fff;font-size:0.9rem;margin-bottom:0">
                Give us the email address you sign in with and we will send you a link to set a new password.
              </p>
            </div>

            <!-- The same answer either way, so this is a status rather than a success. -->
            <div v-if="status" class="alert alert-info py-2 small mb-3">
              <i class="bi bi-info-circle-fill me-2"></i>{{ status }}
            </div>

            <div v-if="form.errors.email" class="alert alert-danger py-2 small mb-3">
              <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ form.errors.email }}
            </div>

            <form @submit.prevent="submit">
              <div class="mb-3">
                <label style="color:#ffd700;font-weight:600;font-size:0.85rem;display:block;margin-bottom:6px">
                  E-mail
                </label>
                <input
                  type="email"
                  class="form-control"
                  :class="{ 'is-invalid': form.errors.email }"
                  v-model="form.email"
                  autocomplete="email"
                  autofocus
                  placeholder="you@company.co.za"
                  style="background:rgba(255,255,255,0.92);color:#000;border-radius:8px"
                />
              </div>

              <button type="submit" class="btn btn-primary btn-block w-100 fs-5" :disabled="form.processing">
                <span v-if="form.processing">
                  <span class="spinner-border spinner-border-sm me-2"></span>Sending…
                </span>
                <span v-else><i class="bi bi-send-fill me-2"></i>Send reset link</span>
              </button>
            </form>

            <div class="text-center mt-4">
              <Link href="/login" class="forgot-link">Back to sign in</Link>
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
