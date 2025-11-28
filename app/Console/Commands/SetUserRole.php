<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class SetUserRole extends Command
{
    protected $signature = 'user:set-role {email} {role=admin}';
    protected $description = 'Définit le rôle d\'un utilisateur (admin ou user)';

    public function handle()
    {
        $email = $this->argument('email');
        $role = $this->argument('role');
        
        if (!in_array($role, ['admin', 'user'])) {
            $this->error("Le rôle doit être 'admin' ou 'user'");
            return 1;
        }
        
        $user = User::where('email', $email)->first();
        
        if (!$user) {
            $this->error("Utilisateur non trouvé : {$email}");
            return 1;
        }
        
        $user->role = $role;
        $user->save();
        
        $this->info("✅ Rôle '{$role}' défini pour l'utilisateur : {$user->name} ({$user->email})");
        
        return 0;
    }
}
