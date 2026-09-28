<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $password = (string) env('BITACORA_SEED_PASSWORD', '');
        if ($password === '') {
            throw new \RuntimeException('Defina BITACORA_SEED_PASSWORD en .env antes de sembrar los usuarios.');
        }

        $users = [
            [
                'name' => 'Ysabel Mantilla',
                'email' => 'ysabel.mantilla@cvg.gob.ve',
            ],
            [
                'name' => 'Jessica Alfonzo',
                'email' => 'jessica.alfonzo@cvg.gob.ve',
            ],
            [
                'name' => 'Darimar Zambrano',
                'email' => 'darimar.zambrano@cvg.gob.ve',
            ],
            [
                'name' => 'Norman Boccardo',
                'email' => 'norman.boccardo@cvg.gob.ve',
            ],
        ];

        foreach ($users as $user) {
            User::updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'password' => $password,
                    'email_verified_at' => now(),
                ]
            );
        }
    }
}
