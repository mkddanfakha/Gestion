<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            $user = $request->user();
            // Rediriger les vendeurs vers la liste des ventes
            if ($user && $user->hasRole('vendeur')) {
                return redirect()->intended(route('sales.index', absolute: false));
            }
            return redirect()->intended(route('dashboard', absolute: false));
        }

        try {
            $user = $request->user();
            
            // Vérifier la configuration email
            $mailer = config('mail.default');
            
            // Vérifier que le mailer n'est pas 'log' (qui n'envoie pas vraiment)
            if ($mailer === 'log') {
                return back()->withErrors([
                    'message' => 'La configuration email n\'est pas correcte. Le mailer est configuré sur "log" au lieu de "smtp". Veuillez configurer votre email dans le fichier .env.'
                ]);
            }
            
            // Vérifier que les paramètres SMTP sont configurés
            $smtpHost = config('mail.mailers.smtp.host');
            $smtpUsername = config('mail.mailers.smtp.username');
            $smtpPassword = config('mail.mailers.smtp.password');
            
            if (empty($smtpHost) || empty($smtpUsername) || empty($smtpPassword)) {
                return back()->withErrors([
                    'message' => 'La configuration email SMTP est incomplète. Veuillez vérifier vos paramètres dans le fichier .env (MAIL_HOST, MAIL_USERNAME, MAIL_PASSWORD).'
                ]);
            }
            
            // Envoyer la notification en utilisant Mail::send() avec la vue de notification standard
            try {
                $notification = new \App\Notifications\VerifyEmailNotification();
                $mailMessage = $notification->toMail($user);
                
                // Utiliser la réflexion pour accéder aux propriétés privées du MailMessage
                $reflection = new \ReflectionClass($mailMessage);
                
                // Extraire les propriétés nécessaires
                $greetingProperty = $reflection->getProperty('greeting');
                $greetingProperty->setAccessible(true);
                $greeting = $greetingProperty->getValue($mailMessage);
                
                $introLinesProperty = $reflection->getProperty('introLines');
                $introLinesProperty->setAccessible(true);
                $introLines = $introLinesProperty->getValue($mailMessage);
                
                $actionTextProperty = $reflection->getProperty('actionText');
                $actionTextProperty->setAccessible(true);
                $actionText = $actionTextProperty->getValue($mailMessage);
                
                $actionUrlProperty = $reflection->getProperty('actionUrl');
                $actionUrlProperty->setAccessible(true);
                $actionUrl = $actionUrlProperty->getValue($mailMessage);
                
                $outroLinesProperty = $reflection->getProperty('outroLines');
                $outroLinesProperty->setAccessible(true);
                $outroLines = $outroLinesProperty->getValue($mailMessage);
                
                $salutationProperty = $reflection->getProperty('salutation');
                $salutationProperty->setAccessible(true);
                $salutation = $salutationProperty->getValue($mailMessage);
                
                $levelProperty = $reflection->getProperty('level');
                $levelProperty->setAccessible(true);
                $level = $levelProperty->getValue($mailMessage);
                
                $subjectProperty = $reflection->getProperty('subject');
                $subjectProperty->setAccessible(true);
                $subject = $subjectProperty->getValue($mailMessage);
                
                // Utiliser Mail::send() directement avec la vue de notification standard
                $viewData = [
                    'greeting' => $greeting,
                    'introLines' => $introLines ?? [],
                    'actionText' => $actionText,
                    'actionUrl' => $actionUrl,
                    'displayableActionUrl' => $actionUrl,
                    'outroLines' => $outroLines ?? [],
                    'salutation' => $salutation,
                    'level' => $level ?? 'success',
                ];
                
                Mail::send('vendor.notifications.email', $viewData, function ($message) use ($subject, $user) {
                    $message->to($user->email, $user->name)
                        ->subject($subject);
                });
            } catch (\Exception $mailException) {
                \Log::error('Erreur lors de l\'envoi de l\'email de vérification', [
                    'user_id' => $user->id,
                    'error' => $mailException->getMessage()
                ]);
                
                throw $mailException;
            }
            
            return back()->with('success', 'Un nouveau lien de vérification a été envoyé à votre adresse email.');
        } catch (\Exception $e) {
            \Log::error('Erreur lors de l\'envoi de l\'email de vérification', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);
            
            return back()->withErrors([
                'message' => 'Une erreur est survenue lors de l\'envoi de l\'email : ' . $e->getMessage()
            ]);
        }
    }
}
