<?php

declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\BarcodeController;
use App\Controllers\BranchController;
use App\Controllers\CreditNoteController;
use App\Controllers\DebitNoteController;
use App\Controllers\HoController;
use App\Controllers\PaymentController;
use App\Controllers\IpdController;
use App\Controllers\CustomerController;
use App\Controllers\DashboardController;
use App\Controllers\DoctorController;
use App\Controllers\EmployeeController;
use App\Controllers\GenericController;
use App\Controllers\ImportController;
use App\Controllers\MetaController;
use App\Controllers\OrderController;
use App\Controllers\PrescriptionController;
use App\Controllers\PublicController;
use App\Controllers\PurchaseController;
use App\Controllers\RateMarginController;
use App\Controllers\RefillController;
use App\Controllers\ReportController;
use App\Controllers\SalesController;
use App\Controllers\StockController;
use App\Controllers\ProductBindingController;
use App\Controllers\ProductController;
use App\Controllers\RegistrationController;
use App\Controllers\SupplierController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

/** @var Router $router */

// ---- Public ------------------------------------------------------------
$router->get('/api/health', static fn (Request $r) => Response::success([
    'service' => config('app.name'),
    'time'    => now(),
    'status'  => 'ok',
], 'Service healthy.'));

$router->group(['prefix' => '/api/auth'], function (Router $router): void {
    $router->post('/register', [RegistrationController::class, 'register']);
    $router->post('/login', [AuthController::class, 'login']);
    $router->post('/refresh', [AuthController::class, 'refresh']);
    $router->post('/logout', [AuthController::class, 'logout']);
});

// ---- Public ePharmacy (no authentication) ----
$router->group(['prefix' => '/api/public'], function (Router $router): void {
    $router->get('/branches', [PublicController::class, 'branches']);
    $router->get('/catalog', [PublicController::class, 'catalog']);
    $router->post('/orders', [PublicController::class, 'placeOrder']);
    $router->get('/orders/track', [PublicController::class, 'track']);
});

// ---- Authenticated -----------------------------------------------------
$router->group(['prefix' => '/api', 'middleware' => ['auth']], function (Router $router): void {
    $router->get('/auth/me', [AuthController::class, 'me']);
    $router->get('/dashboard/summary', [DashboardController::class, 'summary']);

    // ---- Lookups / metadata ----
    $router->get('/meta/schedule-categories', [MetaController::class, 'scheduleCategories']);
    $router->get('/meta/profile', [MetaController::class, 'profile']);
    $router->get('/meta/branches', [MetaController::class, 'branches']);
    $router->get('/meta/roles', [MetaController::class, 'roles']);

    // ---- Master catalog: Generics (global) ----
    $router->get('/generics', [GenericController::class, 'index']);
    $router->post('/generics', [GenericController::class, 'store']);
    $router->get('/generics/{id}', [GenericController::class, 'show']);
    $router->put('/generics/{id}', [GenericController::class, 'update']);
    $router->delete('/generics/{id}', [GenericController::class, 'destroy']);

    // ---- Master catalog: Products (global) ----
    // Static segments registered before the {id} routes so they match first.
    $router->get('/products/search', [ProductController::class, 'search']);
    $router->post('/products/bind', [ProductController::class, 'bind']);
    $router->get('/products', [ProductController::class, 'index']);
    $router->post('/products', [ProductController::class, 'store']);
    $router->get('/products/{id}/substitutes', [ProductController::class, 'substitutes']);
    $router->get('/products/{id}', [ProductController::class, 'show']);
    $router->put('/products/{id}', [ProductController::class, 'update']);
    $router->delete('/products/{id}', [ProductController::class, 'destroy']);

    // ---- Tenant bound products (this branch's catalog) ----
    $router->get('/bound-products', [ProductBindingController::class, 'index']);
    $router->put('/bound-products/{id}', [ProductBindingController::class, 'update']);
    $router->delete('/bound-products/{id}', [ProductBindingController::class, 'destroy']);

    // ---- Tenant masters ----
    $router->get('/customers', [CustomerController::class, 'index']);
    $router->post('/customers', [CustomerController::class, 'store']);
    $router->get('/customers/{id}', [CustomerController::class, 'show']);
    $router->put('/customers/{id}', [CustomerController::class, 'update']);
    $router->delete('/customers/{id}', [CustomerController::class, 'destroy']);

    $router->get('/doctors', [DoctorController::class, 'index']);
    $router->post('/doctors', [DoctorController::class, 'store']);
    $router->get('/doctors/{id}', [DoctorController::class, 'show']);
    $router->put('/doctors/{id}', [DoctorController::class, 'update']);
    $router->delete('/doctors/{id}', [DoctorController::class, 'destroy']);

    $router->get('/suppliers', [SupplierController::class, 'index']);
    $router->post('/suppliers', [SupplierController::class, 'store']);
    $router->get('/suppliers/{id}', [SupplierController::class, 'show']);
    $router->put('/suppliers/{id}', [SupplierController::class, 'update']);
    $router->delete('/suppliers/{id}', [SupplierController::class, 'destroy']);

    // ---- Rate Margin Master (category-wise PTR/PTS pricing) ----
    $router->get('/rate-margins/compute', [RateMarginController::class, 'compute']);
    $router->get('/rate-margins', [RateMarginController::class, 'index']);
    $router->post('/rate-margins', [RateMarginController::class, 'store']);
    $router->get('/rate-margins/{id}', [RateMarginController::class, 'show']);
    $router->put('/rate-margins/{id}', [RateMarginController::class, 'update']);
    $router->delete('/rate-margins/{id}', [RateMarginController::class, 'destroy']);

    $router->get('/accounts', [AccountController::class, 'index']);
    $router->post('/accounts', [AccountController::class, 'store']);
    $router->get('/accounts/{id}', [AccountController::class, 'show']);
    $router->put('/accounts/{id}', [AccountController::class, 'update']);
    $router->delete('/accounts/{id}', [AccountController::class, 'destroy']);

    // ---- Purchase ----
    $router->get('/purchases', [PurchaseController::class, 'index']);
    $router->post('/purchases', [PurchaseController::class, 'store']);
    $router->get('/purchases/{id}', [PurchaseController::class, 'show']);
    $router->get('/purchases/{id}/barcodes', [BarcodeController::class, 'purchaseLabels']);

    // ---- Purchase import (CSV now; Gmail/PDF-OCR are extension points) ----
    $router->post('/import/purchase/csv', [ImportController::class, 'purchaseCsv']);
    $router->post('/import/purchase/gmail', [ImportController::class, 'purchaseGmail']);
    $router->post('/import/purchase/pdf', [ImportController::class, 'purchasePdf']);

    // ---- Sales / Billing ----
    $router->get('/sales/scan', [SalesController::class, 'scan']);
    $router->get('/sales/search-products', [SalesController::class, 'searchProducts']);
    $router->get('/sales', [SalesController::class, 'index']);
    $router->post('/sales', [SalesController::class, 'store']);
    $router->get('/sales/{id}/substitutes', [SalesController::class, 'substitutes']);
    $router->get('/sales/{id}/invoice', [SalesController::class, 'invoice']);
    $router->get('/sales/{id}', [SalesController::class, 'show']);

    // Substitutes + batches lookup for a bound product (used live during billing)
    $router->get('/bound-products/{id}/substitutes', [SalesController::class, 'substitutes']);
    $router->get('/bound-products/{id}/batches', [SalesController::class, 'batches']);

    // ---- Prescriptions ----
    $router->post('/prescriptions', [PrescriptionController::class, 'upload']);

    // ---- IPD (In-Patient) admissions ----
    $router->get('/ipd/admissions', [IpdController::class, 'index']);
    $router->post('/ipd/admissions', [IpdController::class, 'store']);
    $router->get('/ipd/admissions/{id}', [IpdController::class, 'show']);
    $router->post('/ipd/admissions/{id}/payment', [IpdController::class, 'payment']);
    $router->post('/ipd/admissions/{id}/discharge', [IpdController::class, 'discharge']);

    // ---- Online orders / delivery (staff) ----
    $router->get('/orders', [OrderController::class, 'index']);
    $router->get('/orders/{id}', [OrderController::class, 'show']);
    $router->put('/orders/{id}/status', [OrderController::class, 'updateStatus']);

    // ---- Refill reminders ----
    $router->get('/refills/summary', [RefillController::class, 'summary']);
    $router->get('/refills', [RefillController::class, 'index']);
    $router->post('/refills', [RefillController::class, 'store']);
    $router->put('/refills/{id}', [RefillController::class, 'update']);

    // ---- Payments (vouchers) & Notes ----
    $router->get('/payments', [PaymentController::class, 'index']);
    $router->post('/payments', [PaymentController::class, 'store']);
    $router->get('/payments/{id}', [PaymentController::class, 'show']);
    $router->put('/payments/{id}', [PaymentController::class, 'update']);
    $router->delete('/payments/{id}', [PaymentController::class, 'destroy']);

    $router->get('/credit-notes', [CreditNoteController::class, 'index']);
    $router->post('/credit-notes', [CreditNoteController::class, 'store']);
    $router->get('/credit-notes/{id}', [CreditNoteController::class, 'show']);
    $router->put('/credit-notes/{id}', [CreditNoteController::class, 'update']);
    $router->delete('/credit-notes/{id}', [CreditNoteController::class, 'destroy']);

    $router->get('/debit-notes', [DebitNoteController::class, 'index']);
    $router->post('/debit-notes', [DebitNoteController::class, 'store']);
    $router->get('/debit-notes/{id}', [DebitNoteController::class, 'show']);
    $router->put('/debit-notes/{id}', [DebitNoteController::class, 'update']);
    $router->delete('/debit-notes/{id}', [DebitNoteController::class, 'destroy']);

    // ---- Stock book / opening stock ----
    $router->get('/stock/opening', [StockController::class, 'openingList']);
    $router->post('/stock/opening', [StockController::class, 'openingStore']);
    $router->post('/stock/write-off-expired', [StockController::class, 'writeOffExpired']);

    // ---- Reports ----
    $router->get('/reports/branches', [ReportController::class, 'branches']);
    $router->get('/reports/stock-book', [ReportController::class, 'stockBook']);
    $router->get('/reports/stock-as-of', [ReportController::class, 'stockAsOf']);
    $router->get('/reports/fda-tally', [ReportController::class, 'fdaTally']);
    $router->get('/reports/sales-register', [ReportController::class, 'salesRegister']);
    $router->get('/reports/purchase-register', [ReportController::class, 'purchaseRegister']);
    $router->get('/reports/gst-summary', [ReportController::class, 'gstSummary']);
    $router->get('/reports/gst-hsn', [ReportController::class, 'gstHsn']);
    $router->get('/reports/stock', [ReportController::class, 'stock']);
    $router->get('/reports/expiry', [ReportController::class, 'expiry']);
    $router->get('/reports/expiry-alert', [ReportController::class, 'expiryAlert']);
    $router->get('/reports/non-moving', [ReportController::class, 'nonMoving']);
    $router->get('/reports/profit', [ReportController::class, 'profit']);
    $router->get('/reports/schedule-register', [ReportController::class, 'scheduleRegister']);
    $router->get('/reports/h1-sales', [ReportController::class, 'h1Sales']);
    $router->get('/reports/x-sales', [ReportController::class, 'xSales']);
    $router->get('/reports/doctor-wise', [ReportController::class, 'doctorWise']);
    $router->get('/reports/cash-book', [ReportController::class, 'cashBook']);
    $router->get('/reports/online-sales', [ReportController::class, 'onlineSales']);
    $router->get('/reports/chronic', [ReportController::class, 'chronic']);

    // ---- Employees / users (HO) - admin only ----
    $router->group(['middleware' => ['role:ho_admin,branch_admin']], function (Router $router): void {
        $router->get('/employees', [EmployeeController::class, 'index']);
        $router->post('/employees', [EmployeeController::class, 'store']);
        $router->put('/employees/{id}', [EmployeeController::class, 'update']);
        $router->delete('/employees/{id}', [EmployeeController::class, 'destroy']);
    });

    // ---- Head Office: branches, portfolio, performance, transfers (ho_admin) ----
    $router->group(['middleware' => ['role:ho_admin']], function (Router $router): void {
        $router->get('/branches', [BranchController::class, 'index']);
        $router->post('/branches', [BranchController::class, 'store']);
        $router->get('/branches/{id}', [BranchController::class, 'show']);
        $router->put('/branches/{id}', [BranchController::class, 'update']);
        $router->delete('/branches/{id}', [BranchController::class, 'destroy']);

        $router->get('/ho/branch-performance', [HoController::class, 'branchPerformance']);
        $router->get('/ho/portfolio', [HoController::class, 'portfolio']);
        $router->get('/ho/transfers', [HoController::class, 'transfers']);
        $router->post('/ho/transfers', [HoController::class, 'transfer']);
    });
});
