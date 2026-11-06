# Retail serverdev — add PIN & address to `listClients`

AuraGharTak reads pharmacies from:

`https://paperlesspharmacy.in/serverdev/emr_stock_api.php?type=listClients`

**Today (default Retail API)** each client only has:

| Field | In JSON |
|--------|---------|
| `client_no` | yes |
| `medical_name` | yes |
| `state` | yes |
| `city` | always empty |
| `pincode` / `address` / `phone` | **not sent** |

Without PIN/city/address, the app cannot sort retail shops by distance (geocoding shop name + state often lands 100+ km away).

## Fix on serverdev (one SQL change)

In `emr_stock_api.php`, replace the `listClients` query block with:

```php
$sql = "SELECT client_no, medical, state, address, Address1, Pin, mobile, cNumber
        FROM clients WHERE $where ORDER BY medical ASC, client_no ASC";
// ...
$addr = trim((string)($row['address'] ?? ''));
if ($addr === '') {
    $addr = trim((string)($row['Address1'] ?? ''));
}
$pin = preg_replace('/\D+/', '', (string)($row['Pin'] ?? ''));
$pin = substr($pin, 0, 6);
$mobile = trim((string)($row['mobile'] ?? ''));
if ($mobile === '') {
    $mobile = trim((string)($row['cNumber'] ?? ''));
}
$clients[] = array(
    'client_no' => (string)$row['client_no'],
    'medical_name' => $medical,
    'city' => '',
    'state' => (string)($row['state'] ?? ''),
    'address' => $addr,
    'Pin' => $pin,
    'pincode' => $pin,
    'mobile' => $mobile,
    'phone' => $mobile,
);
```

Upload the updated `emr_stock_api.php` to serverdev (FTP for that host). The Android app already maps `Pin`, `pincode`, `address`, `mobile`, `cNumber`.

Do **not** commit this into `C:\Madhav\retail` unless you merge the same change there for parity.
