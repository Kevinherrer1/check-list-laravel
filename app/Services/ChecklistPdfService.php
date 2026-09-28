<?php

namespace App\Services;

use App\Models\Check;
use App\Models\Review;
use TCPDF;

class ChecklistPdfService
{
    private const APP_NAME = 'Bitácora DBA';

    public function download(Review $review, ?string $fallbackResponsible = null)
    {
        $binary = $this->build($review, $fallbackResponsible);
        $ymd = $this->dateYmd($review);
        $name = 'Bitacora_Checklist_'.date('dmY', strtotime($ymd) ?: time()).'.pdf';

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Content-Length' => (string) strlen($binary),
        ]);
    }

    public function build(Review $review, ?string $fallbackResponsible = null): string
    {
        $review->loadMissing(['checks.server.mounts']);
        $checks = $review->checks
            ->sortBy(fn (Check $check) => $check->server?->sort_order ?? $check->id)
            ->values();

        $responsable = trim((string) ($review->responsible ?: $fallbackResponsible ?: ''));
        if ($responsable === '') {
            $responsable = '—';
        }

        $ymd = $this->dateYmd($review);
        $resumen = $this->resumen($checks);

        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator(self::APP_NAME);
        $pdf->SetAuthor($responsable === '—' ? self::APP_NAME : $responsable);
        $pdf->SetTitle('Checklist de respaldos — '.$this->fechaEs($ymd));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->SetMargins(8, 10, 8);
        $pdf->AddPage();

        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->Cell(0, 6, self::APP_NAME, 0, 1, 'L');
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 5, 'Checklist de respaldos', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 5, $this->fechaEs($ymd).'  ·  responsable '.$responsable, 0, 1, 'L');
        $pdf->Ln(1);

        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 5, sprintf(
            'OK %d   |   Fallas %d   |   Pend./parcial %d   |   Total %d',
            $resumen['ok'],
            $resumen['falla'],
            $resumen['pend'] + $resumen['parcial'],
            $resumen['total']
        ), 0, 1, 'L');
        $pdf->Ln(2);

        $w = [40, 26, 11, 13, 13, 24, 36, 14, 104];
        $headers = ['Servidor', 'IP', 'On', 'Mont.', 'Resp.', 'Hora', 'Tam.', 'Est.', 'Resumen'];

        $pdf->SetFont('helvetica', 'B', 7.5);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->SetTextColor(30, 30, 30);
        foreach ($headers as $i => $h) {
            $pdf->Cell($w[$i], 6.5, $h, 1, 0, 'C', true);
        }
        $pdf->Ln();

        $pdf->SetFont('helvetica', '', 7);
        foreach ($checks as $check) {
            $g = $this->estado($check);
            if ($g === 'falla') {
                $pdf->SetFillColor(255, 235, 235);
            } elseif ($g === 'ok') {
                $pdf->SetFillColor(235, 250, 240);
            } elseif ($g === 'parcial' || $g === 'pendiente') {
                $pdf->SetFillColor(255, 250, 230);
            } else {
                $pdf->SetFillColor(255, 255, 255);
            }

            $raw = [
                (string) ($check->server?->name ?? ''),
                (string) ($check->server?->ip ?: $check->server?->hostname ?: ''),
                $this->corto((string) $check->powered_on),
                $this->corto((string) $check->mounts_status),
                $this->corto((string) $check->backup),
                (string) $check->generated_at,
                (string) $check->size,
                $this->etiqueta($g),
                $this->notaPrint($check, 120),
            ];
            $align = ['L', 'L', 'C', 'C', 'C', 'L', 'L', 'C', 'L'];
            foreach ($raw as $i => $txt) {
                $pdf->Cell($w[$i], 6, $this->fit($pdf, $txt, $w[$i]), 1, 0, $align[$i], true, '', 0, false, 'T', 'M');
            }
            $pdf->Ln();
        }

        if ($checks->isEmpty()) {
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Cell(array_sum($w), 8, 'Sin chequeos para esta fecha.', 1, 1, 'L', true);
        }

        $notas = trim((string) ($review->notes ?? ''));
        if ($notas !== '') {
            $pdf->Ln(3);
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, 'Notas del día', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 8);
            $pdf->MultiCell(0, 4, $notas, 0, 'L');
        }

        $pdf->Ln(2);
        $pdf->SetFont('helvetica', 'I', 7);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 4, 'Generado '.date('d/m/Y H:i').' · '.self::APP_NAME, 0, 1, 'L');

        return (string) $pdf->Output('', 'S');
    }

    private function dateYmd(Review $review): string
    {
        $date = $review->date;
        if (is_object($date) && method_exists($date, 'format')) {
            return $date->format('Y-m-d');
        }

        return (string) $date;
    }

    private function fechaEs(string $ymd): string
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $ymd);
        if (! $dt) {
            return $ymd;
        }
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return $dias[(int) $dt->format('w')].', '.$dt->format('j').' de '.$meses[(int) $dt->format('n') - 1].' de '.$dt->format('Y');
    }

    private function estado(Check $check): string
    {
        if ($check->powered_on === 'apagado' || $check->mounts_status === 'falla' || $check->backup === 'fallido') {
            return 'falla';
        }
        $onOk = $check->powered_on === 'prendido';
        $mountsOk = in_array($check->mounts_status, ['ok', 'na'], true);
        $backupOk = in_array($check->backup, ['exitoso', 'na'], true);
        if ($onOk && $mountsOk && $backupOk) {
            return 'ok';
        }
        $pending = in_array($check->powered_on, ['pending', 'pendiente'], true)
            || in_array($check->mounts_status, ['pending', 'pendiente'], true)
            || in_array($check->backup, ['pending', 'pendiente'], true);
        if ($pending && ! $onOk) {
            return 'pendiente';
        }

        return 'parcial';
    }

    private function resumen($checks): array
    {
        $ok = 0;
        $falla = 0;
        $pend = 0;
        $parcial = 0;
        foreach ($checks as $check) {
            $g = $this->estado($check);
            if ($g === 'ok') {
                $ok++;
            } elseif ($g === 'falla') {
                $falla++;
            } elseif ($g === 'parcial') {
                $parcial++;
            } else {
                $pend++;
            }
        }

        return [
            'total' => $checks->count(),
            'ok' => $ok,
            'falla' => $falla,
            'pend' => $pend,
            'parcial' => $parcial,
        ];
    }

    private function etiqueta(string $g): string
    {
        return match ($g) {
            'ok' => 'OK',
            'falla' => 'Falla',
            'parcial' => 'Parcial',
            default => 'Pendiente',
        };
    }

    private function corto(string $v): string
    {
        return match ($v) {
            'prendido' => 'Sí',
            'apagado' => 'No',
            'pending', 'pendiente' => 'Pend.',
            'exitoso' => 'OK',
            'fallido' => 'Falla',
            'ok' => 'OK',
            'falla' => 'Falla',
            'na' => 'N/A',
            default => $v,
        };
    }

    private function notaPrint(Check $check, int $max = 90): string
    {
        $causa = trim((string) $check->root_cause);
        if ($causa !== '') {
            return $this->recortar($causa, $max);
        }
        $obs = trim((string) ($check->observations ?? ''));
        if ($obs !== '') {
            return $this->recortar($obs, $max);
        }
        $auto = trim((string) $check->review_result);
        if ($auto === '') {
            return '';
        }
        $auto = preg_replace('/\s+/', ' ', $auto) ?? $auto;
        if (preg_match('/^(.{1,80}→\s*\S+)/u', $auto, $m)) {
            return $this->recortar($m[1], $max);
        }

        return $this->recortar($auto, $max);
    }

    private function recortar(string $s, int $max): string
    {
        $s = trim($s);
        if ($s === '' || mb_strlen($s) <= $max) {
            return $s;
        }

        return rtrim(mb_substr($s, 0, $max - 1)).'…';
    }

    private function fit(TCPDF $pdf, string $txt, float $widthMm, float $pad = 1.5): string
    {
        $txt = trim(preg_replace('/\s+/u', ' ', $txt) ?? $txt);
        if ($txt === '') {
            return '';
        }
        $max = max(2.0, $widthMm - $pad);
        if ($pdf->GetStringWidth($txt) <= $max) {
            return $txt;
        }
        $ellipsis = '…';
        $len = mb_strlen($txt);
        while ($len > 0) {
            $len--;
            $candidate = mb_substr($txt, 0, $len).$ellipsis;
            if ($pdf->GetStringWidth($candidate) <= $max) {
                return $candidate;
            }
        }

        return $ellipsis;
    }
}
