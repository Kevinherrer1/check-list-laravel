<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Server;

class ServerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $server = Server::create([
        'name' => 'SIGESP Producción',
        'hostname' => 'sigespro',
        'ip' => '172.18.40.19',
        'ssh_command' => 'ssh bdatos@172.18.40.19',
        'username' => 'bdatos',
        'system' => 'SIGESP (Producción)',
        'typical_time' => '2:38 am',
        'does_backup' => true,
        'review_script' => '/usr/local/bin/revision-dba.sh',
        'active' => true,
        'sort_order' => 1,
    ]);

    $server->mounts()->create(['path' => '/BACKUP']);
    $server->mounts()->create(['path' => '/DB']);
    $server->mounts()->create(['path' => '/PHP']);
    }



}
