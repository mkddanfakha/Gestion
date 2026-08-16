# Document Manager — MKD-Pro

Infrastructure documentaire commune à tout MKD-Pro. Avant d'ajouter une fonctionnalité liée aux fichiers, vérifier si elle peut s'intégrer ici plutôt que de créer un système parallèle.

## Vue d'ensemble

```
                    DOCUMENT MANAGER MKD-PRO
                              │
             ┌────────────────┼────────────────┐
             │                │                │
             ▼                ▼                ▼
       Attachment         Document         Preview
        Manager            Access           Engine
             │                │                │
             │                │                ▼
             │                │      DocumentPreviewModal
             │                │
             │                ▼
             │        permissions / RBAC
             │
             ▼
       fichiers / metadata
```

## Séparation des responsabilités

| Couche | Rôle | Fichiers principaux |
|--------|------|------------------------|
| **Attachment Manager** | Stockage, métadonnées, association entité, upload, suppression, téléchargement | `AttachmentService`, `Attachment`, `HasAttachments`, `AttachmentController` |
| **Document Access (backend)** | Autorisation, URLs preview/download, headers HTTP (`inline` / `attachment`) | `AttachmentAuthorizer`, `AttachmentController`, `DocumentPreviewController`, contrôleurs `*/print` et `*/download` |
| **Preview Engine (frontend)** | État, ouverture, fallback, changement de document | `useDocumentPreview.ts`, `usePdfEmbedSupport.ts`, `utils/documentPreview.ts` |
| **Preview UI** | Affichage PDF, image, fallback, boutons, responsive | `DocumentPreviewModal.vue` |

**Règle :** ne pas créer `ExpensePreviewService`, `QuotePreviewService`, etc. si le comportement est identique.

## Deux origines de documents

| Type | Exemples | Cycle de vie | Accès backend |
|------|----------|--------------|---------------|
| **A. Généré par MKD-Pro** | devis.pdf, bc.pdf, bl.pdf, facture.pdf | Dompdf + Blade, parfois cache temporaire | `DocumentPreviewController`, routes `*/preview`, `*/print`, `*/download` |
| **B. Uploadé par l'utilisateur** | ticket.jpg, reçu.png, justificatif.pdf | Persistant via Attachment Manager | `AttachmentController` (`show` inline, `download` attachment) |

Les deux consomment le **même** `DocumentPreviewModal`. Le comportement technique dépend du **MIME type**, pas du module métier.

## Flux frontend

### Pièce jointe (Attachment Manager)

```typescript
import { useDocumentPreview } from '@/composables/useDocumentPreview'

const { openAttachment } = useDocumentPreview()
await openAttachment(attachment)
```

### Document métier — formulaire (brouillon)

```typescript
await openFromPayload('quotes.preview', payload, 'Apercu_Devis.pdf', 'Aperçu du devis')
```

### Document métier — entité enregistrée

```typescript
await openFromUrl(route('quotes.print', { quote: id }), `Devis_${number}.pdf`)
```

### Descripteur générique

```typescript
await openDocument({
  title: 'Aperçu du document',
  documentUrl: previewUrl,
  downloadUrl: downloadUrl,
  documentName: 'facture.pdf',
  mimeType: 'application/pdf',
  documentType: 'attachment', // contexte uniquement
})
```

## États du preview

`closed` → `loading` → `embedded` | `fallback` | `error`

Le footer affiche toujours **Ouvrir**, **Télécharger**, **Fermer** (plus **Imprimer** pour PDF intégré).

## Détection du type de contenu

Priorité au MIME type (`utils/documentPreview.ts`), repli sur l'extension si nécessaire :

- `application/pdf` → iframe + fallback mobile si besoin
- `image/*` → `<img>` responsive
- Autres → fallback « Aperçu non disponible » + Ouvrir / Télécharger

## Extension à un nouveau module

1. Backend : `use HasAttachments` sur le modèle (si fichiers uploadés)
2. `AttachmentAuthorizer::RESOURCE_MAP` + slug dans `AttachmentService`
3. UI : `<AttachmentUploader />`, `<AttachmentList />`, `@preview` → `openAttachment()`
4. Preview PDF généré : `openFromPayload` / `openFromUrl` — **pas** de nouveau modal

Ne pas créer : upload dédié, preview modal dédié, logique PDF/image dupliquée.

## Modules

| Module | Attachment Manager | Preview unifié |
|--------|-------------------|----------------|
| Dépenses | Intégré | Oui |
| Devis, BC, BL, Factures | Trait backend, UI à étendre | Oui (`openFromPayload` / `openFromUrl`) |
| BL facture fournisseur | Champs inline (migration future) | À migrer vers modale unifiée |

## Systèmes conservés séparément (justifiés)

- **Produits** : Spatie Media Library
- **Entreprise** : `CompanyAssetService`

## Tests dev

Forcer le fallback PDF : `?pdfPreviewFallback=1` ou `sessionStorage.setItem('mkd:pdf-preview-force-fallback', '1')`

## Voir aussi

- [attachments.md](./attachments.md) — détail Attachment Manager
- `resources/js/composables/useDocumentPreview.ts`
- `resources/js/components/documents/DocumentPreviewModal.vue`
