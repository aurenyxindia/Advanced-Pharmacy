<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Model;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Models\CreditNote;
use App\Services\ReturnNotePdfService;
use App\Services\WholesaleCreditNoteService;

final class CreditNoteController extends ResourceController
{
    protected bool $tenantScoped = true;
    protected bool $branchScoped = true;
    protected array $fillable = ['note_no', 'party_name', 'against_invoice', 'amount', 'gst_amount', 'reason', 'note_date'];
    protected array $searchColumns = ['note_no', 'party_name', 'against_invoice'];
    protected string $defaultOrder = 'note_date DESC, id DESC';

    protected function model(): Model
    {
        return new CreditNote();
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
        $svc = new WholesaleCreditNoteService();
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
        ], 'Credit notes.');
    }

    public function show(Request $request): Response
    {
        $svc = new WholesaleCreditNoteService();
        $branchId = (int) ($request->branchId() ?: 0);
        $id = (int) $request->attribute('id');
        $note = $svc->getNote((int) $request->clientId(), $branchId, $id);
        if (!$note) {
            throw HttpException::notFound('Credit note not found.');
        }
        return $this->success($note, 'Credit note.');
    }

    /** Retail-parity Wholesale Credit Note PDF (TCPDF). */
    public function pdf(Request $request): Response
    {
        $svc = new WholesaleCreditNoteService();
        $branchId = (int) ($request->branchId() ?: 0);
        $id = (int) $request->attribute('id');
        $clientId = (int) $request->clientId();
        $note = $svc->getNote($clientId, $branchId, $id);
        if (!$note) {
            throw HttpException::notFound('Credit note not found.');
        }
        $pdf = (new ReturnNotePdfService())->creditNote($note, $clientId, $branchId);
        $file = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($note['note_no'] ?? 'credit-note'));
        return Response::raw($pdf, 'application/pdf', 200, [
            'Content-Disposition' => 'inline; filename="credit-note-' . $file . '.pdf"',
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, $this->storeRules());
        $payload = $this->fillData($data, $request);
        $payload['note_no'] = trim((string) ($payload['note_no'] ?? ''))
            ?: $this->nextNoteNo((int) $request->clientId(), (int) $request->branchId());
        $payload['created_at'] = now();
        $payload['updated_at'] = now();
        (new WholesaleCreditNoteService())->ensureSchema();
        $id = $this->model()->insert($payload);
        return $this->created(['id' => $id, 'note_no' => $payload['note_no']], 'Credit note saved.');
    }

    public function customers(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $rows = (new WholesaleCreditNoteService())->customers((int) $request->clientId(), $branchId);
        return $this->success($rows, 'Customers.');
    }

    public function returnProducts(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $cust = trim((string) ($request->query('customer') ?? $request->query('custName') ?? ''));
        $rows = (new WholesaleCreditNoteService())->productsForCustomer((int) $request->clientId(), $branchId, $cust);
        return $this->success($rows, 'Products.');
    }

    public function returnBatches(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $cust = trim((string) ($request->query('customer') ?? $request->query('custName') ?? ''));
        $name = trim((string) ($request->query('product_name') ?? $request->query('p_name') ?? ''));
        $rows = (new WholesaleCreditNoteService())->batchesForCustomer((int) $request->clientId(), $branchId, $cust, $name);
        return $this->success($rows, 'Batches.');
    }

    public function openNotes(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $party = trim((string) ($request->query('party') ?? $request->query('customer') ?? ''));
        $rows = (new WholesaleCreditNoteService())->openNotesForParty((int) $request->clientId(), $branchId, $party);
        return $this->success($rows, 'Open credit notes.');
    }

    public function saveReturn(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $userId = isset($request->user()['id']) ? (int) $request->user()['id'] : null;
        $result = (new WholesaleCreditNoteService())->saveReturn(
            (int) $request->clientId(),
            $branchId,
            $userId,
            $request->all()
        );
        return $this->created($result, 'Wholesale credit note saved.');
    }

    public function updateReturn(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $id = (int) $request->attribute('id');
        $result = (new WholesaleCreditNoteService())->updateReturn(
            (int) $request->clientId(),
            $branchId,
            $id,
            $request->all()
        );
        return $this->success($result, 'Wholesale credit note updated.');
    }

    private function nextNoteNo(int $clientId, int $branchId): string
    {
        $yy = date('y');
        $prefix = "CN/{$clientId}/{$yy}/";
        $stmt = Connection::ops()->prepare(
            "SELECT note_no FROM credit_notes
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
