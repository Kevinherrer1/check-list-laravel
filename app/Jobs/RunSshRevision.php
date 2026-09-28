<?php

namespace App\Jobs;

use App\Models\Check;
use App\Services\SshRevisionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class RunSshRevision implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 25;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $checkId,
        public string $jobId,
        public string $encryptedPassword,
        public ?string $sshUser = null,
    ) {}

    public function handle(SshRevisionService $sshRevision): void
    {
        $check = Check::query()
            ->with('server.mounts', 'review')
            ->findOrFail($this->checkId);

        if ($check->ssh_job_id !== $this->jobId) {
            return;
        }

        $check->update([
            'ssh_status' => 'running',
            'ssh_error' => null,
            'ssh_started_at' => now(),
            'ssh_finished_at' => null,
        ]);

        $result = $sshRevision->review(
            $check,
            Crypt::decryptString($this->encryptedPassword),
            $this->sshUser,
        );

        $check->update([
            'ssh_status' => $result['ok'] ? 'completed' : 'failed',
            'ssh_error' => $result['ok'] ? null : ($result['error'] ?? 'La revisión SSH falló.'),
            'ssh_finished_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Check::query()
            ->whereKey($this->checkId)
            ->where('ssh_job_id', $this->jobId)
            ->update([
                'ssh_status' => 'failed',
                'ssh_error' => $exception?->getMessage() ?? 'La revisión SSH falló.',
                'ssh_finished_at' => now(),
            ]);
    }
}
