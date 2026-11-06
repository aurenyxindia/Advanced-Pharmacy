<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\ReturnDeductionService;

final class ReturnDeductionController extends Controller
{
    public function index(Request $request): Response
    {
        $rows = (new ReturnDeductionService())->list((int) $request->clientId());
        return $this->success($rows, 'Return deductions.');
    }

    public function store(Request $request): Response
    {
        $result = (new ReturnDeductionService())->save((int) $request->clientId(), $request->all());
        return $this->created($result, 'Return deduction saved.');
    }
}
