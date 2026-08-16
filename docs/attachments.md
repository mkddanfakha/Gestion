# Attachment Manager — MKD-Pro

Couche **stockage et métadonnées** du [Document Manager](./document-manager.md). Système centralisé de pièces jointes basé sur une relation polymorphique Laravel.

Tout aperçu (PDF, image, fallback) passe par **`DocumentPreviewModal`** et **`useDocumentPreview`** — ne pas créer de modal preview dédié par module.

## Architecture

- **Table** : `attachments`
- **Modèle** : `App\Models\Attachment`
- **Trait** : `App\Models\Concerns\HasAttachments`
- **Service** : `App\Services\AttachmentService`
- **Autorisation** : `App\Services\AttachmentAuthorizer`
- **Contrôleur** : `App\Http\Controllers\AttachmentController`
- **Configuration** : `config/attachments.php`

## Storage

- **Disk par défaut** : `local` (`storage/app/private`) via `ATTACHMENTS_DISK`
- **Structure** : `attachments/{slug}/{id}/{uuid}.ext`
- **Slugs** : `expenses`, `quotes`, `purchase-orders`, `delivery-notes`, `customers`, `suppliers`

Les fichiers ne sont **pas publics** par défaut. Accès via routes authentifiées uniquement.

## Backend

```php
$attachmentService->add($expense, $file, $user);
$attachmentService->addMany($expense, $files, $user);
$attachmentService->delete($attachment, $user);
$attachmentService->download($attachment);
$attachmentService->deleteFor($model);
```

## Routes

| Méthode | Route | Action |
|---------|-------|--------|
| GET | `/attachments/{attachment}` | Prévisualisation (inline) |
| GET | `/attachments/{attachment}/download` | Téléchargement |
| DELETE | `/attachments/{attachment}` | Suppression |

## Sécurité

- Validation MIME, extension, taille côté serveur
- RBAC via permissions de l'entité attachable (`expenses.view`, `expenses.update`, etc.)
- Protection IDOR : accès refusé si l'utilisateur ne peut pas voir l'entité parente
- Gestionnaires : scope sur leurs propres dépenses

## Frontend

Composants réutilisables :

- `AttachmentUploader.vue` — sélection locale (soumission avec formulaire)
- `AttachmentList.vue` — liste, téléchargement, suppression, émission `@preview`
- `DocumentPreviewModal.vue` — aperçu unifié (PDF, images, fallback) via `openAttachment()`

```typescript
import { useDocumentPreview } from '@/composables/useDocumentPreview'

const { openAttachment } = useDocumentPreview()

const onPreview = (attachment: AttachmentRecord) => {
  void openAttachment(attachment, `Aperçu — ${attachment.original_name}`)
}
```

### useFormDraft

**Important** : les objets `File` ne sont **pas** stockés dans localStorage, sessionStorage ou BroadcastChannel. Seules les données sérialisables du formulaire sont persistées dans le brouillon.

## Ajouter un nouvel attachable

1. Ajouter `use HasAttachments` au modèle
2. Charger `attachments` dans le contrôleur (show/edit)
3. Gérer `attachments[]` dans store/update si upload à la soumission
4. Ajouter le mapping dans `AttachmentAuthorizer::RESOURCE_MAP`
5. Ajouter le slug dans `AttachmentService::resolveAttachableSlug`
6. Utiliser `<AttachmentUploader />` et `@preview` → `openAttachment()` (DocumentPreviewModal)

## Commandes

```bash
php artisan attachments:cleanup --dry-run
php artisan attachments:cleanup
```

## Audit

Événements enregistrés :

- `attachment_added`
- `attachment_deleted`

## Modules

| Module | Statut |
|--------|--------|
| Dépenses | Intégré |
| Devis | Préparé (trait) |
| Bons de commande | Préparé (trait) |
| Bons de livraison | Préparé (trait) |
| Clients | Préparé (trait) |
| Fournisseurs | Préparé (trait) |

## Systèmes existants non migrés

- **Produits** : Spatie Media Library (conservé)
- **BL fournisseur** : champs inline `invoice_file_*` (conservé, migration future possible)
- **Entreprise** : `CompanyAssetService` (conservé)
