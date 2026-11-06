# Paste this prompt in the **Retail** project (Cursor / agent)

---

## Task: AuraGharTak Android → Retail AuraGharTak orders (PHP only)

**Context:** The AuraGharTak Android app places **retail pharmacy** orders by opening WhatsApp and (in app v1.5.5+) calling the **serverdev EMR stock API**. Retail staff see orders in **AuraGharTak** module (`aura_ghar_tak.php?type=listOrders`), which reads table **`agt_orders`** / **`agt_order_items`** (same as web share-link orders). Today Android orders are **not** inserted into `agt_orders`, so Retail shows nothing.

**Do not change Angular UI.** **Only** `php/server/` — minimal, production-safe.

**Same DB as:** `aura_ghar_tak.php` and `db.php` (`paperlesspharmac_retail` on live).

### Requirements

1. **New file:** `php/server/emr_retail_app_orders.inc.php`  
   - Functions used by `emr_stock_api.php` (must rely on existing helpers in that file: `emr_json`, `emr_fail`, `emr_esc`, `emr_as_of`).
   - **`emr_retail_place_order($conn, $config, $rawInput)`**  
     - POST JSON body fields: `client_no`, optional `order_no` (e.g. `RTL-20251001-1234`), `patient_name`, `patient_contact` (10 digits), `patient_address`, optional `pincode`, `note`, `delivery_slot`, `items[]` with `p_name`/`name`, `qty`, `unit_mrp`/`rate`.  
     - Prefix `patient_note` with `AuraGharTak Android`.  
     - Insert into **`agt_orders`** with **`status = 'Placed'`** (Retail default filter) and **`agt_order_items`**.  
     - Use **`emr_retail_ensure_agt_tables`** compatible with existing `aura_ghar_tak.php` schema (include `prescription_file` column ALTER if missing).  
     - Resolve **`client_no`** via **`clients`** table (exact + numeric padding e.g. `001` vs `1`). Any row in `clients` is allowed when API key is valid (Android picks pharmacy from `listClients`).  
     - Idempotent duplicate: same `client_no` + `order_no` → success `duplicate: true`.  
   - **`emr_retail_list_orders_by_phone($conn, $config)`**  
     - GET `phone` (last 10 digits).  
     - Return JSON `{ status, orders[] }` with `order_no`, `client_no`, `pharmacy_name`/`medical_name`, `status`, `estimated_total`, `created_at`/`entry_date`.  
     - Search **all** pharmacies in DB (not only `allowed_clients`).

2. **Edit:** `php/server/emr_stock_api.php`  
   - After `emr_require_api_key($config);`, parse POST JSON into `$rawInput` (for `placeRetailOrder` only).  
   - `require_once __DIR__ . '/emr_retail_app_orders.inc.php';`  
   - Before other types, handle:  
     - `type=placeRetailOrder` → POST only → `emr_retail_place_order($conn, $config, $rawInput);`  
     - `type=listOrdersByPhone` → GET → `emr_retail_list_orders_by_phone($conn, $config);`  
   - Update file header comment and unknown-type message to include these types.  
   - Keep existing EyeFlow/stock behaviour unchanged.

3. **Deploy:** Upload to live **serverdev** (same folder as current `emr_stock_api.php`), e.g. `https://paperlesspharmacy.in/serverdev/emr_stock_api.php`.

4. **Verify:**  
   - POST `placeRetailOrder` with valid `X-API-Key` and `client_no` of a test pharmacy → row in `agt_orders`.  
   - Retail login → **AuraGharTak** → **Placed** tab shows the order.  
   - GET `listOrdersByPhone&phone=XXXXXXXXXX` returns that order.

### Reference implementation

Copy from Advanced Pharmacy repo (if available):

- `tools/serverdev/emr_retail_app_orders.inc.php` (full include file)
- Patch `emr_stock_api.php` as described above (~25 lines after API key check)

### Do not

- Change `aura_ghar_tak.php` unless insert fails due to schema (prefer matching its table definition).  
- Put API keys in frontend.  
- Break existing types: `ping`, `listClients`, `getStock`, `checkAvailability`, `getSettings`.

---

**Android side (already in Advanced `auraghartak-android` v1.5.5):** after retail checkout, POST `placeRetailOrder`; on Orders refresh, GET `listOrdersByPhone`. No Retail Angular work required.
