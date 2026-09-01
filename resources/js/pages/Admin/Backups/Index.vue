<template>
  <AppLayout>
    <IndexPageLayout>
      <PageHeader
        title="Sauvegardes"
        subtitle="Protégez vos données et récupérez votre activité en cas de problème."
        icon="bi-shield-check"
      >
        <template #actions-primary>
          <button
            v-if="canCreate('backups')"
            type="button"
            class="btn btn-primary"
            :disabled="processing || operationsBusy"
            aria-label="Créer une sauvegarde"
            @click="openCreatePanel"
          >
            <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>
            Créer une sauvegarde
          </button>
        </template>
        <template #actions-secondary>
          <button
            v-if="canCreate('backups')"
            type="button"
            class="btn btn-outline-secondary"
            :disabled="processing || operationsBusy"
            aria-label="Importer une sauvegarde"
            @click="openImportPanel"
          >
            <i class="bi bi-upload me-1" aria-hidden="true"></i>
            Importer une sauvegarde
          </button>
        </template>
      </PageHeader>

      <div
        v-if="flashSuccess"
        class="alert alert-success alert-dismissible fade show"
        role="status"
        aria-live="polite"
      >
        <i class="bi bi-check-circle me-2" aria-hidden="true"></i>
        {{ flashSuccess }}
        <button type="button" class="btn-close" aria-label="Fermer" @click="flashSuccess = null" />
      </div>

      <div
        v-if="flashError"
        class="alert alert-danger alert-dismissible fade show"
        role="alert"
        aria-live="assertive"
      >
        <i class="bi bi-exclamation-triangle me-2" aria-hidden="true"></i>
        {{ flashError }}
        <button type="button" class="btn-close" aria-label="Fermer" @click="flashError = null" />
      </div>

      <div
        v-if="operationsBusy && !createProgress"
        class="alert alert-warning"
        role="status"
      >
        <i class="bi bi-hourglass-split me-2" aria-hidden="true"></i>
        Une opération de sauvegarde ou de restauration est en cours. Certaines actions sont temporairement indisponibles.
      </div>

      <div
        v-if="createProgress"
        class="backup-create-progress card"
        role="status"
        aria-live="polite"
        :aria-busy="createProgress.status === 'running' || createProgress.status === 'queued'"
      >
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-2">
            <div>
              <h2 class="backup-panel__title mb-1">Création de sauvegarde</h2>
              <p class="text-muted mb-0 small">{{ createProgress.message }}</p>
            </div>
            <span
              class="backup-badge"
              :class="{
                'backup-badge--progress': createProgress.status === 'running' || createProgress.status === 'queued',
                'backup-badge--success': createProgress.status === 'completed',
                'backup-badge--danger': createProgress.status === 'failed',
              }"
            >
              {{ createProgressStatusLabel }}
            </span>
          </div>
          <div
            class="progress backup-create-progress__bar"
            role="progressbar"
            :aria-valuenow="createProgress.percentage"
            aria-valuemin="0"
            aria-valuemax="100"
          >
            <div
              class="progress-bar"
              :class="{
                'bg-success': createProgress.status === 'completed',
                'bg-danger': createProgress.status === 'failed',
                'progress-bar-striped progress-bar-animated': createProgress.status === 'running' || createProgress.status === 'queued',
              }"
              :style="{ width: `${Math.min(100, Math.max(0, createProgress.percentage))}%` }"
            />
          </div>
          <p class="small text-muted mb-0 mt-2">
            {{ createProgress.percentage }} %
            <span v-if="createProgress.filename" class="ms-2 text-break">· {{ createProgress.filename }}</span>
          </p>
        </div>
      </div>

      <div
        v-if="importPreview"
        class="backup-import-preview card"
        role="status"
        aria-live="polite"
      >
        <div class="card-body">
          <div class="backup-import-preview__head">
            <h2 class="backup-panel__title mb-1">Import réussi</h2>
            <p class="text-muted mb-0 small">
              L'archive a été vérifiée et ajoutée à vos sauvegardes.
              Aucune restauration n'a été effectuée.
            </p>
          </div>
          <dl class="backup-import-preview__grid">
            <div>
              <dt>Fichier</dt>
              <dd class="text-break">{{ importPreview.filename }}</dd>
            </div>
            <div>
              <dt>Type</dt>
              <dd>{{ typeLabel(importPreview.type) }}</dd>
            </div>
            <div>
              <dt>Taille</dt>
              <dd>{{ importPreview.size || '—' }}</dd>
            </div>
            <div>
              <dt>Statut</dt>
              <dd>
                <span class="backup-badge backup-badge--imported">Importée</span>
              </dd>
            </div>
            <div class="backup-import-preview__hash">
              <dt>Empreinte SHA-256</dt>
              <dd class="text-break font-monospace small">{{ importPreview.sha256 || '—' }}</dd>
            </div>
          </dl>
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            @click="importPreview = null"
          >
            Fermer l'aperçu
          </button>
        </div>
      </div>

      <!-- Résumé -->
      <section class="backup-summary" aria-label="État des sauvegardes">
        <article class="backup-summary__card">
          <p class="backup-summary__label">Dernière sauvegarde</p>
          <p class="backup-summary__value">
            <template v-if="summary.last_backup_at">
              {{ formatDateLong(summary.last_backup_at) }}
              <span class="backup-summary__muted">à {{ formatTime(summary.last_backup_at) }}</span>
            </template>
            <template v-else>—</template>
          </p>
        </article>
        <article class="backup-summary__card">
          <p class="backup-summary__label">Type</p>
          <p class="backup-summary__value">
            {{ typeLabel(summary.last_backup_type) }}
          </p>
        </article>
        <article class="backup-summary__card">
          <p class="backup-summary__label">Statut</p>
          <p class="backup-summary__value">
            <span
              v-if="summary.is_busy"
              class="backup-badge backup-badge--progress"
            >En cours</span>
            <span
              v-else-if="summary.last_backup_status"
              class="backup-badge"
              :class="statusBadgeClass(summary.last_backup_status)"
            >{{ statusLabel(summary.last_backup_status) }}</span>
            <span v-else class="backup-summary__muted">—</span>
          </p>
        </article>
        <article class="backup-summary__card">
          <p class="backup-summary__label">Sauvegardes disponibles</p>
          <p class="backup-summary__value backup-summary__value--lg">{{ summary.count }}</p>
        </article>
      </section>

      <!-- Création -->
      <section
        v-if="canCreate('backups') && showCreatePanel"
        id="backup-create"
        class="backup-panel card"
        aria-labelledby="backup-create-title"
      >
        <div class="card-body">
          <div class="backup-panel__head">
            <h2 id="backup-create-title" class="backup-panel__title">
              Créer une sauvegarde
            </h2>
            <p class="backup-panel__subtitle text-muted mb-0">
              Choisissez ce que vous souhaitez protéger.
            </p>
          </div>

          <div class="backup-options" role="radiogroup" aria-label="Type de sauvegarde">
            <button
              type="button"
              class="backup-option"
              :class="{ 'backup-option--selected': createType === 'database' }"
              role="radio"
              :aria-checked="createType === 'database'"
              :disabled="processing || operationsBusy"
              @click="createType = 'database'"
            >
              <span class="backup-option__icon" aria-hidden="true">
                <i class="bi bi-database" />
              </span>
              <span class="backup-option__body">
                <span class="backup-option__title">Base de données uniquement</span>
                <span class="backup-option__desc">
                  Sauvegarde les données de votre application :
                  clients, produits, ventes, stocks, utilisateurs, paramètres, etc.
                </span>
              </span>
            </button>

            <button
              type="button"
              class="backup-option"
              :class="{ 'backup-option--selected': createType === 'full' }"
              role="radio"
              :aria-checked="createType === 'full'"
              :disabled="processing || operationsBusy"
              @click="createType = 'full'"
            >
              <span class="backup-option__icon" aria-hidden="true">
                <i class="bi bi-folder-symlink" />
              </span>
              <span class="backup-option__body">
                <span class="backup-option__title">Base de données + fichiers</span>
                <span class="backup-option__desc">
                  Sauvegarde les données de l'application ainsi que les fichiers associés.
                </span>
              </span>
            </button>
          </div>

          <div class="backup-panel__actions">
            <button
              type="button"
              class="btn btn-primary"
              :disabled="processing || operationsBusy || !createType"
              @click="confirmCreate"
            >
              <span
                v-if="processing && activeAction === 'create'"
                class="spinner-border spinner-border-sm me-1"
                role="status"
                aria-hidden="true"
              />
              <i v-else class="bi bi-shield-check me-1" aria-hidden="true" />
              {{ processing && activeAction === 'create' ? 'Création en cours…' : 'Continuer' }}
            </button>
          </div>
        </div>
      </section>

      <!-- Import -->
      <section
        v-if="canCreate('backups') && showImportPanel"
        id="backup-import"
        class="backup-panel card"
        aria-labelledby="backup-import-title"
      >
        <div class="card-body">
          <div class="backup-panel__head">
            <h2 id="backup-import-title" class="backup-panel__title">
              Importer une sauvegarde
            </h2>
            <p class="backup-panel__subtitle text-muted mb-0">
              Sélectionnez une archive de sauvegarde MKD-Pro.
              L'archive sera vérifiée avant d'être ajoutée à vos sauvegardes disponibles.
            </p>
          </div>

          <div class="backup-import-note" role="note">
            <i class="bi bi-info-circle me-2" aria-hidden="true" />
            <div>
              <strong>Importer</strong> ajoute l'archive à la liste.
              Cela ne <strong>restaure</strong> jamais automatiquement vos données.
            </div>
          </div>

          <label class="backup-dropzone" for="backup-file-input">
            <input
              id="backup-file-input"
              ref="fileInputRef"
              type="file"
              class="visually-hidden"
              accept=".zip,application/zip"
              :disabled="processing || operationsBusy"
              @change="onImportFileChange"
            >
            <i class="bi bi-cloud-upload backup-dropzone__icon" aria-hidden="true" />
            <span class="backup-dropzone__title">
              {{ importFile ? importFile.name : 'Choisir un fichier .zip' }}
            </span>
            <span class="backup-dropzone__hint text-muted">
              Archive MKD-Pro uniquement · max. 10 Go
            </span>
          </label>

          <div class="backup-panel__actions">
            <button
              type="button"
              class="btn btn-outline-secondary"
              :disabled="processing"
              @click="closeImportPanel"
            >
              Annuler
            </button>
            <button
              type="button"
              class="btn btn-success"
              :disabled="processing || operationsBusy || !importFile"
              @click="submitImport"
            >
              <span
                v-if="processing && activeAction === 'import'"
                class="spinner-border spinner-border-sm me-1"
                role="status"
                aria-hidden="true"
              />
              <i v-else class="bi bi-upload me-1" aria-hidden="true" />
              {{ processing && activeAction === 'import' ? 'Import en cours…' : 'Importer' }}
            </button>
          </div>
        </div>
      </section>

      <!-- Liste / empty -->
      <section class="backup-list-section" aria-labelledby="backup-list-title">
        <div class="backup-list-section__head">
          <h2 id="backup-list-title" class="backup-panel__title mb-0">
            Sauvegardes disponibles
          </h2>
        </div>

        <div v-if="backups.length === 0" class="backup-empty card">
          <div class="card-body text-center py-5">
            <div class="backup-empty__icon" aria-hidden="true">
              <i class="bi bi-shield-plus" />
            </div>
            <h3 class="h5 mb-2">Aucune sauvegarde disponible</h3>
            <p class="text-muted mb-4 mx-auto backup-empty__text">
              Créez votre première sauvegarde pour protéger
              les données de votre activité.
            </p>
            <div class="backup-empty__actions">
              <button
                v-if="canCreate('backups')"
                type="button"
                class="btn btn-primary"
                :disabled="processing || operationsBusy"
                @click="openCreatePanel"
              >
                <i class="bi bi-plus-circle me-1" aria-hidden="true" />
                Créer une sauvegarde
              </button>
              <button
                v-if="canCreate('backups')"
                type="button"
                class="btn btn-outline-secondary"
                :disabled="processing || operationsBusy"
                @click="openImportPanel"
              >
                <i class="bi bi-upload me-1" aria-hidden="true" />
                Importer une sauvegarde
              </button>
            </div>
          </div>
        </div>

        <template v-else>
          <!-- Desktop table -->
          <div class="card backup-table-card d-none d-lg-block">
            <div class="table-responsive">
              <table class="table table-hover mb-0 backup-table">
                <thead>
                  <tr>
                    <th scope="col">Type</th>
                    <th scope="col">Date</th>
                    <th scope="col">Taille</th>
                    <th scope="col">Statut</th>
                    <th scope="col">Origine</th>
                    <th scope="col">Intégrité</th>
                    <th scope="col" class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="backup in backups" :key="backup.name">
                    <td>
                      <div class="fw-medium">{{ typeLabel(backup.type) }}</div>
                      <div class="text-muted small text-break">{{ backup.name }}</div>
                      <div v-if="resolvedSha256(backup)" class="text-muted small font-monospace" :title="resolvedSha256(backup) || undefined">
                        {{ truncateHash(resolvedSha256(backup) || '') }}
                      </div>
                    </td>
                    <td>
                      <div>{{ formatDateShort(backup.date) }}</div>
                      <div class="text-muted small">{{ formatTime(backup.date) }}</div>
                    </td>
                    <td>
                      <span class="backup-size">{{ backup.size }}</span>
                    </td>
                    <td>
                      <span class="backup-badge" :class="statusBadgeClass(backup.status)">
                        {{ statusLabel(backup.status) }}
                      </span>
                    </td>
                    <td>
                      <span v-if="backup.source" class="backup-source">{{ sourceLabel(backup.source) }}</span>
                      <span v-else class="text-muted">—</span>
                    </td>
                    <td>
                      <span class="backup-badge" :class="integrityBadgeClass(resolvedIntegrity(backup))">
                        {{ integrityLabel(resolvedIntegrity(backup)) }}
                      </span>
                      <div v-if="resolvedCompatibility(backup)" class="text-muted small mt-1">
                        {{ resolvedCompatibility(backup) }}
                      </div>
                    </td>
                    <td class="text-end">
                      <div class="backup-row-actions" role="group" :aria-label="`Actions pour ${backup.name}`">
                        <button
                          type="button"
                          class="btn btn-sm btn-outline-secondary"
                          :disabled="processing || verifyingName === backup.name"
                          :aria-label="`Vérifier l'intégrité de ${backup.name}`"
                          @click="verifyBackupIntegrity(backup)"
                        >
                          <span
                            v-if="verifyingName === backup.name"
                            class="spinner-border spinner-border-sm me-1"
                            role="status"
                            aria-hidden="true"
                          />
                          <i v-else class="bi bi-shield-check me-1" aria-hidden="true" />
                          Vérifier
                        </button>
                        <button
                          v-if="canDownload('backups')"
                          type="button"
                          class="btn btn-sm btn-outline-primary"
                          :disabled="processing"
                          :aria-label="`Télécharger ${backup.name}`"
                          @click="downloadBackup(backup.name)"
                        >
                          <i class="bi bi-download me-1" aria-hidden="true" />
                          Télécharger
                        </button>
                        <button
                          v-if="canRestore('backups') && canRestoreBackup(backup)"
                          type="button"
                          class="btn btn-sm btn-outline-warning"
                          :disabled="processing || operationsBusy"
                          :aria-label="`Restaurer ${backup.name}`"
                          @click="openRestoreConfirm(backup)"
                        >
                          <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true" />
                          Restaurer
                        </button>
                        <button
                          v-if="canDelete('backups')"
                          type="button"
                          class="btn btn-sm btn-outline-danger"
                          :disabled="processing || operationsBusy"
                          :aria-label="`Supprimer ${backup.name}`"
                          @click="openDeleteConfirm(backup)"
                        >
                          <i class="bi bi-trash me-1" aria-hidden="true" />
                          Supprimer
                        </button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Mobile / tablet cards -->
          <div class="backup-cards d-lg-none" role="list">
            <article
              v-for="backup in backups"
              :key="backup.name"
              class="backup-card card"
              role="listitem"
            >
              <div class="card-body">
                <div class="backup-card__top">
                  <div>
                    <p class="backup-card__type mb-1">{{ typeLabel(backup.type) }}</p>
                    <p class="backup-card__date text-muted mb-0">
                      {{ formatDateShort(backup.date) }} · {{ formatTime(backup.date) }}
                    </p>
                  </div>
                  <span class="backup-badge" :class="statusBadgeClass(backup.status)">
                    {{ statusLabel(backup.status) }}
                  </span>
                </div>
                <p class="backup-card__meta text-muted small mb-3">
                  {{ backup.size }}
                  <span class="mx-1" aria-hidden="true">·</span>
                  <template v-if="backup.source">
                    {{ sourceLabel(backup.source) }}
                    <span class="mx-1" aria-hidden="true">·</span>
                  </template>
                  <span class="backup-badge" :class="integrityBadgeClass(resolvedIntegrity(backup))">
                    {{ integrityLabel(resolvedIntegrity(backup)) }}
                  </span>
                  <span class="mx-1" aria-hidden="true">·</span>
                  <span class="text-break">{{ backup.name }}</span>
                </p>
                <div class="backup-card__actions">
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary"
                    :disabled="processing || verifyingName === backup.name"
                    :aria-label="`Vérifier l'intégrité de ${backup.name}`"
                    @click="verifyBackupIntegrity(backup)"
                  >
                    Vérifier
                  </button>
                  <button
                    v-if="canDownload('backups')"
                    type="button"
                    class="btn btn-sm btn-outline-primary"
                    :disabled="processing"
                    :aria-label="`Télécharger ${backup.name}`"
                    @click="downloadBackup(backup.name)"
                  >
                    Télécharger
                  </button>
                  <button
                    v-if="canRestore('backups') && canRestoreBackup(backup)"
                    type="button"
                    class="btn btn-sm btn-outline-warning"
                    :disabled="processing || operationsBusy"
                    :aria-label="`Restaurer ${backup.name}`"
                    @click="openRestoreConfirm(backup)"
                  >
                    Restaurer
                  </button>
                  <button
                    v-if="canDelete('backups')"
                    type="button"
                    class="btn btn-sm btn-outline-danger"
                    :disabled="processing || operationsBusy"
                    :aria-label="`Supprimer ${backup.name}`"
                    @click="openDeleteConfirm(backup)"
                  >
                    Supprimer
                  </button>
                </div>
              </div>
            </article>
          </div>
        </template>
      </section>

      <p class="backup-disk-hint text-muted small mt-3 mb-0">
        Stockage : disque <strong>{{ disk }}</strong>.
        La restauration remplace les données de la base cible choisie — jamais automatiquement à l'import.
      </p>
    </IndexPageLayout>

    <!-- Modal create confirm -->
    <Teleport to="body">
      <div
        v-if="createConfirmOpen"
        class="backup-modal-backdrop"
        role="presentation"
        @click.self="!processing && closeCreateConfirm()"
      >
        <div
          class="backup-modal"
          role="dialog"
          aria-modal="true"
          aria-labelledby="create-confirm-title"
        >
          <h3 id="create-confirm-title" class="backup-modal__title">
            Créer cette sauvegarde ?
          </h3>
          <dl class="backup-modal__dl">
            <div>
              <dt>Type</dt>
              <dd>{{ createType === 'database' ? 'Base de données uniquement' : 'Base de données + fichiers' }}</dd>
            </div>
          </dl>
          <p class="text-muted small mb-4">
            Cette opération peut prendre quelques instants.
          </p>
          <div class="backup-modal__actions">
            <button
              type="button"
              class="btn btn-outline-secondary"
              :disabled="processing"
              @click="closeCreateConfirm"
            >
              Annuler
            </button>
            <button
              type="button"
              class="btn btn-primary"
              :disabled="processing"
              @click="runCreate"
            >
              <span
                v-if="processing && activeAction === 'create'"
                class="spinner-border spinner-border-sm me-1"
                role="status"
                aria-hidden="true"
              />
              Créer la sauvegarde
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Modal delete -->
    <Teleport to="body">
      <div
        v-if="deleteTarget"
        class="backup-modal-backdrop"
        role="presentation"
        @click.self="!processing && (deleteTarget = null)"
      >
        <div
          class="backup-modal"
          role="dialog"
          aria-modal="true"
          aria-labelledby="delete-confirm-title"
        >
          <h3 id="delete-confirm-title" class="backup-modal__title">
            Supprimer cette sauvegarde ?
          </h3>
          <p class="mb-2">
            <strong>{{ deleteTarget.name }}</strong>
          </p>
          <p class="text-muted mb-4">
            Cette sauvegarde sera définitivement supprimée.
            Cette action est irréversible.
          </p>
          <div class="backup-modal__actions">
            <button
              type="button"
              class="btn btn-outline-secondary"
              :disabled="processing"
              @click="deleteTarget = null"
            >
              Annuler
            </button>
            <button
              type="button"
              class="btn btn-danger"
              :disabled="processing"
              @click="runDelete"
            >
              <span
                v-if="processing && activeAction === 'delete'"
                class="spinner-border spinner-border-sm me-1"
                role="status"
                aria-hidden="true"
              />
              Supprimer
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Modal restore (multi-step) -->
    <Teleport to="body">
      <div
        v-if="restoreTarget"
        class="backup-modal-backdrop"
        role="presentation"
        @click.self="!processing && closeRestore()"
      >
        <div
          class="backup-modal backup-modal--danger backup-modal--restore"
          role="dialog"
          aria-modal="true"
          aria-labelledby="restore-confirm-title"
        >
          <h3 id="restore-confirm-title" class="backup-modal__title">
            Restaurer cette sauvegarde ?
          </h3>
          <p class="small text-muted mb-3">
            Étape {{ restoreStep }} / 2 · <span class="text-break">{{ restoreTarget.name }}</span>
          </p>

          <!-- Step 1: inspection preview -->
          <div v-if="restoreStep === 1">
            <div v-if="restoreInspectLoading" class="text-center py-4">
              <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" />
              Analyse de l'archive…
            </div>
            <div v-else-if="restoreInspectError" class="alert alert-danger" role="alert">
              {{ restoreInspectError }}
            </div>
            <template v-else-if="restoreInspection">
              <div class="backup-restore-warning" role="alert">
                <p class="mb-2">
                  Cette opération remplacera les données de la <strong>base cible</strong>
                  par celles de cette sauvegarde (<strong>base de données uniquement</strong>).
                </p>
                <p class="mb-0">
                  Aucun fichier applicatif ne sera modifié.
                  La base métier <code>{{ protected_database || 'gestion' }}</code> est interdite.
                </p>
              </div>

              <dl class="backup-inspect-grid">
                <div>
                  <dt>Type</dt>
                  <dd>{{ typeLabel(restoreInspection.type) }}</dd>
                </div>
                <div>
                  <dt>Dump SQL</dt>
                  <dd>{{ restoreInspection.sql_present ? 'Présent' : 'Absent' }}</dd>
                </div>
                <div>
                  <dt>Verdict</dt>
                  <dd>{{ restoreInspection.verdict || '—' }}</dd>
                </div>
                <div>
                  <dt>Restaurable</dt>
                  <dd>
                    <span
                      class="backup-badge"
                      :class="restoreInspection.can_restore ? 'backup-badge--success' : 'backup-badge--danger'"
                    >
                      {{ restoreInspection.can_restore ? 'Oui' : 'Non' }}
                    </span>
                  </dd>
                </div>
                <div>
                  <dt>Intégrité</dt>
                  <dd>
                    <span class="backup-badge" :class="integrityBadgeClass(mapIntegrityResult(restoreInspection.integrity))">
                      {{ integrityResultLabel(restoreInspection.integrity) }}
                    </span>
                  </dd>
                </div>
                <div v-if="restoreInspection.compatibility" class="backup-inspect-grid__full">
                  <dt>Compatibilité</dt>
                  <dd>{{ restoreInspection.compatibility }}</dd>
                </div>
                <div v-if="restoreInspection.looks_like_schema_only" class="backup-inspect-grid__full">
                  <dt>Attention</dt>
                  <dd>Le dump semble ne contenir que le schéma (peu ou pas de données métier).</dd>
                </div>
                <div v-if="restoreInspection.contains_dotenv" class="backup-inspect-grid__full">
                  <dt>Attention</dt>
                  <dd>L'archive contient un fichier .env — restez prudent.</dd>
                </div>
                <div class="backup-inspect-grid__full">
                  <dt>Empreinte SHA-256</dt>
                  <dd class="text-break font-monospace small">{{ restoreInspection.sha256 || '—' }}</dd>
                </div>
              </dl>
            </template>

            <div class="backup-modal__actions">
              <button
                type="button"
                class="btn btn-outline-secondary"
                :disabled="processing"
                @click="closeRestore"
              >
                Annuler
              </button>
              <button
                type="button"
                class="btn btn-warning"
                :disabled="processing || restoreInspectLoading || !restoreInspection?.can_restore"
                @click="restoreStep = 2"
              >
                Continuer
              </button>
            </div>
          </div>

          <!-- Step 2: target + safety + RESTORE -->
          <div v-else>
            <div class="backup-restore-warning mb-3" role="alert">
              <p class="mb-0">
                Dernière confirmation : les données actuelles de la base cible
                seront <strong>remplacées</strong>. Cette action est irréversible
                pour cette base.
              </p>
            </div>

            <div class="mb-3">
              <label class="form-label" for="restore-target-select">Base cible</label>
              <select
                id="restore-target-select"
                v-model="restoreTargetDb"
                class="form-select"
                :disabled="processing"
              >
                <option v-for="db in restoreTargets" :key="db" :value="db">{{ db }}</option>
              </select>
            </div>

            <div class="form-check mb-3">
              <input
                id="restore-acknowledge"
                v-model="restoreAcknowledge"
                class="form-check-input"
                type="checkbox"
                :disabled="processing"
              >
              <label class="form-check-label" for="restore-acknowledge">
                Je comprends que les données de la base cible seront remplacées.
              </label>
            </div>

            <div class="form-check mb-3">
              <input
                id="restore-safety"
                v-model="restoreSafetyBackup"
                class="form-check-input"
                type="checkbox"
                :disabled="processing"
              >
              <label class="form-check-label" for="restore-safety">
                Créer d'abord une sauvegarde de sécurité de l'application (base de données).
                Recommandé avant toute restauration.
              </label>
            </div>

            <div class="mb-4">
              <label class="form-label" for="restore-phrase-input">
                Saisissez exactement <strong>RESTORE</strong> pour confirmer
              </label>
              <input
                id="restore-phrase-input"
                v-model="restorePhrase"
                type="text"
                class="form-control"
                autocomplete="off"
                :disabled="processing"
                aria-describedby="restore-phrase-help"
              >
              <div id="restore-phrase-help" class="form-text">
                La restauration ne se lance jamais au simple clic sur une ligne.
              </div>
            </div>

            <div class="backup-modal__actions">
              <button
                type="button"
                class="btn btn-outline-secondary"
                :disabled="processing"
                @click="restoreStep = 1"
              >
                Retour
              </button>
              <button
                type="button"
                class="btn btn-warning"
                :disabled="!canSubmitRestore"
                @click="runRestore"
              >
                <span
                  v-if="processing && activeAction === 'restore'"
                  class="spinner-border spinner-border-sm me-1"
                  role="status"
                  aria-hidden="true"
                />
                Restaurer la base
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </AppLayout>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import {
  BackupCreatePollingController,
  backupCreateSuccessListRefreshOptions,
  shouldInitializeQueuedProgress,
  shouldStartBackupCreatePolling,
  type BackupCreateProgressSnapshot,
  type BackupCreatePollingHooks,
} from '@/utils/backupCreatePolling'
import {
  resolveRestoreErrorResponse,
  resolveRestoreSuccessResponse,
} from '@/utils/backupRestoreResponse'
import { router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/layouts/BootstrapLayout.vue'
import IndexPageLayout from '@/components/page/IndexPageLayout.vue'
import PageHeader from '@/components/page/PageHeader.vue'
import { route } from '@/lib/routes'
import { getCsrfToken } from '@/lib/csrf'
import { usePermissions } from '@/composables/usePermissions'
import Swal from 'sweetalert2'

const { canCreate, canDownload, canDelete, canRestore } = usePermissions()

type BackupType = 'database' | 'full' | 'unknown' | string | null
type BackupStatus = 'valid' | 'invalid' | string | null

interface Backup {
  name: string
  path: string
  size: string
  size_bytes?: number
  date: string
  timestamp: number
  type?: BackupType
  status?: BackupStatus
  source?: string | null
  sha256?: string | null
  integrity?: string | null
  compatibility?: string | null
  manifest_version?: number | null
}

interface ImportPreview {
  filename: string
  type?: string
  status?: string
  size?: string
  size_bytes?: number | null
  sha256?: string | null
  restored?: boolean
}

interface CreateProgress {
  job_id: string
  status: string
  percentage: number
  message: string
  only_db?: boolean
  filename?: string | null
  updated_at?: string | null
}

interface RestoreInspection {
  filename?: string
  readable: boolean
  can_restore: boolean
  size_bytes?: number | null
  sha256?: string | null
  verdict?: string | null
  sql_present?: boolean
  sql_file_count?: number
  business_inserts?: number
  looks_like_schema_only?: boolean
  has_attachments_paths?: boolean
  contains_dotenv?: boolean
  type?: string | null
  source?: string | null
  status?: string | null
  integrity?: string | null
  compatibility?: string | null
  integrity_message?: string | null
  message?: string
}

interface BackupSummary {
  count: number
  last_backup_at: string | null
  last_backup_type: BackupType
  last_backup_status: BackupStatus
  is_busy: boolean
}

interface Props {
  backups: Backup[]
  disk: string
  restore_allowed_databases?: string[]
  protected_database?: string
  operations_busy?: boolean
  summary?: BackupSummary
}

const props = withDefaults(defineProps<Props>(), {
  restore_allowed_databases: () => [],
  protected_database: 'gestion',
  operations_busy: false,
  summary: () => ({
    count: 0,
    last_backup_at: null,
    last_backup_type: null,
    last_backup_status: null,
    is_busy: false,
  }),
})

const page = usePage()

const processing = ref(false)
const activeAction = ref<'create' | 'import' | 'delete' | 'restore' | null>(null)
const showCreatePanel = ref(false)
const showImportPanel = ref(false)
const createType = ref<'database' | 'full'>('full')
const createConfirmOpen = ref(false)
const importFile = ref<File | null>(null)
const fileInputRef = ref<HTMLInputElement | null>(null)
const deleteTarget = ref<Backup | null>(null)
const restoreTarget = ref<Backup | null>(null)
const restoreTargetDb = ref('')
const restorePhrase = ref('')
const restoreStep = ref(1)
const restoreAcknowledge = ref(false)
const restoreSafetyBackup = ref(true)
const restoreInspectLoading = ref(false)
const restoreInspectError = ref<string | null>(null)
const restoreInspection = ref<RestoreInspection | null>(null)
const flashSuccess = ref<string | null>(null)
const flashError = ref<string | null>(null)
const importPreview = ref<ImportPreview | null>(null)
const createProgress = ref<CreateProgress | null>(null)
const createJobId = ref<string | null>(null)
const verifyingName = ref<string | null>(null)
const integrityOverrides = ref<Record<string, {
  integrity?: string
  sha256?: string | null
  compatibility?: string | null
}>>({})
const createPolling = new BackupCreatePollingController()
let createListRefreshPending = false

const createProgressStatusLabel = computed(() => {
  const status = createProgress.value?.status
  if (status === 'queued') {
    return 'En file'
  }
  if (status === 'running') {
    return 'En cours'
  }
  if (status === 'completed') {
    return 'Réussie'
  }
  if (status === 'failed') {
    return 'Échec'
  }
  return '—'
})

const operationsBusy = computed(
  () =>
    props.operations_busy
    || props.summary.is_busy
    || createProgress.value?.status === 'queued'
    || createProgress.value?.status === 'running',
)

const restoreTargets = computed(() =>
  props.restore_allowed_databases?.length
    ? props.restore_allowed_databases
    : ['gestion_recovery', 'gestion_test'],
)

const canSubmitRestore = computed(() =>
  !processing.value
  && restoreAcknowledge.value
  && restorePhrase.value === 'RESTORE'
  && !!restoreTargetDb.value
  && restoreTargetDb.value !== (props.protected_database || 'gestion')
  && !!restoreInspection.value?.can_restore,
)

const applyCreateProgressSnapshot = (data: BackupCreateProgressSnapshot) => {
  createProgress.value = {
    job_id: data.job_id,
    status: data.status,
    percentage: Number(data.percentage || 0),
    message: data.message || '',
    only_db: data.only_db,
    filename: data.filename ?? null,
    updated_at: data.updated_at ?? null,
  }
}

const buildCreatePollingHooks = (): BackupCreatePollingHooks => ({
  fetchStatus: async (jobId) => {
    const response = await fetch(route('admin.backups.create-status', jobId), {
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': getCsrfToken(),
      },
      credentials: 'same-origin',
    })

    if (response.status === 404) {
      return 'not_found'
    }
    if (!response.ok) {
      return 'skip'
    }

    const data = await response.json() as CreateProgress
    return {
      job_id: data.job_id || jobId,
      status: data.status,
      percentage: Number(data.percentage || 0),
      message: data.message || '',
      only_db: data.only_db,
      filename: data.filename ?? null,
      updated_at: data.updated_at ?? null,
    }
  },
  onProgress: (progress) => {
    applyCreateProgressSnapshot(progress)
  },
  onCompleted: (progress) => {
    applyCreateProgressSnapshot(progress)
    processing.value = false
    activeAction.value = null
    // Prevent onMounted/flash watcher from re-arming the same job if props are reused.
    const flash = (page.props as { flash?: { backup_job_id?: string | null } }).flash
    if (flash && flash.backup_job_id) {
      flash.backup_job_id = null
    }
    showSuccessNotice(progress.message || 'Sauvegarde créée avec succès.')
    if (!createListRefreshPending) {
      createListRefreshPending = true
      router.visit(route('admin.backups.index'), {
        ...backupCreateSuccessListRefreshOptions,
        only: [...backupCreateSuccessListRefreshOptions.only],
        onFinish: () => {
          createListRefreshPending = false
        },
      })
    }
  },
  onFailed: (progress) => {
    applyCreateProgressSnapshot(progress)
    processing.value = false
    activeAction.value = null
    showErrorNotice(progress.message || 'La sauvegarde n\'a pas pu être créée. Veuillez réessayer.')
  },
  onTimeout: (jobId) => {
    createProgress.value = {
      job_id: jobId,
      status: 'failed',
      percentage: 0,
      message: 'Délai dépassé. Vérifiez qu\'un worker de file d\'attente est actif, puis réessayez.',
    }
    processing.value = false
    activeAction.value = null
  },
  intervalMs: 2000,
  maxAttempts: 180,
})

const beginCreatePolling = (jobId: string, source: 'success' | 'flash'): boolean => {
  const mayStart = source === 'flash'
    ? shouldStartBackupCreatePolling({
        jobId,
        activeJobId: createPolling.getActiveJobId(),
        pollingActive: createPolling.isPollingActive(),
        terminalJobIds: createPolling.getTerminalJobIds(),
        consumedFlashJobIds: createPolling.getConsumedFlashJobIds(),
        fromFlash: true,
      })
    : shouldStartBackupCreatePolling({
        jobId,
        activeJobId: createPolling.getActiveJobId(),
        pollingActive: createPolling.isPollingActive(),
        terminalJobIds: createPolling.getTerminalJobIds(),
        fromFlash: false,
      })

  if (!mayStart) {
    if (source === 'flash') {
      createPolling.markFlashConsumed(jobId)
    }
    return false
  }

  createJobId.value = jobId
  if (
    shouldInitializeQueuedProgress({
      jobId,
      current: createProgress.value
        ? {
            job_id: createProgress.value.job_id,
            status: createProgress.value.status,
            percentage: createProgress.value.percentage,
            message: createProgress.value.message,
          }
        : null,
    })
  ) {
    createProgress.value = {
      job_id: jobId,
      status: 'queued',
      percentage: 5,
      message: 'Création de sauvegarde en file d\'attente…',
    }
  }

  return source === 'flash'
    ? createPolling.startFromFlash(jobId, buildCreatePollingHooks())
    : createPolling.start(jobId, buildCreatePollingHooks())
}

const syncFlashFromPage = () => {
  const flash = (page.props as {
    flash?: {
      success?: string
      error?: string
      import_preview?: ImportPreview | null
      backup_job_id?: string | null
    }
  }).flash
  if (flash?.success) {
    flashSuccess.value = String(flash.success)
  }
  if (flash?.error) {
    flashError.value = String(flash.error)
  }
  if (flash?.import_preview) {
    importPreview.value = flash.import_preview
  }
  // Flash may recover polling after hard navigation, but never re-arms a consumed/terminal job.
  if (flash?.backup_job_id) {
    beginCreatePolling(String(flash.backup_job_id), 'flash')
  }
}

const onGlobalKeydown = (event: KeyboardEvent) => {
  if (event.key !== 'Escape' || processing.value) {
    return
  }
  if (createConfirmOpen.value) {
    closeCreateConfirm()
  } else if (deleteTarget.value) {
    deleteTarget.value = null
  } else if (restoreTarget.value) {
    closeRestore()
  }
}

onMounted(() => {
  syncFlashFromPage()
  document.addEventListener('keydown', onGlobalKeydown)
})

onUnmounted(() => {
  document.removeEventListener('keydown', onGlobalKeydown)
  createPolling.stopAndClearActive()
})

watch(
  () => (page.props as {
    flash?: {
      success?: string
      error?: string
      import_preview?: ImportPreview | null
      backup_job_id?: string | null
    }
  }).flash,
  () => syncFlashFromPage(),
  { deep: true },
)

const formatDateLong = (dateString: string): string => {
  const date = new Date(dateString.replace(' ', 'T'))
  if (Number.isNaN(date.getTime())) {
    return dateString
  }
  return date.toLocaleDateString('fr-FR', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })
}

const formatDateShort = (dateString: string): string => {
  const date = new Date(dateString.replace(' ', 'T'))
  if (Number.isNaN(date.getTime())) {
    return dateString
  }
  return date.toLocaleDateString('fr-FR', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

const formatTime = (dateString: string): string => {
  const date = new Date(dateString.replace(' ', 'T'))
  if (Number.isNaN(date.getTime())) {
    return ''
  }
  return date.toLocaleTimeString('fr-FR', {
    hour: '2-digit',
    minute: '2-digit',
  })
}

const typeLabel = (type: BackupType): string => {
  if (type === 'database') {
    return 'Base de données'
  }
  if (type === 'full') {
    return 'Base + fichiers'
  }
  if (!type) {
    return '—'
  }
  return 'Archive'
}

const statusLabel = (status: BackupStatus): string => {
  if (status === 'valid') {
    return 'Réussie'
  }
  if (status === 'imported') {
    return 'Importée'
  }
  if (status === 'invalid') {
    return 'Invalide'
  }
  return '—'
}

const statusBadgeClass = (status: BackupStatus): string => {
  if (status === 'valid') {
    return 'backup-badge--success'
  }
  if (status === 'imported') {
    return 'backup-badge--imported'
  }
  if (status === 'invalid') {
    return 'backup-badge--danger'
  }
  return 'backup-badge--muted'
}

const sourceLabel = (source: string | null | undefined): string => {
  if (source === 'import') {
    return 'Importée'
  }
  if (source === 'manual') {
    return 'Manuelle'
  }
  if (source === 'scheduler') {
    return 'Automatique'
  }
  return '—'
}

const resolvedIntegrity = (backup: Backup): string | null | undefined =>
  integrityOverrides.value[backup.name]?.integrity ?? backup.integrity

const resolvedSha256 = (backup: Backup): string | null | undefined =>
  integrityOverrides.value[backup.name]?.sha256 ?? backup.sha256

const resolvedCompatibility = (backup: Backup): string | null | undefined =>
  integrityOverrides.value[backup.name]?.compatibility ?? backup.compatibility

const truncateHash = (hash: string): string => {
  if (hash.length <= 16) {
    return hash
  }
  return `${hash.slice(0, 8)}…${hash.slice(-8)}`
}

const integrityLabel = (integrity: string | null | undefined): string => {
  switch (integrity) {
    case 'manifest_present':
      return 'Manifeste valide'
    case 'VALID':
    case 'valid':
      return 'Hash vérifié'
    case 'INVALID':
    case 'invalid':
      return 'Intégrité invalide'
    case 'MISSING':
    case 'missing':
      return 'Fichier manquant'
    case 'MANIFEST_INVALID':
    case 'manifest_invalid':
      return 'Manifeste invalide / absent'
    case 'unknown':
    default:
      return 'Intégrité inconnue'
  }
}

const integrityBadgeClass = (integrity: string | null | undefined): string => {
  switch (integrity) {
    case 'manifest_present':
    case 'VALID':
    case 'valid':
      return 'backup-badge--success'
    case 'INVALID':
    case 'invalid':
    case 'MISSING':
    case 'missing':
      return 'backup-badge--danger'
    case 'MANIFEST_INVALID':
    case 'manifest_invalid':
      return 'backup-badge--progress'
    default:
      return 'backup-badge--muted'
  }
}

const mapIntegrityResult = (result: string | null | undefined): string => {
  if (!result) {
    return 'unknown'
  }
  return result
}

const integrityResultLabel = (result: string | null | undefined): string => {
  switch (result) {
    case 'VALID':
      return 'Hash vérifié'
    case 'INVALID':
      return 'Intégrité invalide'
    case 'MISSING':
      return 'Fichier manquant'
    case 'MANIFEST_INVALID':
      return 'Manifeste invalide / absent'
    default:
      return integrityLabel(result)
  }
}

const canRestoreBackup = (backup: Backup): boolean =>
  backup.status === 'valid' || backup.status === 'imported'

const verifyBackupIntegrity = async (backup: Backup) => {
  if (verifyingName.value) {
    return
  }
  verifyingName.value = backup.name
  try {
    const response = await fetch(route('admin.backups.verify-integrity', backup.name), {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': getCsrfToken(),
      },
      credentials: 'same-origin',
      body: JSON.stringify({}),
    })
    const data = await response.json() as {
      result?: string
      message?: string
      sha256_actual?: string | null
      compatibility?: string | null
    }

    const mapped = data.result || 'unknown'
    integrityOverrides.value = {
      ...integrityOverrides.value,
      [backup.name]: {
        integrity: mapped,
        sha256: data.sha256_actual ?? backup.sha256,
        compatibility: data.compatibility ?? backup.compatibility,
      },
    }

    if (data.result === 'VALID') {
      showSuccessNotice(data.message || 'Intégrité vérifiée.')
    } else {
      showErrorNotice(data.message || 'La vérification d\'intégrité a échoué.')
    }
  } catch {
    showErrorNotice('Impossible de vérifier l\'intégrité de cette sauvegarde.')
  } finally {
    verifyingName.value = null
  }
}

const openCreatePanel = () => {
  showImportPanel.value = false
  showCreatePanel.value = true
  requestAnimationFrame(() => {
    document.getElementById('backup-create')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  })
}

const openImportPanel = () => {
  showCreatePanel.value = false
  showImportPanel.value = true
  importFile.value = null
  requestAnimationFrame(() => {
    document.getElementById('backup-import')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  })
}

const closeImportPanel = () => {
  showImportPanel.value = false
  importFile.value = null
  if (fileInputRef.value) {
    fileInputRef.value.value = ''
  }
}

const confirmCreate = () => {
  if (processing.value || operationsBusy.value) {
    return
  }
  createConfirmOpen.value = true
}

const closeCreateConfirm = () => {
  if (!processing.value) {
    createConfirmOpen.value = false
  }
}

const showSuccessNotice = (message: string) => {
  flashSuccess.value = message
  flashError.value = null
  void Swal.fire({
    icon: 'success',
    title: 'Succès',
    text: message,
    confirmButtonText: 'OK',
    confirmButtonColor: '#198754',
    timer: 6000,
    timerProgressBar: true,
    allowOutsideClick: true,
  })
}

const showErrorNotice = (message: string) => {
  flashError.value = message
  flashSuccess.value = null
  void Swal.fire({
    icon: 'error',
    title: 'Erreur',
    text: message,
    confirmButtonText: 'OK',
    confirmButtonColor: '#dc3545',
  })
}

const friendlyError = (errors: unknown, fallback: string): string => {
  if (typeof errors === 'string' && errors.trim()) {
    return errors
  }
  if (errors && typeof errors === 'object') {
    const record = errors as Record<string, unknown>
    for (const key of Object.keys(record)) {
      const value = record[key]
      if (typeof value === 'string' && value.trim()) {
        return value
      }
      if (Array.isArray(value) && typeof value[0] === 'string') {
        return value[0]
      }
    }
  }
  return fallback
}

const runCreate = () => {
  if (processing.value || operationsBusy.value) {
    return
  }

  processing.value = true
  activeAction.value = 'create'
  flashError.value = null

  router.post(
    route('admin.backups.store'),
    { only_db: createType.value === 'database' },
    {
      preserveScroll: true,
      onSuccess: (inertiaPage) => {
        createConfirmOpen.value = false
        showCreatePanel.value = false
        const flash = (inertiaPage.props as {
          flash?: {
            success?: string
            error?: string
            backup_job_id?: string | null
          }
        }).flash
        if (flash?.error) {
          processing.value = false
          activeAction.value = null
          showErrorNotice(String(flash.error))
          return
        }
        if (flash?.backup_job_id) {
          if (flash.success) {
            flashSuccess.value = String(flash.success)
          }
          // Prefer onSuccess as primary start; flash watcher is idempotent via consumed set.
          createPolling.markFlashConsumed(String(flash.backup_job_id))
          beginCreatePolling(String(flash.backup_job_id), 'success')
          return
        }
        processing.value = false
        activeAction.value = null
        showSuccessNotice(
          flash?.success
            ? String(flash.success)
            : 'Sauvegarde créée avec succès.',
        )
      },
      onError: (errors) => {
        processing.value = false
        activeAction.value = null
        showErrorNotice(
          friendlyError(errors, 'La sauvegarde n\'a pas pu être créée. Veuillez réessayer.'),
        )
      },
      onFinish: () => {
        // Keep processing=true while polling an async job.
        if (!createJobId.value) {
          processing.value = false
          activeAction.value = null
        }
      },
    },
  )
}

const onImportFileChange = (event: Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  if (!file) {
    importFile.value = null
    return
  }
  if (!file.name.toLowerCase().endsWith('.zip')) {
    importFile.value = null
    input.value = ''
    showErrorNotice('Le fichier doit être une archive .zip.')
    return
  }
  const maxSize = 10 * 1024 * 1024 * 1024
  if (file.size > maxSize) {
    importFile.value = null
    input.value = ''
    showErrorNotice('Le fichier est trop volumineux (maximum 10 Go).')
    return
  }
  importFile.value = file
}

const submitImport = () => {
  if (processing.value || !importFile.value) {
    return
  }

  processing.value = true
  activeAction.value = 'import'

  void Swal.fire({
    title: 'Import en cours',
    html: `Vérification de <strong>${importFile.value.name}</strong>…<br>Aucune restauration ne sera lancée.`,
    allowOutsideClick: false,
    allowEscapeKey: false,
    showConfirmButton: false,
    didOpen: () => {
      Swal.showLoading()
    },
  })

  const formData = new FormData()
  formData.append('backup_file', importFile.value)

  router.post(route('admin.backups.import'), formData, {
    forceFormData: true,
    preserveScroll: true,
      onSuccess: (inertiaPage) => {
      closeImportPanel()
      const flash = (inertiaPage.props as {
        flash?: {
          success?: string
          error?: string
          import_preview?: ImportPreview | null
        }
      }).flash
      Swal.close()
      if (flash?.error) {
        showErrorNotice(String(flash.error))
        return
      }
      if (flash?.import_preview) {
        importPreview.value = flash.import_preview
      }
      showSuccessNotice(
        flash?.success
          ? String(flash.success)
          : 'Sauvegarde importée avec succès. Aucune restauration n\'a été effectuée.',
      )
    },
    onError: (errors) => {
      Swal.close()
      showErrorNotice(
        friendlyError(errors, 'L\'import de la sauvegarde a échoué. Veuillez réessayer.'),
      )
    },
    onFinish: () => {
      processing.value = false
      activeAction.value = null
    },
  })
}

const downloadBackup = (backupName: string) => {
  window.location.href = route('admin.backups.download', backupName)
}

const openDeleteConfirm = (backup: Backup) => {
  deleteTarget.value = backup
}

const runDelete = () => {
  if (processing.value || !deleteTarget.value) {
    return
  }

  const name = deleteTarget.value.name
  processing.value = true
  activeAction.value = 'delete'

  router.delete(route('admin.backups.destroy', name), {
    preserveScroll: true,
    onSuccess: (inertiaPage) => {
      deleteTarget.value = null
      const flash = (inertiaPage.props as { flash?: { success?: string; error?: string } }).flash
      if (flash?.error) {
        showErrorNotice(String(flash.error))
        return
      }
      showSuccessNotice(flash?.success ? String(flash.success) : 'Sauvegarde supprimée.')
    },
    onError: (errors) => {
      showErrorNotice(
        friendlyError(errors, 'La suppression a échoué. Veuillez réessayer.'),
      )
    },
    onFinish: () => {
      processing.value = false
      activeAction.value = null
    },
  })
}

const openRestoreConfirm = (backup: Backup) => {
  restoreTarget.value = backup
  restorePhrase.value = ''
  restoreTargetDb.value = restoreTargets.value[0] ?? ''
  restoreStep.value = 1
  restoreAcknowledge.value = false
  restoreSafetyBackup.value = true
  restoreInspection.value = null
  restoreInspectError.value = null
  void loadRestoreInspection(backup.name)
}

const loadRestoreInspection = async (backupName: string) => {
  restoreInspectLoading.value = true
  restoreInspectError.value = null
  try {
    const response = await fetch(route('admin.backups.inspect', backupName), {
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': getCsrfToken(),
      },
      credentials: 'same-origin',
    })
    const data = await response.json() as RestoreInspection
    if (!response.ok) {
      restoreInspectError.value = data.message || 'Impossible d\'analyser cette sauvegarde.'
      restoreInspection.value = null
      return
    }
    restoreInspection.value = data
    if (!data.can_restore) {
      restoreInspectError.value = 'Cette archive ne peut pas être restaurée en l\'état.'
    }
  } catch {
    restoreInspectError.value = 'Impossible d\'analyser cette sauvegarde.'
    restoreInspection.value = null
  } finally {
    restoreInspectLoading.value = false
  }
}

const closeRestore = (force = false) => {
  if (!force && processing.value) {
    return
  }
  restoreTarget.value = null
  restorePhrase.value = ''
  restoreStep.value = 1
  restoreAcknowledge.value = false
  restoreInspection.value = null
  restoreInspectError.value = null
}

const runRestore = () => {
  if (processing.value || !restoreTarget.value || !canSubmitRestore.value) {
    return
  }

  const name = restoreTarget.value.name
  processing.value = true
  activeAction.value = 'restore'

  const safetyNote = restoreSafetyBackup.value
    ? '<br>Sauvegarde de sécurité : oui'
    : ''

  void Swal.fire({
    title: 'Restauration en cours',
    html: `Cible : <strong>${restoreTargetDb.value}</strong><br>Fichiers applicatifs : non touchés${safetyNote}`,
    allowOutsideClick: false,
    allowEscapeKey: false,
    showConfirmButton: false,
    didOpen: () => {
      Swal.showLoading()
    },
  })

  router.post(
    route('admin.backups.restore', name),
    {
      confirm: true,
      confirmation_phrase: 'RESTORE',
      target_database: restoreTargetDb.value,
      restore_mode: 'database',
      safety_backup: restoreSafetyBackup.value,
      acknowledge_overwrite: true,
    },
    {
      preserveScroll: true,
      onSuccess: (inertiaPage) => {
        const resolution = resolveRestoreSuccessResponse(inertiaPage)
        Swal.close()
        if (resolution.outcome === 'error') {
          showErrorNotice(resolution.message)
          return
        }
        closeRestore(true)
        showSuccessNotice(resolution.message)
        router.visit(route('admin.backups.index'), {
          preserveScroll: true,
          only: ['backups', 'summary', 'operations_busy'],
        })
      },
      onError: (errors) => {
        Swal.close()
        showErrorNotice(resolveRestoreErrorResponse(errors))
      },
      onFinish: () => {
        processing.value = false
        activeAction.value = null
      },
    },
  )
}
</script>

<style scoped>
.backup-summary {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.85rem;
  margin-bottom: 1.25rem;
}

.backup-summary__card {
  background: var(--color-surface-elevated);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md, 0.75rem);
  padding: 1rem 1.1rem;
  transition: background-color var(--transition-theme, 0.2s ease),
    border-color var(--transition-theme, 0.2s ease);
}

.backup-summary__label {
  margin: 0 0 0.35rem;
  font-size: 0.75rem;
  letter-spacing: 0.03em;
  text-transform: uppercase;
  color: var(--color-text-muted);
}

.backup-summary__value {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
  color: var(--color-text-primary);
  line-height: 1.35;
}

.backup-summary__value--lg {
  font-size: 1.35rem;
}

.backup-summary__muted {
  font-weight: 500;
  color: var(--color-text-muted);
}

.backup-panel {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md, 0.85rem);
  background: var(--color-surface-elevated);
  margin-bottom: 1.25rem;
  box-shadow: var(--shadow-sm, none);
}

.backup-panel__head {
  margin-bottom: 1rem;
}

.backup-panel__title {
  font-size: 1.1rem;
  font-weight: 650;
  color: var(--color-text-primary);
  margin: 0 0 0.25rem;
}

.backup-panel__subtitle {
  font-size: 0.925rem;
  max-width: 42rem;
}

.backup-panel__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem;
  justify-content: flex-end;
  margin-top: 1.15rem;
}

.backup-options {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.85rem;
}

.backup-option {
  display: flex;
  gap: 0.85rem;
  text-align: start;
  align-items: flex-start;
  padding: 1rem;
  border-radius: var(--radius-md, 0.75rem);
  border: 1px solid var(--color-border);
  background: var(--color-surface-hover, transparent);
  color: var(--color-text-primary);
  cursor: pointer;
  transition: border-color 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
}

.backup-option:hover:not(:disabled),
.backup-option:focus-visible {
  border-color: var(--color-accent-muted, var(--color-accent));
  outline: none;
  box-shadow: 0 0 0 3px var(--color-accent-soft, rgba(99, 102, 241, 0.2));
}

.backup-option--selected {
  border-color: var(--color-accent);
  background: var(--color-accent-soft, rgba(99, 102, 241, 0.12));
  box-shadow: inset 0 0 0 1px var(--color-accent);
}

.backup-option:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.backup-option__icon {
  width: 2.4rem;
  height: 2.4rem;
  border-radius: 0.65rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: var(--color-surface-elevated);
  color: var(--color-accent);
  flex-shrink: 0;
  font-size: 1.15rem;
}

.backup-option__title {
  display: block;
  font-weight: 650;
  margin-bottom: 0.25rem;
}

.backup-option__desc {
  display: block;
  font-size: 0.875rem;
  color: var(--color-text-muted);
  line-height: 1.45;
}

.backup-import-note {
  display: flex;
  align-items: flex-start;
  gap: 0.25rem;
  padding: 0.85rem 1rem;
  margin-bottom: 1rem;
  border-radius: var(--radius-md, 0.65rem);
  border: 1px solid var(--color-border);
  background: var(--color-surface-hover, transparent);
  color: var(--color-text-secondary, var(--color-text-primary));
  font-size: 0.9rem;
}

.backup-dropzone {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.35rem;
  min-height: 8rem;
  padding: 1.25rem;
  border: 1.5px dashed var(--color-border);
  border-radius: var(--radius-md, 0.75rem);
  background: var(--color-background);
  cursor: pointer;
  text-align: center;
  transition: border-color 0.15s ease, background-color 0.15s ease;
}

.backup-dropzone:hover,
.backup-dropzone:focus-within {
  border-color: var(--color-accent);
  background: var(--color-accent-soft, rgba(99, 102, 241, 0.08));
}

.backup-dropzone__icon {
  font-size: 1.75rem;
  color: var(--color-accent);
}

.backup-dropzone__title {
  font-weight: 600;
  color: var(--color-text-primary);
}

.backup-dropzone__hint {
  font-size: 0.825rem;
}

.backup-list-section__head {
  margin-bottom: 0.75rem;
}

.backup-table-card {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md, 0.85rem);
  overflow: hidden;
  background: var(--color-surface-elevated);
}

.backup-table thead th {
  background: var(--color-surface-hover, transparent);
  color: var(--color-text-muted);
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  border-bottom-color: var(--color-border);
  white-space: nowrap;
}

.backup-table td {
  vertical-align: middle;
  border-color: var(--color-border-subtle, var(--color-border));
  color: var(--color-text-primary);
}

.backup-row-actions {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  justify-content: flex-end;
}

.backup-size {
  font-variant-numeric: tabular-nums;
  color: var(--color-text-secondary, var(--color-text-primary));
}

.backup-badge {
  display: inline-flex;
  align-items: center;
  padding: 0.2rem 0.55rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 600;
  line-height: 1.2;
  border: 1px solid transparent;
}

.backup-badge--success {
  color: var(--color-success, #198754);
  background: color-mix(in srgb, var(--color-success, #198754) 14%, transparent);
  border-color: color-mix(in srgb, var(--color-success, #198754) 28%, transparent);
}

.backup-badge--danger {
  color: var(--color-danger, #dc3545);
  background: color-mix(in srgb, var(--color-danger, #dc3545) 14%, transparent);
  border-color: color-mix(in srgb, var(--color-danger, #dc3545) 28%, transparent);
}

.backup-badge--imported {
  color: var(--color-accent, #6366f1);
  background: color-mix(in srgb, var(--color-accent, #6366f1) 14%, transparent);
  border-color: color-mix(in srgb, var(--color-accent, #6366f1) 28%, transparent);
}

.backup-source {
  font-size: 0.875rem;
  color: var(--color-text-secondary, var(--color-text-primary));
}

.backup-create-progress {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md, 0.85rem);
  background: var(--color-surface-elevated);
  margin-bottom: 1.25rem;
}

.backup-create-progress__bar {
  height: 0.65rem;
  background: var(--color-surface-hover, transparent);
}

.backup-import-preview {
  border: 1px solid color-mix(in srgb, var(--color-success, #198754) 35%, var(--color-border));
  border-radius: var(--radius-md, 0.85rem);
  background: color-mix(in srgb, var(--color-success, #198754) 8%, var(--color-surface-elevated));
  margin-bottom: 1.25rem;
}

.backup-import-preview__head {
  margin-bottom: 0.85rem;
}

.backup-import-preview__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem 1rem;
  margin: 0 0 1rem;
}

.backup-import-preview__grid dt {
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--color-text-muted);
  margin-bottom: 0.15rem;
}

.backup-import-preview__grid dd {
  margin: 0;
  font-weight: 600;
  color: var(--color-text-primary);
}

.backup-import-preview__hash {
  grid-column: 1 / -1;
}

.backup-badge--progress {
  color: var(--color-warning, #b45309);
  background: color-mix(in srgb, var(--color-warning, #ffc107) 18%, transparent);
  border-color: color-mix(in srgb, var(--color-warning, #ffc107) 35%, transparent);
}

.backup-badge--muted {
  color: var(--color-text-muted);
  background: var(--color-surface-hover, transparent);
  border-color: var(--color-border);
}

.backup-cards {
  display: grid;
  gap: 0.75rem;
}

.backup-card {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md, 0.85rem);
  background: var(--color-surface-elevated);
}

.backup-card__top {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 0.75rem;
  margin-bottom: 0.5rem;
}

.backup-card__type {
  font-weight: 650;
  color: var(--color-text-primary);
}

.backup-card__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
}

.backup-empty {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md, 0.85rem);
  background: var(--color-surface-elevated);
}

.backup-empty__icon {
  width: 3.5rem;
  height: 3.5rem;
  margin: 0 auto 1rem;
  border-radius: 1rem;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.6rem;
  color: var(--color-accent);
  background: var(--color-accent-soft, rgba(99, 102, 241, 0.12));
}

.backup-empty__text {
  max-width: 26rem;
}

.backup-empty__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem;
  justify-content: center;
}

.backup-disk-hint {
  max-width: 48rem;
}

.backup-modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 1080;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: color-mix(in srgb, #0f172a 55%, transparent);
}

.backup-modal {
  width: min(100%, 28rem);
  max-height: min(92vh, 40rem);
  overflow: auto;
  padding: 1.35rem 1.4rem;
  border-radius: var(--radius-md, 0.9rem);
  border: 1px solid var(--color-border);
  background: var(--color-surface-elevated);
  color: var(--color-text-primary);
  box-shadow: var(--shadow-lg, 0 20px 40px rgba(0, 0, 0, 0.25));
}

.backup-modal--danger {
  width: min(100%, 32rem);
}

.backup-modal--restore {
  width: min(100%, 36rem);
}

.backup-inspect-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem 1rem;
  margin: 0 0 1rem;
}

.backup-inspect-grid dt {
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--color-text-muted);
  margin-bottom: 0.15rem;
}

.backup-inspect-grid dd {
  margin: 0;
  font-weight: 600;
  color: var(--color-text-primary);
}

.backup-inspect-grid__full {
  grid-column: 1 / -1;
}

.backup-modal__title {
  font-size: 1.15rem;
  font-weight: 700;
  margin: 0 0 0.85rem;
}

.backup-modal__dl {
  margin: 0 0 0.75rem;
}

.backup-modal__dl dt {
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--color-text-muted);
  margin-bottom: 0.15rem;
}

.backup-modal__dl dd {
  margin: 0;
  font-weight: 600;
}

.backup-modal__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.55rem;
  justify-content: flex-end;
}

.backup-restore-warning {
  padding: 0.85rem 1rem;
  margin-bottom: 1rem;
  border-radius: var(--radius-md, 0.65rem);
  border: 1px solid color-mix(in srgb, var(--color-warning, #ffc107) 45%, var(--color-border));
  background: color-mix(in srgb, var(--color-warning, #ffc107) 12%, transparent);
  color: var(--color-text-primary);
  font-size: 0.925rem;
}

@media (max-width: 991.98px) {
  .backup-summary {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .backup-options {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 575.98px) {
  .backup-summary {
    grid-template-columns: 1fr;
  }

  .backup-import-preview__grid {
    grid-template-columns: 1fr;
  }

  .backup-panel__actions,
  .backup-modal__actions {
    flex-direction: column-reverse;
  }

  .backup-panel__actions .btn,
  .backup-modal__actions .btn,
  .backup-empty__actions .btn,
  .backup-card__actions .btn {
    width: 100%;
  }
}
</style>
