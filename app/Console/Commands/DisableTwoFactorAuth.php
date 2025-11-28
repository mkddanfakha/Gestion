<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class DisableTwoFactorAuth extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = '2fa:disable {email : L\'email de l\'utilisateur}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Désactive l\'authentification à deux facteurs pour un utilisateur';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        
        $user = User::where('email', $email)->first();
        
        if (!$user) {
            $this->error("Aucun utilisateur trouvé avec l'email : {$email}");
            return 1;
        }
        
        // Désactiver le 2FA en supprimant les données
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
        
        $this->info("✅ L'authentification à deux facteurs a été désactivée pour l'utilisateur : {$user->name} ({$user->email})");
        $this->info("L'utilisateur peut maintenant se connecter sans code 2FA.");
        
        return 0;
    }
}
