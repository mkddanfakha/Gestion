<!-- Exemple d'utilisation des composants d'alerte Bootstrap -->
<template>
  <div class="container py-4">
    <h2 class="mb-4">Exemples d'alertes Bootstrap</h2>
    
    <!-- Alerte de succès -->
    <AlertSuccess 
      title="Opération réussie !" 
      message="Votre produit a été créé avec succès."
      class="mb-3"
    />
    
    <!-- Alerte d'erreur -->
    <AlertError 
      :errors="['Le nom du produit est requis', 'Le prix doit être supérieur à 0']"
      title="Erreurs de validation"
      class="mb-3"
    />
    
    <!-- Alerte d'information -->
    <AlertInfo 
      title="Information importante" 
      message="Les modifications seront appliquées immédiatement."
      class="mb-3"
    />
    
    <!-- Alerte d'avertissement -->
    <AlertWarning 
      title="Attention" 
      message="Ce produit est en rupture de stock."
      class="mb-3"
    />
    
    <!-- Alerte personnalisée -->
    <Alert variant="primary" dismissible>
      <AlertTitle>Message personnalisé</AlertTitle>
      <AlertDescription>
        Vous pouvez créer des alertes personnalisées avec différents variants.
      </AlertDescription>
    </Alert>
  </div>
</template>

<script setup lang="ts">
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import AlertSuccess from '@/components/AlertSuccess.vue';
import AlertError from '@/components/AlertError.vue';
import AlertInfo from '@/components/AlertInfo.vue';
import AlertWarning from '@/components/AlertWarning.vue';
</script>
