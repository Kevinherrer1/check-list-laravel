<?php

namespace Tests\Feature;

use App\Jobs\RunSshRevision;
use App\Models\Check;
use App\Models\Review;
use App\Models\Server;
use App\Models\User;
use App\Services\SshRevisionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class SshRevisionQueueTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_authenticated_user_can_queue_an_ssh_revision(): void
    {
        Queue::fake([RunSshRevision::class]);
        Sanctum::actingAs(User::factory()->create());
        $check = $this->createCheck();

        $response = $this->postJson("/api/checks/{$check->id}/ssh", [
            'password' => 'secret-password',
            'ssh_user' => 'operator',
        ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('check.ssh_status', 'queued');

        Queue::assertPushed(
            RunSshRevision::class,
            fn (RunSshRevision $job): bool => $job->checkId === $check->id
                && $job->sshUser === 'operator'
                && Crypt::decryptString($job->encryptedPassword) === 'secret-password',
        );

        $this->assertDatabaseHas('checks', [
            'id' => $check->id,
            'ssh_status' => 'queued',
            'ssh_job_id' => $response->json('job_id'),
        ]);
    }

    public function test_running_check_is_not_queued_twice(): void
    {
        Queue::fake([RunSshRevision::class]);
        Sanctum::actingAs(User::factory()->create());
        $check = $this->createCheck();
        $check->update([
            'ssh_status' => 'running',
            'ssh_job_id' => 'a4294f8d-f8c7-48f5-b395-716c36f804a1',
        ]);

        $this->postJson("/api/checks/{$check->id}/ssh", [
            'password' => 'secret-password',
        ])
            ->assertAccepted()
            ->assertJsonPath('job_id', 'a4294f8d-f8c7-48f5-b395-716c36f804a1')
            ->assertJsonPath('status', 'running');

        Queue::assertNothingPushed();
    }

    public function test_job_updates_the_check_and_exposes_its_status(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $check = $this->createCheck();
        $jobId = '89f3b57c-1ba2-4488-90b2-33a942109d09';
        $check->update([
            'ssh_status' => 'queued',
            'ssh_job_id' => $jobId,
        ]);

        $service = Mockery::mock(SshRevisionService::class);
        $service->shouldReceive('review')
            ->once()
            ->withArgs(
                fn (Check $receivedCheck, string $password, ?string $sshUser): bool => $receivedCheck->is($check)
                    && $password === 'secret-password'
                    && $sshUser === 'operator',
            )
            ->andReturn(['ok' => true]);

        (new RunSshRevision(
            $check->id,
            $jobId,
            Crypt::encryptString('secret-password'),
            'operator',
        ))->handle($service);

        $this->getJson("/api/ssh-revisions/{$jobId}")
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('check.id', $check->id);

        $this->assertDatabaseHas('checks', [
            'id' => $check->id,
            'ssh_status' => 'completed',
            'ssh_error' => null,
        ]);
    }

    public function test_ssh_endpoints_require_authentication(): void
    {
        $check = $this->createCheck();

        $this->postJson("/api/checks/{$check->id}/ssh", [
            'password' => 'secret-password',
        ])->assertUnauthorized();
    }

    private function createCheck(): Check
    {
        $server = Server::create([
            'name' => 'Test server',
            'hostname' => 'test-server',
            'active' => true,
        ]);
        $review = Review::create([
            'date' => '2026-09-22',
            'responsible' => 'Tester',
        ]);

        return Check::create([
            'review_id' => $review->id,
            'server_id' => $server->id,
        ]);
    }
}
