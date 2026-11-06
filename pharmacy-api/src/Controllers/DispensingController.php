<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Services\DispensingService;
use App\Services\UserRightsService;

/** Purchase → Dispensing: issue stock to the attached hospital (no payment, amount on record). */
final class DispensingController extends Controller
{
    private const MODULE = 'DISPENSING';

    public function index(Request $request): Response
    {
        $this->requireRights($request);
        $rows = (new DispensingService())->list(
            (int) $request->clientId(),
            (int) ($request->branchId() ?: 0),
            [
                'from' => (string) $request->query('from', ''),
                'to' => (string) $request->query('to', ''),
                'hospital_id' => (int) $request->query('hospital_id', 0),
                'search' => (string) $request->query('search', ''),
            ],
        );
        return $this->success($rows, 'Dispensing list.');
    }

    public function show(Request $request): Response
    {
        $this->requireRights($request);
        $row = (new DispensingService())->show(
            (int) $request->clientId(),
            (int) ($request->branchId() ?: 0),
            (string) $request->query('no', ''),
        );
        if ($row === null) {
            throw HttpException::notFound('Dispensing not found.');
        }
        return $this->success($row, 'Dispensing.');
    }

    public function reportPdf(Request $request): Response
    {
        $this->requireRights($request);
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');
        $pdf = (new DispensingService())->reportPdf(
            (int) $request->clientId(),
            (int) ($request->branchId() ?: 0),
            [
                'from' => $from,
                'to' => $to,
                'hospital_id' => (int) $request->query('hospital_id', 0),
                'search' => (string) $request->query('search', ''),
            ],
        );
        $stamp = trim((string) preg_replace('/[^0-9_-]/', '', $from . '_to_' . $to), '_') ?: date('Y-m-d');
        return Response::raw($pdf, 'application/pdf', 200, [
            'Content-Disposition' => 'inline; filename="Hospital-Dispensing-Report-' . $stamp . '.pdf"',
        ]);
    }

    public function store(Request $request): Response
    {
        $this->requireRights($request, true);
        $user = $request->user() ?? [];
        $userId = (int) ($user['id'] ?? 0);
        $userName = trim((string) ($user['name'] ?? $user['email'] ?? ''));
        if ($userId > 0) {
            try {
                $st = Connection::ho()->prepare('SELECT name FROM users WHERE id = :id LIMIT 1');
                $st->execute(['id' => $userId]);
                $userName = trim((string) ($st->fetchColumn() ?: '')) ?: $userName;
            } catch (\Throwable) {
                // Fall back to the name in the token.
            }
        }

        $result = (new DispensingService())->create(
            (int) $request->clientId(),
            (int) ($request->branchId() ?: 0),
            $request->all(),
            ['user_id' => $userId > 0 ? $userId : null, 'user_name' => $userName],
        );
        return $this->created($result, 'Dispensing saved: ' . $result['dispensing_no']);
    }

    private function requireRights(Request $request, bool $edit = false): void
    {
        $user = $request->user() ?? [];
        (new UserRightsService())->requireModule(
            (int) $request->clientId(),
            (int) ($user['id'] ?? 0),
            (string) ($user['role'] ?? ''),
            self::MODULE,
            $edit,
        );
    }
}
