<?php

namespace App\Http\Controllers;

use App\Models\OutboundDocument;
use App\Services\OutboundService;
use App\Support\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Optional Invoice Ninja webhook (invoice/credit created or updated). Records
 * the document and, when a transmission is requested, processes it right away
 * (the same switches apply as for the scheduler, which remains the fallback).
 */
class WebhookController extends Controller
{
    public function invoiceNinja(Request $request, OutboundService $outbound): JsonResponse
    {
        $secret = (string) config('einvoicing.webhook_secret');

        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Einvoicing-Secret'))) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $payload = $request->json()->all();
        $kind = $request->query('entity') === 'credit' ? 'credit' : 'invoice';

        if (! isset($payload['id'], $payload['number'])) {
            return response()->json(['ignored' => true], 202);
        }

        try {
            $document = $outbound->upsert($kind, $payload);
        } catch (Throwable $e) {
            Journal::exception('webhook', 'invoiceninja_failed', $e);

            return response()->json(['error' => 'failed'], 500);
        }

        // A transmission was just requested in Invoice Ninja: handle it once the
        // response is sent (Factur-X rendering takes up to a minute).
        if ($document && $document->requested && $document->state === OutboundDocument::PENDING) {
            app()->terminating(function () use ($document, $outbound) {
                set_time_limit(600);
                ignore_user_abort(true);

                try {
                    $outbound->processOne($document);
                } catch (Throwable $e) {
                    Journal::exception('webhook', 'process_failed', $e, $document);
                }
            });
        }

        return response()->json(['recorded' => (bool) $document], 202);
    }
}
