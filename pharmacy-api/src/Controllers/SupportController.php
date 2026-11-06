<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use PDO;
use PDOException;

/**
 * Support tickets (Retail support.php parity).
 *
 * Client 1 is the support admin: sees every client's tickets, updates status,
 * remark and attachments, and chats as "Support Team". Every other client can
 * raise tickets and only ever sees / chats on its own tickets.
 */
final class SupportController extends Controller
{
    private const ADMIN_CLIENT_ID = 1;
    private const STATUSES = ['Generated', 'Pending', 'In Process', 'Resolved', 'Rejected'];
    private const DEFAULT_STATUS = 'In Process';
    private const DEFAULT_REMARK = 'this query will be resolved in 24 hrs';
    private const ADMIN_LOG_LIMIT = 20;
    private const MAX_FILES = 8;
    private const MAX_REPLY_FILES = 5;
    private const MAX_ADMIN_ATTACHMENTS = 16;
    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
    private const MAX_DOC_BYTES = 10 * 1024 * 1024;
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    private const DOC_EXT = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'zip', 'rar', 'ppt', 'pptx'];
    private const MIME_BY_EXT = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'bmp' => 'image/bmp', 'pdf' => 'application/pdf',
        'txt' => 'text/plain', 'csv' => 'text/csv',
    ];

    public function index(Request $request): Response
    {
        $isAdmin = $this->isAdmin($request);
        $where = ['1=1'];
        $bind = [];
        if (!$isAdmin) {
            $where[] = 't.client_id = :cid';
            $bind['cid'] = (int) $request->clientId();
        }

        $status = $this->normalizeStatus((string) $request->query('status', ''));
        if ($status !== '') {
            $where[] = 't.status = :status';
            $bind['status'] = $status;
        }

        $search = trim((string) $request->query('search', ''));
        $limit = 500;
        $limited = false;
        if ($isAdmin) {
            if ($search !== '') {
                $where[] = '(t.ticket_no LIKE :q1 OR t.medical_name LIKE :q2 OR CAST(t.client_id AS CHAR) = :q3)';
                $bind['q1'] = '%' . $search . '%';
                $bind['q2'] = '%' . $search . '%';
                $bind['q3'] = ltrim($search, '0') ?: '0';
            } else {
                $limit = self::ADMIN_LOG_LIMIT;
                $limited = true;
            }
        }

        $stmt = Connection::ho()->prepare(
            'SELECT t.*, (SELECT COUNT(*) FROM support_ticket_replies r WHERE r.ticket_id = t.id) AS reply_count
             FROM support_tickets t
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY t.id DESC
             LIMIT ' . $limit
        );
        $stmt->execute($bind);
        $tickets = array_map(fn (array $row) => $this->ticketRow($row), $stmt->fetchAll(PDO::FETCH_ASSOC));

        return $this->success([
            'is_admin'    => $isAdmin,
            'tickets'     => $tickets,
            'limited_log' => $limited,
            'log_limit'   => $limited ? self::ADMIN_LOG_LIMIT : null,
            'statuses'    => self::STATUSES,
        ], 'Support tickets.');
    }

    public function store(Request $request): Response
    {
        $subject = trim((string) $request->input('subject', ''));
        $queryText = trim((string) $request->input('query_text', ''));
        $errors = [];
        if ($subject === '') {
            $errors['subject'] = ['Subject / query title is required.'];
        } elseif (mb_strlen($subject) > 500) {
            $errors['subject'] = ['Subject may not exceed 500 characters.'];
        }
        if ($queryText === '') {
            $errors['query_text'] = ['Query details are required.'];
        }
        if ($errors) {
            throw HttpException::validation($errors);
        }

        $clientId = (int) $request->clientId();
        $user = $request->user() ?? [];
        [$medical, $contact] = $this->clientInfo($clientId);
        $createdBy = trim((string) ($user['name'] ?? '')) ?: (string) ($user['email'] ?? '');
        $now = now();

        $db = Connection::ho();
        $id = 0;
        $ticketNo = '';
        for ($attempt = 0; $attempt < 5 && $id === 0; $attempt++) {
            $ticketNo = $this->nextTicketNo($clientId, $attempt);
            try {
                $db->prepare(
                    'INSERT INTO support_tickets
                        (ticket_no, client_id, branch_id, user_id, medical_name, contact_no, created_by,
                         subject, query_text, screenshots, status, admin_remark, admin_screenshots, admin_files,
                         created_at, updated_at, status_changed_at)
                     VALUES (:no, :cid, :bid, :uid, :medical, :contact, :by, :subject, :query, :shots, :status,
                             :remark, :ashots, :afiles, :c, :u, NULL)'
                )->execute([
                    'no'      => $ticketNo,
                    'cid'     => $clientId,
                    'bid'     => $request->branchId(),
                    'uid'     => isset($user['id']) ? (int) $user['id'] : null,
                    'medical' => mb_substr($medical, 0, 255),
                    'contact' => mb_substr($contact, 0, 32),
                    'by'      => mb_substr($createdBy, 0, 150),
                    'subject' => $subject,
                    'query'   => $queryText,
                    'shots'   => '[]',
                    'status'  => self::DEFAULT_STATUS,
                    'remark'  => self::DEFAULT_REMARK,
                    'ashots'  => '[]',
                    'afiles'  => '[]',
                    'c'       => $now,
                    'u'       => $now,
                ]);
                $id = (int) $db->lastInsertId();
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }
        if ($id === 0) {
            throw new HttpException('Could not generate a ticket number. Please try again.', 500);
        }

        $shots = $this->saveFiles('screenshots', $id, 'st', self::IMAGE_EXT, self::MAX_IMAGE_BYTES, self::MAX_FILES);
        if ($shots) {
            $db->prepare('UPDATE support_tickets SET screenshots = :s WHERE id = :id')
                ->execute(['s' => json_encode(array_column($shots, 'file')), 'id' => $id]);
        }

        return $this->created([
            'id'        => $id,
            'ticket_no' => $ticketNo,
            'ticket'    => $this->ticketRow($this->loadTicket($id)),
        ], 'Ticket raised successfully.');
    }

    public function updateStatus(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            throw HttpException::forbidden('Only support admin can update ticket status.');
        }
        $ticket = $this->loadTicket((int) $request->attribute('id'));
        if (strcasecmp((string) $ticket['status'], 'Resolved') === 0) {
            throw HttpException::validation(['status' => ['Resolved tickets cannot be edited.']]);
        }
        $status = $this->normalizeStatus((string) $request->input('status', ''));
        if ($status === '') {
            throw HttpException::validation(['status' => ['Select a valid status.']]);
        }
        $remark = trim((string) $request->input('admin_remark', ''));

        $id = (int) $ticket['id'];
        $newShots = $this->saveFiles('admin_screenshots', $id, 'as', self::IMAGE_EXT, self::MAX_IMAGE_BYTES, self::MAX_FILES);
        $newFiles = $this->saveFiles('admin_files', $id, 'af', self::DOC_EXT, self::MAX_DOC_BYTES, self::MAX_FILES);

        $shots = array_slice(array_merge(
            $this->decodeList($ticket['admin_screenshots'] ?? ''),
            array_column($newShots, 'file')
        ), 0, self::MAX_ADMIN_ATTACHMENTS);
        $files = array_slice(array_merge(
            $this->decodeList($ticket['admin_files'] ?? ''),
            $newFiles
        ), 0, self::MAX_ADMIN_ATTACHMENTS);

        $now = now();
        Connection::ho()->prepare(
            'UPDATE support_tickets
             SET status = :status, admin_remark = :remark, admin_screenshots = :shots, admin_files = :files,
                 updated_at = :u, status_changed_at = :sc
             WHERE id = :id'
        )->execute([
            'status' => $status,
            'remark' => $remark,
            'shots'  => json_encode(array_values($shots)),
            'files'  => json_encode(array_values($files)),
            'u'      => $now,
            'sc'     => $now,
            'id'     => $id,
        ]);

        return $this->success(['ticket' => $this->ticketRow($this->loadTicket($id))], 'Ticket updated.');
    }

    public function replies(Request $request): Response
    {
        $ticket = $this->accessibleTicket($request, (int) $request->attribute('id'));
        $stmt = Connection::ho()->prepare(
            'SELECT * FROM support_ticket_replies WHERE ticket_id = :id ORDER BY id ASC LIMIT 500'
        );
        $stmt->execute(['id' => (int) $ticket['id']]);
        $replies = array_map(fn (array $r) => $this->replyRow($r), $stmt->fetchAll(PDO::FETCH_ASSOC));

        return $this->success([
            'ticket'    => $this->ticketRow($ticket),
            'replies'   => $replies,
            'can_reply' => $this->canReply($request, $ticket),
        ], 'Ticket conversation.');
    }

    public function postReply(Request $request): Response
    {
        $ticket = $this->accessibleTicket($request, (int) $request->attribute('id'));
        if (!$this->canReply($request, $ticket)) {
            throw HttpException::validation(['message' => ['This ticket is locked for replies.']]);
        }

        $id = (int) $ticket['id'];
        $isAdmin = $this->isAdmin($request);
        $message = trim((string) $request->input('message', ''));
        $shots = $this->saveFiles('reply_screenshots', $id, 'rs', self::IMAGE_EXT, self::MAX_IMAGE_BYTES, self::MAX_REPLY_FILES);
        $files = $this->saveFiles('reply_files', $id, 'rf', self::DOC_EXT, self::MAX_DOC_BYTES, self::MAX_REPLY_FILES);

        if ($message === '' && !$shots && !$files) {
            throw HttpException::validation(['message' => ['Enter a message or attach a file.']]);
        }
        if ($message === '') {
            $message = $shots ? '[Screenshot attached]' : '[File attached]';
        }

        $user = $request->user() ?? [];
        $sender = $isAdmin
            ? 'Support Team'
            : (trim((string) ($user['name'] ?? '')) ?: ((string) $ticket['medical_name'] ?: 'Client'));

        $now = now();
        $db = Connection::ho();
        $db->prepare(
            'INSERT INTO support_ticket_replies
                (ticket_id, client_id, user_id, sender_name, is_admin, message, screenshots, files, created_at)
             VALUES (:tid, :cid, :uid, :sender, :admin, :msg, :shots, :files, :c)'
        )->execute([
            'tid'    => $id,
            'cid'    => (int) $request->clientId(),
            'uid'    => isset($user['id']) ? (int) $user['id'] : null,
            'sender' => mb_substr($sender, 0, 150),
            'admin'  => $isAdmin ? 1 : 0,
            'msg'    => $message,
            'shots'  => json_encode(array_column($shots, 'file')),
            'files'  => json_encode($files),
            'c'      => $now,
        ]);
        $replyId = (int) $db->lastInsertId();

        $reopened = !$isAdmin && strcasecmp((string) $ticket['status'], 'Resolved') === 0;
        if ($reopened) {
            $db->prepare(
                "UPDATE support_tickets SET status = 'In Process', updated_at = :u, status_changed_at = :sc WHERE id = :id"
            )->execute(['u' => $now, 'sc' => $now, 'id' => $id]);
        } else {
            $db->prepare('UPDATE support_tickets SET updated_at = :u WHERE id = :id')
                ->execute(['u' => $now, 'id' => $id]);
        }

        $stmt = $db->prepare('SELECT * FROM support_ticket_replies WHERE id = :id');
        $stmt->execute(['id' => $replyId]);

        return $this->created([
            'reply'    => $this->replyRow($stmt->fetch(PDO::FETCH_ASSOC) ?: []),
            'reopened' => $reopened,
            'ticket'   => $this->ticketRow($this->loadTicket($id)),
        ], $reopened ? 'Reply posted and ticket reopened.' : 'Reply posted.');
    }

    /** New tickets / client replies for admin; status changes / support replies for clients. */
    public function poll(Request $request): Response
    {
        $sinceRaw = trim((string) $request->query('since', ''));
        $sinceTs = $sinceRaw !== '' ? strtotime($sinceRaw) : false;
        $since = $sinceTs ? date('Y-m-d H:i:s', $sinceTs) : date('Y-m-d H:i:s', time() - 60);
        $serverTime = now();
        $db = Connection::ho();
        $isAdmin = $this->isAdmin($request);
        $notifications = [];

        if ($isAdmin) {
            $stmt = $db->prepare(
                'SELECT * FROM support_tickets WHERE created_at > :s AND client_id <> :admin ORDER BY id ASC LIMIT 30'
            );
            $stmt->execute(['s' => $since, 'admin' => self::ADMIN_CLIENT_ID]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $notifications[] = [
                    'type'   => 'new_ticket',
                    'key'    => 'new_' . $row['id'],
                    'ticket' => $this->ticketRow($row),
                ];
            }
            $stmt = $db->prepare(
                'SELECT r.*, t.ticket_no, t.subject, t.medical_name, t.status AS ticket_status, t.client_id AS ticket_client
                 FROM support_ticket_replies r
                 INNER JOIN support_tickets t ON t.id = r.ticket_id
                 WHERE r.is_admin = 0 AND r.created_at > :s
                 ORDER BY r.id ASC LIMIT 30'
            );
            $stmt->execute(['s' => $since]);
        } else {
            $cid = (int) $request->clientId();
            $stmt = $db->prepare(
                'SELECT * FROM support_tickets
                 WHERE client_id = :cid AND status_changed_at IS NOT NULL AND status_changed_at > :s
                 ORDER BY id ASC LIMIT 30'
            );
            $stmt->execute(['cid' => $cid, 's' => $since]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $notifications[] = [
                    'type'   => 'status_update',
                    'key'    => 'status_' . $row['id'] . '_' . $row['status_changed_at'],
                    'ticket' => $this->ticketRow($row),
                ];
            }
            $stmt = $db->prepare(
                'SELECT r.*, t.ticket_no, t.subject, t.medical_name, t.status AS ticket_status, t.client_id AS ticket_client
                 FROM support_ticket_replies r
                 INNER JOIN support_tickets t ON t.id = r.ticket_id
                 WHERE t.client_id = :cid AND r.is_admin = 1 AND r.created_at > :s
                 ORDER BY r.id ASC LIMIT 30'
            );
            $stmt->execute(['cid' => $cid, 's' => $since]);
        }

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $notifications[] = [
                'type'   => 'ticket_reply',
                'key'    => 'reply_' . $row['id'],
                'ticket' => [
                    'id'           => (int) $row['ticket_id'],
                    'ticket_no'    => $row['ticket_no'],
                    'subject'      => $row['subject'],
                    'medical_name' => $row['medical_name'],
                    'status'       => $row['ticket_status'],
                    'client_id'    => (int) $row['ticket_client'],
                ],
                'reply' => $this->replyRow($row),
            ];
        }

        return $this->success([
            'is_admin'      => $isAdmin,
            'notifications' => $notifications,
            'server_time'   => $serverTime,
        ], 'Support notifications.');
    }

    public function file(Request $request): Response
    {
        $ticket = $this->accessibleTicket($request, (int) $request->attribute('id'));
        $name = (string) $request->attribute('file');
        if (!preg_match('/^[a-z]{2}_\d{14}_[a-f0-9]{16}\.[a-z0-9]{2,5}$/', $name)) {
            throw HttpException::notFound('File not found.');
        }
        $path = $this->ticketDir((int) $ticket['id']) . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path)) {
            throw HttpException::notFound('File not found.');
        }
        $mime = self::MIME_BY_EXT[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
        return Response::raw((string) file_get_contents($path), $mime, 200, [
            'Cache-Control'          => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function isAdmin(Request $request): bool
    {
        return (int) $request->clientId() === self::ADMIN_CLIENT_ID;
    }

    /** @return array<string,mixed> */
    private function loadTicket(int $id): array
    {
        $stmt = Connection::ho()->prepare(
            'SELECT t.*, (SELECT COUNT(*) FROM support_ticket_replies r WHERE r.ticket_id = t.id) AS reply_count
             FROM support_tickets t WHERE t.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw HttpException::notFound('Ticket not found.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function accessibleTicket(Request $request, int $id): array
    {
        $ticket = $this->loadTicket($id);
        if (!$this->isAdmin($request) && (int) $ticket['client_id'] !== (int) $request->clientId()) {
            throw HttpException::notFound('Ticket not found.');
        }
        return $ticket;
    }

    /** @param array<string,mixed> $ticket */
    private function canReply(Request $request, array $ticket): bool
    {
        $status = (string) ($ticket['status'] ?? '');
        if (strcasecmp($status, 'Rejected') === 0) {
            return false;
        }
        if ($this->isAdmin($request)) {
            return strcasecmp($status, 'Resolved') !== 0;
        }
        return true;
    }

    private function normalizeStatus(string $status): string
    {
        foreach (self::STATUSES as $allowed) {
            if (strcasecmp(trim($status), $allowed) === 0) {
                return $allowed;
            }
        }
        return '';
    }

    /** @return array{0:string,1:string} */
    private function clientInfo(int $clientId): array
    {
        $stmt = Connection::ho()->prepare('SELECT business_name, phone FROM clients WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [trim((string) ($row['business_name'] ?? '')), trim((string) ($row['phone'] ?? ''))];
    }

    private function nextTicketNo(int $clientId, int $offset): string
    {
        $stmt = Connection::ho()->prepare('SELECT COUNT(*) FROM support_tickets WHERE client_id = :cid');
        $stmt->execute(['cid' => $clientId]);
        $next = (int) $stmt->fetchColumn() + 1 + $offset;
        return 'ST/' . str_pad((string) $clientId, 3, '0', STR_PAD_LEFT) . '/' . date('y') . '/'
            . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function ticketDir(int $ticketId): string
    {
        return storage_path('uploads/support/' . $ticketId);
    }

    /**
     * Saves files from a multipart field (`name` or `name[]`); silently skips
     * invalid types / sizes like Retail.
     *
     * @param list<string> $allowedExt
     * @return list<array{file:string,name:string}>
     */
    private function saveFiles(string $field, int $ticketId, string $prefix, array $allowedExt, int $maxBytes, int $maxCount): array
    {
        $bag = $_FILES[$field] ?? null;
        if (!$bag) {
            return [];
        }
        $names = (array) $bag['name'];
        $tmps = (array) $bag['tmp_name'];
        $errs = (array) $bag['error'];
        $sizes = (array) $bag['size'];

        $dir = $this->ticketDir($ticketId);
        $saved = [];
        foreach ($names as $i => $orig) {
            if (count($saved) >= $maxCount) {
                break;
            }
            if ((int) ($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($tmps[$i] ?? ''))) {
                continue;
            }
            $size = (int) ($sizes[$i] ?? 0);
            if ($size <= 0 || $size > $maxBytes) {
                continue;
            }
            $ext = strtolower(pathinfo((string) $orig, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) {
                continue;
            }
            if (in_array($ext, self::IMAGE_EXT, true) && @getimagesize((string) $tmps[$i]) === false) {
                continue;
            }
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new HttpException('Could not create upload directory.', 500);
            }
            $file = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (move_uploaded_file((string) $tmps[$i], $dir . DIRECTORY_SEPARATOR . $file)) {
                $saved[] = ['file' => $file, 'name' => mb_substr(basename((string) $orig), 0, 150)];
            }
        }
        return $saved;
    }

    /** @return list<mixed> */
    private function decodeList(?string $raw): array
    {
        $decoded = $raw ? json_decode($raw, true) : null;
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function fileUrl(int $ticketId, string $file): string
    {
        return '/support/tickets/' . $ticketId . '/files/' . $file;
    }

    /**
     * @param list<mixed> $items
     * @return list<array{name:string,url:string}>
     */
    private function fileMeta(int $ticketId, array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $file = is_array($item) ? (string) ($item['file'] ?? '') : (string) $item;
            if ($file === '') {
                continue;
            }
            $name = is_array($item) ? (string) ($item['name'] ?? $file) : $file;
            $out[] = ['name' => $name, 'url' => $this->fileUrl($ticketId, $file)];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function ticketRow(array $row): array
    {
        $id = (int) $row['id'];
        $url = fn ($f) => $this->fileUrl($id, (string) $f);
        return [
            'id'                    => $id,
            'ticket_no'             => (string) $row['ticket_no'],
            'client_id'             => (int) $row['client_id'],
            'client_no'             => str_pad((string) $row['client_id'], 3, '0', STR_PAD_LEFT),
            'medical_name'          => (string) $row['medical_name'],
            'contact_no'            => (string) $row['contact_no'],
            'created_by'            => (string) $row['created_by'],
            'subject'               => (string) $row['subject'],
            'query_text'            => (string) $row['query_text'],
            'status'                => (string) $row['status'],
            'admin_remark'          => (string) ($row['admin_remark'] ?? ''),
            'screenshot_urls'       => array_map($url, $this->decodeList($row['screenshots'] ?? '')),
            'admin_screenshot_urls' => array_map($url, $this->decodeList($row['admin_screenshots'] ?? '')),
            'admin_files'           => $this->fileMeta($id, $this->decodeList($row['admin_files'] ?? '')),
            'reply_count'           => (int) ($row['reply_count'] ?? 0),
            'created_at'            => (string) $row['created_at'],
            'updated_at'            => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function replyRow(array $row): array
    {
        if (!$row) {
            return [];
        }
        $tid = (int) $row['ticket_id'];
        return [
            'id'              => (int) $row['id'],
            'ticket_id'       => $tid,
            'sender_name'     => (string) $row['sender_name'],
            'is_admin'        => (int) $row['is_admin'] === 1,
            'message'         => (string) $row['message'],
            'screenshot_urls' => array_map(fn ($f) => $this->fileUrl($tid, (string) $f), $this->decodeList($row['screenshots'] ?? '')),
            'files'           => $this->fileMeta($tid, $this->decodeList($row['files'] ?? '')),
            'created_at'      => (string) $row['created_at'],
        ];
    }
}
