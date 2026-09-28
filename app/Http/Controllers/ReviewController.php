<?php

namespace App\Http\Controllers;

use App\Services\ChecklistPdfService;
use App\Services\ReviewDayService;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function show(string $date, ReviewDayService $days)
    {
        return response()->json($days->ensure($date));
    }

    public function pdf(string $date, ChecklistPdfService $pdf, ReviewDayService $days, Request $request)
    {
        $review = $days->ensure($date);

        return $pdf->download($review, $request->user()?->name);
    }
}
