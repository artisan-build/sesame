<?php

namespace App\Sesame\Http;

use App\Models\SesameRun;
use App\Sesame\Ingest\IngestBuffer;
use App\Sesame\RunReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ground truth for reconciliation, authenticated with the account's truth
 * token (Authorization: Bearer ...), printed once when the account is created.
 */
final class RunController
{
    public function truth(Request $request, string $run): JsonResponse
    {
        $row = $this->authorize($request, $run);
        $limit = max(1, min(5000, (int) $request->query('limit', '1000')));

        return new JsonResponse(['run_id' => $row->id, 'seed' => $row->seed] + RunReport::truth($row, $request->query('after'), $limit));
    }

    public function summary(Request $request, string $run): JsonResponse
    {
        return new JsonResponse(RunReport::summary($this->authorize($request, $run)));
    }

    /**
     * Classify MessageIds the client holds that the truth API does not:
     * lost in an ingest buffer (a simulator loss to exclude, not a client
     * finding), still undrained (ask again), or unknown (a client finding).
     */
    public function missing(Request $request, string $run): JsonResponse
    {
        $this->authorize($request, $run);
        $ids = array_slice(array_values(array_filter((array) $request->json('message_ids', []), is_string(...))), 0, 5000);
        $known = DB::table('sesame_messages')->whereIn('message_id', $ids)->pluck('message_id')->flip();
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = isset($known[$id]) ? ['status' => 'present'] : IngestBuffer::lossFor($id);
        }

        return new JsonResponse(['message_ids' => $out]);
    }

    private function authorize(Request $request, string $run): SesameRun
    {
        $row = SesameRun::with('account')->find($run);
        $token = (string) $request->bearerToken();
        abort_if($row === null || $token === '' || ! hash_equals($row->account->truth_token_hash, hash('sha256', $token)), 404);

        return $row;
    }
}
