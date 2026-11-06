<?php
/**
 * Replace the listClients block in serverdev emr_stock_api.php (Retail DB table `clients`).
 * Sends Pin + address so AuraGharTak can sort by distance.
 */
if ($type === 'listClients') {
    $allowed = $config['allowed_clients'];
    $where = "1=1";
    if (is_array($allowed) && count($allowed) > 0) {
        $parts = array();
        foreach ($allowed as $c) {
            $parts[] = "'" . emr_esc($conn, $c) . "'";
        }
        $where = "client_no IN (" . implode(',', $parts) . ")";
    }
    $sql = "SELECT client_no, medical, medical_name, state, address, Address1, Pin, mobile, cNumber
            FROM clients WHERE $where ORDER BY medical ASC, client_no ASC";
    $res = $conn->query($sql);
    $clients = array();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $medical = trim((string)($row['medical'] ?? ''));
            if ($medical === '') {
                $medical = trim((string)($row['medical_name'] ?? ''));
            }
            if ($medical === '') {
                $medical = (string)$row['client_no'];
            }
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
        }
    }
    emr_json(array(
        'status' => 'success',
        'default_client_no' => $config['default_client_no'],
        'clients' => $clients,
        'as_of' => emr_as_of(),
    ));
}
