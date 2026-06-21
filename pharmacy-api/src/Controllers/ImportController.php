<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\PurchaseImportService;

final class ImportController extends Controller
{
    /** Parse an uploaded CSV/TSV purchase invoice into draft lines for preview. */
    public function purchaseCsv(Request $request): Response
    {
        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw HttpException::validation(['file' => ['Please upload a valid CSV file.']]);
        }

        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'tsv', 'txt'], true)) {
            throw HttpException::validation(['file' => ['Only CSV/TSV files are supported here. For Excel, export to CSV first.']]);
        }

        $result = (new PurchaseImportService())->parseCsv($file['tmp_name']);
        return $this->success($result, 'File parsed. Review and confirm the lines.');
    }

    public function purchaseGmail(Request $request): Response
    {
        $result = (new PurchaseImportService())->fetchFromGmail();
        return $this->success($result, 'Fetched from Gmail.');
    }

    public function purchasePdf(Request $request): Response
    {
        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw HttpException::validation(['file' => ['Please upload a valid PDF file.']]);
        }
        $result = (new PurchaseImportService())->extractFromPdf($file['tmp_name']);
        return $this->success($result, 'Extracted from PDF.');
    }
}
