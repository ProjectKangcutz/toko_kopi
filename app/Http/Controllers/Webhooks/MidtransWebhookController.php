<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Payment\MidtransWebhookIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MidtransWebhookController extends Controller
{
    public function handle(Request $request, MidtransWebhookIngestionService $ingestionService): JsonResponse
    {
        $result = $ingestionService->ingest($request);

        return response()->json($result['body'], $result['status_code']);
    }
}
