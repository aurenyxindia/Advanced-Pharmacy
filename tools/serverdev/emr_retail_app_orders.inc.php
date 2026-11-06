<?php
/**
 * AuraGharTak Android — retail order save + list by mobile (agt_orders).
 * Included from emr_stock_api.php (X-API-Key auth).
 */

function emr_retail_digits($s) {
    $d = preg_replace('/\D+/', '', (string)$s);
    if (strlen($d) >= 10) {
        return substr($d, -10);
    }
    return $d;
}

function emr_retail_lookup_client_no($conn, $input) {
    $input = trim((string)$input);
    if ($input === '') {
        return '';
    }
    $candidates = array($input);
    if (preg_match('/^\d+$/', $input)) {
        $trimmed = ltrim($input, '0');
        if ($trimmed === '') {
            $trimmed = '0';
        }
        $candidates[] = $trimmed;
        $candidates[] = str_pad($trimmed, 3, '0', STR_PAD_LEFT);
    }
    $seen = array();
    foreach ($candidates as $c) {
        $c = trim((string)$c);
        if ($c === '' || isset($seen[$c])) {
            continue;
        }
        $seen[$c] = true;
        $esc = emr_esc($conn, $c);
        $res = $conn->query("SELECT client_no FROM clients WHERE client_no='$esc' LIMIT 1");
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            return (string)$row['client_no'];
        }
    }
    return '';
}

function emr_retail_ensure_agt_tables($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS agt_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_no VARCHAR(32) NOT NULL,
        order_no VARCHAR(64) NOT NULL,
        share_token VARCHAR(64) NULL,
        patient_no VARCHAR(64) NULL,
        patient_name VARCHAR(255) NULL,
        patient_contact VARCHAR(32) NULL,
        patient_address VARCHAR(500) NULL,
        patient_note VARCHAR(500) NULL,
        prescription_file VARCHAR(255) NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'Placed',
        item_count INT NOT NULL DEFAULT 0,
        estimated_total DECIMAL(15,2) NOT NULL DEFAULT 0,
        sale_order_no VARCHAR(64) NULL,
        sale_id INT NULL,
        sale_net DECIMAL(15,2) NULL,
        entry_date DATETIME NOT NULL,
        ready_at DATETIME NULL,
        out_for_delivery_at DATETIME NULL,
        delivered_at DATETIME NULL,
        KEY idx_client_status (client_no, status),
        KEY idx_contact (client_no, patient_contact),
        UNIQUE KEY uk_order (client_no, order_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS agt_order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_no VARCHAR(32) NOT NULL,
        order_id INT NOT NULL,
        order_no VARCHAR(64) NOT NULL,
        p_name VARCHAR(255) NOT NULL,
        batch_no VARCHAR(64) NULL,
        pack_size VARCHAR(64) NULL,
        p_cat VARCHAR(128) NULL,
        mrp DECIMAL(15,2) NOT NULL DEFAULT 0,
        unit_mrp DECIMAL(15,2) NOT NULL DEFAULT 0,
        qty DECIMAL(15,2) NOT NULL DEFAULT 0,
        line_total DECIMAL(15,2) NOT NULL DEFAULT 0,
        exp_date VARCHAR(32) NULL,
        KEY idx_order (order_id),
        KEY idx_client_order (client_no, order_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $col = @$conn->query("SHOW COLUMNS FROM agt_orders LIKE 'prescription_file'");
    if (!$col || $col->num_rows === 0) {
        @$conn->query("ALTER TABLE agt_orders ADD COLUMN prescription_file VARCHAR(255) NULL AFTER patient_note");
    }
}

function emr_retail_client_no_from_input($conn, $config, $rawInput) {
    $client_no = trim((string)($rawInput['client_no'] ?? $_GET['client_no'] ?? ''));
    if ($client_no === '') {
        $client_no = (string)$config['default_client_no'];
    }
    if ($client_no === '' || !preg_match('/^[A-Za-z0-9_\-]{1,32}$/', $client_no)) {
        emr_fail('Invalid client_no', 400);
    }
    $canonical = emr_retail_lookup_client_no($conn, $client_no);
    if ($canonical === '') {
        emr_fail('Unknown client_no', 404, array('client_no' => $client_no));
    }
    // AuraGharTak Android: any pharmacy row in `clients` (API key already authenticates partner).
    return $canonical;
}

function emr_retail_place_order($conn, $config, $rawInput) {
    emr_retail_ensure_agt_tables($conn);
    $client_no = emr_retail_client_no_from_input($conn, $config, $rawInput);
    $c = emr_esc($conn, $client_no);

    $patient_name = trim((string)($rawInput['patient_name'] ?? ''));
    $patient_contact = emr_retail_digits($rawInput['patient_contact'] ?? $rawInput['phone'] ?? '');
    $patient_address = trim((string)($rawInput['patient_address'] ?? $rawInput['address'] ?? ''));
    $pincode = trim((string)($rawInput['pincode'] ?? ''));
    if ($pincode !== '' && stripos($patient_address, $pincode) === false) {
        $patient_address = trim($patient_address . ($patient_address !== '' ? ', ' : '') . 'PIN ' . $pincode);
    }
    $note = trim((string)($rawInput['patient_note'] ?? $rawInput['note'] ?? ''));
    $payment = trim((string)($rawInput['payment_method'] ?? ''));
    if ($payment !== '' && stripos($note, 'Payment:') === false) {
        $note = trim('Payment: ' . $payment . ($note !== '' ? ' | ' . $note : ''));
    }
    $delivery = trim((string)($rawInput['delivery_slot'] ?? ''));
    if ($delivery !== '') {
        $note = trim($note . ($note !== '' ? ' | ' : '') . 'Delivery: ' . $delivery);
    }
    $note = trim('AuraGharTak Android' . ($note !== '' ? ' — ' . $note : ''));

    if ($patient_name === '' || strlen($patient_contact) < 10) {
        emr_fail('patient_name and valid patient_contact (10 digits) are required', 400);
    }

    $items = $rawInput['items'] ?? array();
    if (is_string($items)) {
        $items = json_decode($items, true);
    }
    if (!is_array($items) || count($items) === 0) {
        emr_fail('items array is required', 400);
    }

    $order_no = trim((string)($rawInput['order_no'] ?? ''));
    if ($order_no === '') {
        $order_no = 'RTL-' . date('Ymd') . '-' . str_pad((string)mt_rand(1000, 9999), 4, '0', STR_PAD_LEFT);
    }
    if (!preg_match('/^[A-Za-z0-9_\-\/]{4,64}$/', $order_no)) {
        emr_fail('Invalid order_no', 400);
    }
    $ono = emr_esc($conn, $order_no);
    $dup = $conn->query("SELECT id FROM agt_orders WHERE client_no='$c' AND order_no='$ono' LIMIT 1");
    if ($dup && $dup->num_rows > 0) {
        emr_json(array(
            'status' => 'success',
            'order_no' => $order_no,
            'message' => 'Order already recorded',
            'duplicate' => true,
        ));
    }

    $entry_date = date('Y-m-d H:i:s');
    $conn->begin_transaction();
    try {
        $sql = "INSERT INTO agt_orders (client_no, order_no, share_token, patient_no, patient_name, patient_contact,
            patient_address, patient_note, status, item_count, estimated_total, entry_date)
            VALUES ('$c', '$ono', '',
            '',
            '" . emr_esc($conn, $patient_name) . "',
            '" . emr_esc($conn, $patient_contact) . "',
            '" . emr_esc($conn, $patient_address) . "',
            '" . emr_esc($conn, $note) . "',
            'Placed', 0, 0, '$entry_date')";
        if (!$conn->query($sql)) {
            throw new Exception($conn->error ?: 'Failed to create order');
        }
        $order_id = (int)$conn->insert_id;
        $total = 0.0;
        $count = 0;
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $p_name = trim((string)($it['p_name'] ?? $it['name'] ?? $it['product_name'] ?? ''));
            $qty = (float)($it['qty'] ?? 0);
            if ($p_name === '' || $qty <= 0) {
                continue;
            }
            $unit = (float)($it['unit_mrp'] ?? $it['rate'] ?? $it['mrp'] ?? 0);
            if ($unit <= 0) {
                $unit = (float)($it['mrp'] ?? 0);
            }
            $line = round($unit * $qty, 2);
            $total += $line;
            $count++;
            $batch = trim((string)($it['batch_no'] ?? ''));
            $conn->query("INSERT INTO agt_order_items (client_no, order_id, order_no, p_name, batch_no, pack_size, p_cat,
                mrp, unit_mrp, qty, line_total, exp_date) VALUES (
                '$c', $order_id, '$ono',
                '" . emr_esc($conn, $p_name) . "',
                '" . emr_esc($conn, $batch) . "',
                '" . emr_esc($conn, (string)($it['pack_size'] ?? '')) . "',
                '" . emr_esc($conn, (string)($it['p_cat'] ?? '')) . "',
                '" . emr_esc($conn, $unit) . "',
                '" . emr_esc($conn, $unit) . "',
                '" . emr_esc($conn, $qty) . "',
                '" . emr_esc($conn, $line) . "',
                '" . emr_esc($conn, (string)($it['expiry'] ?? $it['exp_date'] ?? '')) . "'
            )");
        }
        if ($count === 0) {
            throw new Exception('No valid line items');
        }
        $conn->query("UPDATE agt_orders SET item_count=$count, estimated_total='" . emr_esc($conn, round($total, 2)) . "'
            WHERE id=$order_id");
        $conn->commit();
        emr_json(array(
            'status' => 'success',
            'order_no' => $order_no,
            'order_id' => $order_id,
            'estimated_total' => round($total, 2),
            'message' => 'Order saved for pharmacy',
        ));
    } catch (Exception $e) {
        $conn->rollback();
        emr_fail($e->getMessage(), 400);
    }
}

function emr_retail_list_orders_by_phone($conn, $config) {
    emr_retail_ensure_agt_tables($conn);
    $phone = emr_retail_digits($_GET['phone'] ?? $_GET['patient_contact'] ?? '');
    if (strlen($phone) < 10) {
        emr_fail('phone query param required (10 digits)', 400);
    }
    $phoneEsc = emr_esc($conn, $phone);
    // Match retail AuraGharTak list (any pharmacy in shared retail DB).
    $clientWhere = '1=1';
    $sql = "SELECT o.client_no, o.order_no, o.patient_name, o.patient_contact, o.status,
                   o.estimated_total, o.entry_date, o.patient_note,
                   c.medical AS medical_name
            FROM agt_orders o
            LEFT JOIN clients c ON c.client_no = o.client_no
            WHERE $clientWhere
              AND RIGHT(REPLACE(REPLACE(o.patient_contact,' ',''),'-',''), 10) = '$phoneEsc'
            ORDER BY o.id DESC
            LIMIT 50";
    $orders = array();
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $medical = trim((string)($row['medical_name'] ?? ''));
            if ($medical === '') {
                $medical = (string)$row['client_no'];
            }
            $orders[] = array(
                'client_no' => (string)$row['client_no'],
                'order_no' => (string)$row['order_no'],
                'medical_name' => $medical,
                'pharmacy_name' => $medical,
                'patient_name' => (string)($row['patient_name'] ?? ''),
                'status' => (string)($row['status'] ?? 'Placed'),
                'estimated_total' => (float)($row['estimated_total'] ?? 0),
                'entry_date' => (string)($row['entry_date'] ?? ''),
                'created_at' => (string)($row['entry_date'] ?? ''),
            );
        }
    }
    emr_json(array(
        'status' => 'success',
        'phone' => $phone,
        'count' => count($orders),
        'orders' => $orders,
        'as_of' => emr_as_of(),
    ));
}
