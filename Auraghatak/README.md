# AuraGharTak — Patient Android App

Consumer app for **Paperless Pharmacy** customers who order medicines from local pharmacies running Advanced Pharmacy / AuraGharTak.

## How it works (same backend as the web shop)

1. A pharmacy using **AuraGharTak** creates a **personal stock link** for the patient (WhatsApp or copy link from the ERP).
2. The link contains a **token** (e.g. `…/agt-shop?token=…`).
3. This app opens that token, loads **live in-stock items** for that store, and places an order via the **existing public API** — no new PHP.

| Action | API (no auth) |
|--------|----------------|
| Load pharmacy + catalog | `GET /api/public/agt/shop?token=&q=` |
| Place order | `POST /api/public/agt/orders` — `token`, `items[]`, `patient_note`, `prescription_base64` |

Responses follow `{ "success", "message", "data" }` like the Angular `agt-shop` page.

## What the app adds (client-only)

- Paperless Pharmacy / AuraGharTak branding and mobile UX
- Save **My pharmacies** (tokens + names) on device
- Deep links: `https://…/agt-shop?token=…` and `auraghatak://shop?token=…`
- Local **order history** (order numbers you placed from this phone)
- Optional browse **active Paperless branches** (`GET /api/public/branches`) to discover stores — ordering still needs the pharmacy’s AuraGharTak link

## Configure API base URL

Edit `app/build.gradle.kts` → `buildConfigField` `API_BASE_URL` (default: `https://pp.gmpserver.com/api`).

## Build

1. Open the `Auraghatak` folder in **Android Studio** (Ladybug or newer).
2. Let Gradle sync and download dependencies.
3. Run on device/emulator (API 26+).

## Play Store

- Package: `in.paperlesspharmacy.auraghatak`
- Add intent filters for your production `agt-shop` host in `AndroidManifest.xml` when the live domain is fixed.
