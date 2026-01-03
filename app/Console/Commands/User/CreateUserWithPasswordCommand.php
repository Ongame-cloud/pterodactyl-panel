<?php

namespace Pterodactyl\Console\Commands\User;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Pterodactyl\Services\Users\UserCreationService;

class CreateUserWithPasswordCommand extends Command
{
    protected $description = 'Crée un utilisateur avec un mot de passe généré automatiquement';

    protected $signature = 'p:user:create {--email=} {--username=} {--name-first=} {--name-last=} {--admin=}';

    public function __construct(private UserCreationService $creationService)
    {
        parent::__construct();
    }

    public function handle()
    {
        $root_admin = $this->option('admin') ?? $this->confirm('Utilisateur administrateur ?');
        $email = $this->option('email') ?? $this->ask('Email');
        $username = $this->option('username') ?? $this->ask('Nom d\'utilisateur');
        $name_first = $this->option('name-first') ?? $this->ask('Prénom');
        $name_last = $this->option('name-last') ?? $this->ask('Nom');

        $password = Str::random(16);

        $user = $this->creationService->handle(compact('email', 'username', 'name_first', 'name_last', 'password', 'root_admin'));

        $this->newLine();
        $this->info('✓ Utilisateur créé avec succès !');
        $this->newLine();
        
        $this->table(['Champ', 'Valeur'], [
            ['UUID', $user->uuid],
            ['Email', $user->email],
            ['Username', $user->username],
            ['Nom', $user->name],
            ['Admin', $user->root_admin ? 'Oui' : 'Non'],
            ['Mot de passe', $password],
        ]);

        $this->newLine();
        $this->warn('⚠ Sauvegardez ce mot de passe, il ne sera plus affiché : ' . $password);
        $this->newLine();
    }
}
