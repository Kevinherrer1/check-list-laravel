<?php

namespace App\Services;

use App\Models\Check;
use App\Models\Review;
use Illuminate\Support\Carbon;

class ReportService
{
    public function range(?string $from, ?string $to): array
    {
        $today = now()->toDateString();
        $from = $this->validDate($from) ? $from : now()->startOfMonth()->toDateString();
        $to = $this->validDate($to) ? $to : $today;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    public function summary(string $from, string $to): array
    {
        $rows = $this->rows($from, $to);
        $days = $this->byDay($rows);
        $totals = $this->totals($days);

        return [
            'from' => $from,
            'to' => $to,
            'from_label' => $this->fechaEs($from),
            'to_label' => $this->fechaEs($to),
            'totals' => $totals,
            'days' => $days,
            'servers' => $this->byServer($rows),
        ];
    }

    public function rows(string $from, string $to): array
    {
        $reviews = Review::query()
            ->whereBetween('date', [$from, $to])
            ->with(['checks.server'])
            ->orderBy('date')
            ->get();

        $out = [];
        foreach ($reviews as $review) {
            $fecha = $this->ymd($review->date);
            $responsable = trim((string) ($review->responsible ?? ''));
            foreach ($review->checks as $check) {
                $server = $check->server;
                if (! $server) {
                    continue;
                }
                $out[] = [
                    'fecha' => $fecha,
                    'fecha_label' => $this->fechaEs($fecha),
                    'responsable' => $responsable,
                    'server_id' => $server->id,
                    'nombre' => (string) $server->name,
                    'ip' => (string) ($server->ip ?: $server->hostname),
                    'sistema' => (string) ($server->system ?? ''),
                    'orden' => (int) ($server->sort_order ?? 0),
                    'powered_on' => $this->norm((string) $check->powered_on),
                    'mounts_status' => $this->norm((string) $check->mounts_status),
                    'backup' => $this->norm((string) $check->backup),
                    'generated_at' => (string) $check->generated_at,
                    'backup_date' => $check->backup_date
                        ? $this->ymd($check->backup_date)
                        : '',
                    'size' => (string) $check->size,
                    'root_cause' => (string) $check->root_cause,
                    'review_result' => (string) $check->review_result,
                    'observations' => (string) ($check->observations ?? ''),
                    'estado_general' => $this->estadoGeneral($check),
                    'estado_label' => $this->etiqueta($this->estadoGeneral($check)),
                ];
            }
        }

        usort($out, fn ($a, $b) => [$a['fecha'], $a['orden'], $a['server_id']]
            <=> [$b['fecha'], $b['orden'], $b['server_id']]);

        return $out;
    }

    public function fechaEs(string $ymd): string
    {
        $dt = Carbon::createFromFormat('Y-m-d', $ymd);
        if (! $dt) {
            return $ymd;
        }
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return $dias[(int) $dt->format('w')].', '.$dt->format('j').' de '.$meses[(int) $dt->format('n') - 1].' de '.$dt->format('Y');
    }

    public function etiqueta(string $g): string
    {
        return match ($g) {
            'ok' => 'OK',
            'falla' => 'Falla',
            'parcial' => 'Parcial',
            default => 'Pendiente',
        };
    }

    private function byDay(array $rows): array
    {
        $porFecha = [];
        foreach ($rows as $r) {
            $f = $r['fecha'];
            if (! isset($porFecha[$f])) {
                $porFecha[$f] = [
                    'fecha' => $f,
                    'fecha_label' => $r['fecha_label'],
                    'ok' => 0,
                    'falla' => 0,
                    'pendiente' => 0,
                    'parcial' => 0,
                    'total' => 0,
                ];
            }
            $porFecha[$f]['total']++;
            $g = $r['estado_general'];
            if ($g === 'ok') {
                $porFecha[$f]['ok']++;
            } elseif ($g === 'falla') {
                $porFecha[$f]['falla']++;
            } elseif ($g === 'parcial') {
                $porFecha[$f]['parcial']++;
            } else {
                $porFecha[$f]['pendiente']++;
            }
        }
        $dias = array_values($porFecha);
        usort($dias, fn ($a, $b) => strcmp($b['fecha'], $a['fecha']));

        return $dias;
    }

    private function totals(array $dias): array
    {
        $t = ['dias' => count($dias), 'ok' => 0, 'falla' => 0, 'pendiente' => 0, 'parcial' => 0, 'total' => 0, 'pct_ok' => 0];
        foreach ($dias as $d) {
            $t['ok'] += (int) $d['ok'];
            $t['falla'] += (int) $d['falla'];
            $t['pendiente'] += (int) $d['pendiente'];
            $t['parcial'] += (int) $d['parcial'];
            $t['total'] += (int) $d['total'];
        }
        $t['pct_ok'] = $t['total'] > 0 ? (int) round(100 * $t['ok'] / $t['total']) : 0;

        return $t;
    }

    private function byServer(array $rows): array
    {
        $por = [];
        foreach ($rows as $r) {
            $sid = (int) $r['server_id'];
            if (! isset($por[$sid])) {
                $por[$sid] = [
                    'server_id' => $sid,
                    'nombre' => $r['nombre'],
                    'ip' => $r['ip'],
                    'sistema' => $r['sistema'],
                    'orden' => $r['orden'],
                    'dias' => 0,
                    'ok' => 0,
                    'falla' => 0,
                    'pendiente' => 0,
                    'parcial' => 0,
                    'fallas_respaldo' => 0,
                    'fallas_montaje' => 0,
                    'apagados' => 0,
                ];
            }
            $por[$sid]['dias']++;
            $g = $r['estado_general'];
            if ($g === 'ok') {
                $por[$sid]['ok']++;
            } elseif ($g === 'falla') {
                $por[$sid]['falla']++;
            } elseif ($g === 'parcial') {
                $por[$sid]['parcial']++;
            } else {
                $por[$sid]['pendiente']++;
            }
            if ($r['backup'] === 'fallido') {
                $por[$sid]['fallas_respaldo']++;
            }
            if ($r['mounts_status'] === 'falla') {
                $por[$sid]['fallas_montaje']++;
            }
            if ($r['powered_on'] === 'apagado') {
                $por[$sid]['apagados']++;
            }
        }
        $list = array_values($por);
        usort($list, fn ($a, $b) => [$b['falla'], $a['orden'], $a['nombre']]
            <=> [$a['falla'], $b['orden'], $b['nombre']]);

        return $list;
    }

    private function estadoGeneral(Check $check): string
    {
        $vals = [
            $this->norm((string) $check->powered_on),
            $this->norm((string) $check->mounts_status),
            $this->norm((string) $check->backup),
        ];
        if (in_array('apagado', $vals, true) || in_array('falla', $vals, true) || in_array('fallido', $vals, true)) {
            return 'falla';
        }
        $pend = 0;
        $ok = 0;
        foreach ($vals as $v) {
            if ($v === 'pendiente') {
                $pend++;
            } elseif (in_array($v, ['prendido', 'ok', 'exitoso', 'na'], true)) {
                $ok++;
            }
        }
        if ($pend === 0) {
            return 'ok';
        }
        if ($ok === 0) {
            return 'pendiente';
        }

        return 'parcial';
    }

    private function norm(string $v): string
    {
        return $v === 'pending' ? 'pendiente' : $v;
    }

    private function validDate(?string $ymd): bool
    {
        return is_string($ymd) && (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd);
    }

    private function ymd(mixed $date): string
    {
        if (is_object($date) && method_exists($date, 'format')) {
            return $date->format('Y-m-d');
        }

        return (string) $date;
    }
}
