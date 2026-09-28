<?php

namespace Database\Seeders;

use App\Models\Mount;
use App\Models\Server;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ServerSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $servers = [
            [
                'name' => 'SIGESP Calidad',
                'hostname' => 'sigespcal',
                'ip' => '172.18.40.25',
                'ssh_command' => 'ssh bdatos@172.18.40.25',
                'username' => 'bdatos',
                'system' => 'SIGESP (Calidad)',
                'typical_time' => '3:30 am',
                'does_backup' => true,
                'review_script' => '/usr/local/bin/revision-dba.sh',
                'active' => true,
                'sort_order' => 1,
                'mounts' => [
                    '/BACKUP/PHP',
                ],
            ],
            [
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
                'sort_order' => 2,
                'mounts' => [
                    '/BACKUP',
                    '/DB',
                    '/PHP',
                ],
            ],
            [
                'name' => 'Adabas/ Natural (deb6)',
                'hostname' => 'pzosdgstdeb6.pzo.cvg.com',
                'ip' => '172.25.214.109',
                'ssh_command' => 'ssh sag@pzosdgstdeb6.pzo.cvg.com',
                'username' => 'sag',
                'system' => 'Adabas/ Natural',
                'typical_time' => '2:54 - 3:48 am / 9:00 pm',
                'does_backup' => true,
                'review_script' => '/usr/local/bin/revision-dba.sh',
                'active' => true,
                'sort_order' => 3,
                'mounts' => [
                    '/NETAPP_POSTGRES',
                    '/NETAPP_ACUEDUCTOS',
                    '/Qtranscripcion',
                    '/Qreportes_nominas',
                    '/mnt/netapp',
                    '/Qsacofigo',
                ],
            ],
            [
                'name' => 'Adabas/ Natural (virt6)',
                'hostname' => 'pzosdgstvirt6',
                'ip' => '172.25.214.114',
                'ssh_command' => 'ssh sag@172.25.214.114',
                'username' => 'sag',
                'system' => 'Adabas/ Natural',
                'does_backup' => false,
                'observations' => 'No se realiza respaldo, imagen del deb6 (hierro).',
                'active' => true,
                'sort_order' => 4,
                'mounts' => [],
            ],
            [
                'name' => 'Informix / Sacofigo (deb12)',
                'hostname' => 'pzosdgstdeb12.pzo.cvg.com',
                'ip' => '172.25.214.116',
                'ssh_command' => 'ssh informix@pzosdgstdeb12.pzo.cvg.com',
                'username' => 'informix',
                'system' => 'Informix / Sacofigo',
                'typical_time' => '1:00 - 3:30 am',
                'does_backup' => true,
                'review_script' => '/usr/local/bin/revision-dba.sh',
                'active' => true,
                'sort_order' => 5,
                'mounts' => [
                    '/BACKUP',
                    '/Qsacofigo',
                    '/Qregistroycontrol',
                ],
            ],
            [
                'name' => 'Informix / Sacofigo (virt12)',
                'hostname' => 'pzosdgstvirt12-prod',
                'ip' => '172.25.208.167',
                'ssh_command' => 'ssh informix@172.25.208.167',
                'username' => 'informix',
                'system' => 'Informix / Sacofigo',
                'typical_time' => '1:00 - 3:30 am',
                'does_backup' => true,
                'observations' => 'VM produccion; cron root pendiente; revision-dba.sh pendiente en /usr/local/bin (ver scripts/informix-virt12/README.md)',
                'review_script' => '/usr/local/bin/revision-dba.sh',
                'active' => true,
                'sort_order' => 6,
                'mounts' => [
                    '/BACKUP',
                ],
            ],
            [
                'name' => 'Informix / Sacofigo (virt41)',
                'hostname' => 'pzosdgstvirt41',
                'ip' => '172.25.214.179',
                'ssh_command' => 'ssh sacofigo@172.25.214.179',
                'username' => 'sacofigo',
                'system' => 'Informix / Sacofigo',
                'typical_time' => '2:00 - 3:00 am',
                'does_backup' => true,
                'observations' => 'Calidad+desarrollo; cron activo (ver scripts/informix-virt41/README.md)',
                'review_script' => '/usr/local/bin/revision-dba.sh',
                'active' => true,
                'sort_order' => 7,
                'mounts' => [
                    '/BACKUP',
                    '/BACKUP/calidad',
                    '/BACKUP/desarrollo',
                    '/ifx-instancia',
                    '/opt-informixcali',
                    '/programas',
                    '/Dbs',
                    '/Qsacofigo',
                ],
            ],
            [
                'name' => 'Intranet (deb14)',
                'hostname' => 'pzosdgstdeb14.pzo.cvg.com',
                'ip' => '172.25.214.118',
                'ssh_command' => 'ssh ysabel_mantilla@pzosdgstdeb14.pzo.cvg.com',
                'username' => 'ysabel_mantilla',
                'system' => 'Intranet',
                'typical_time' => '2:15 am / 1:00 am',
                'does_backup' => true,
                'observations' => 'Respaldos en /WEB_DATA_BACKUP; revision-dba.sh instalado en /usr/local/bin',
                'review_script' => '/usr/local/bin/revision-dba.sh',
                'active' => true,
                'sort_order' => 8,
                'mounts' => [
                    '/WEB_DATA_BACKUP',
                    '/NETAPP_POSTGRES',
                    '/NETAPP_ACUEDUCTOS',
                ],
            ],
        ];

        foreach ($servers as $data) {
            $mounts = $data['mounts'] ?? [];
            unset($data['mounts']);

            $server = Server::updateOrCreate(
                ['hostname' => $data['hostname']],
                $data
            );

            foreach ($mounts as $path) {
                Mount::firstOrCreate([
                    'server_id' => $server->id,
                    'path' => $path,
                ]);
            }
        }
    }
}
