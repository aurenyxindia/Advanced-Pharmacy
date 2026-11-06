<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AiBillExtractService;
use App\Services\GmailImportService;
use App\Services\PurchaseImportService;
use App\Support\UploadGuard;

final class ImportController extends Controller
{
    /** List supported ERP format keys. */
    public function purchaseFormats(Request $request): Response
    {
        return $this->success((new PurchaseImportService())->listFormats());
    }

    /**
     * Parse CSV/TSV/TXT (or CSV text from Excel conversion) with optional format.
     * Accepts multipart file and/or `csv_text` + `format`.
     */
    public function purchaseParse(Request $request): Response
    {
        UploadGuard::boostRuntime();
        $format = trim((string) ($request->input('format') ?? 'Auto'));
        if ($format === '') {
            $format = 'Auto';
        }
        $csvText = $request->input('csv_text');
        $svc = (new PurchaseImportService())->withCatalogPolicy((int) ($request->clientId() ?? 0));

        if (is_string($csvText) && trim($csvText) !== '') {
            UploadGuard::assertContentLength(strlen($csvText), 'csv_text');
            $result = $svc->parse($csvText, $format, true);
            $result['filename'] = trim((string) ($request->input('filename') ?? 'file.csv')) ?: 'file.csv';
            return $this->success($result, 'File parsed. Review and confirm the lines.');
        }

        $file = UploadGuard::requireUploadedFile($_FILES['file'] ?? null);
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'tsv', 'txt'], true)) {
            throw HttpException::validation([
                'file' => ['Only CSV/TSV/TXT are accepted here. Convert Excel to CSV in the browser first.'],
            ]);
        }
        $result = $svc->parse($file['tmp_name'], $format, false);
        $result['filename'] = (string) ($file['name'] ?? 'file.csv');
        return $this->success($result, 'File parsed. Review and confirm the lines.');
    }

    /** Parse an uploaded CSV/TSV purchase invoice into draft lines (legacy). */
    public function purchaseCsv(Request $request): Response
    {
        UploadGuard::boostRuntime();
        $file = UploadGuard::requireUploadedFile($_FILES['file'] ?? null);

        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'tsv', 'txt'], true)) {
            throw HttpException::validation(['file' => ['Only CSV/TSV files are supported here. For Excel, export to CSV first.']]);
        }

        $result = (new PurchaseImportService())
            ->withCatalogPolicy((int) ($request->clientId() ?? 0))
            ->parseCsv($file['tmp_name']);
        return $this->success($result, 'File parsed. Review and confirm the lines.');
    }

    /** Search / rematch products for import lines. */
    public function purchaseMatch(Request $request): Response
    {
        $body = $request->all();
        $query = isset($body['q']) ? (string) $body['q'] : null;
        $names = null;
        if (isset($body['names']) && is_array($body['names'])) {
            $names = $body['names'];
        }
        $limit = (int) ($body['limit'] ?? 20);
        $clientId = (int) ($request->clientId() ?? 0);
        $ownClientId = ($clientId > 0 && (new \App\Services\ClientCatalogPolicy())->hideProducts($clientId))
            ? $clientId
            : null;
        $result = (new PurchaseImportService())->matchProducts(
            $query,
            $names,
            max(1, min(50, $limit)),
            $ownClientId
        );
        return $this->success($result);
    }

    public function checkInvoice(Request $request): Response
    {
        $invoiceNo = trim((string) ($request->query('invoice_no') ?? $request->input('invoice_no') ?? ''));
        $result = (new PurchaseImportService())->checkInvoice((int) $request->clientId(), $invoiceNo);
        return $this->success($result);
    }

    public function purchaseAiStatus(Request $request): Response
    {
        return $this->success((new AiBillExtractService())->status());
    }

    public function purchaseAi(Request $request): Response
    {
        UploadGuard::boostRuntime();
        $ai = new AiBillExtractService();

        // Multi-page images (PDF→PNG from browser) — preferred for OpenAI accuracy.
        $images = $request->input('images');
        if (is_array($images) && $images !== []) {
            $result = $ai->extractFromImagePayloads($images);
            return $this->success($result, 'Extracted with AI. Verify before saving.');
        }

        $file = UploadGuard::requireUploadedFile($_FILES['file'] ?? null);
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
            throw HttpException::validation(['file' => ['Supported types: PDF, JPG, PNG, WEBP.']]);
        }
        $mime = (string) ($file['type'] ?? 'application/octet-stream');
        // OpenAI cannot read PDF as vision — require page images.
        if ($ext === 'pdf' && $ai->provider() === 'openai') {
            throw HttpException::validation([
                'file' => ['PDF must be converted to page images first. Re-upload from the Import by AI panel.'],
            ]);
        }
        $result = $ai->extractFromFile($file['tmp_name'], $mime);
        return $this->success($result, 'Extracted with AI. Verify before saving.');
    }

    public function purchasePdf(Request $request): Response
    {
        return $this->purchaseAi($request);
    }

    public function gmailStatus(Request $request): Response
    {
        return $this->success((new GmailImportService())->status((int) $request->clientId()));
    }

    public function gmailStart(Request $request): Response
    {
        $url = (new GmailImportService())->authUrl((int) $request->clientId());
        return $this->success(['url' => $url]);
    }

    /** OAuth callback — returns HTML (popup). Auth middleware may be skipped for this route. */
    public function gmailCallback(Request $request): Response
    {
        $code = (string) ($request->query('code') ?? '');
        $state = (string) ($request->query('state') ?? '');
        if ($code === '') {
            return Response::raw('<!doctype html><html><body>Invalid OAuth callback.</body></html>', 'text/html; charset=utf-8', 400);
        }
        $html = (new GmailImportService())->handleCallback($code, $state);
        return Response::raw($html, 'text/html; charset=utf-8', 200);
    }

    public function gmailDisconnect(Request $request): Response
    {
        (new GmailImportService())->disconnect((int) $request->clientId());
        return $this->success(['linked' => false], 'Gmail disconnected.');
    }

    public function gmailList(Request $request): Response
    {
        $items = (new GmailImportService())->listAttachments((int) $request->clientId());
        return $this->success(['attachments' => $items]);
    }

    /**
     * Download Gmail attachment and parse with selected format.
     * Excel attachments: return raw base64 for frontend conversion when needed.
     */
    public function gmailDownload(Request $request): Response
    {
        UploadGuard::boostRuntime();
        $messageId = trim((string) ($request->query('messageId') ?? $request->input('messageId') ?? ''));
        $attachmentId = trim((string) ($request->query('attachmentId') ?? $request->input('attachmentId') ?? ''));
        $filename = trim((string) ($request->query('filename') ?? $request->input('filename') ?? 'file.csv'));
        $format = trim((string) ($request->query('format') ?? $request->input('format') ?? 'Generic'));

        if ($messageId === '' || $attachmentId === '') {
            throw HttpException::validation([
                'messageId' => ['messageId is required.'],
                'attachmentId' => ['attachmentId is required.'],
            ]);
        }

        $bytes = (new GmailImportService())->downloadAttachment(
            (int) $request->clientId(),
            $messageId,
            $attachmentId
        );
        UploadGuard::assertContentLength(strlen($bytes), 'file');
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if ($ext === 'txt') {
            throw HttpException::validation([
                'filename' => ['TXT attachments are not supported for email import. Use CSV or Excel.'],
            ]);
        }

        if (in_array($ext, ['xlsx', 'xls'], true)) {
            $b64 = base64_encode($bytes);
            unset($bytes);
            return $this->success([
                'needs_excel_convert' => true,
                'filename'            => $filename,
                'base64'              => $b64,
                'format'              => $format,
            ], 'Excel attachment ready for client-side conversion.');
        }

        $result = (new PurchaseImportService())
            ->withCatalogPolicy((int) ($request->clientId() ?? 0))
            ->parse($bytes, $format !== '' ? $format : 'Generic', true);
        unset($bytes);
        $result['filename'] = $filename;
        return $this->success($result, 'Attachment parsed. Review and confirm the lines.');
    }

    /** Legacy stub — prefer gmailList / gmailDownload. */
    public function purchaseGmail(Request $request): Response
    {
        $items = (new GmailImportService())->listAttachments((int) $request->clientId());
        return $this->success(['attachments' => $items], 'Fetched from Gmail.');
    }
}
