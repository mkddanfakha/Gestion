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

    private static function subjectTypePhrase(string $module, string $context = 'default'): string
    {
        return match ($module) {
            'Produit' => 'le produit',
            'Client' => 'le client',
            'Fournisseur' => 'le fournisseur',
            'Bon de commande' => $context === 'cancel' ? 'la commande' : 'le bon de commande',
            'Bon de livraison' => 'le bon de livraison',
            'Facture' => 'la facture',
            'Dépense' => 'la dépense',
            'Entreprise' => 'les informations de l\'entreprise',
            'Utilisateur' => 'l\'utilisateur',
            default => 'l\'élément',
        };
    }
}
