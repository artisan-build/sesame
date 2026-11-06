<?php

namespace App\Sesame\Http;

use App\Models\SesameRun;
use App\Sesame\RunReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    private function authorize(Request $request, string $run): SesameRun
    {
        $row = SesameRun::with('account')->find($run);
        $token = (string) $request->bearerToken();
        abort_if($row === null || $token === '' || ! hash_equals($row->account->truth_token_hash, hash('sha256', $token)), 404);

        return $row;
    }
}
