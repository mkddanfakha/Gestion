<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Audit\ChangeDetector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActivityLogger
{
    public static function log(
        string $action,
        string $module,
        string $description,
        ?Model $subject = null,
        ?User $user = null,
        ?Request $request = null,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): ActivityLog {
        $request ??= request();

        if (!$user && $request) {
            $user = $request->user();
        }

        if (!$user) {
            $user = Auth::user();
        }

        return ActivityLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'module' => $module,
            'description' => $description,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }

    public static function logCreate(string $module, Model $subject, ?string $label = null): ActivityLog
    {
        $label ??= ActivityLog::resolveSubjectLabel($subject);
        $changes = ChangeDetector::captureCreation($subject);

        return self::log(
            ActivityLog::ACTION_CREATE,
            $module,
            sprintf('a créé %s "%s"', self::subjectTypePhrase($module), $label),
            $subject,
            oldValues: $changes['old_values'],
            newValues: $changes['new_values'],
        );
    }

    public static function logUpdate(string $module, Model $subject, ?string $label = null): ActivityLog
    {
        $label ??= ActivityLog::resolveSubjectLabel($subject);
        $changes = ChangeDetector::detectChanges($subject);

        return self::log(
            ActivityLog::ACTION_UPDATE,
            $module,
            sprintf('a modifié %s "%s"', self::subjectTypePhrase($module, 'update'), $label),
            $subject,
            oldValues: $changes['old_values'],
            newValues: $changes['new_values'],
        );
    }

    public static function logDelete(string $module, Model $subject, ?string $label = null): ActivityLog
    {
        $label ??= ActivityLog::resolveSubjectLabel($subject);
        $changes = ChangeDetector::captureDeletion($subject);

        return self::log(
            ActivityLog::ACTION_DELETE,
            $module,
            sprintf('a supprimé %s "%s"', self::subjectTypePhrase($module), $label),
            $subject,
            oldValues: $changes['old_values'],
            newValues: $changes['new_values'],
        );
    }

    public static function logValidate(string $module, Model $subject, ?string $label = null): ActivityLog
    {
        $label ??= ActivityLog::resolveSubjectLabel($subject);
        $changes = ChangeDetector::detectChanges($subject);

        return self::log(
            ActivityLog::ACTION_VALIDATE,
            $module,
            sprintf('a validé %s %s', self::subjectTypePhrase($module, 'validate'), $label),
            $subject,
            oldValues: $changes['old_values'],
            newValues: $changes['new_values'],
        );
    }

    public static function logCancel(string $module, Model $subject, ?string $label = null): ActivityLog
    {
        $label ??= ActivityLog::resolveSubjectLabel($subject);
        $changes = ChangeDetector::detectChanges($subject);

        return self::log(
            ActivityLog::ACTION_CANCEL,
            $module,
            sprintf('a annulé %s %s', self::subjectTypePhrase($module, 'cancel'), $label),
            $subject,
            oldValues: $changes['old_values'],
            newValues: $changes['new_values'],
        );
    }

    public static function logPayment(Model $subject, ?string $label = null): ActivityLog
    {
        $label ??= ActivityLog::resolveSubjectLabel($subject);
        $changes = ChangeDetector::detectChanges($subject);

        return self::log(
            ActivityLog::ACTION_PAYMENT,
            'Paiement',
            sprintf('a enregistré le paiement de la facture %s', $label),
            $subject,
            oldValues: $changes['old_values'],
            newValues: $changes['new_values'],
        );
    }

    public static function logRestore(string $module, Model $subject, ?string $label = null): ActivityLog
    {
        $label ??= ActivityLog::resolveSubjectLabel($subject);
        $changes = ChangeDetector::captureRestore($subject);

        return self::log(
            ActivityLog::ACTION_RESTORE,
            $module,
            sprintf('a restauré %s "%s"', self::subjectTypePhrase($module), $label),
            $subject,
            oldValues: $changes['old_values'],
            newValues: $changes['new_values'],
        );
    }

    public static function logLogin(User $user, ?Request $request = null): ActivityLog
    {
        return self::log(
            ActivityLog::ACTION_LOGIN,
            'Authentification',
            's\'est connecté',
            $user,
            $user,
            $request,
        );
    }

    public static function logLogout(User $user, ?Request $request = null): ActivityLog
    {
        return self::log(
            ActivityLog::ACTION_LOGOUT,
            'Authentification',
            's\'est déconnecté',
            $user,
            $user,
            $request,
        );
    }

    public static function logCompanyLogoAdd(Model $company): ActivityLog
    {
        return self::log(
            ActivityLog::ACTION_UPDATE,
            'Entreprise',
            'a ajouté le logo de l\'entreprise',
            $company,
        );
    }

    public static function logCompanyLogoReplace(Model $company): ActivityLog
    {
        return self::log(
            ActivityLog::ACTION_UPDATE,
            'Entreprise',
            'a remplacé le logo de l\'entreprise',
            $company,
        );
    }

    public static function logCompanyLogoDelete(Model $company): ActivityLog
    {
        return self::log(
            ActivityLog::ACTION_UPDATE,
            'Entreprise',
            'a supprimé le logo de l\'entreprise',
            $company,
        );
    }

    public static function logCompanySignatureAdd(Model $company): ActivityLog
    {
        return self::log(
            ActivityLog::ACTION_UPDATE,
            'Entreprise',
            'a ajouté la signature de l\'entreprise',
            $company,
        );
    }

    public static function logCompanySignatureReplace(Model $company): ActivityLog
    {
        return self::log(
            ActivityLog::ACTION_UPDATE,
            'Entreprise',
            'a remplacé la signature de l\'entreprise',
            $company,
        );
    }

    public static function logCompanySignatureDelete(Model $company): ActivityLog
    {
        return self::log(
            ActivityLog::ACTION_UPDATE,
            'Entreprise',
            'a supprimé la signature de l\'entreprise',
            $company,
        );
    }

    public static function logCompanyStampAdd(Model $company): ActivityLog
    {
        return self::log(
            ActivityLog::ACTION_UPDATE,
            'Entreprise',
            'a ajouté le cachet de l\'entreprise',
            $company,
        );
    }

    public static function logCompanyStampReplace(Model $company): ActivityLog
    {
        return self::log(
            ActivityLog::ACTION_UPDATE,
            'Entreprise',
            'a remplacé le cachet de l\'entreprise',
            $company,
        );
    }

    public static function logCompanyStampDelete(Model $company): ActivityLog
    {
        return self::log(
            ActivityLog::ACTION_UPDATE,
            'Entreprise',
            'a supprimé le cachet de l\'entreprise',
            $company,
        );
    }

    public static function logCompanyPrintPreferencesUpdate(Model $company): ActivityLog
    {
        return self::log(
            ActivityLog::ACTION_UPDATE,
            'Entreprise',
            'a modifié les préférences d\'impression de l\'entreprise',
            $company,
        );
    }

    public static function logAttachmentAdded(Model $attachable, \App\Models\Attachment $attachment, ?User $user = null): ActivityLog
    {
        $module = self::resolveAttachmentModule($attachable);
        $label = ActivityLog::resolveSubjectLabel($attachable);

        return self::log(
            ActivityLog::ACTION_ATTACHMENT_ADDED,
            $module,
            sprintf('a ajouté %s à %s %s', $attachment->original_name, self::subjectTypePhrase($module), $label),
            $attachable,
            $user,
            newValues: [
                'attachment_id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
            ],
        );
    }

    public static function logAttachmentDeleted(Model $attachable, string $originalName, \App\Models\Attachment $attachment, ?User $user = null): ActivityLog
    {
        $module = self::resolveAttachmentModule($attachable);
        $label = ActivityLog::resolveSubjectLabel($attachable);

        return self::log(
            ActivityLog::ACTION_ATTACHMENT_DELETED,
            $module,
            sprintf('a supprimé %s de %s %s', $originalName, self::subjectTypePhrase($module), $label),
            $attachable,
            $user,
            oldValues: [
                'attachment_id' => $attachment->id,
                'original_name' => $originalName,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
            ],
        );
    }

    private static function resolveAttachmentModule(Model $attachable): string
    {
        return match ($attachable::class) {
            \App\Models\Expense::class => 'Dépense',
            \App\Models\Quote::class => 'Devis',
            \App\Models\PurchaseOrder::class => 'Bon de commande',
            \App\Models\DeliveryNote::class => 'Bon de livraison',
            \App\Models\Customer::class => 'Client',
            \App\Models\Supplier::class => 'Fournisseur',
            default => class_basename($attachable),
        };
    }

    private static function subjectTypePhrase(string $module, string $context = 'default'): string
    {
        return match ($module) {
            'Produit' => 'le produit',
            'Client' => 'le client',
            'Fournisseur' => 'le fournisseur',
            'Bon de commande' => $context === 'cancel' ? 'la commande' : 'le bon de commande',
            'Bon de livraison' => 'le bon de livraison',
            'Inventaire' => 'la session d\'inventaire',
            'Facture' => 'la facture',
            'Dépense' => 'la dépense',
            'Entreprise' => 'les informations de l\'entreprise',
            'Utilisateur' => 'l\'utilisateur',
            default => 'l\'élément',
        };
    }
}
