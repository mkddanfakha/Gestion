<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import SettingsLayout from '@/layouts/settings/Layout.vue'
import HeadingSmall from '@/components/HeadingSmall.vue'
import { useNotificationPreferences } from '@/modules/NotificationCenter/composables/useNotificationPreferences'
import type {
  NotificationPreferencesPayload,
  NotificationPriority,
} from '@/modules/NotificationCenter/types'
import { Head } from '@inertiajs/vue3'
import { reactive, ref, watch } from 'vue'

const props = defineProps<{
  preferencesPayload: NotificationPreferencesPayload
}>()

const { applyPayload, updatePreferences } = useNotificationPreferences()
applyPayload(props.preferencesPayload)

const form = reactive({
  ...props.preferencesPayload.user,
  toast_durations: { ...props.preferencesPayload.user.toast_durations },
  sound_profiles: { ...props.preferencesPayload.user.sound_profiles },
})

const meta = props.preferencesPayload.meta
const globalPrefs = props.preferencesPayload.global
const priorities = meta.priorities as NotificationPriority[]

const saving = ref(false)
const message = ref('')
const errorMessage = ref('')

watch(
  () => props.preferencesPayload,
  (payload) => {
    applyPayload(payload)
    Object.assign(form, payload.user, {
      toast_durations: { ...payload.user.toast_durations },
      sound_profiles: { ...payload.user.sound_profiles },
    })
  },
  { deep: true },
)

async function savePreferences() {
  saving.value = true
  message.value = ''
  errorMessage.value = ''

  try {
    await updatePreferences({ ...form })
    message.value = 'Préférences enregistrées et appliquées immédiatement.'
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'Erreur inconnue.'
  } finally {
    saving.value = false
  }
}

async function requestBrowserPermission() {
  const { useBrowserNotifications } = await import('@/modules/NotificationCenter/composables/useBrowserNotifications')
  await useBrowserNotifications().requestPermission()
}
</script>

<template>
  <AppLayout>
    <Head title="Préférences notifications" />

    <SettingsLayout>
      <div class="card shadow-sm">
        <div class="card-body">
          <HeadingSmall
            title="Notifications personnelles"
            description="Personnalisez la façon dont vous recevez les alertes. Les changements sont appliqués sans rechargement."
          />

          <div v-if="message" class="alert alert-success">{{ message }}</div>
          <div v-if="errorMessage" class="alert alert-danger">{{ errorMessage }}</div>

          <div class="row g-4">
            <div class="col-lg-6">
              <h3 class="h6 text-uppercase text-muted mb-3">Comportement</h3>
              <div class="d-flex flex-column gap-3">
                <div class="d-flex justify-content-between align-items-start border rounded p-3">
                  <div>
                    <div class="fw-semibold">Recevoir les toasts</div>
                    <small v-if="!globalPrefs.toasts_enabled" class="text-warning">Désactivé globalement</small>
                  </div>
                  <div class="form-check form-switch mb-0">
                    <input v-model="form.toasts_enabled" class="form-check-input" type="checkbox" :disabled="!globalPrefs.toasts_enabled" />
                  </div>
                </div>
                <div class="d-flex justify-content-between align-items-start border rounded p-3">
                  <div>
                    <div class="fw-semibold">Recevoir le son</div>
                    <small v-if="!globalPrefs.sound_enabled" class="text-warning">Désactivé globalement</small>
                  </div>
                  <div class="form-check form-switch mb-0">
                    <input v-model="form.sound_enabled" class="form-check-input" type="checkbox" :disabled="!globalPrefs.sound_enabled" />
                  </div>
                </div>
                <div class="d-flex justify-content-between align-items-start border rounded p-3">
                  <div>
                    <div class="fw-semibold">Notifications navigateur</div>
                    <small v-if="!globalPrefs.browser_enabled" class="text-warning">Désactivé globalement</small>
                    <div v-if="form.browser_enabled && globalPrefs.browser_enabled">
                      <button type="button" class="btn btn-link btn-sm p-0" @click="requestBrowserPermission">
                        Autoriser le navigateur
                      </button>
                    </div>
                  </div>
                  <div class="form-check form-switch mb-0">
                    <input v-model="form.browser_enabled" class="form-check-input" type="checkbox" :disabled="!globalPrefs.browser_enabled" />
                  </div>
                </div>
                <div class="d-flex justify-content-between align-items-start border rounded p-3">
                  <div class="fw-semibold">Uniquement les notifications critiques</div>
                  <div class="form-check form-switch mb-0">
                    <input v-model="form.critical_only" class="form-check-input" type="checkbox" />
                  </div>
                </div>
                <div class="d-flex justify-content-between align-items-start border rounded p-3">
                  <div class="fw-semibold">Masquer les notifications résolues</div>
                  <div class="form-check form-switch mb-0">
                    <input v-model="form.hide_resolved" class="form-check-input" type="checkbox" />
                  </div>
                </div>
                <div class="d-flex justify-content-between align-items-start border rounded p-3">
                  <div>
                    <div class="fw-semibold">Marquer comme lues à l'ouverture</div>
                    <small v-if="!globalPrefs.auto_mark_read_on_open" class="text-warning">Désactivé globalement</small>
                  </div>
                  <div class="form-check form-switch mb-0">
                    <input v-model="form.auto_mark_read_on_open" class="form-check-input" type="checkbox" :disabled="!globalPrefs.auto_mark_read_on_open" />
                  </div>
                </div>
                <div class="d-flex justify-content-between align-items-start border rounded p-3">
                  <div>
                    <div class="fw-semibold">Regrouper les notifications similaires</div>
                    <small v-if="!globalPrefs.grouping_enabled" class="text-warning">Désactivé globalement</small>
                  </div>
                  <div class="form-check form-switch mb-0">
                    <input v-model="form.grouping_enabled" class="form-check-input" type="checkbox" :disabled="!globalPrefs.grouping_enabled" />
                  </div>
                </div>
              </div>
            </div>

            <div class="col-lg-6">
              <h3 class="h6 text-uppercase text-muted mb-3">Toasts & sons</h3>
              <div class="mb-3">
                <label class="form-label">Position des toasts</label>
                <select v-model="form.toast_position" class="form-select">
                  <option v-for="(label, key) in meta.toast_positions" :key="key" :value="key">
                    {{ label }}
                  </option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Volume des sons ({{ Math.round((form.sound_volume ?? 0) * 100) }}%)</label>
                <input v-model.number="form.sound_volume" type="range" min="0" max="1" step="0.05" class="form-range" :disabled="!form.sound_enabled || !globalPrefs.sound_enabled" />
              </div>
              <div class="mb-3">
                <label class="form-label fw-medium">Durée des toasts (ms)</label>
                <div class="row g-2">
                  <div v-for="priority in priorities" :key="priority" class="col-md-4">
                    <label class="form-label small text-capitalize">{{ priority }}</label>
                    <input v-model.number="form.toast_durations[priority]" type="number" min="1000" max="60000" step="500" class="form-control form-control-sm" />
                  </div>
                </div>
              </div>
              <div class="mb-3">
                <label class="form-label fw-medium">Son par priorité</label>
                <div v-for="priority in priorities" :key="`sound-${priority}`" class="mb-2">
                  <label class="form-label small text-capitalize">{{ priority }}</label>
                  <select
                    v-model="form.sound_profiles[priority]"
                    class="form-select form-select-sm"
                    :disabled="!form.sound_enabled || !globalPrefs.sound_enabled"
                  >
                    <option v-for="key in meta.sound_profile_keys" :key="key" :value="key">
                      {{ meta.sound_profiles[key]?.label ?? key }}
                    </option>
                  </select>
                </div>
              </div>
            </div>
          </div>

          <button class="btn btn-primary mt-4" :disabled="saving" @click="savePreferences">
            <span v-if="saving" class="spinner-border spinner-border-sm me-1" />
            Enregistrer les préférences
          </button>
        </div>
      </div>
    </SettingsLayout>
  </AppLayout>
</template>
