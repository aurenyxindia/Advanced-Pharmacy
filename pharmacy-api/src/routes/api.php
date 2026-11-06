<?php

declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AuraAiController;
use App\Controllers\AuraGharTakController;
use App\Controllers\AgtDeliveryController;
use App\Controllers\RetailProxyController;
use App\Middleware\PublicRateLimitMiddleware;
use App\Controllers\AuthController;
use App\Controllers\BarcodeController;
use App\Controllers\BranchController;
use App\Controllers\CreditNoteController;
use App\Controllers\DebitNoteController;
use App\Controllers\HoController;
use App\Controllers\PaymentController;
use App\Controllers\IpdController;
use App\Controllers\CustomerController;
use App\Controllers\DailyClosingController;
use App\Controllers\DashboardController;
use App\Controllers\DispensingController;
use App\Controllers\DoctorController;
use App\Controllers\HospitalController;
use App\Controllers\EmployeeController;
use App\Controllers\GenericController;
use App\Controllers\HsnCodeController;
use App\Controllers\ManufacturerController;
use App\Controllers\ImportController;
use App\Controllers\MetaController;
use App\Controllers\MrController;
use App\Controllers\OrderController;
use App\Controllers\PrescriptionController;
use App\Controllers\PartnerController;
use App\Controllers\PartnerSaleReceiveController;
use App\Controllers\PublicController;
use App\Controllers\AuraGharTakUserController;
use App\Controllers\PurchaseController;
use App\Controllers\PurchaseOrderController;
use App\Controllers\MinInventoryController;
use App\Controllers\RateMarginController;
use App\Controllers\RefillController;
use App\Controllers\ReportController;
use App\Controllers\SalesController;
use App\Controllers\StockController;
use App\Controllers\SupportController;
use App\Controllers\ProfileController;
use App\Controllers\UserRightsController;
use App\Controllers\ProductController;
use App\Controllers\RegistrationController;
use App\Controllers\AdminRegistrationController;
use App\Controllers\SubscriptionPlanController;
use App\Controllers\SubscriptionController;
use App\Controllers\DistributorController;
use App\Controllers\ReturnDeductionController;
use App\Controllers\RetailSalesReturnController;
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
    $router->post('/forgot-password/send-otp', [AuthController::class, 'forgotPasswordSendOtp']);
    $router->post('/forgot-password/reset', [AuthController::class, 'forgotPasswordReset']);
    $router->post('/refresh', [AuthController::class, 'refresh']);
    $router->post('/logout', [AuthController::class, 'logout']);
});

// ---- Gmail OAuth callback (browser redirect; no JWT) ----
$router->get('/api/import/gmail/callback', [ImportController::class, 'gmailCallback']);

// ---- Razorpay webhook (no JWT; signature verified in controller) ----
$router->post('/api/subscription/webhook', [SubscriptionController::class, 'webhook']);


// ---- Paperless Share Stock receive (API-key; CORS OPTIONS handled globally by App) ----
$router->get('/api/partner/sale-receive', [PartnerSaleReceiveController::class, 'handle']);
$router->post('/api/partner/sale-receive', [PartnerSaleReceiveController::class, 'handle']);

// ---- Public ePharmacy (no authentication) ----
$router->group(['prefix' => '/api/public', 'middleware' => [PublicRateLimitMiddleware::class]], function (Router $router): void {
    $router->get('/branches', [PublicController::class, 'branches']);
    $router->get('/store-locations', [PublicController::class, 'storeLocations']);
    $router->post('/store-locations/stubs', [PublicController::class, 'syncStoreLocationStubs']);
    $router->get('/catalog', [PublicController::class, 'catalog']);
    $router->get('/catalog/substitutes', [PublicController::class, 'catalogSubstitutes']);
    $router->post('/orders', [PublicController::class, 'placeOrder']);
    $router->get('/orders/track', [PublicController::class, 'track']);
    $router->post('/orders/cancel', [PublicController::class, 'cancelOrder']);
    $router->get('/agt/shop', [AuraGharTakController::class, 'publicShop']);
    $router->post('/agt/orders', [AuraGharTakController::class, 'publicPlaceOrder']);
    $router->post('/agt/orders/cancel', [AuraGharTakController::class, 'publicCancelOrder']);
    $router->get('/agt/prescription/{file}', [AuraGharTakController::class, 'publicPrescription']);
    $router->get('/agt/live', [AgtDeliveryController::class, 'publicLive']);
    $router->get('/retail/clients', [RetailProxyController::class, 'clients']);
    $router->get('/retail/stock', [RetailProxyController::class, 'stock']);
    $router->post('/retail/orders', [RetailProxyController::class, 'placeOrder']);
    $router->get('/retail/orders', [RetailProxyController::class, 'ordersByPhone']);
    $router->get('/subscription-plans', [SubscriptionPlanController::class, 'publicIndex']);
    $router->post('/agt-users/register', [AuraGharTakUserController::class, 'register']);
    $router->post('/agt-users/login', [AuraGharTakUserController::class, 'login']);
    $router->post('/agt-users/logout', [AuraGharTakUserController::class, 'logout']);
    $router->get('/agt-users/me', [AuraGharTakUserController::class, 'me']);
    $router->post('/agt-users/profile', [AuraGharTakUserController::class, 'updateProfile']);
    $router->get('/agt-users/photo/{file}', [AuraGharTakUserController::class, 'photo']);
});

// ---- Partner API (EyeFlow EMR etc.) — API key maps to one pharmacy client ----
$router->group(['prefix' => '/api/partner/v1', 'middleware' => ['partner']], function (Router $router): void {
    $router->get('/me', [PartnerController::class, 'me']);
    $router->get('/branches', [PartnerController::class, 'branches']);
    // /stock/all before /stock so it is matched as its own route
    $router->get('/stock/all', [PartnerController::class, 'stockAll']);
    $router->get('/stock', [PartnerController::class, 'stock']);
    $router->get('/stock/products', [PartnerController::class, 'products']);
    $router->get('/stock/search', [PartnerController::class, 'search']);
});

// ---- Authenticated -----------------------------------------------------
$router->group(['prefix' => '/api', 'middleware' => ['auth']], function (Router $router): void {
    $router->get('/auth/me', [AuthController::class, 'me']);
    $router->get('/dashboard/summary', [DashboardController::class, 'summary']);

    // Daily Closing (day-end reconciliation)
    $router->get('/daily-closing/summary', [DailyClosingController::class, 'summary']);
    $router->post('/daily-closing/day-end', [DailyClosingController::class, 'dayEnd']);
    $router->get('/daily-closing/report', [DailyClosingController::class, 'report']);
    $router->get('/daily-closing/export', [DailyClosingController::class, 'exportReportExcel']);
    $router->get('/daily-closing/export-row', [DailyClosingController::class, 'exportRowExcel']);

    // ---- Lookups / metadata ----
    $router->get('/meta/schedule-categories', [MetaController::class, 'scheduleCategories']);
    $router->get('/meta/profile', [MetaController::class, 'profile']);
    $router->get('/profile', [ProfileController::class, 'show']);
    $router->put('/profile', [ProfileController::class, 'update']);
    $router->post('/profile/upload/{type}', [ProfileController::class, 'upload']);
    $router->get('/profile/assets/{clientId}/{type}', [ProfileController::class, 'asset']);
    // Platform admin only (client 1 / branch 1) — activate / inactivate pharmacies.
    $router->get('/admin/registrations', [AdminRegistrationController::class, 'pending']);
    $router->post('/admin/registrations/{clientId}/activate', [AdminRegistrationController::class, 'activate']);
    $router->post('/admin/registrations/{clientId}/toggle', [AdminRegistrationController::class, 'toggle']);
    $router->get('/admin/subscription-plans', [SubscriptionPlanController::class, 'index']);
    $router->post('/admin/subscription-plans', [SubscriptionPlanController::class, 'store']);
    $router->put('/admin/subscription-plans/{code}', [SubscriptionPlanController::class, 'update']);
    $router->get('/subscription/status', [SubscriptionController::class, 'status']);
    $router->post('/subscription/checkout', [SubscriptionController::class, 'checkout']);
    $router->post('/subscription/verify', [SubscriptionController::class, 'verify']);
    $router->post('/subscription/activate', [SubscriptionController::class, 'activate']); // disabled → 410
    $router->get('/subscription/quote', [SubscriptionController::class, 'quote']);

    $router->get('/user-rights/modules', [UserRightsController::class, 'modules']);
    $router->get('/user-rights/users', [UserRightsController::class, 'users']);
    $router->get('/user-rights/users/{id}', [UserRightsController::class, 'showUser']);
    $router->post('/user-rights/users', [UserRightsController::class, 'saveUser']);
    $router->delete('/user-rights/users/{id}', [UserRightsController::class, 'deleteUser']);
    $router->get('/user-rights/me', [UserRightsController::class, 'myRights']);
    $router->get('/meta/pack-sizes', [MetaController::class, 'packSizes']);
    $router->get('/meta/parse-pack', [MetaController::class, 'parsePack']);
    $router->get('/meta/branches', [MetaController::class, 'branches']);
    $router->get('/meta/roles', [MetaController::class, 'roles']);

    // ---- Master catalog: Generics (global) ----
    $router->get('/generics/stats', [GenericController::class, 'stats']);
    $router->get('/generics', [GenericController::class, 'index']);
    $router->post('/generics', [GenericController::class, 'store']);
    $router->get('/generics/{id}', [GenericController::class, 'show']);
    $router->put('/generics/{id}', [GenericController::class, 'update']);
    $router->delete('/generics/{id}', [GenericController::class, 'destroy']);

    // ---- Master catalog: HSN codes (global) ----
    $router->get('/hsn-codes', [HsnCodeController::class, 'index']);
    $router->post('/hsn-codes', [HsnCodeController::class, 'store']);
    $router->get('/hsn-codes/{id}', [HsnCodeController::class, 'show']);
    $router->put('/hsn-codes/{id}', [HsnCodeController::class, 'update']);
    $router->delete('/hsn-codes/{id}', [HsnCodeController::class, 'destroy']);

    // ---- Master catalog: Manufacturers (global) ----
    $router->get('/manufacturers', [ManufacturerController::class, 'index']);
    $router->post('/manufacturers', [ManufacturerController::class, 'store']);
    $router->get('/manufacturers/{id}', [ManufacturerController::class, 'show']);
    $router->put('/manufacturers/{id}', [ManufacturerController::class, 'update']);
    $router->delete('/manufacturers/{id}', [ManufacturerController::class, 'destroy']);

    // ---- Master catalog: Products (global) ----
    // Static segments registered before the {id} routes so they match first.
    $router->get('/products/search', [ProductController::class, 'search']);
    $router->get('/products/stats', [ProductController::class, 'stats']);
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
    $router->get('/customers/lookup', [CustomerController::class, 'lookup']);
    $router->post('/customers', [CustomerController::class, 'store']);
    $router->get('/customers/{id}', [CustomerController::class, 'show']);
    $router->put('/customers/{id}', [CustomerController::class, 'update']);
    $router->delete('/customers/{id}', [CustomerController::class, 'destroy']);

    $router->get('/doctors', [DoctorController::class, 'index']);
    $router->post('/doctors', [DoctorController::class, 'store']);
    $router->get('/doctors/{id}', [DoctorController::class, 'show']);
    $router->put('/doctors/{id}', [DoctorController::class, 'update']);
    $router->delete('/doctors/{id}', [DoctorController::class, 'destroy']);

    // ---- Hospital Master (Retail master/hospital; default hospital for Dispensing) ----
    $router->get('/hospitals', [HospitalController::class, 'index']);
    $router->post('/hospitals', [HospitalController::class, 'store']);
    $router->get('/hospitals/{id}', [HospitalController::class, 'show']);
    $router->put('/hospitals/{id}', [HospitalController::class, 'update']);
    $router->delete('/hospitals/{id}', [HospitalController::class, 'destroy']);

    // ---- Master catalog: Distributors (tenant-scoped in master DB) ----
    $router->get('/distributors', [DistributorController::class, 'index']);
    $router->post('/distributors', [DistributorController::class, 'store']);
    $router->get('/distributors/{id}', [DistributorController::class, 'show']);
    $router->put('/distributors/{id}', [DistributorController::class, 'update']);
    $router->delete('/distributors/{id}', [DistributorController::class, 'destroy']);
    // Legacy alias (purchase screens may still call /suppliers)
    $router->get('/suppliers', [DistributorController::class, 'index']);
    $router->post('/suppliers', [DistributorController::class, 'store']);
    $router->get('/suppliers/{id}', [DistributorController::class, 'show']);
    $router->put('/suppliers/{id}', [DistributorController::class, 'update']);
    $router->delete('/suppliers/{id}', [DistributorController::class, 'destroy']);

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
    $router->put('/purchases/{id}', [PurchaseController::class, 'update']);
    $router->delete('/purchases/{id}', [PurchaseController::class, 'destroy']);
    $router->get('/purchases/{id}/barcodes', [BarcodeController::class, 'purchaseLabels']);
    $router->get('/purchases/{id}/label-barcodes', [BarcodeController::class, 'purchaseThermalLabels']);

    // ---- Purchase Order (Retail /purchaseOrder: Customise PO + Auto-Purchase short book) ----
    $router->get('/purchase-orders', [PurchaseOrderController::class, 'index']);
    $router->post('/purchase-orders', [PurchaseOrderController::class, 'store']);
    $router->get('/purchase-orders/short-book', [PurchaseOrderController::class, 'shortBook']);
    $router->get('/purchase-orders/sales-range', [PurchaseOrderController::class, 'salesRange']);
    $router->get('/purchase-orders/{id}/pdf', [PurchaseOrderController::class, 'pdf']);
    $router->get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show']);
    $router->put('/purchase-orders/{id}', [PurchaseOrderController::class, 'update']);

    // ---- Hospital Dispensing (Purchase → Dispensing; stock out, no payment) ----
    $router->get('/dispensing', [DispensingController::class, 'index']);
    $router->get('/dispensing/view', [DispensingController::class, 'show']);
    $router->get('/dispensing/report-pdf', [DispensingController::class, 'reportPdf']);
    $router->post('/dispensing', [DispensingController::class, 'store']);

    // MR (Medical Representative)
    $router->get('/mr/dashboard', [MrController::class, 'dashboard']);
    $router->get('/mr/visits', [MrController::class, 'visits']);
    $router->post('/mr/visits', [MrController::class, 'saveVisit']);
    $router->get('/mr/stock', [MrController::class, 'stock']);
    $router->get('/mr/previous-rates', [MrController::class, 'previousRates']);
    $router->get('/mr/orders/next-no', [MrController::class, 'nextOrderNo']);
    $router->get('/mr/orders', [MrController::class, 'orders']);
    $router->post('/mr/orders', [MrController::class, 'saveOrder']);
    $router->get('/mr/orders/{id}/pdf', [MrController::class, 'orderPdf']);
    $router->get('/mr/orders/{id}', [MrController::class, 'showOrder']);
    $router->delete('/mr/orders/{id}', [MrController::class, 'deleteOrder']);
    $router->get('/mr/stock-followups', [MrController::class, 'stockFollowups']);
    $router->post('/mr/stock-followups', [MrController::class, 'saveStockFollowup']);
    $router->get('/mr/monitor', [MrController::class, 'monitor']);
    $router->get('/mr/daily-reports', [MrController::class, 'dailyReports']);
    $router->post('/mr/daily-reports', [MrController::class, 'saveDailyReport']);
    $router->get('/mr/outstanding', [MrController::class, 'outstanding']);
    $router->post('/mr/outstanding-followups', [MrController::class, 'saveOutstandingFollowup']);
    $router->get('/mr/customers', [MrController::class, 'customers']);

    // ---- Minimum Inventory Trigger (Retail mi_trigger) → Dashboard alert → Auto-Purchase ----
    $router->get('/min-inventory', [MinInventoryController::class, 'index']);
    $router->get('/min-inventory/alerts', [MinInventoryController::class, 'alerts']);
    $router->get('/min-inventory/products', [MinInventoryController::class, 'products']);
    $router->post('/min-inventory', [MinInventoryController::class, 'store']);
    $router->delete('/min-inventory/{id}', [MinInventoryController::class, 'destroy']);

    // ---- Inward Stock (Paperless shared bills — approve / reject) ----
    $router->get('/inward-stock', [PartnerSaleReceiveController::class, 'index']);
    $router->get('/inward-stock/{id}', [PartnerSaleReceiveController::class, 'show']);
    $router->post('/inward-stock/{id}/approve', [PartnerSaleReceiveController::class, 'approve']);
    $router->post('/inward-stock/{id}/reject', [PartnerSaleReceiveController::class, 'reject']);

    // ---- Purchase import (CSV / Excel text / AI / Gmail) ----
    $router->get('/import/purchase/formats', [ImportController::class, 'purchaseFormats']);
    $router->post('/import/purchase/parse', [ImportController::class, 'purchaseParse']);
    $router->post('/import/purchase/match', [ImportController::class, 'purchaseMatch']);
    $router->get('/import/purchase/check-invoice', [ImportController::class, 'checkInvoice']);
    $router->post('/import/purchase/ai', [ImportController::class, 'purchaseAi']);
    $router->get('/import/purchase/ai/status', [ImportController::class, 'purchaseAiStatus']);
    $router->post('/import/purchase/csv', [ImportController::class, 'purchaseCsv']);
    $router->post('/import/purchase/gmail', [ImportController::class, 'purchaseGmail']);
    $router->post('/import/purchase/pdf', [ImportController::class, 'purchasePdf']);
    $router->get('/import/gmail/status', [ImportController::class, 'gmailStatus']);
    $router->get('/import/gmail/start', [ImportController::class, 'gmailStart']);
    $router->post('/import/gmail/disconnect', [ImportController::class, 'gmailDisconnect']);
    $router->get('/import/gmail/list', [ImportController::class, 'gmailList']);
    $router->get('/import/gmail/download', [ImportController::class, 'gmailDownload']);

    // ---- Sales / Billing ----
    $router->get('/sales/scan', [SalesController::class, 'scan']);
    $router->post('/sales/quick-purchase', [SalesController::class, 'quickPurchase']);
    $router->get('/sales/search-products', [SalesController::class, 'searchProducts']);
    $router->get('/sales/prev-customer-rates', [SalesController::class, 'prevCustomerRates']);
    $router->get('/sales/patient-history', [SalesController::class, 'patientRetailHistory']);
    $router->get('/sales', [SalesController::class, 'index']);
    $router->post('/sales', [SalesController::class, 'store']);
    $router->put('/sales/{id}', [SalesController::class, 'update']);
    $router->delete('/sales/{id}', [SalesController::class, 'destroy']);
    $router->get('/sales/{id}/substitutes', [SalesController::class, 'substitutes']);
    $router->get('/sales/{id}/invoice', [SalesController::class, 'invoice']);
    $router->get('/sales/{id}/invoice-dual', [SalesController::class, 'invoiceDual']);
    $router->get('/sales/{id}/invoice-dotmatrix', [SalesController::class, 'invoiceDotMatrix']);
    $router->post('/sales/{id}/whatsapp', [SalesController::class, 'whatsapp']);
    $router->patch('/sales/{id}/payment-mode', [SalesController::class, 'updatePaymentMode']);
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

    // ---- AuraGharTak (home delivery + token shop) ----
    $router->get('/agt/customers', [AuraGharTakController::class, 'customers']);
    $router->post('/agt/share-link', [AuraGharTakController::class, 'createShare']);
    $router->get('/agt/order-counts', [AuraGharTakController::class, 'orderCounts']);
    $router->get('/agt/orders', [AuraGharTakController::class, 'listOrders']);
    $router->get('/agt/orders/detail', [AuraGharTakController::class, 'showOrder']);
    $router->get('/agt/orders/pending', [AuraGharTakController::class, 'pendingByPatient']);
    $router->post('/agt/orders/mark-ready', [AuraGharTakController::class, 'markReady']);
    $router->post('/agt/orders/status', [AuraGharTakController::class, 'updateStatus']);
    $router->get('/agt/settings', [AuraGharTakController::class, 'branchSettings']);
    $router->post('/agt/settings', [AuraGharTakController::class, 'saveBranchSettings']);
    $router->get('/agt/store-location', [AuraGharTakController::class, 'storeLocation']);
    $router->post('/agt/store-location', [AuraGharTakController::class, 'saveStoreLocation']);
    $router->get('/agt/riders', [AgtDeliveryController::class, 'riders']);
    $router->post('/agt/orders/rider', [AgtDeliveryController::class, 'assignRider']);
    $router->get('/agt/rider/orders', [AgtDeliveryController::class, 'riderOrders']);
    $router->post('/agt/rider/start', [AgtDeliveryController::class, 'riderStart']);
    $router->post('/agt/rider/delivered', [AgtDeliveryController::class, 'riderDelivered']);
    $router->post('/agt/rider/location', [AgtDeliveryController::class, 'riderLocation']);

    // ---- AuraAI (pharmacy assistant chat) ----
    $router->get('/aura-ai/status', [AuraAiController::class, 'status']);
    $router->post('/aura-ai/chat', [AuraAiController::class, 'chat']);

    // ---- Support tickets (client 1 = support admin; others see own tickets only) ----
    $router->get('/support/poll', [SupportController::class, 'poll']);
    $router->get('/support/tickets', [SupportController::class, 'index']);
    $router->post('/support/tickets', [SupportController::class, 'store']);
    $router->post('/support/tickets/{id}/status', [SupportController::class, 'updateStatus']);
    $router->get('/support/tickets/{id}/replies', [SupportController::class, 'replies']);
    $router->post('/support/tickets/{id}/replies', [SupportController::class, 'postReply']);
    $router->get('/support/tickets/{id}/files/{file}', [SupportController::class, 'file']);

    // ---- Refill reminders ----
    $router->get('/refills/summary', [RefillController::class, 'summary']);
    $router->get('/refills', [RefillController::class, 'index']);
    $router->post('/refills', [RefillController::class, 'store']);
    $router->put('/refills/{id}', [RefillController::class, 'update']);

    // ---- Payments (vouchers) & Notes ----
    $router->get('/payments', [PaymentController::class, 'index']);
    $router->post('/payments', [PaymentController::class, 'store']);
    $router->get('/payments/dues/distributors', [PaymentController::class, 'distributorDues']);
    $router->get('/payments/dues/customers', [PaymentController::class, 'customerDues']);
    $router->get('/payments/dues/wholesale-customers', [PaymentController::class, 'wholesaleCustomerDues']);
    $router->post('/payments/settle/distributor', [PaymentController::class, 'settleDistributor']);
    $router->post('/payments/settle/customer', [PaymentController::class, 'settleCustomer']);
    $router->post('/payments/settle/wholesale-customer', [PaymentController::class, 'settleWholesaleCustomer']);
    $router->get('/payments/history', [PaymentController::class, 'history']);
    $router->get('/payments/allocations/{id}/print', [PaymentController::class, 'printReceipt']);
    $router->get('/payments/{id}', [PaymentController::class, 'show']);
    $router->put('/payments/{id}', [PaymentController::class, 'update']);
    $router->delete('/payments/{id}', [PaymentController::class, 'destroy']);

    $router->get('/credit-notes', [CreditNoteController::class, 'index']);
    $router->post('/credit-notes', [CreditNoteController::class, 'store']);
    $router->get('/credit-notes/open', [CreditNoteController::class, 'openNotes']);
    $router->get('/credit-notes/customers', [CreditNoteController::class, 'customers']);
    $router->get('/credit-notes/return-products', [CreditNoteController::class, 'returnProducts']);
    $router->get('/credit-notes/return-batches', [CreditNoteController::class, 'returnBatches']);
    $router->post('/credit-notes/return', [CreditNoteController::class, 'saveReturn']);
    $router->put('/credit-notes/{id}/return', [CreditNoteController::class, 'updateReturn']);
    $router->get('/credit-notes/{id}/pdf', [CreditNoteController::class, 'pdf']);
    $router->get('/credit-notes/{id}', [CreditNoteController::class, 'show']);
    $router->put('/credit-notes/{id}', [CreditNoteController::class, 'update']);
    $router->delete('/credit-notes/{id}', [CreditNoteController::class, 'destroy']);

    $router->get('/return-deductions', [ReturnDeductionController::class, 'index']);
    $router->post('/return-deductions', [ReturnDeductionController::class, 'store']);

    $router->get('/retail-sales-returns/sales', [RetailSalesReturnController::class, 'sales']);
    $router->get('/retail-sales-returns/sales/{id}', [RetailSalesReturnController::class, 'saleDetails']);
    $router->post('/retail-sales-returns', [RetailSalesReturnController::class, 'store']);
    $router->post('/retail-sales-returns/manual', [RetailSalesReturnController::class, 'storeManual']);
    $router->get('/retail-sales-returns/history', [RetailSalesReturnController::class, 'history']);
    $router->get('/retail-sales-returns/history/{id}/pdf', [RetailSalesReturnController::class, 'historyPdf']);
    $router->get('/retail-sales-returns/history/{id}', [RetailSalesReturnController::class, 'historyDetails']);

    $router->get('/debit-notes', [DebitNoteController::class, 'index']);
    $router->post('/debit-notes', [DebitNoteController::class, 'store']);
    $router->get('/debit-notes/open', [DebitNoteController::class, 'openNotes']);
    $router->get('/debit-notes/return-products', [DebitNoteController::class, 'returnProducts']);
    $router->get('/debit-notes/return-batches', [DebitNoteController::class, 'returnBatches']);
    $router->get('/debit-notes/return-suppliers', [DebitNoteController::class, 'returnSuppliers']);
    $router->post('/debit-notes/return', [DebitNoteController::class, 'saveReturn']);
    $router->put('/debit-notes/{id}/return', [DebitNoteController::class, 'updateReturn']);
    $router->get('/debit-notes/{id}/pdf', [DebitNoteController::class, 'pdf']);
    $router->get('/debit-notes/{id}', [DebitNoteController::class, 'show']);
    $router->put('/debit-notes/{id}', [DebitNoteController::class, 'update']);
    $router->delete('/debit-notes/{id}', [DebitNoteController::class, 'destroy']);

    // ---- Stock book / opening stock ----
    $router->get('/stock/opening', [StockController::class, 'openingList']);
    $router->get('/stock/opening/match-products', [StockController::class, 'openingMatchProducts']);
    $router->post('/stock/opening', [StockController::class, 'openingStore']);
    $router->post('/stock/opening/bulk', [StockController::class, 'openingBulkStore']);
    $router->put('/stock/opening/{id}', [StockController::class, 'openingUpdate']);
    $router->delete('/stock/opening/{id}', [StockController::class, 'openingDestroy']);
    $router->post('/stock/write-off-expired', [StockController::class, 'writeOffExpired']);
    $router->get('/stock/adjustment/batches', [StockController::class, 'adjustmentBatches']);
    $router->post('/stock/adjustment', [StockController::class, 'adjustmentStore']);
    $router->post('/stock/repair-free-pack-units', [StockController::class, 'repairFreePackUnits']);

    // ---- Reports ----
    $router->get('/reports/branches', [ReportController::class, 'branches']);
    $router->get('/reports/filter-options', [ReportController::class, 'filterOptions']);
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
    $router->get('/reports/cash-counter-day', [ReportController::class, 'cashCounterDay']);
    $router->get('/reports/online-sales', [ReportController::class, 'onlineSales']);
    $router->get('/reports/chronic', [ReportController::class, 'chronic']);
    // Retail parity analytics
    $router->get('/reports/date-wise-purchase', [ReportController::class, 'dateWisePurchase']);
    $router->get('/reports/all-purchase', [ReportController::class, 'allPurchase']);
    $router->get('/reports/distributor-wise-purchase', [ReportController::class, 'distributorWisePurchase']);
    $router->get('/reports/product-wise-purchase', [ReportController::class, 'productWisePurchase']);
    $router->get('/reports/manufacturer-wise-purchase', [ReportController::class, 'manufacturerWisePurchase']);
    $router->get('/reports/category-wise-purchase', [ReportController::class, 'categoryWisePurchase']);
    $router->get('/reports/date-wise-sale', [ReportController::class, 'dateWiseSale']);
    $router->get('/reports/all-product-wise-sale', [ReportController::class, 'allProductWiseSale']);
    $router->get('/reports/product-wise-sale', [ReportController::class, 'productWiseSale']);
    $router->get('/reports/manufacturer-wise-sale', [ReportController::class, 'manufacturerWiseSale']);
    $router->get('/reports/category-wise-sale', [ReportController::class, 'categoryWiseSale']);
    $router->get('/reports/customer-wise-sale', [ReportController::class, 'customerWiseSale']);
    $router->get('/reports/free-qty', [ReportController::class, 'freeQty']);
    $router->get('/reports/purchase-gst', [ReportController::class, 'purchaseGst']);
    $router->get('/reports/sales-gst', [ReportController::class, 'salesGst']);
    $router->get('/reports/sale-gst-slab', [ReportController::class, 'saleGstSlabWise']);
    $router->get('/reports/purchase-gst-slab', [ReportController::class, 'purchaseGstSlabWise']);
    $router->get('/reports/all-product-sale-gst', [ReportController::class, 'allProductSaleGst']);
    $router->get('/reports/all-product-purchase-gst', [ReportController::class, 'allProductPurchaseGst']);
    $router->get('/reports/distributor-payments', [ReportController::class, 'distributorPayments']);
    $router->get('/reports/customer-payments', [ReportController::class, 'customerPayments']);
    $router->get('/reports/profit-loss', [ReportController::class, 'profitLoss']);
    $router->get('/reports/distributor-profit-loss', [ReportController::class, 'distributorProfitLoss']);
    $router->get('/reports/expired-stock', [ReportController::class, 'expiredStock']);
    $router->get('/reports/short-stock', [ReportController::class, 'shortStock']);
    $router->get('/reports/stock-adjustment-audit', [ReportController::class, 'stockAdjustmentAudit']);
    $router->get('/reports/purchase-edit-audit', [ReportController::class, 'purchaseEditAudit']);
    $router->get('/reports/hospital-dispensing', [ReportController::class, 'hospitalDispensing']);
    $router->get('/reports/near-expiry', [ReportController::class, 'nearExpiry']);
    $router->get('/reports/manufacturer-wise-stock', [ReportController::class, 'manufacturerWiseStock']);
    $router->get('/reports/product-transaction', [ReportController::class, 'productTransaction']);
    $router->get('/reports/sale-gst-slab-bill', [ReportController::class, 'saleGstSlabBillWise']);
    $router->get('/reports/purchase-gst-slab-bill', [ReportController::class, 'purchaseGstSlabBillWise']);
    $router->get('/reports/hsn-b2c', [ReportController::class, 'hsnB2c']);
    $router->get('/reports/hsn-b2b', [ReportController::class, 'hsnB2b']);
    $router->get('/reports/b2b-invoices', [ReportController::class, 'b2bInvoices']);
    $router->get('/reports/b2c-small', [ReportController::class, 'b2cSmall']);
    $router->get('/reports/document-issued', [ReportController::class, 'documentIssued']);
    $router->get('/reports/distributor-wise-sale', [ReportController::class, 'distributorWiseSale']);
    $router->get('/reports/distributor-wise-stock', [ReportController::class, 'distributorWiseStock']);
    $router->get('/reports/sale-stock', [ReportController::class, 'saleStock']);
    $router->get('/reports/doctor-margin', [ReportController::class, 'doctorMargin']);
    $router->get('/reports/area-wise-payment', [ReportController::class, 'areaWisePayment']);
    $router->get('/reports/accurate-profit-loss', [ReportController::class, 'accurateProfitLoss']);

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
        $router->get('/ho/branch-stock', [HoController::class, 'branchStock']);
        $router->get('/ho/transfers', [HoController::class, 'transfers']);
        $router->get('/ho/transfers/{id}', [HoController::class, 'transferShow']);
        $router->post('/ho/transfers', [HoController::class, 'transfer']);
    });
});
