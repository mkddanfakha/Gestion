<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { useSweetAlert } from '@/composables/useSweetAlert';
import { route } from '@/lib/routes';
import Swal from 'sweetalert2';

const { success, error, confirm } = useSweetAlert();

const deleteAccount = async () => {
  // Première confirmation
  const confirmed = await confirm(
    'Êtes-vous sûr de vouloir supprimer votre compte ? Cette action est irréversible et toutes vos données seront supprimées de manière permanente.',
    'Supprimer le compte'
  );

  if (!confirmed) {
    return;
  }

  // Demander le mot de passe avec SweetAlert
  const { value: password, isConfirmed: passwordConfirmed } = await Swal.fire({
    title: 'Confirmer la suppression',
    html: `
      <div class="text-start">
        <p class="mb-3">Pour confirmer la suppression de votre compte, veuillez entrer votre mot de passe :</p>
        <input 
          id="swal-password" 
          type="password" 
          class="swal2-input form-control" 
          placeholder="Mot de passe"
          autocomplete="current-password"
        >
      </div>
    `,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Supprimer le compte',
    cancelButtonText: 'Annuler',
    confirmButtonColor: '#dc3545',
    cancelButtonColor: '#6c757d',
    reverseButtons: true,
    focusConfirm: false,
    preConfirm: () => {
      const passwordInput = document.getElementById('swal-password') as HTMLInputElement;
      const password = passwordInput?.value;
      if (!password) {
        Swal.showValidationMessage('Veuillez entrer votre mot de passe');
        return false;
      }
      return password;
    },
    didOpen: () => {
      const passwordInput = document.getElementById('swal-password') as HTMLInputElement;
      if (passwordInput) {
        passwordInput.focus();
      }
    }
  });

  if (!passwordConfirmed || !password) {
    return;
  }

  // Soumettre le formulaire avec le mot de passe
  router.delete(route('profile.destroy'), {
    data: {
      password: password
    },
    onSuccess: () => {
      success('Votre compte a été supprimé avec succès.');
    },
    onError: (errors) => {
      // Afficher le message d'erreur du serveur ou un message par défaut
      const errorMessage = errors?.message || errors?.error || errors?.password || 'Erreur lors de la suppression du compte.';
      error(errorMessage);
    }
  });
};
</script>

<template>
    <div class="card border-danger">
        <div class="card-body">
            <div class="alert alert-warning mb-3">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>Attention :</strong> Cette action est irréversible. Veuillez procéder avec prudence.
            </div>
            <button 
                class="btn btn-danger" 
                data-test="delete-user-button"
                @click="deleteAccount"
            >
                <i class="bi bi-trash me-1"></i>
                Supprimer le compte
            </button>
        </div>
    </div>
</template>
