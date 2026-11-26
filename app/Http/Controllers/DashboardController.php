<?php

namespace App\Http\Controllers;

use App\Models\ExchangeLog;
use App\Models\InboundDocument;
use App\Models\OutboundDocument;
use App\Services\InboundService;
use App\Services\OutboundService;
use App\Services\SuperPdp\SuperPdpClient;
use App\Services\SyncService;
use App\Support\Archive;
use App\Support\Journal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class DashboardController extends Controller
{
    public function index(SuperPdpClient $superPdp): View
    {
        $year = now()->startOfYear();
        $volume = OutboundDocument::query()->whereNotNull('superpdp_id')->where('submitted_at', '>=', $year)->count()
            + InboundDocument::query()->where('received_at', '>=', $year)->count();

        return view('dashboard', [
            'environment' => $superPdp->environment(),
            'configured' => $superPdp->configured(),
            'lastSync' => Cache::get('einvoicing.last_sync'),
            'outboundCounts' => OutboundDocument::query()->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state'),
            'inboundCounts' => InboundDocument::query()->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state'),
            'outbound' => OutboundDocument::query()->latest('issue_date')->latest('id')->limit(30)->get(),
            'inbound' => InboundDocument::query()->latest('received_at')->limit(30)->get(),
            'alerts' => ExchangeLog::query()->whereIn('level', ['warning', 'error'])->latest('id')->limit(10)->get(),
            'volume' => $volume,
            'cost' => max((float) config('einvoicing.superpdp.annual_minimum'), $volume * (float) config('einvoicing.superpdp.price_per_invoice')),
        ]);
    }

    public function outbound(OutboundDocument $document): View
    {
        return view('outbound', [
            'document' => $document,
            'events' => $document->events()->orderBy('event_created_at')->get(),
            'logs' => ExchangeLog::query()->where('document_type', 'outbound')->where('document_id', $document->id)->latest('id')->limit(50)->get(),
            'maySend' => OutboundService::emissionEnabled(),
        ]);
    }

    public function inbound(InboundDocument $document): View
    {
        return view('inbound', [
            'document' => $document,
            'events' => $document->events()->orderBy('event_created_at')->get(),
            'logs' => ExchangeLog::query()->where('document_type', 'inbound')->where('document_id', $document->id)->latest('id')->limit(50)->get(),
            'buyerStatuses' => InboundService::BUYER_STATUSES,
        ]);
    }

    public function journal(Request $request): View
    {
        $logs = ExchangeLog::query()
            ->when($request->query('level'), fn ($q, $level) => $q->where('level', $level))
            ->latest('id')
            ->paginate(100);

        return view('journal', ['logs' => $logs]);
    }

    public function sync(SyncService $sync): RedirectResponse
    {
        $report = $sync->run();

        return back()->with('status', $report === null ? 'Une synchronisation est déjà en cours.' : 'Synchronisation terminée : '.json_encode($report, JSON_UNESCAPED_UNICODE));
    }

    public function prepare(OutboundDocument $document, OutboundService $outbound): RedirectResponse
    {
        if ($document->superpdp_id) {
            return back()->with('error', 'Document déjà déposé.');
        }

        $document->forceFill(['state' => OutboundDocument::PENDING, 'next_attempt_at' => null])->save();
        $result = $outbound->prepare($document);

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Factur-X générée et validée.' : 'Préparation impossible : '.implode(' | ', $result['errors']));
    }

    public function send(Request $request, OutboundDocument $document, OutboundService $outbound): RedirectResponse
    {
        $request->validate(['confirm' => 'accepted']);

        try {
            $document->forceFill(['requested' => true])->save();
            $outbound->send($document);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $document->refresh();

        return back()->with($document->superpdp_id ? 'status' : 'error', $document->superpdp_id ? "Déposée sur SUPER PDP (#{$document->superpdp_id})." : 'Dépôt non effectué : '.implode(' | ', (array) $document->errors));
    }

    public function reportPayment(Request $request, OutboundDocument $document, OutboundService $outbound): RedirectResponse
    {
        $request->validate(['confirm' => 'accepted']);

        try {
            $done = $outbound->reportPayment($document, force: true);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with($done ? 'status' : 'error', $done ? 'Statut « Encaissée » transmis.' : 'Non transmis : facture non soldée dans Invoice Ninja, déjà déclarée, ou connecteur désactivé.');
    }

    public function import(InboundDocument $document, InboundService $inbound): RedirectResponse
    {
        $ok = $inbound->import($document, force: true);
        $document->refresh();

        return back()->with($ok ? 'status' : 'error', $ok ? 'Dépense créée dans Invoice Ninja.' : 'Import impossible : '.implode(' | ', (array) $document->errors));
    }

    public function ignore(Request $request, InboundDocument $document, InboundService $inbound): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);

        try {
            $inbound->ignore($document, $data['reason']);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Facture écartée (conservée dans l\'archive).');
    }

    public function buyerStatus(Request $request, InboundDocument $document, InboundService $inbound): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(InboundService::BUYER_STATUSES))],
            'reason' => 'nullable|string|max:40',
            'note' => 'nullable|string|max:1000',
            'confirm' => 'accepted',
        ]);

        try {
            $inbound->sendBuyerStatus($document, $data['status'], $data['reason'] ?? null, $data['note'] ?? null);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Statut transmis au fournisseur.');
    }

    public function file(string $direction, int $id, string $kind): Response
    {
        $document = $direction === 'outbound' ? OutboundDocument::query()->findOrFail($id) : InboundDocument::query()->findOrFail($id);
        $entry = $document->file($kind) ?? abort(404);

        if (! Archive::verify($entry)) {
            Journal::error('archive', 'integrity', "Empreinte invalide pour {$entry['path']}.", $document);
            abort(409, 'Fichier archivé altéré (empreinte SHA-256 différente).');
        }

        return response(Archive::get($entry), 200, [
            'Content-Type' => $entry['mime'],
            'Content-Disposition' => 'attachment; filename="'.addslashes($entry['name']).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
