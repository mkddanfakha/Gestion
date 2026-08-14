<template>
  <div class="card mb-4 company-print-identity">
    <div class="card-header company-print-identity__header">
      <div>
        <h5 class="card-title mb-1">Identité d'impression</h5>
        <p class="text-muted small mb-0">
          Logo, signature et cachet utilisés sur vos documents PDF
        </p>
      </div>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4">
          <CompanyAssetSection
            title="Logo"
            :asset-url="logoUrl"
            :can-manage="canManage"
            :upload-route="route('company.logo.upload')"
            :delete-route="route('company.logo.delete')"
            upload-field-name="logo"
            import-label="Importer le logo"
            replace-label="Remplacer"
            save-label="Enregistrer le logo"
            saving-label="Importation..."
            delete-confirm-title="Supprimer le logo"
            delete-confirm-message="Voulez-vous vraiment supprimer le logo de l'entreprise ?"
            hint="Affiché en en-tête des documents"
            variant="logo"
          />
        </div>
        <div class="col-md-4">
          <CompanyAssetSection
            title="Signature"
            :asset-url="signatureUrl"
            :can-manage="canManage"
            :upload-route="route('company.signature.upload')"
            :delete-route="route('company.signature.delete')"
            upload-field-name="signature"
            import-label="Importer la signature"
            replace-label="Remplacer"
            save-label="Enregistrer la signature"
            saving-label="Importation..."
            delete-confirm-title="Supprimer la signature"
            delete-confirm-message="Voulez-vous vraiment supprimer la signature de l'entreprise ?"
            hint="PNG transparent recommandé"
            variant="signature"
          />
        </div>
        <div class="col-md-4">
          <CompanyAssetSection
            title="Cachet"
            :asset-url="stampUrl"
            :can-manage="canManage"
            :upload-route="route('company.stamp.upload')"
            :delete-route="route('company.stamp.delete')"
            upload-field-name="stamp"
            import-label="Importer le cachet"
            replace-label="Remplacer"
            save-label="Enregistrer le cachet"
            saving-label="Importation..."
            delete-confirm-title="Supprimer le cachet"
            delete-confirm-message="Voulez-vous vraiment supprimer le cachet de l'entreprise ?"
            hint="Tampon officiel de l'entreprise"
            variant="stamp"
          />
        </div>
      </div>

      <div class="company-print-layout-preview mt-4 pt-4 border-top">
        <h6 class="company-print-layout-preview__title">Aperçu de la zone d'impression</h6>
        <p class="text-muted small mb-3">
          Représentation indicative de l'identité sur vos documents PDF
        </p>
        <div class="company-print-layout-preview__document">
          <div class="company-print-layout-preview__header">
            <div v-if="logoUrl" class="company-print-layout-preview__logo-wrap">
              <img :src="logoUrl" alt="Logo entreprise" class="company-print-layout-preview__logo" />
            </div>
            <div class="company-print-layout-preview__company">
              <strong>{{ companyName || 'Nom de l\'entreprise' }}</strong>
              <div v-if="companyAddress" class="small text-muted">{{ companyAddress }}</div>
              <div v-if="companyPhone" class="small text-muted">{{ companyPhone }}</div>
            </div>
          </div>

          <div class="company-print-layout-preview__divider"></div>

          <div class="company-print-layout-preview__footer">
            <div class="company-print-layout-preview__identity-slot">
              <span class="company-print-layout-preview__label">Signature</span>
              <img
                v-if="signatureUrl"
                :src="signatureUrl"
                alt="Signature"
                class="company-print-layout-preview__signature"
              />
              <span v-else class="company-print-layout-preview__placeholder">Non importée</span>
            </div>
            <div class="company-print-layout-preview__identity-slot">
              <span class="company-print-layout-preview__label">Cachet</span>
              <img
                v-if="stampUrl"
                :src="stampUrl"
                alt="Cachet"
                class="company-print-layout-preview__stamp"
              />
              <span v-else class="company-print-layout-preview__placeholder">Non importé</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import CompanyAssetSection from '@/components/company/CompanyAssetSection.vue'
import { route } from '@/lib/routes'

defineProps<{
  logoUrl?: string | null
  signatureUrl?: string | null
  stampUrl?: string | null
  companyName?: string | null
  companyAddress?: string | null
  companyPhone?: string | null
  canManage?: boolean
}>()
</script>

<style scoped>
.company-print-identity__header {
  background: var(--color-surface-elevated);
}

.company-print-layout-preview__title {
  color: var(--color-text-primary);
  margin-bottom: 0.25rem;
}

.company-print-layout-preview__document {
  background: #fff;
  border: 1px solid var(--color-border);
  border-radius: 0.5rem;
  padding: 1rem;
  color: #212529;
}

.company-print-layout-preview__header {
  display: flex;
  gap: 1rem;
  align-items: flex-start;
}

.company-print-layout-preview__logo-wrap {
  flex-shrink: 0;
}

.company-print-layout-preview__logo {
  max-width: 72px;
  max-height: 72px;
  object-fit: contain;
}

.company-print-layout-preview__divider {
  border-top: 1px dashed var(--color-border);
  margin: 1rem 0;
}

.company-print-layout-preview__footer {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
}

.company-print-layout-preview__identity-slot {
  flex: 1;
  text-align: center;
}

.company-print-layout-preview__label {
  display: block;
  font-size: 0.75rem;
  color: var(--color-text-muted);
  margin-bottom: 0.35rem;
}

.company-print-layout-preview__signature,
.company-print-layout-preview__stamp {
  max-width: 120px;
  max-height: 64px;
  object-fit: contain;
}

.company-print-layout-preview__placeholder {
  font-size: 0.75rem;
  color: var(--color-text-muted);
}
</style>
