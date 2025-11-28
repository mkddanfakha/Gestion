import Swal from 'sweetalert2'

export const useSweetAlert = () => {
  return {
    success: (message: string, title: string = 'Succès') => {
      return Swal.fire({
        icon: 'success',
        title: title,
        text: message,
        confirmButtonText: 'OK',
        confirmButtonColor: '#198754'
      })
    },

    error: (message: string, title: string = 'Erreur') => {
      // Utiliser html si le message contient du HTML, sinon utiliser text
      const isHtml = message.includes('<') || message.includes('&lt;')
      return Swal.fire({
        icon: 'error',
        title: title,
        ...(isHtml ? { html: message } : { text: message }),
        confirmButtonText: 'OK',
        confirmButtonColor: '#dc3545'
      })
    },

    warning: (message: string, title: string = 'Attention') => {
      return Swal.fire({
        icon: 'warning',
        title: title,
        text: message,
        confirmButtonText: 'OK',
        confirmButtonColor: '#ffc107',
        confirmButtonTextColor: '#000'
      })
    },

    info: (message: string, title: string = 'Information') => {
      return Swal.fire({
        icon: 'info',
        title: title,
        text: message,
        confirmButtonText: 'OK',
        confirmButtonColor: '#0dcaf0',
        confirmButtonTextColor: '#000'
      })
    },

    confirm: (message: string, title: string = 'Confirmation') => {
      return Swal.fire({
        icon: 'warning',
        title: title,
        text: message,
        showCancelButton: true,
        confirmButtonText: 'Oui',
        cancelButtonText: 'Non',
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        reverseButtons: true
      }).then((result) => {
        return result.isConfirmed
      })
    },

    // Fonction pour les confirmations avec des détails supplémentaires
    confirmWithDetails: (message: string, title: string = 'Confirmation', details?: string) => {
      return Swal.fire({
        icon: 'warning',
        title: title,
        html: `
          <div class="text-start">
            <p>${message}</p>
            ${details ? `<div class="mt-3"><strong>Détails :</strong><br><small class="text-muted">${details}</small></div>` : ''}
          </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Oui',
        cancelButtonText: 'Non',
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        reverseButtons: true
      }).then((result) => {
        return result.isConfirmed
      })
    }
  }
}
