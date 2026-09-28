<?php

namespace App\Services;

use App\Models\Review;

class ChecklistMailService
{
    public function __construct(
        private ChecklistPdfService $pdf,
        private ReviewDayService $days,
        private ReportService $reports,
        private CvgLdapDirectoryService $ldap,
    ) {}

    public function options(?string $fecha = null): array
    {
        $cfg = config('bitacora_correo');
        $fecha = $this->validDate($fecha) ? $fecha : now()->toDateString();
        $fechaLabel = $this->reports->fechaEs($fecha);
        $remitentes = $this->remitentes();
        $agenda = $this->agenda();

        return [
            'enabled' => (bool) ($cfg['enabled'] ?? false) && $remitentes !== [],
            'fecha' => $fecha,
            'fecha_label' => $fechaLabel,
            'saludo' => $this->saludo(),
            'remitentes' => array_map(fn ($r) => [
                'id' => $r['id'],
                'nombre' => $r['nombre'],
                'from' => $r['from'],
            ], $remitentes),
            'contactos' => $agenda['contactos'],
            'ldap' => [
                'ok' => $agenda['ldap_ok'],
                'count' => $agenda['ldap_count'],
                'error' => $agenda['ldap_error'],
            ],
            'to_default' => (string) ($cfg['to_default'] ?? ''),
            'cc_default' => (string) ($cfg['cc_default'] ?? ''),
            'asunto' => str_replace('{fecha}', $fechaLabel, (string) ($cfg['asunto_plantilla'] ?? 'Bitacora DBA - checklist {fecha}')),
            'mensaje' => str_replace(
                ['{saludo}', '{fecha}'],
                [$this->saludo(), $fechaLabel],
                (string) ($cfg['mensaje_default'] ?? "{saludo},\n\nSe adjunta el checklist diario de respaldos del día {fecha}.\n\nSaludos,\n{remitente}\n")
            ),
        ];
    }

    public function send(array $opts): array
    {
        if (! (bool) config('bitacora_correo.enabled')) {
            return ['ok' => false, 'error' => 'El envío de correo está deshabilitado.'];
        }

        $rem = $this->remitentePorId((string) ($opts['remitente_id'] ?? ''));
        if (! $rem) {
            return ['ok' => false, 'error' => 'Elija quién envía'];
        }

        $password = trim((string) ($opts['password'] ?? ''));
        if ($password === '') {
            return ['ok' => false, 'error' => 'Falta la clave del correo. Escríbala en el formulario al enviar (no se guarda).'];
        }

        $fecha = (string) ($opts['fecha'] ?? now()->toDateString());
        if (! $this->validDate($fecha)) {
            $fecha = now()->toDateString();
        }

        $toList = $this->lista((string) ($opts['to'] ?? ''));
        if ($toList === []) {
            return ['ok' => false, 'error' => 'Indique al menos un destinatario válido'];
        }
        $ccList = $this->lista((string) ($opts['cc'] ?? ''));

        $review = $this->days->ensure($fecha);
        if (trim((string) $rem['nombre']) !== '') {
            Review::query()->whereKey($review->id)->update([
                'responsible' => $rem['nombre'],
            ]);
            $review->responsible = $rem['nombre'];
        }

        $binary = $this->pdf->build($review, $rem['nombre']);
        $filename = 'Bitacora_Checklist_'.date('dmY', strtotime($fecha)).'.pdf';
        if ($binary === '' || strncmp($binary, '%PDF', 4) !== 0) {
            return ['ok' => false, 'error' => 'No se pudo generar el PDF'];
        }

        $fechaLabel = $this->reports->fechaEs($fecha);
        $asunto = trim((string) ($opts['asunto'] ?? ''));
        if ($asunto === '') {
            $asunto = str_replace('{fecha}', $fechaLabel, (string) config('bitacora_correo.asunto_plantilla'));
        }

        $mensaje = trim((string) ($opts['mensaje'] ?? ''));
        if ($mensaje === '') {
            $mensaje = (string) config('bitacora_correo.mensaje_default');
        }
        if (str_contains($mensaje, '{saludo}') || str_contains($mensaje, '{fecha}') || str_contains($mensaje, '{remitente}')) {
            $mensaje = str_replace(
                ['{saludo}', '{fecha}', '{remitente}'],
                [$this->saludo(), $fechaLabel, $rem['nombre']],
                $mensaje
            );
        }
        if (! str_contains($mensaje, $rem['nombre']) && ! str_contains($mensaje, $rem['from'])) {
            $mensaje .= "\n\nEnviado por: ".$rem['nombre'].' <'.$rem['from'].">\n";
        }

        $cfg = [
            'from' => $rem['from'],
            'from_name' => $rem['nombre'],
            'smtp_user' => $rem['smtp_user'],
            'smtp_password' => $password,
            'smtp_host' => (string) config('bitacora_correo.smtp_host'),
            'smtp_port' => (int) config('bitacora_correo.smtp_port'),
            'reply_to' => $rem['from'],
        ];

        $mime = $this->construirMime($cfg, $toList, $ccList, $asunto, $mensaje, $binary, $filename);
        $raw = $mime['headers']."\r\n\r\n".$mime['body'];
        $smtp = $this->smtpEnviar($cfg, $toList, $ccList, $raw);
        if (! $smtp['ok']) {
            return ['ok' => false, 'error' => $smtp['error'] ?? 'Error SMTP', 'to' => implode(', ', $toList)];
        }

        return ['ok' => true, 'to' => implode(', ', $toList)];
    }

    private function remitentes(): array
    {
        $out = [];
        foreach (config('bitacora_correo.remitentes', []) as $i => $r) {
            if (! is_array($r)) {
                continue;
            }
            $from = trim((string) ($r['from'] ?? ''));
            $user = trim((string) ($r['smtp_user'] ?? ''));
            if (! filter_var($from, FILTER_VALIDATE_EMAIL) || $user === '') {
                continue;
            }
            $id = trim((string) ($r['id'] ?? '')) ?: ('r'.$i);
            $out[] = [
                'id' => $id,
                'nombre' => trim((string) ($r['nombre'] ?? $from)) ?: $from,
                'from' => $from,
                'smtp_user' => $user,
            ];
        }

        return $out;
    }

    /**
     * Locales primero (uso diario DBA); el resto sale del LDAP de Thunderbird.
     *
     * @return array{contactos: list<array{nombre: string, email: string}>, ldap_ok: bool, ldap_count: int, ldap_error: ?string}
     */
    private function agenda(): array
    {
        $local = $this->contactosLocales();
        $ldap = $this->ldap->lookup();
        $seen = [];
        $out = [];

        foreach ($local as $row) {
            $key = strtolower($row['email']);
            $seen[$key] = true;
            $out[] = $row;
        }

        foreach ($ldap['contactos'] as $row) {
            $key = strtolower($row['email']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }

        return [
            'contactos' => $out,
            'ldap_ok' => (bool) $ldap['ok'],
            'ldap_count' => (int) $ldap['count'],
            'ldap_error' => $ldap['ok'] ? null : ($ldap['error'] ?? 'LDAP no disponible'),
        ];
    }

    /**
     * @return list<array{nombre: string, email: string}>
     */
    private function contactosLocales(): array
    {
        $out = [];
        $seen = [];
        foreach (config('bitacora_correo.contactos', []) as $row) {
            $email = is_array($row) ? trim((string) ($row['email'] ?? '')) : trim((string) $row);
            $nombre = is_array($row) ? trim((string) ($row['nombre'] ?? '')) : '';
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $key = strtolower($email);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'nombre' => $nombre !== '' ? $nombre : $email,
                'email' => $email,
            ];
        }

        return $out;
    }

    private function remitentePorId(string $id): ?array
    {
        foreach ($this->remitentes() as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }

        return null;
    }

    private function lista(string $raw): array
    {
        $parts = preg_split('/[,;\s]+/', trim($raw)) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) {
                $out[] = $p;
            }
        }

        return array_values(array_unique($out));
    }

    private function saludo(): string
    {
        $h = (int) now('America/Caracas')->format('G');
        if ($h >= 5 && $h < 12) {
            return 'Buenos días';
        }
        if ($h >= 12 && $h < 19) {
            return 'Buenas tardes';
        }

        return 'Buenas noches';
    }

    private function validDate(?string $ymd): bool
    {
        return is_string($ymd) && (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd);
    }

    private function encodeAddr(string $name, string $email): string
    {
        $name = trim($name);
        if ($name === '') {
            return $email;
        }

        return '=?UTF-8?B?'.base64_encode($name).'?= <'.$email.'>';
    }

    private function encodeSubject(string $asunto): string
    {
        return '=?UTF-8?B?'.base64_encode($asunto).'?=';
    }

    private function construirMime(array $cfg, array $toList, array $ccList, string $asunto, string $mensaje, string $pdf, string $filename): array
    {
        $from = (string) ($cfg['from'] ?? '');
        $fromName = (string) ($cfg['from_name'] ?? 'Bitácora DBA');
        $boundary = 'b_'.bin2hex(random_bytes(12));

        $headers = [];
        $headers[] = 'From: '.$this->encodeAddr($fromName, $from);
        $headers[] = 'To: '.implode(', ', $toList);
        if ($ccList !== []) {
            $headers[] = 'Cc: '.implode(', ', $ccList);
        }
        $headers[] = 'Reply-To: '.$from;
        $headers[] = 'Subject: '.$this->encodeSubject($asunto);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/mixed; boundary="'.$boundary.'"';
        $headers[] = 'Date: '.date('r');
        $headers[] = 'Message-ID: <'.bin2hex(random_bytes(8)).'@bitacora-dba.local>';

        $body = '';
        $body .= '--'.$boundary."\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= str_replace(["\r\n", "\r", "\n"], "\r\n", $mensaje)."\r\n\r\n";
        $body .= '--'.$boundary."\r\n";
        $body .= 'Content-Type: application/pdf; name="'.$filename."\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= 'Content-Disposition: attachment; filename="'.$filename."\"\r\n\r\n";
        $body .= chunk_split(base64_encode($pdf))."\r\n";
        $body .= '--'.$boundary."--\r\n";

        return [
            'headers' => implode("\r\n", $headers),
            'body' => $body,
        ];
    }

    private function smtpEnviar(array $cfg, array $toList, array $ccList, string $rawMessage): array
    {
        $host = (string) ($cfg['smtp_host'] ?? '');
        $port = (int) ($cfg['smtp_port'] ?? 465);
        $user = (string) ($cfg['smtp_user'] ?? '');
        $pass = (string) ($cfg['smtp_password'] ?? '');
        $from = (string) ($cfg['from'] ?? '');

        $remote = 'ssl://'.$host.':'.$port;
        $errno = 0;
        $errstr = '';
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            ],
        ]);
        $fp = @stream_socket_client($remote, $errno, $errstr, 25, STREAM_CLIENT_CONNECT, $ctx);
        if (! $fp) {
            return [
                'ok' => false,
                'error' => "No conecta SMTP {$host}:{$port} — {$errstr}. Si el certificado está vencido, revise red/VPN.",
            ];
        }
        stream_set_timeout($fp, 20);

        $read = static function () use ($fp): string {
            $data = '';
            while (! feof($fp)) {
                $line = fgets($fp, 515);
                if ($line === false) {
                    break;
                }
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }

            return $data;
        };
        $cmd = static function (string $line) use ($fp): void {
            fwrite($fp, $line."\r\n");
        };
        $expect = static function (string $resp, string $code) use ($fp): ?string {
            if (! str_starts_with(trim($resp), $code)) {
                fclose($fp);

                return 'SMTP inesperado: '.trim(preg_replace('/\s+/', ' ', $resp) ?? $resp);
            }

            return null;
        };

        $banner = $read();
        if ($err = $expect($banner, '220')) {
            return ['ok' => false, 'error' => $err];
        }

        $cmd('EHLO bitacora-dba.local');
        if ($err = $expect($read(), '250')) {
            return ['ok' => false, 'error' => $err];
        }

        $cmd('AUTH LOGIN');
        if ($err = $expect($read(), '334')) {
            return ['ok' => false, 'error' => $err];
        }
        $cmd(base64_encode($user));
        if ($err = $expect($read(), '334')) {
            return ['ok' => false, 'error' => $err];
        }
        $cmd(base64_encode($pass));
        $auth = $read();
        if (! str_starts_with(trim($auth), '235')) {
            fclose($fp);

            return ['ok' => false, 'error' => 'Usuario/clave del correo rechazados. Verifique la clave digitada.'];
        }

        $cmd('MAIL FROM:<'.$from.'>');
        if ($err = $expect($read(), '250')) {
            return ['ok' => false, 'error' => $err];
        }

        $rcpts = array_values(array_unique(array_merge($toList, $ccList)));
        foreach ($rcpts as $rcpt) {
            $cmd('RCPT TO:<'.$rcpt.'>');
            $r = $read();
            if (! str_starts_with(trim($r), '250') && ! str_starts_with(trim($r), '251')) {
                fclose($fp);

                return ['ok' => false, 'error' => 'Destinatario rechazado ('.$rcpt.'): '.trim($r)];
            }
        }

        $cmd('DATA');
        if ($err = $expect($read(), '354')) {
            return ['ok' => false, 'error' => $err];
        }

        $payload = preg_replace('/^\./m', '..', $rawMessage) ?? $rawMessage;
        fwrite($fp, $payload."\r\n.\r\n");
        $dataResp = $read();
        if (! str_starts_with(trim($dataResp), '250')) {
            fclose($fp);

            return ['ok' => false, 'error' => 'Servidor no aceptó el mensaje: '.trim($dataResp)];
        }

        $cmd('QUIT');
        fclose($fp);

        return ['ok' => true];
    }
}
