<?php

namespace App\Http\Controllers;

use App\Models\Mount;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServerController extends Controller
{
    public function index()
    {
        return response()->json($this->list());
    }

    public function show(Server $server)
    {
        return response()->json($server->load('mounts'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $paths = $this->mountPaths($request);

        $server = DB::transaction(function () use ($data, $paths) {
            if (($data['sort_order'] ?? 0) <= 0) {
                $data['sort_order'] = (int) Server::query()->max('sort_order') + 1;
            }
            if (trim((string) ($data['ssh_command'] ?? '')) === '') {
                $data['ssh_command'] = $this->guessSsh($data);
            }
            if (trim((string) ($data['review_script'] ?? '')) === '') {
                $data['review_script'] = '/usr/local/bin/revision-dba.sh';
            }
            $server = Server::create($data);
            $this->syncMounts($server, $paths);

            return $server->load('mounts');
        });

        return response()->json($server, 201);
    }

    public function update(Request $request, Server $server)
    {
        $data = $this->validated($request);
        $paths = $this->mountPaths($request);

        $server = DB::transaction(function () use ($server, $data, $paths) {
            if (trim((string) ($data['ssh_command'] ?? '')) === '') {
                $data['ssh_command'] = $this->guessSsh($data);
            }
            $server->update($data);
            $this->syncMounts($server, $paths);

            return $server->fresh()->load('mounts');
        });

        return response()->json($server);
    }

    public function destroy(Server $server)
    {
        $server->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'ip' => ['nullable', 'string', 'max:64'],
            'ssh_command' => ['nullable', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:64'],
            'system' => ['nullable', 'string', 'max:255'],
            'typical_time' => ['nullable', 'string', 'max:64'],
            'does_backup' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'review_script' => ['nullable', 'string', 'max:255'],
            'netapp_volume' => ['nullable', 'string', 'max:255'],
            'nfs_root' => ['nullable', 'string', 'max:255'],
            'nfs_slug' => ['nullable', 'string', 'max:64'],
            'observations' => ['nullable', 'string', 'max:2000'],
            'mounts_text' => ['nullable', 'string', 'max:4000'],
        ]);

        foreach ([
            'hostname', 'ip', 'ssh_command', 'username', 'system', 'typical_time',
            'review_script', 'netapp_volume', 'nfs_root', 'nfs_slug',
        ] as $key) {
            $data[$key] = trim((string) ($data[$key] ?? ''));
        }
        $data['observations'] = trim((string) ($data['observations'] ?? '')) ?: null;
        $data['does_backup'] = $request->boolean('does_backup', true);
        $data['active'] = $request->boolean('active', true);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        unset($data['mounts_text']);

        return $data;
    }

    /**
     * @return list<string>
     */
    private function mountPaths(Request $request): array
    {
        $raw = $request->input('mounts_text', $request->input('mounts', ''));
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = preg_split('/[\r\n,;]+/', (string) $raw) ?: [];
        }
        $out = [];
        $seen = [];
        foreach ($parts as $p) {
            $path = trim((string) $p);
            if ($path === '' || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            $out[] = $path;
        }

        return $out;
    }

    /**
     * @param  list<string>  $paths
     */
    private function syncMounts(Server $server, array $paths): void
    {
        $keep = [];
        foreach ($paths as $path) {
            $mount = Mount::query()->firstOrCreate([
                'server_id' => $server->id,
                'path' => $path,
            ]);
            $keep[] = $mount->id;
        }

        $q = Mount::query()->where('server_id', $server->id);
        if ($keep !== []) {
            $q->whereNotIn('id', $keep);
        }
        $q->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function guessSsh(array $data): string
    {
        $user = trim((string) ($data['username'] ?? ''));
        $host = trim((string) (($data['ip'] ?? '') !== '' ? $data['ip'] : ($data['hostname'] ?? '')));
        if ($user === '' || $host === '') {
            return '';
        }

        return 'ssh '.$user.'@'.$host;
    }

    private function list()
    {
        return Server::query()
            ->with('mounts')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
}
