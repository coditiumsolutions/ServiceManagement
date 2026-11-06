<?php
/**
 * Quick-create customer (JSON) used from the service request form.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_login();

header('Content-Type: application/json; charset=UTF-8');

if (!is_post_request()) {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid security token.']);
    exit;
}

$fullName = post_string('FullName', 150);
$mobileNo = post_string('MobileNo', 20);
$address  = post_string('Address', 500);
$area     = post_string('Area', 100);
$city     = post_string('City', 100);
$email    = post_string('Email', 150);

$errors = [];
if ($fullName === '') {
    $errors[] = 'Customer name is required.';
}
if ($mobileNo === '') {
    $errors[] = 'Mobile number is required.';
}
if ($address === '') {
    $errors[] = 'Address is required.';
}
if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $errors[] = 'Email address is not valid.';
}

if ($errors !== []) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => implode(' ', $errors)]);
    exit;
}

$pdo = db();

try {
    $dup = $pdo->prepare('SELECT uid FROM Customers WHERE MobileNo = ? LIMIT 1');
    $dup->execute([$mobileNo]);
    $existingId = $dup->fetchColumn();
    if ($existingId) {
        $existing = $pdo->prepare('SELECT uid, CustomerCode, FullName, MobileNo, Address FROM Customers WHERE uid = ?');
        $existing->execute([(int) $existingId]);
        $row = $existing->fetch();
        echo json_encode([
            'ok'       => true,
            'existing' => true,
            'customer' => [
                'uid'          => (int) $row['uid'],
                'CustomerCode' => (string) $row['CustomerCode'],
                'FullName'     => (string) $row['FullName'],
                'MobileNo'     => (string) $row['MobileNo'],
                'Address'      => (string) ($row['Address'] ?? ''),
            ],
            'message' => 'Customer with this mobile already exists and was selected.',
        ]);
        exit;
    }

    $code = generate_customer_code($pdo);
    $insert = $pdo->prepare(
        'INSERT INTO Customers
            (CustomerCode, FullName, MobileNo, Email, Address, Area, City, IsActive)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
    );
    $insert->execute([
        $code,
        $fullName,
        $mobileNo,
        $email !== '' ? $email : null,
        $address,
        $area !== '' ? $area : null,
        $city !== '' ? $city : null,
    ]);

    $newId = (int) $pdo->lastInsertId();
    echo json_encode([
        'ok'       => true,
        'existing' => false,
        'customer' => [
            'uid'          => $newId,
            'CustomerCode' => $code,
            'FullName'     => $fullName,
            'MobileNo'     => $mobileNo,
            'Address'      => $address,
        ],
        'message' => 'Customer created successfully.',
    ]);
} catch (PDOException $exception) {
    error_log('[ServiceManagement] quick_create customer failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not create customer. Please try again.']);
}
