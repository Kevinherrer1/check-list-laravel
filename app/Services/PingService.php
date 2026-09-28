<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Process;

class PingService
{
    public function run(Server $server): array
    {
        $host = trim((string) ($server->ip ?: $server->hostname));

        if ($host === '' || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-]{0,253}$/', $host)) {
            return [
                'ok' => false,
                'ms' => null,
                'error' => 'Host inválido',
                'host' => $host,
            ];
        }

        $result = Process::timeout(8)->run(['ping', '-c', '1', '-W', '2', $host]);
        $text = trim($result->output()."\n".$result->errorOutput());
        $ms = null;
        if (preg_match('/time[=<]\s*([0-9.]+)\s*ms/i', $text, $m)) {
            $ms = (float) $m[1];
        }

        return [
            'ok' => $result->successful(),
            'ms' => $ms,
            'error' => $result->successful() ? null : 'Sin respuesta',
            'host' => $host,
        ];
    }
}
