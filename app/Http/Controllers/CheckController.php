<?php

namespace App\Http\Controllers;

use App\Models\Check;
use App\Services\PingService;
use Illuminate\Http\Request;

class CheckController extends Controller
{
    public function show(Check $check)
    {
        $check->load('server.mounts');

        return response()->json($check);
    }

    public function update(Request $request, Check $check)
    {
        $data = $request->validate([
            'powered_on' => ['sometimes', 'in:pending,prendido,apagado'],
            'mounts_status' => ['sometimes', 'in:pending,ok,falla,na'],
            'mounts_details' => ['sometimes', 'array'],
            'mounts_details.*' => ['boolean'],
            'backup' => ['sometimes', 'in:pending,exitoso,fallido,na'],
            'generated_at' => ['sometimes', 'string', 'max:255'],
            'backup_date' => ['sometimes', 'nullable', 'date'],
            'size' => ['sometimes', 'string', 'max:255'],
            'root_cause' => ['sometimes', 'string', 'max:255'],
            'notified' => ['sometimes', 'string', 'max:255'],
            'channel' => ['sometimes', 'string', 'max:255'],
            'observations' => ['sometimes', 'nullable', 'string'],
            'review_result' => ['sometimes', 'string', 'max:255'],
            'reviewed_by' => ['sometimes', 'string', 'max:255'],
        ]);

        $check->update($data);
        $check->load('server.mounts');

        return response()->json($check);
    }

    public function ping(Check $check, PingService $ping)
    {
        $check->load('server.mounts');
        $result = $ping->run($check->server);

        if (! empty($result['ok'])) {
            $payload = ['powered_on' => 'prendido'];
            if (! $check->server->does_backup) {
                $payload['backup'] = 'na';
            }
            if ($check->server->mounts->isEmpty()) {
                $payload['mounts_status'] = 'na';
            }
            $check->update($payload);
        }

        $check->refresh()->load('server.mounts');

        return response()->json([
            'ok' => (bool) $result['ok'],
            'ms' => $result['ms'],
            'error' => $result['error'],
            'host' => $result['host'],
            'check' => $check,
        ]);
    }
}
