<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Models\IpdAdmission;

/**
 * In-Patient (IPD) admissions. An admission accrues many sale bills until
 * discharge; partial payments are recorded against it and the balance is
 * billed (= total billed - total paid).
 */
final class IpdController extends Controller
{
    public function index(Request $request): Response
    {
        $where = 'a.client_id = :c';
        $bind = ['c' => $request->clientId()];
        if (($request->user()['scope'] ?? 'branch') !== 'ho' && $request->branchId() !== null) {
            $where .= ' AND a.branch_id = :b';
            $bind['b'] = $request->branchId();
        }
        if ($status = $request->query('status')) {
            $where .= ' AND a.status = :status';
            $bind['status'] = $status;
        }

        $rows = $this->query(
            "SELECT a.*,
                    COALESCE((SELECT SUM(s.net_amount) FROM sales s WHERE s.ipd_admission_id = a.id), 0) AS billed,
                    COALESCE((SELECT SUM(p.amount) FROM ipd_payments p WHERE p.admission_id = a.id), 0) AS paid
             FROM ipd_admissions a
             WHERE {$where}
             ORDER BY a.status ASC, a.admitted_at DESC LIMIT 200",
            $bind
        );
        foreach ($rows as &$r) {
            $r['balance'] = round((float) $r['billed'] - (float) $r['paid'], 2);
        }
        unset($r);

        return $this->success($rows, 'Admissions.');
    }

    public function show(Request $request): Response
    {
        $admission = $this->findAdmission($request);

        $admission['bills'] = $this->query(
            'SELECT id, invoice_no, invoice_date, net_amount, gst_amount, profit_amount
             FROM sales WHERE ipd_admission_id = :id ORDER BY invoice_date ASC, id ASC',
            ['id' => $admission['id']]
        );
        $admission['payments'] = $this->query(
            'SELECT id, amount, mode, note, paid_at FROM ipd_payments WHERE admission_id = :id ORDER BY paid_at ASC',
            ['id' => $admission['id']]
        );

        $billed = array_sum(array_map(static fn ($b) => (float) $b['net_amount'], $admission['bills']));
        $paid = array_sum(array_map(static fn ($p) => (float) $p['amount'], $admission['payments']));
        $admission['totals'] = [
            'billed'  => round($billed, 2),
            'paid'    => round($paid, 2),
            'balance' => round($billed - $paid, 2),
        ];

        return $this->success($admission, 'Admission detail.');
    }

    public function store(Request $request): Response
    {
        if ($request->branchId() === null) {
            throw HttpException::forbidden('A branch context is required.');
        }
        $data = $this->validate($request, [
            'patient_name' => 'required|string|max:150',
            'contact_no'   => 'string|max:20',
            'address'      => 'string|max:255',
            'doctor_name'  => 'string|max:150',
            'ward'         => 'string|max:60',
            'bed_no'       => 'string|max:30',
        ]);

        $model = new IpdAdmission();
        $id = $model->insert([
            'client_id'    => $request->clientId(),
            'branch_id'    => $request->branchId(),
            'admission_no' => $model->nextAdmissionNo($request->clientId(), $request->branchId()),
            'patient_name' => $data['patient_name'],
            'contact_no'   => $data['contact_no'] ?? null,
            'address'      => $data['address'] ?? null,
            'doctor_name'  => $data['doctor_name'] ?? null,
            'ward'         => $data['ward'] ?? null,
            'bed_no'       => $data['bed_no'] ?? null,
            'status'       => 'admitted',
            'admitted_at'  => now(),
            'created_by'   => $request->user()['id'] ?? null,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return $this->created(['id' => $id], 'Patient admitted.');
    }

    public function payment(Request $request): Response
    {
        $admission = $this->findAdmission($request);
        if ($admission['status'] !== 'admitted') {
            throw HttpException::validation(['admission' => ['This admission is already discharged.']]);
        }
        $data = $this->validate($request, [
            'amount' => 'required|numeric|min:1',
            'mode'   => 'string|max:20',
            'note'   => 'string|max:255',
        ]);

        Connection::ops()->prepare(
            'INSERT INTO ipd_payments (client_id, branch_id, admission_id, amount, mode, note, paid_at, created_by, created_at)
             VALUES (:c, :b, :a, :amt, :mode, :note, :now, :by, :now2)'
        )->execute([
            'c' => $request->clientId(), 'b' => $admission['branch_id'], 'a' => $admission['id'],
            'amt' => $data['amount'], 'mode' => $data['mode'] ?? 'cash', 'note' => $data['note'] ?? null,
            'now' => now(), 'by' => $request->user()['id'] ?? null, 'now2' => now(),
        ]);

        return $this->created(null, 'Payment recorded.');
    }

    public function discharge(Request $request): Response
    {
        $admission = $this->findAdmission($request);
        (new IpdAdmission())->update((int) $admission['id'], [
            'status' => 'discharged',
            'discharged_at' => now(),
            'updated_at' => now(),
        ]);

        $billed = (float) ($this->query('SELECT COALESCE(SUM(net_amount),0) t FROM sales WHERE ipd_admission_id = :id', ['id' => $admission['id']])[0]['t'] ?? 0);
        $paid = (float) ($this->query('SELECT COALESCE(SUM(amount),0) t FROM ipd_payments WHERE admission_id = :id', ['id' => $admission['id']])[0]['t'] ?? 0);

        return $this->success([
            'billed'  => round($billed, 2),
            'paid'    => round($paid, 2),
            'balance' => round($billed - $paid, 2),
        ], 'Patient discharged. Final settlement below.');
    }

    private function findAdmission(Request $request): array
    {
        $admission = (new IpdAdmission())->find((int) $request->attribute('id'));
        if (!$admission || (int) $admission['client_id'] !== $request->clientId()) {
            throw HttpException::notFound('Admission not found.');
        }
        return $admission;
    }

    private function query(string $sql, array $bind): array
    {
        $stmt = Connection::ops()->prepare($sql);
        $stmt->execute($bind);
        return $stmt->fetchAll();
    }
}
