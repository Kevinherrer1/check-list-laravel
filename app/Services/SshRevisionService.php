<?php

namespace App\Services;

use App\Models\Check;
use App\Models\Server;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

class SshRevisionService
{
    public function review(Check $check, string $password, ?string $sshUser = null): array
    {
        $server = $check->server;
        if (! $server) {
            return ['ok' => false, 'error' => 'Servidor no encontrado'];
        }

        $script = trim((string) $server->review_script);
        $sshCommand = trim((string) $server->ssh_command);
        if ($script === '' && $sshCommand === '') {
            return ['ok' => false, 'error' => 'Sin script de revisión ni SSH configurado'];
        }

        $userOverride = $sshUser !== null && trim($sshUser) !== '' ? trim($sshUser) : null;

        if ($this->isConnectivityOnly($server)) {
            $run = $this->testConnectivity($server, $password, $userOverride);
            if (! $run['ok']) {
                $this->applyFailure($check, $run);

                return $run + ['servidor_id' => $server->id, 'nombre' => $server->name];
            }

            $payload = $this->mapConnectivity($server, $run['data']);
            $payload['reviewed_by'] = (string) ($run['user'] ?? $userOverride ?? '');
            $this->apply($check, $payload);

            return [
                'ok' => true,
                'modo' => 'conectividad',
                'servidor_id' => $server->id,
                'nombre' => $server->name,
                'revisado_por' => $payload['reviewed_by'],
                'resultado' => $run['data'],
            ];
        }

        $run = $this->runScript($server, $password, $userOverride);
        if (! $run['ok']) {
            $this->applyFailure($check, $run);

            return $run + ['servidor_id' => $server->id, 'nombre' => $server->name];
        }

        $payload = $this->mapRevision($check, $run['data']);
        $payload['reviewed_by'] = (string) ($run['user'] ?? $userOverride ?? '');
        $this->apply($check, $payload);

        return [
            'ok' => true,
            'modo' => 'revision',
            'servidor_id' => $server->id,
            'nombre' => $server->name,
            'revisado_por' => $payload['reviewed_by'],
            'resultado' => $run['data'],
        ];
    }

    private function isConnectivityOnly(Server $server): bool
    {
        if (trim((string) $server->review_script) !== '') {
            return false;
        }

        return trim((string) $server->ssh_command) !== '';
    }

    private function parseSshCommand(string $sshCommand): ?array
    {
        $sshCommand = trim($sshCommand);
        if ($sshCommand === '') {
            return null;
        }

        if (! preg_match('/^ssh\s+(?:-[^\s]+\s+)*([^\s@]+@)?([A-Za-z0-9.\-]+)/', $sshCommand, $m)) {
            return null;
        }

        $userHost = ($m[1] ?? '').$m[2];
        if (str_contains($userHost, '@')) {
            [$user, $host] = explode('@', $userHost, 2);
        } else {
            $user = '';
            $host = $userHost;
        }

        if ($host === '') {
            return null;
        }

        return ['user' => $user, 'host' => $host];
    }

    private function target(Server $server, ?string $userOverride = null): array
    {
        $parsed = $this->parseSshCommand((string) $server->ssh_command);
        $user = trim((string) $server->username);
        if ($parsed && $parsed['user'] !== '') {
            $user = $parsed['user'];
        }

        $ip = trim((string) $server->ip);
        $host = $ip !== ''
            ? $ip
            : trim((string) (($parsed['host'] ?? '') ?: $server->hostname));

        $override = trim((string) ($userOverride ?? ''));
        if ($override !== '') {
            $user = $override;
        }

        return [
            'user' => $user,
            'host' => $host,
            'target' => ($user !== '' ? $user.'@' : '').$host,
        ];
    }

    private function haveSshpass(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $ok = Process::run(['which', 'sshpass'])->successful();
        }

        return $ok;
    }

    private function exec(Server $server, string $password, string $remoteCmd, ?string $userOverride = null): array
    {
        $targetInfo = $this->target($server, $userOverride);
        $host = $targetInfo['host'];
        $target = $targetInfo['target'];
        $user = $targetInfo['user'];

        if ($host === '') {
            return ['ok' => false, 'error' => 'Sin IP/host para SSH'];
        }

        $password = trim($password);
        if ($password === '') {
            return ['ok' => false, 'error' => 'Indique la contraseña SSH del servidor'];
        }

        if (! $this->haveSshpass()) {
            return [
                'ok' => false,
                'error' => 'Falta sshpass en esta PC. Instale con: sudo apt install sshpass',
            ];
        }

        try {
            $result = Process::timeout(18)->idleTimeout(8)->run([
                'sshpass',
                '-p',
                $password,
                'ssh',
                '-o', 'ConnectTimeout=5',
                '-o', 'ConnectionAttempts=1',
                '-o', 'ControlMaster=no',
                '-o', 'NumberOfPasswordPrompts=1',
                '-o', 'LogLevel=ERROR',
                '-o', 'StrictHostKeyChecking=no',
                '-o', 'UserKnownHostsFile=/dev/null',
                '-o', 'PreferredAuthentications=password,keyboard-interactive',
                '-o', 'PubkeyAuthentication=no',
                '-o', 'GSSAPIAuthentication=no',
                '-o', 'HostKeyAlgorithms=+ssh-rsa,ssh-dss',
                '-o', 'PubkeyAcceptedAlgorithms=+ssh-rsa',
                // deb12 (OpenSSH viejo): solo ofrece KEX SHA1; listarlos explícitos evita el rechazo del cliente moderno.
                '-o', 'KexAlgorithms=diffie-hellman-group-exchange-sha1,diffie-hellman-group14-sha1,diffie-hellman-group1-sha1',
                '-o', 'Ciphers=+aes128-cbc,3des-cbc,aes192-cbc,aes256-cbc',
                '-o', 'MACs=+hmac-sha1',
                $target,
                $remoteCmd,
            ]);
        } catch (ProcessTimedOutException $e) {
            $partial = trim((string) ($e->result->errorOutput()."\n".$e->result->output()));
            $hint = $partial !== ''
                ? ' Detalle: '.$this->preview($partial)
                : ' Suele ser VPN activa, contraseña incorrecta o el script remoto colgado (p. ej. du sobre NFS).';

            return [
                'ok' => false,
                'error' => 'SSH no terminó en 18s en '.$target.'.'.$hint,
                'raw' => $partial,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => 'No se pudo ejecutar SSH: '.mb_substr($e->getMessage(), 0, 180),
            ];
        }

        $text = $this->sanitizeSshOutput(trim($result->output()."\n".$result->errorOutput()));
        $code = $result->exitCode();
        $lines = $text === '' ? [] : preg_split('/\r\n|\r|\n/', $text);

        if ($code === 124) {
            return [
                'ok' => false,
                'error' => 'El script remoto se demoró demasiado en '.$target.' (posible colgado en NetApp/du). Pruebe: ssh '.$target.' '.escapeshellarg($remoteCmd),
                'raw' => $text,
                'exit_code' => $code,
                'target' => $target,
                'user' => $user,
            ];
        }

        if ($text === '') {
            return [
                'ok' => false,
                'error' => 'Sin respuesta SSH (¿host alcanzable? ¿usuario correcto?)',
                'raw' => '',
                'exit_code' => $code,
                'target' => $target,
                'user' => $user,
            ];
        }

        if (stripos($text, 'Timeout, server') !== false && stripos($text, 'not responding') !== false) {
            return [
                'ok' => false,
                'error' => 'SSH entró a '.$target.' pero la sesión se cortó sin salida del script (¿script colgado en NetApp/NFS?). Pruebe: ssh '.$target.' '.escapeshellarg($remoteCmd),
                'raw' => $text,
                'exit_code' => $code,
                'target' => $target,
                'user' => $user,
            ];
        }

        if (stripos($text, 'Usage: sshpass') !== false) {
            return [
                'ok' => false,
                'error' => 'sshpass no recibió la contraseña. Revise que sshpass esté instalado (sudo apt install sshpass).',
                'raw' => $text,
                'exit_code' => $code,
                'target' => $target,
                'user' => $user,
            ];
        }

        if (stripos($text, 'Could not resolve hostname') !== false || stripos($text, 'Name or service not known') !== false) {
            $ip = trim((string) $server->ip);
            $hint = $ip !== '' ? ' Se intentará por IP si está en el inventario ('.$ip.').' : '';

            return [
                'ok' => false,
                'error' => 'No se resolvió el nombre del servidor ('.$host.').'.$hint,
                'raw' => $text,
                'exit_code' => $code,
                'target' => $target,
                'user' => $user,
            ];
        }

        if ($this->isUnreachableText($text)) {
            return [
                'ok' => false,
                'error' => 'Sin respuesta en el puerto 22 ('.$target.'). El servidor está inalcanzable o apagado.',
                'raw' => $text,
                'exit_code' => $code,
                'target' => $target,
                'user' => $user,
            ];
        }

        if (stripos($text, 'Connection refused') !== false) {
            return [
                'ok' => false,
                'error' => 'El host respondió pero rechazó SSH en el puerto 22 ('.$target.').',
                'raw' => $text,
                'exit_code' => $code,
                'target' => $target,
                'user' => $user,
            ];
        }

        if (stripos($text, 'no matching key exchange') !== false) {
            return [
                'ok' => false,
                'error' => 'El servidor usa SSH antiguo (Debian 6). No coincidió el intercambio de claves. '.$this->preview($text),
                'raw' => $text,
                'exit_code' => $code,
                'target' => $target,
                'user' => $user,
            ];
        }

        if (
            stripos($text, 'Permission denied') !== false
            || stripos($text, 'Invalid user') !== false
            || stripos($text, 'Could not chdir') !== false
        ) {
            $hint = '';
            $configured = trim((string) $server->username);
            if ($configured !== '' && strcasecmp($configured, $user) !== 0) {
                $hint = '. En este servidor el usuario SSH configurado es '.$configured;
            }

            return [
                'ok' => false,
                'error' => 'Contraseña incorrecta o usuario sin acceso SSH ('.$target.')'.$hint,
                'raw' => $text,
                'exit_code' => $code,
                'target' => $target,
                'user' => $user,
            ];
        }

        if (
            stripos($text, 'No such file') !== false
            || stripos($text, 'command not found') !== false
        ) {
            return [
                'ok' => false,
                'error' => 'No está el script de revisión en el servidor ('.$remoteCmd.'). '.$this->preview($text),
                'raw' => $text,
                'exit_code' => $code,
                'target' => $target,
                'user' => $user,
            ];
        }

        return [
            'ok' => true,
            'raw' => $text,
            'exit_code' => $code,
            'target' => $target,
            'user' => $user,
            'lines' => $lines,
        ];
    }

    private function testConnectivity(Server $server, string $password, ?string $userOverride = null): array
    {
        $run = $this->exec($server, $password, 'echo SSH_OK', $userOverride);
        if (! $run['ok']) {
            return $run;
        }

        $hostname = '';
        $okLine = false;
        foreach ($run['lines'] as $line) {
            $line = trim($line);
            if ($line === 'SSH_OK') {
                $okLine = true;

                continue;
            }
            if ($line !== '' && stripos($line, 'Setting environment') === false && $line !== '[done]') {
                if ($hostname === '' && preg_match('/^[A-Za-z0-9._-]+$/', $line)) {
                    $hostname = $line;
                }
            }
        }

        if (! $okLine && $run['exit_code'] !== 0) {
            return [
                'ok' => false,
                'error' => 'SSH conectó pero no respondió la prueba (salida inesperada)',
                'raw' => $run['raw'],
                'exit_code' => $run['exit_code'],
                'target' => $run['target'],
            ];
        }

        if (! $okLine) {
            return [
                'ok' => false,
                'error' => 'SSH respondió pero no se encontró confirmación SSH_OK',
                'raw' => $run['raw'],
                'exit_code' => $run['exit_code'],
                'target' => $run['target'],
            ];
        }

        return [
            'ok' => true,
            'data' => [
                'servidor' => (string) ($server->hostname ?: $server->ip ?: ''),
                'respaldo' => 'na',
                'detalle' => 'SSH OK'.($hostname !== '' ? ' ('.$hostname.')' : ''),
                'hostname' => $hostname,
            ],
            'raw' => $run['raw'],
            'exit_code' => $run['exit_code'],
            'target' => $run['target'],
            'user' => $run['user'] ?? '',
        ];
    }

    private function runScript(Server $server, string $password, ?string $userOverride = null): array
    {
        $script = trim((string) $server->review_script);
        if ($script === '') {
            $script = '/usr/local/bin/revision-dba.sh';
        }

        $target = $this->target($server, $userOverride)['target'];
        // En servidores muy antiguos (p. ej. deb12 / kernel 2.6) el wrapper "timeout -k"
        // puede colgar la sesión SSH no interactiva. El script en sí responde en <1s.
        $remote = $script;
        $remoteJson = $script.' --json';
        $run = $this->exec($server, $password, $remote, $userOverride);
        if (! $run['ok']) {
            return $run;
        }

        $text = $run['raw'];
        $code = $run['exit_code'];
        $data = $this->parsePlainOutput($text);

        if ($data === null) {
            $runJson = $this->exec($server, $password, $remoteJson, $userOverride);
            if (! $runJson['ok']) {
                return [
                    'ok' => false,
                    'error' => ($runJson['error'] ?? 'SSH respondió pero no se entendió la salida').' Pruebe: ssh '.$target.' '.escapeshellarg($script),
                    'raw' => $text,
                    'exit_code' => $code,
                ];
            }
            foreach ($runJson['lines'] as $line) {
                $line = trim($line);
                if ($line !== '' && $line[0] === '{') {
                    $decoded = json_decode($line, true);
                    if (is_array($decoded)) {
                        $data = $decoded;
                        $text = $runJson['raw'];
                        $code = $runJson['exit_code'];
                        break;
                    }
                }
            }
        }

        if ($data === null) {
            return [
                'ok' => false,
                'error' => 'SSH respondió pero no se entendió la salida: '.$this->preview($text).' Pruebe: ssh '.$target.' '.escapeshellarg($script),
                'raw' => $text,
                'exit_code' => $code,
            ];
        }

        return [
            'ok' => true,
            'data' => $data,
            'raw' => $text,
            'exit_code' => $code,
            'target' => $run['target'] ?? $target,
            'user' => $run['user'] ?? '',
        ];
    }

    private function preview(string $text): string
    {
        $oneLine = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        return mb_substr($oneLine, 0, 180);
    }

    private function sanitizeSshOutput(string $text): string
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, 'Warning: Permanently added')) {
                continue;
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    private function parsePlainOutput(string $text): ?array
    {
        $map = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $val] = explode('=', $line, 2);
            $map[trim($key)] = trim($val);
        }

        if (! isset($map['SERVIDOR']) && ! isset($map['MONTAJE_BACKUP'])) {
            return null;
        }

        $montajes = [];
        foreach ($map as $key => $val) {
            if (str_starts_with($key, 'MONTAJE_/')) {
                $montajes[substr($key, 8)] = $val;
            }
        }
        if (isset($map['MONTAJE_BACKUP']) && ! isset($montajes['/BACKUP'])) {
            $montajes['/BACKUP'] = $map['MONTAJE_BACKUP'];
        }
        if (isset($map['MONTAJE_BACKUP']) && ! isset($montajes['/mnt/netapp/BACKUP'])) {
            $montajes['/mnt/netapp/BACKUP'] = $map['MONTAJE_BACKUP'];
        }

        $montajePhp = $map['MONTAJE_/BACKUP/PHP'] ?? ($map['MONTAJE_PHP'] ?? '');
        if ($montajePhp === '' && $montajes !== []) {
            $montajePhp = 'ok';
        }
        if ($montajePhp === '') {
            $montajePhp = 'falla';
        }

        $out = [
            'servidor' => $map['SERVIDOR'] ?? '',
            'sistema' => $map['SISTEMA'] ?? '',
            'fecha' => $map['FECHA'] ?? '',
            'fechad' => $map['FECHAD'] ?? '',
            'archivo_php' => $map['ARCHIVO_PHP'] ?? '',
            'archivo_db' => $map['ARCHIVO_DB'] ?? '',
            'hora_gen' => $map['HORA_GEN'] ?? ($map['HORA'] ?? ''),
            'montaje_backup' => $map['MONTAJE_BACKUP'] ?? 'falla',
            'montaje_php' => $montajePhp,
            'respaldo' => strtolower($map['RESPALDO'] ?? 'pendiente'),
            'archivo' => $map['ARCHIVO'] ?? '',
            'tamano' => $map['TAMANO'] ?? '',
            'detalle' => $map['DETALLE'] ?? '',
            'exit_code' => (int) ($map['EXIT_CODE'] ?? 1),
        ];
        if ($montajes !== []) {
            $out['montajes'] = $montajes;
        }

        return $out;
    }

    private function backupDateFromData(array $data, Check $check): ?string
    {
        $fechad = (string) ($data['fechad'] ?? '');
        if (preg_match('/-(\d{8})$/', $fechad, $m)) {
            $d = $m[1];

            return substr($d, 0, 4).'-'.substr($d, 4, 2).'-'.substr($d, 6, 2);
        }

        $fecha = (string) ($data['fecha'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return $fecha;
        }

        $reviewDate = $check->review?->date;
        if ($reviewDate) {
            return $reviewDate->format('Y-m-d');
        }

        return null;
    }

    private function backupLine(array $data): string
    {
        $respaldo = strtolower((string) ($data['respaldo'] ?? ''));
        $detalle = trim((string) ($data['detalle'] ?? ''));
        if (in_array($respaldo, ['falla', 'fallido', 'fail'], true) && $detalle !== '') {
            return $detalle;
        }

        $fechad = (string) ($data['fechad'] ?? '');
        $archivos = [];
        if (! empty($data['archivo_php'])) {
            $archivos[] = (string) $data['archivo_php'];
        }
        if (! empty($data['archivo_db'])) {
            $archivos[] = (string) $data['archivo_db'];
        }
        if ($fechad === '' && $archivos === []) {
            return $detalle;
        }

        $linea = $fechad;
        if ($archivos !== []) {
            $linea .= ($linea !== '' ? ' → ' : '').implode(', ', $archivos);
        }
        if ($respaldo === 'ok' || $respaldo === 'exitoso') {
            return $linea;
        }
        if ($detalle !== '') {
            return $linea !== '' ? ($linea.' · '.$detalle) : $detalle;
        }

        return $linea;
    }

    private function mountValue(array $data, string $ruta): string
    {
        $montajesData = $data['montajes'] ?? null;
        if (is_array($montajesData)) {
            if (array_key_exists($ruta, $montajesData)) {
                return (string) $montajesData[$ruta];
            }
            foreach ($montajesData as $k => $v) {
                if (strcasecmp((string) $k, $ruta) === 0) {
                    return (string) $v;
                }
            }
        }

        $nfsAll = strtolower((string) ($data['montaje_nfs'] ?? ''));
        if ($nfsAll === 'ok') {
            return 'ok';
        }

        $backup = strtolower((string) ($data['montaje_backup'] ?? ''));
        if ($backup === 'ok') {
            $r = strtolower($ruta);
            if (str_contains($r, 'netapp') || str_contains($r, 'backup')) {
                return 'ok';
            }
        }

        return 'falla';
    }

    private function mapConnectivity(Server $server, array $data): array
    {
        $detalle = (string) ($data['detalle'] ?? 'SSH OK');
        $montajesMap = [];
        foreach ($server->mounts as $mount) {
            $montajesMap[$mount->path] = false;
        }

        return [
            'powered_on' => 'prendido',
            'mounts_status' => $server->mounts->isEmpty() ? 'na' : 'pending',
            'mounts_details' => $montajesMap,
            'backup' => 'na',
            'backup_date' => null,
            'size' => '',
            'root_cause' => '',
            'review_result' => $detalle,
        ];
    }

    private function mapRevision(Check $check, array $data): array
    {
        $server = $check->server;
        $montajesMap = [];
        $mounts = $server->mounts;

        if (isset($data['montajes']) && is_array($data['montajes'])) {
            foreach ($mounts as $mount) {
                $ruta = (string) $mount->path;
                $montajesMap[$ruta] = $this->mountValue($data, $ruta) === 'ok';
            }
            $allOk = $mounts->isNotEmpty() && ! in_array(false, $montajesMap, true);
            $montajesEstado = $allOk ? 'ok' : ($mounts->isEmpty() ? 'na' : 'falla');
        } else {
            $montajeBackup = (string) ($data['montaje_backup'] ?? 'falla');
            $montajePhp = (string) ($data['montaje_php'] ?? 'falla');

            foreach ($mounts as $mount) {
                $ruta = (string) $mount->path;
                if ($ruta === '/BACKUP/PHP' || str_starts_with($ruta, '/BACKUP/')) {
                    $montajesMap[$ruta] = ($montajeBackup === 'ok' && $montajePhp === 'ok');
                } elseif ($ruta === '/BACKUP') {
                    $montajesMap[$ruta] = $montajeBackup === 'ok';
                } else {
                    $montajesMap[$ruta] = $montajeBackup === 'ok';
                }
            }

            if ($montajeBackup === 'ok' && ($montajePhp === 'ok' || $mounts->isEmpty())) {
                $montajesEstado = 'ok';
            } else {
                $montajesEstado = $mounts->isEmpty() ? 'na' : 'falla';
            }
        }

        $respaldoRaw = strtolower((string) ($data['respaldo'] ?? 'falla'));
        $respaldo = match ($respaldoRaw) {
            'ok', 'exitoso' => 'exitoso',
            'na' => 'na',
            'pendiente', 'pending' => 'pending',
            default => 'fallido',
        };
        if (! $server->does_backup) {
            $respaldo = 'na';
        }

        $causa = '';
        $detalle = trim((string) ($data['detalle'] ?? ''));
        if ($respaldo === 'fallido' || $montajesEstado === 'falla') {
            $causa = $detalle !== '' ? $detalle : 'Revisión SSH reportó falla';
        }

        return [
            'powered_on' => 'prendido',
            'mounts_status' => $montajesEstado,
            'mounts_details' => $montajesMap,
            'backup' => $respaldo,
            'generated_at' => trim((string) ($data['hora_gen'] ?? '')),
            'backup_date' => $this->backupDateFromData($data, $check),
            'size' => (string) ($data['tamano'] ?? ''),
            'root_cause' => $causa,
            'review_result' => $this->backupLine($data),
        ];
    }

    private function isUnreachableText(string $text): bool
    {
        $blob = strtolower($text);

        return str_contains($blob, 'connection timed out')
            || str_contains($blob, 'operation timed out')
            || str_contains($blob, 'connect timeout')
            || str_contains($blob, 'no route to host')
            || str_contains($blob, 'network is unreachable')
            || str_contains($blob, 'network unreachable')
            || str_contains($blob, 'host is unreachable');
    }

    private function applyFailure(Check $check, array $run): void
    {
        $blob = strtolower((string) (($run['error'] ?? '').' '.($run['raw'] ?? '')));
        $error = mb_substr((string) ($run['error'] ?? 'Error SSH'), 0, 255);
        $server = $check->server;

        $payload = [
            'review_result' => $error,
            'root_cause' => $error,
            'reviewed_by' => (string) ($run['user'] ?? ''),
        ];
        $payload = $this->notApplicable($server, $payload);

        if (
            str_contains($blob, 'usage: sshpass')
            || str_contains($blob, 'falta sshpass')
            || str_contains($blob, 'no recibió la contraseña')
        ) {
            $check->update($payload);

            return;
        }

        $unreachable = $this->isUnreachableText($blob)
            || str_contains($blob, 'inalcanzable o apagado')
            || str_contains($blob, 'sin respuesta ssh')
            || str_contains($blob, 'sin respuesta en el puerto 22');

        $authFail = str_contains($blob, 'permission denied')
            || str_contains($blob, 'contraseña incorrecta')
            || str_contains($blob, 'invalid user');

        $reachedHost = ! $unreachable && (
            $authFail
            || str_contains($blob, 'connection refused')
            || str_contains($blob, 'rechazó ssh')
            || str_contains($blob, 'key exchange')
            || str_contains($blob, 'host key')
            || str_contains($blob, 'no such file')
            || str_contains($blob, 'no está el script')
            || str_contains($blob, 'no se entendió')
        );

        $reviewIncomplete = str_contains($blob, 'no such file')
            || str_contains($blob, 'no está el script')
            || (! $unreachable && str_contains($blob, 'no se entendió'));

        $timedOut = str_contains($blob, 'tiempo de espera')
            || str_contains($blob, 'timed out')
            || str_contains($blob, 'timeout, server')
            || str_contains($blob, 'not responding')
            || str_contains($blob, 'no se pudo ejecutar ssh')
            || str_contains($blob, 'sin respuesta ssh')
            || str_contains($blob, 'puerto 22')
            || str_contains($blob, 'no responde')
            || str_contains($blob, 'script colgado');

        // El ping (ICMP) es la fuente de "encendido". SSH solo confirma prendido
        // si llegó al host; un fallo/timeout de SSH no debe tumbar un ping OK.
        if ($reachedHost) {
            $payload['powered_on'] = 'prendido';
        }

        if (! $authFail && ! $timedOut && ($reviewIncomplete || $unreachable)) {
            if ($server?->mounts?->isNotEmpty()) {
                $payload['mounts_status'] = 'falla';
            }
            if ($server?->does_backup) {
                $payload['backup'] = 'fallido';
            }
        }

        $payload = $this->notApplicable($server, $payload);
        $check->update($payload);
    }

    private function notApplicable(?Server $server, array $payload): array
    {
        if (! $server) {
            return $payload;
        }
        if (! $server->does_backup) {
            $payload['backup'] = 'na';
        }
        if ($server->mounts->isEmpty()) {
            $payload['mounts_status'] = 'na';
        }

        return $payload;
    }

    private function apply(Check $check, array $payload): void
    {
        $check->update($this->notApplicable($check->server, $payload));
    }
}
