<?php

namespace App\Http\Controllers;

use App\Jobs\RunSshRevision;
use App\Models\Check;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class SshRevisionController extends Controller
{
    public function store(Request $request, Check $check): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'ssh_user' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
        ]);

        $check->refresh();

        if (in_array($check->ssh_status, ['queued', 'running'], true)) {
            return response()->json([
                'job_id' => $check->ssh_job_id,
                'status' => $check->ssh_status,
                'check' => $check->load('server.mounts'),
            ], 202);
        }

        $jobId = (string) Str::uuid();
        $queued = Check::query()
            ->whereKey($check->id)
            ->whereNotIn('ssh_status', ['queued', 'running'])
            ->update([
                'ssh_status' => 'queued',
                'ssh_job_id' => $jobId,
                'ssh_error' => null,
                'ssh_started_at' => null,
                'ssh_finished_at' => null,
            ]);

        if ($queued === 0) {
            $check->refresh();

            return response()->json([
                'job_id' => $check->ssh_job_id,
                'status' => $check->ssh_status,
                'check' => $check->load('server.mounts'),
            ], 202);
        }

        set_time_limit(45);

        try {
            RunSshRevision::dispatchSync(
                $check->id,
                $jobId,
                Crypt::encryptString($data['password']),
                $data['ssh_user'] ?? null,
            );
        } catch (\Throwable $e) {
            $check->refresh();
            if (! in_array($check->ssh_status, ['completed', 'failed'], true)) {
                $check->update([
                    'ssh_status' => 'failed',
                    'ssh_error' => mb_substr($e->getMessage(), 0, 240),
                    'ssh_finished_at' => now(),
                ]);
            }
        }

        $check->refresh()->load('server.mounts');
        $status = $check->ssh_status ?: 'failed';
        $pending = in_array($status, ['queued', 'running'], true);

        return response()->json([
            'job_id' => $jobId,
            'status' => $status,
            'error' => $check->ssh_error,
            'check' => $check,
        ], $pending ? 202 : 200);
    }

    public function show(string $jobId): JsonResponse
    {
        $check = Check::query()
            ->where('ssh_job_id', $jobId)
            ->with('server.mounts')
            ->firstOrFail();

        return response()->json([
            'job_id' => $jobId,
            'status' => $check->ssh_status,
            'error' => $check->ssh_error,
            'check' => $check,
        ]);
    }
}
