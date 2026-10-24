<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateFirstAccount extends Command
{
    protected $signature = 'om:premier-compte {--name= : Nom du contrôleur} {--email= : Adresse e-mail}';

    protected $description = 'Crée le premier compte contrôleur, quand aucun compte n\'existe encore';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->error('Des comptes existent déjà : créez les suivants depuis l\'application.');

            return self::FAILURE;
        }

        $data = [
            'name' => $this->option('name') ?? $this->ask('Nom'),
            'email' => $this->option('email') ?? $this->ask('Adresse e-mail'),
            'password' => $this->secret('Mot de passe (10 caractères minimum, lettres et chiffres)'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::create([...$validator->validated(), 'email' => mb_strtolower($data['email'])]);
        Audit::record('user.created', $user, [], $user->only(['name', 'email']), $user);

        $this->info("Compte créé pour {$user->email}.");

        return self::SUCCESS;
    }
}
