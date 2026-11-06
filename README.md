# Advanced Pharmacy Management Suite

A multi-tenant, subscription-based Retail Pharmacy + Distributor management platform.

- **Frontend:** Angular 21 + Bootstrap 5
- **Backend:** Structured Core PHP 8.2 (custom MVC + router, PDO, JWT)
- **Database:** MySQL/MariaDB (3-database architecture on XAMPP)

## Architecture

Multi-tenant SaaS, isolated by `client_id` + `branch_id`, across three databases:

| Database        | Purpose |
|-----------------|---------|
| `pharma_master` | Global, shared. Product Master, Generic Master, substitute mapping, Schedule (H/H1/X/G/Restricted) categorization. Single source of truth. |
| `pharma_ho`     | Tenant-keyed. Clients/subscriptions, drug license, branches/units, employees/users, roles, centralized dashboard, interbranch transfers, central portfolio. |
| `pharma_ops`    | Tenant-keyed. Purchase, sales/billing, batch-wise inventory, accounts, prescriptions, profit. |

**Product binding flow:** a new product is written to `pharma_master`, then bound to the tenant in `pharma_ops`; each client only sees their bound subset.

## Project layout

```
Advanced Pharmacy/
├── pharmacy-api/        # Core PHP backend (REST API)
├── pharmacy-frontend/   # Angular 21 SPA
├── database/            # SQL schema & seed files (per database)
├── tools/               # Dev tooling (auto-commit, db migrate)
└── README.md
```

## Local setup (XAMPP)

### Prerequisites
- XAMPP with PHP 8.2 (located at `C:\xampp1`)
- MySQL/MariaDB running
- Node.js 20+ and npm
- Angular CLI 21

### 1. Backend
```powershell
cd pharmacy-api
# install Composer dependencies (php-jwt, tcpdf, dotenv)
php composer.phar install
# copy and edit environment
copy config\.env.example config\.env
```
Serve the API (built-in PHP server for dev):
```powershell
C:\xampp1\php\php.exe -S localhost:8080 -t public
```

### 2. Database
```powershell
cd database
.\migrate.ps1          # creates databases + applies schema
```

### 3. Frontend
```powershell
cd pharmacy-frontend
npm install
npm start              # ng serve -> http://localhost:4200
```

## Live PHP deploy

Upload backend files to the demo server (preserves live `.env` and `storage/`):

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\deploy-php.ps1
```

Full server paths, SSH/FTP logins, and verify steps: **[docs/DEPLOY-PHP.md](docs/DEPLOY-PHP.md)**

## Versioning

Automatic local commits via `tools/auto-commit.ps1`. See that script for usage.

## Development phases

0. Environment & repo setup
1. Foundation, Auth & Dashboard
2. Masters
3. Purchase (+ import via Gmail/CSV/Excel/PDF OCR)
4. Sales & Billing (+ prescription, TCPDF)
5. Accounts & Reports
6. Multi-branch / HO centralization
7. Refill & Reminders
8. Delivery, Online Booking & ePharmacy
