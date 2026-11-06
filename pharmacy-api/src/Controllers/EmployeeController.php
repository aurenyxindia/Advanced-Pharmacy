<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\User;

/**
 * Employee / user master (HO database). Scoped to the current client.
 */
final class EmployeeController extends Controller
{
    private const ROLES = ['ho_admin', 'branch_admin', 'purchase', 'pharmacist', 'cashier', 'delivery'];

    public function index(Request $request): Response
    {
        $users = new User();
        $result = $users->paginate(
            ['client_id' => $request->clientId()],
            $request->query('search'),
            ['name', 'email', 'phone'],
            (int) $request->query('page', 1),
            (int) $request->query('per_page', 25),
            'name ASC'
        );

        // Never leak password hashes.
        $result['data'] = array_map(static function (array $u) {
            unset($u['password_hash']);
            return $u;
        }, $result['data']);

        return $this->success($result, 'Employees fetched.');
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'name'         => 'required|string|min:2|max:120',
            'email'        => 'required|email|max:150',
            'phone'        => 'string|regex:/^[6-9]\d{9}$/',
            'role'         => 'required|in:' . implode(',', self::ROLES),
            'branch_id'    => 'required|integer',
            'password'     => 'required|string|min:8',
            'status'       => 'in:active,inactive,suspended',
            'reg_no'       => 'string|max:40',
            'joining_date' => 'string|max:20',
            'address'      => 'string|max:255',
        ]);

        $users = new User();
        if ($users->findByEmail($data['email'])) {
            throw HttpException::validation(['email' => ['This email is already in use.']]);
        }

        $id = $users->insert([
            'client_id'     => $request->clientId(),
            'branch_id'     => (int) $data['branch_id'],
            'name'          => $data['name'],
            'email'         => strtolower(trim($data['email'])),
            'phone'         => $data['phone'] ?? null,
            'reg_no'        => isset($data['reg_no']) ? strtoupper(trim((string) $data['reg_no'])) : null,
            'joining_date'  => $data['joining_date'] ?? null,
            'address'       => isset($data['address']) ? strtoupper(trim((string) $data['address'])) : null,
            'password_hash' => AuthService::hashPassword($data['password']),
            'role'          => $data['role'],
            'status'        => $data['status'] ?? 'active',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return $this->created(['id' => $id], 'Employee created.');
    }

    public function update(Request $request): Response
    {
        $users = new User();
        $employee = $this->findScoped($request, $users);

        $data = $this->validate($request, [
            'name'         => 'required|string|min:2|max:120',
            'phone'        => 'string|regex:/^[6-9]\d{9}$/',
            'role'         => 'required|in:' . implode(',', self::ROLES),
            'branch_id'    => 'required|integer',
            'status'       => 'in:active,inactive,suspended',
            'password'     => 'string|min:8',
            'reg_no'       => 'string|max:40',
            'joining_date' => 'string|max:20',
            'address'      => 'string|max:255',
        ]);

        $payload = [
            'name'         => $data['name'],
            'phone'        => $data['phone'] ?? null,
            'reg_no'       => array_key_exists('reg_no', $data)
                ? (trim((string) ($data['reg_no'] ?? '')) !== '' ? strtoupper(trim((string) $data['reg_no'])) : null)
                : ($employee['reg_no'] ?? null),
            'joining_date' => array_key_exists('joining_date', $data) ? ($data['joining_date'] ?: null) : ($employee['joining_date'] ?? null),
            'address'      => array_key_exists('address', $data)
                ? (trim((string) ($data['address'] ?? '')) !== '' ? strtoupper(trim((string) $data['address'])) : null)
                : ($employee['address'] ?? null),
            'role'         => $data['role'],
            'branch_id'    => (int) $data['branch_id'],
            'status'       => $data['status'] ?? $employee['status'],
            'updated_at'   => now(),
        ];
        if (!empty($data['password'])) {
            $payload['password_hash'] = AuthService::hashPassword($data['password']);
        }

        $users->update((int) $employee['id'], $payload);
        return $this->success(null, 'Employee updated.');
    }

    public function destroy(Request $request): Response
    {
        $users = new User();
        $employee = $this->findScoped($request, $users);

        // Soft-disable rather than hard delete to preserve audit history.
        $users->update((int) $employee['id'], ['status' => 'inactive', 'updated_at' => now()]);
        return $this->success(null, 'Employee deactivated.');
    }

    private function findScoped(Request $request, User $users): array
    {
        $employee = $users->find((int) $request->attribute('id'));
        if (!$employee || (int) $employee['client_id'] !== $request->clientId()) {
            throw HttpException::notFound('Employee not found.');
        }
        return $employee;
    }
}
