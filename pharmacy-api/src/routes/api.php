<?php

declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\CustomerController;
use App\Controllers\DashboardController;
use App\Controllers\DoctorController;
use App\Controllers\EmployeeController;
use App\Controllers\GenericController;
use App\Controllers\MetaController;
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

// ---- Authenticated -----------------------------------------------------
$router->group(['prefix' => '/api', 'middleware' => ['auth']], function (Router $router): void {
    $router->get('/auth/me', [AuthController::class, 'me']);
    $router->get('/dashboard/summary', [DashboardController::class, 'summary']);

    // ---- Lookups / metadata ----
    $router->get('/meta/schedule-categories', [MetaController::class, 'scheduleCategories']);
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

    $router->get('/accounts', [AccountController::class, 'index']);
    $router->post('/accounts', [AccountController::class, 'store']);
    $router->get('/accounts/{id}', [AccountController::class, 'show']);
    $router->put('/accounts/{id}', [AccountController::class, 'update']);
    $router->delete('/accounts/{id}', [AccountController::class, 'destroy']);

    // ---- Employees / users (HO) - admin only ----
    $router->group(['middleware' => ['role:ho_admin,branch_admin']], function (Router $router): void {
        $router->get('/employees', [EmployeeController::class, 'index']);
        $router->post('/employees', [EmployeeController::class, 'store']);
        $router->put('/employees/{id}', [EmployeeController::class, 'update']);
        $router->delete('/employees/{id}', [EmployeeController::class, 'destroy']);
    });
});
