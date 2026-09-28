<?php

namespace App\Services;

use App\Models\Check;
use App\Models\Review;
use App\Models\Server;

class ReviewDayService
{
    public function ensure(string $date): Review
    {
        $review = Review::firstOrCreate(
            ['date' => $date],
            [
                'responsible' => '',
                'notes' => null,
                'closed' => false,
            ]
        );

        $servers = Server::query()
            ->where('active', true)
            ->with('mounts')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($servers as $server) {
            $mountsDetails = [];
            foreach ($server->mounts as $mount) {
                $mountsDetails[$mount->path] = false;
            }

            $check = Check::firstOrCreate(
                [
                    'review_id' => $review->id,
                    'server_id' => $server->id,
                ],
                [
                    'powered_on' => 'pending',
                    'mounts_status' => $server->mounts->isEmpty() ? 'na' : 'pending',
                    'mounts_details' => $mountsDetails,
                    'backup' => $server->does_backup ? 'pending' : 'na',
                ]
            );

            $force = [];
            $prev = is_array($check->mounts_details) ? $check->mounts_details : [];
            $nextDetails = [];
            foreach ($server->mounts as $mount) {
                $nextDetails[$mount->path] = (bool) ($prev[$mount->path] ?? false);
            }
            if ($nextDetails !== $prev) {
                $force['mounts_details'] = $nextDetails;
            }
            if (! $server->does_backup && $check->backup !== 'na') {
                $force['backup'] = 'na';
            }
            if ($server->mounts->isEmpty() && $check->mounts_status !== 'na') {
                $force['mounts_status'] = 'na';
            } elseif (! $server->mounts->isEmpty() && $check->mounts_status === 'na') {
                $force['mounts_status'] = 'pending';
            }
            if ($force !== []) {
                $check->update($force);
            }
        }

        $review->load([
            'checks.server.mounts',
        ]);

        // setRelation: si se asigna $review->checks = … Laravel lo trata como
        // columna y el UPDATE de reviews explota (no existe reviews.checks).
        $review->setRelation(
            'checks',
            $review->checks
                ->filter(fn ($check) => $check->server && $check->server->active)
                ->sortBy(fn ($check) => $check->server->sort_order ?? $check->id)
                ->values()
        );

        return $review;
    }
}
