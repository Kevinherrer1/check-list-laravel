<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class CvgLdapDirectoryService
{
    /**
     * @return array{ok: bool, host: string, count: int, error: ?string, contactos: list<array{nombre: string, email: string}>}
     */
    public function lookup(): array
    {
        $cfg = config('bitacora_correo.ldap', []);
        $empty = [
            'ok' => false,
            'host' => '',
            'count' => 0,
            'error' => null,
            'contactos' => [],
        ];

        if (! ($cfg['enabled'] ?? false)) {
            $empty['error'] = 'LDAP deshabilitado';

            return $empty;
        }

        $host = trim((string) ($cfg['host'] ?? ''));
        $ip = trim((string) ($cfg['host_ip'] ?? ''));
        $port = (int) ($cfg['port'] ?? 389);
        $base = trim((string) ($cfg['base'] ?? ''));
        $filter = trim((string) ($cfg['filter'] ?? '(mail=*)'));
        $limit = max(1, (int) ($cfg['limit'] ?? 800));
        $timeout = max(1, (int) ($cfg['timeout'] ?? 5));
        $target = $ip !== '' ? $ip : $host;

        if ($target === '' || $base === '') {
            $empty['error'] = 'Falta host o base LDAP';

            return $empty;
        }

        $uri = sprintf('ldap://%s:%d', $target, $port);
        $empty['host'] = $host !== '' ? $host : $target;

        $raw = $this->search($uri, $base, $filter, $limit, $timeout);
        if (! ($raw['ok'] ?? false)) {
            $empty['error'] = $this->mensajeError((string) ($raw['error'] ?? 'No se alcanzó el LDAP'));

            return $empty;
        }

        $contactos = $this->parseLdif((string) ($raw['ldif'] ?? ''));
        if ($contactos === []) {
            return [
                'ok' => false,
                'host' => $empty['host'],
                'count' => 0,
                'error' => 'El LDAP respondió pero no trajo correos',
                'contactos' => [],
            ];
        }

        usort($contactos, fn ($a, $b) => strcasecmp($a['nombre'], $b['nombre']));

        return [
            'ok' => true,
            'host' => $empty['host'],
            'count' => count($contactos),
            'error' => null,
            'contactos' => $contactos,
        ];
    }

    /**
     * @return list<array{nombre: string, email: string}>
     */
    public function parseLdif(string $ldif): array
    {
        $out = [];
        $seen = [];
        $current = ['nombre' => '', 'email' => ''];

        $flush = function () use (&$out, &$seen, &$current): void {
            $email = strtolower(trim($current['email']));
            $nombre = trim($current['nombre']);
            $current = ['nombre' => '', 'email' => ''];
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            if (isset($seen[$email])) {
                return;
            }
            $seen[$email] = true;
            $out[] = [
                'nombre' => $nombre !== '' ? $nombre : $email,
                'email' => $email,
            ];
        };

        foreach (preg_split("/\r\n|\n|\r/", $ldif) ?: [] as $line) {
            if (str_starts_with($line, '#')) {
                continue;
            }
            if (trim($line) === '') {
                $flush();

                continue;
            }
            if (str_starts_with($line, 'dn:') || str_starts_with($line, 'dn::')) {
                $flush();

                continue;
            }

            $decoded = $this->ldifValue($line);
            if ($decoded === null) {
                continue;
            }
            [$attr, $value] = $decoded;
            $attr = strtolower($attr);
            if ($attr === 'mail' && $current['email'] === '') {
                $current['email'] = $value;
            }
            if (in_array($attr, ['displayname', 'cn', 'givenname'], true) && ($current['nombre'] === '' || $attr === 'displayname')) {
                $current['nombre'] = $value;
            }
        }
        $flush();

        return $out;
    }

    /**
     * @return array{ok: bool, ldif?: string, error?: string}
     */
    private function search(string $uri, string $base, string $filter, int $limit, int $timeout): array
    {
        if (extension_loaded('ldap')) {
            return $this->searchPhp($uri, $base, $filter, $limit, $timeout);
        }

        return $this->searchCli($uri, $base, $filter, $limit, $timeout);
    }

    /**
     * @return array{ok: bool, ldif?: string, error?: string}
     */
    private function searchPhp(string $uri, string $base, string $filter, int $limit, int $timeout): array
    {
        $ds = @ldap_connect($uri);
        if (! $ds) {
            return ['ok' => false, 'error' => "Can't contact LDAP server"];
        }

        ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ds, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($ds, LDAP_OPT_NETWORK_TIMEOUT, $timeout);
        ldap_set_option($ds, LDAP_OPT_TIMELIMIT, $timeout);

        if (! @ldap_bind($ds)) {
            $err = ldap_error($ds);
            ldap_unbind($ds);

            return ['ok' => false, 'error' => $err !== '' ? $err : "Can't contact LDAP server"];
        }

        $attrs = ['cn', 'displayName', 'mail', 'givenName', 'sn'];
        $search = @ldap_search($ds, $base, $filter, $attrs, 0, $limit, $timeout);
        if ($search === false) {
            $err = ldap_error($ds);
            ldap_unbind($ds);

            return ['ok' => false, 'error' => $err !== '' ? $err : 'Búsqueda LDAP falló'];
        }

        $entries = ldap_get_entries($ds, $search);
        ldap_unbind($ds);
        if (! is_array($entries)) {
            return ['ok' => false, 'error' => 'Sin resultados LDAP'];
        }

        $ldif = '';
        $count = (int) ($entries['count'] ?? 0);
        for ($i = 0; $i < $count; $i++) {
            $row = $entries[$i];
            $ldif .= 'dn: '.($row['dn'] ?? 'cn=entry')."\n";
            foreach (['displayname', 'cn', 'givenname', 'mail'] as $key) {
                if (! empty($row[$key][0])) {
                    $ldif .= $key.': '.$row[$key][0]."\n";
                }
            }
            $ldif .= "\n";
        }

        return ['ok' => true, 'ldif' => $ldif];
    }

    /**
     * @return array{ok: bool, ldif?: string, error?: string}
     */
    private function searchCli(string $uri, string $base, string $filter, int $limit, int $timeout): array
    {
        $bin = $this->ldapsearchBin();
        if (! $bin) {
            return ['ok' => false, 'error' => 'PHP no tiene LDAP y no hay ldapsearch'];
        }

        $process = new Process([
            $bin,
            '-x',
            '-LLL',
            '-o', 'ldif-wrap=no',
            '-H', $uri,
            '-b', $base,
            '-l', (string) $timeout,
            '-z', (string) $limit,
            $filter,
            'cn',
            'displayName',
            'mail',
            'givenName',
            'sn',
        ]);
        $process->setTimeout($timeout + 3);
        $process->run();

        $stderr = trim($process->getErrorOutput());
        $stdout = $process->getOutput();
        $code = $process->getExitCode();

        // 0 = ok, 4 = size limit exceeded (igual hay contactos)
        if (! in_array($code, [0, 4], true) || trim($stdout) === '') {
            return ['ok' => false, 'error' => $stderr !== '' ? $stderr : "Can't contact LDAP server"];
        }

        return ['ok' => true, 'ldif' => $stdout];
    }

    private function ldapsearchBin(): ?string
    {
        foreach (['/usr/bin/ldapsearch', '/usr/local/bin/ldapsearch'] as $bin) {
            if (is_executable($bin)) {
                return $bin;
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function ldifValue(string $line): ?array
    {
        if (preg_match('/^([A-Za-z0-9;-]+)::\s*(.+)$/', $line, $m)) {
            $raw = base64_decode(trim($m[2]), true);
            if ($raw === false) {
                return null;
            }

            return [$m[1], trim($raw)];
        }
        if (preg_match('/^([A-Za-z0-9;-]+):\s*(.*)$/', $line, $m)) {
            return [$m[1], trim($m[2])];
        }

        return null;
    }

    private function mensajeError(string $raw): string
    {
        $low = strtolower($raw);
        if (str_contains($low, "can't contact") || str_contains($low, 'connection') || str_contains($low, 'timed out') || str_contains($low, 'timeout')) {
            return 'No se alcanzó pzosdgstdeb7. Apague la VPN (red CVG) y recargue Reportes.';
        }
        if (str_contains($low, 'name or service') || str_contains($low, 'not known') || str_contains($low, 'nxdomain')) {
            return 'El DNS no resuelve pzosdgstdeb7.pzo.cvg.com. Apague la VPN o ponga la IP en BITACORA_LDAP_HOST_IP.';
        }

        return 'LDAP no disponible';
    }
}
