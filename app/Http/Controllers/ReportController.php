<?php

namespace App\Http\Controllers;

use App\Services\ChecklistMailService;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request, ReportService $reports)
    {
        [$from, $to] = $reports->range(
            $request->query('from', $request->query('desde')),
            $request->query('to', $request->query('hasta')),
        );

        return response()->json($reports->summary($from, $to));
    }

    public function mailOptions(Request $request, ChecklistMailService $mail)
    {
        return response()->json($mail->options($request->query('fecha')));
    }

    public function sendPdf(Request $request, ChecklistMailService $mail)
    {
        $data = $request->validate([
            'fecha' => ['required', 'date_format:Y-m-d'],
            'remitente_id' => ['required', 'string', 'max:64'],
            'smtp_password' => ['required', 'string'],
            'to' => ['required', 'string', 'max:255'],
            'cc' => ['nullable', 'string', 'max:255'],
            'asunto' => ['nullable', 'string', 'max:255'],
            'mensaje' => ['nullable', 'string', 'max:5000'],
        ]);

        $result = $mail->send([
            'fecha' => $data['fecha'],
            'remitente_id' => $data['remitente_id'],
            'password' => $data['smtp_password'],
            'to' => $data['to'],
            'cc' => $data['cc'] ?? '',
            'asunto' => $data['asunto'] ?? '',
            'mensaje' => $data['mensaje'] ?? '',
        ]);

        if (empty($result['ok'])) {
            return response()->json([
                'ok' => false,
                'error' => $result['error'] ?? 'No se pudo enviar',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'to' => $result['to'] ?? '',
            'message' => 'Enviado a '.($result['to'] ?? ''),
        ]);
    }

    public function csv(Request $request, ReportService $reports): StreamedResponse
    {
        [$from, $to] = $reports->range(
            $request->query('from', $request->query('desde')),
            $request->query('to', $request->query('hasta')),
        );
        $rows = $reports->rows($from, $to);
        $name = 'Bitacora_Rango_'.date('dmY', strtotime($from)).'_'.date('dmY', strtotime($to)).'.csv';

        return response()->streamDownload(function () use ($rows, $reports) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Fecha', 'Servidor', 'IP / Host', 'Estado general', 'Encendido', 'Montajes NetApp',
                'Hora Gen. Archivo', 'Fecha Respaldo', 'Estado Respaldo', 'Tamaño Archivo',
                '¿Falló? Causa Raíz', 'Resultado revisión', 'Observaciones', 'Responsable DBA',
            ]);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['fecha'],
                    $r['nombre'],
                    $r['ip'],
                    $reports->etiqueta($r['estado_general']),
                    $r['powered_on'],
                    $r['mounts_status'],
                    $r['generated_at'],
                    $r['backup_date'],
                    $r['backup'],
                    $r['size'],
                    $r['root_cause'],
                    $r['review_result'],
                    $r['observations'],
                    $r['responsable'],
                ]);
            }
            fclose($out);
        }, $name, [
            'Content-Type' => 'text/csv; charset=utf-8',
        ]);
    }
}
