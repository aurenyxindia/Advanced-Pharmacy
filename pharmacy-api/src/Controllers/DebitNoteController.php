<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Model;
use App\Core\Request;
use App\Core\Response;
use App\Models\DebitNote;
use App\Services\ReturnNotePdfService;
use App\Services\ReturnNoteService;

final class DebitNoteController extends ResourceController
{
    protected bool $tenantScoped = true;
    protected bool $branchScoped = true;
    protected array $fillable = ['note_no', 'party_name', 'against_invoice', 'amount', 'gst_amount', 'reason', 'note_date'];
    protected array $searchColumns = ['note_no', 'party_name', 'against_invoice'];
    protected string $defaultOrder = 'note_date DESC, id DESC';

    protected function model(): Model
    {
        return new DebitNote();
    }

    protected function storeRules(): array
    {
        return [
            'party_name'      => 'required|string|max:150',
            'against_invoice' => 'string|max:60',
            'amount'          => 'required|numeric|min:0',
            'gst_amount'      => 'numeric|min:0',
            'reason'          => 'string|max:255',
            'note_date'       => 'required|string|max:20',
            'note_no'         => 'string|max:40',
        ];
    }

    public function index(Request $request): Response
    {
        $svc = new ReturnNoteService();
        $svc->ensureSchema();
        $branchId = (int) ($request->branchId() ?: 0);
        if ($branchId <= 0) {
            return $this->error('Branch required.', 422);
        }
        $search = trim((string) ($request->query('search') ?? ''));
        $rows = $svc->listNotes((int) $request->clientId(), $branchId, $search !== '' ? $search : null);
        return $this->success([
            'data' => $rows,
            'total' => count($rows),
            'page' => 1,
            'per_page' => count($rows),
        ], 'Debit notes.');
    }

    public function show(Request $request): Response
    {
        $svc = new ReturnNoteService();
        $branchId = (int) ($request->branchId() ?: 0);
        $id = (int) $request->attribute('id');
        $note = $svc->getNote((int) $request->clientId(), $branchId, $id);
        if (!$note) {
            throw HttpException::notFound('Debit note not found.');
        }
        return $this->success($note, 'Debit note.');
    }

    /** Retail-parity Debit Note PDF (TCPDF). */
    public function pdf(Request $request): Response
    {
        $svc = new ReturnNoteService();
        $branchId = (int) ($request->branchId() ?: 0);
        $id = (int) $request->attribute('id');
        $clientId = (int) $request->clientId();
        $note = $svc->getNote($clientId, $branchId, $id);
        if (!$note) {
            throw HttpException::notFound('Debit note not found.');
        }
        $pdf = (new ReturnNotePdfService())->debitNote($note, $clientId, $branchId);
        $file = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($note['note_no'] ?? 'debit-note'));
        return Response::raw($pdf, 'application/pdf', 200, [
            'Content-Disposition' => 'inline; filename="debit-note-' . $file . '.pdf"',
        ]);
    }

    public function store(Request $request): Response
    {
        // Legacy thin create still supported.
        $data = $this->validate($request, $this->storeRules());
        $payload = $this->fillData($data, $request);
        $payload['note_no'] = trim((string) ($payload['note_no'] ?? ''))
            ?: $this->nextNoteNo((int) $request->clientId(), (int) $request->branchId());
        $payload['created_at'] = now();
        $payload['updated_at'] = now();
        (new ReturnNoteService())->ensureSchema();
        $id = $this->model()->insert($payload);
        return $this->created(['id' => $id, 'note_no' => $payload['note_no']], 'Debit note saved.');
    }

    /** Stock products available for return. */
    public function returnProducts(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $rows = (new ReturnNoteService())->productsWithStock((int) $request->clientId(), $branchId);
        return $this->success($rows, 'Return products.');
    }

    public function returnBatches(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $name = trim((string) ($request->query('product_name') ?? $request->query('p_name') ?? ''));
        $rows = (new ReturnNoteService())->batchesForProduct((int) $request->clientId(), $branchId, $name);
        return $this->success($rows, 'Return batches.');
    }

    public function returnSuppliers(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $name = trim((string) ($request->query('product_name') ?? $request->query('p_name') ?? ''));
        $batch = trim((string) ($request->query('batch_no') ?? ''));
        $rows = (new ReturnNoteService())->suppliersForBatch((int) $request->clientId(), $branchId, $name, $batch);
        return $this->success($rows, 'Return suppliers.');
    }

    /** Open debit notes for a distributor (purchase deduction). */
    public function openNotes(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $party = trim((string) ($request->query('party') ?? $request->query('distributor_name') ?? ''));
        $rows = (new ReturnNoteService())->openNotesForParty((int) $request->clientId(), $branchId, $party);
        return $this->success($rows, 'Open debit notes.');
    }

    public function saveReturn(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $body = $request->all();
        $result = (new ReturnNoteService())->saveReturn(
            (int) $request->clientId(),
            $branchId,
            isset($request->user()['id']) ? (int) $request->user()['id'] : null,
            $body
        );
        return $this->created($result, 'Return note saved.');
    }

    public function updateReturn(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $id = (int) $request->attribute('id');
        $body = $request->all();
        $result = (new ReturnNoteService())->updateReturn(
            (int) $request->clientId(),
            $branchId,
            $id,
            $body
        );
        return $this->success($result, 'Return note updated.');
    }

    private function nextNoteNo(int $clientId, int $branchId): string
    {
        $yy = date('y');
        $prefix = "DN/{$clientId}/{$yy}/";
        $stmt = \App\Database\Connection::ops()->prepare(
            "SELECT note_no FROM debit_notes
             WHERE client_id = :c AND branch_id = :b AND note_no LIKE :p
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['c' => $clientId, 'b' => $branchId, 'p' => $prefix . '%']);
        $last = (string) ($stmt->fetchColumn() ?: '');
        $seq = 1;
        if ($last !== '' && preg_match('#/(\d+)$#', $last, $m)) {
            $seq = (int) $m[1] + 1;
        }
        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
