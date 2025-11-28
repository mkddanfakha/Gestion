<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CheckUserRole extends Command
{
    protected $signature = 'user:check-role {email?}';
    protected $description = 'Vérifie et affiche le rôle des utilisateurs';

    public function handle()
    {
        $email = $this->argument('email');
        
        if ($email) {
            $user = User::where('email', $email)->first();
            if (!$user) {
                $this->error("Utilisateur non trouvé : {$email}");
                return 1;
            }
            $users = collect([$user]);
        } else {
            $users = User::all();
        }
        
        $this->info("Liste des utilisateurs :");
        $this->table(
            ['ID', 'Nom', 'Email', 'Rôle'],
            $users->map(function ($user) {
                return [
                    $user->id,
                    $user->name,
                    $user->email,
                    $user->role ?? 'non défini'
                ];
            })->toArray()
        );
        
        return 0;
    }
}
