<?php
/**
 * Shared helpers for Customer Payments.
 */
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * @return list<string>
 */
function customer_payment_types(): array
{
    return ['Advance', 'Partial', 'Full', 'Refund'];
}

/**
 * @return list<string>
 */
function customer_payment_methods(): array
{
    return ['Cash', 'BankTransfer', 'Cheque', 'Card', 'MobileWallet', 'Other'];
}

/**
 * Generate next receipt number: RCP-YYYYMMDD-####
 */
function generate_receipt_no(PDO $pdo): string
{
    $prefix = 'RCP-' . date('Ymd') . '-';
    $statement = $pdo->prepare(
        'SELECT ReceiptNo FROM CustomerPayments
          WHERE ReceiptNo LIKE ?
          ORDER BY ReceiptNo DESC
          LIMIT 1'
    );
    $statement->execute([$prefix . '%']);
    $latest = $statement->fetchColumn();

    $sequence = 1;
    if (is_string($latest) && preg_match('/(\d{4})$/', $latest, $matches) === 1) {
        $sequence = ((int) $matches[1]) + 1;
    }

    return $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
}

/**
 * Recent service requests for optional payment linking.
 *
 * @return array<int, array<string, mixed>>
 */
function payment_linkable_requests(PDO $pdo, int $limit = 250, ?int $customerId = null): array
{
    $limit = max(1, min(500, $limit));
    $sql = 'SELECT sr.uid, sr.RequestNo, sr.CustomerId, sr.RequestStatus, sr.RequestDateTime,
                   sr.FinalAmount, sr.EstimatedAmount,
                   c.FullName AS CustomerName, c.CustomerCode, c.MobileNo,
                   st.ServiceName
              FROM ServiceRequests sr
              INNER JOIN Customers c ON c.uid = sr.CustomerId
              INNER JOIN ServiceTypes st ON st.uid = sr.ServiceTypeId';
    $params = [];
    if ($customerId !== null && $customerId > 0) {
        $sql .= ' WHERE sr.CustomerId = ?';
        $params[] = $customerId;
    }
    $sql .= ' ORDER BY sr.RequestDateTime DESC LIMIT ' . $limit;

    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

/**
 * @return array<string, mixed>|null
 */
function fetch_customer_payment(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(
        'SELECT cp.*,
                c.CustomerCode, c.FullName AS CustomerName, c.MobileNo AS CustomerMobile,
                sr.RequestNo, sr.RequestStatus, sr.FinalAmount, sr.EstimatedAmount,
                st.ServiceName
           FROM CustomerPayments cp
           INNER JOIN Customers c ON c.uid = cp.CustomerId
           LEFT JOIN ServiceRequests sr ON sr.uid = cp.ServiceRequestId
           LEFT JOIN ServiceTypes st ON st.uid = sr.ServiceTypeId
          WHERE cp.uid = ?'
    );
    $statement->execute([$id]);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

/**
 * Sum of payments for a service request (refunds subtract).
 */
function request_payments_total(PDO $pdo, int $requestId): float
{
    $statement = $pdo->prepare(
        "SELECT COALESCE(SUM(
                    CASE
                        WHEN PaymentType = 'Refund' THEN -Amount
                        ELSE Amount
                    END
                ), 0)
           FROM CustomerPayments
          WHERE ServiceRequestId = ?"
    );
    $statement->execute([$requestId]);
    return (float) $statement->fetchColumn();
}

/**
 * @return array{final_amount: float, paid_amount: float, balance: float}
 */
function request_payment_summary(PDO $pdo, int $requestId, float $finalAmount): array
{
    $paid = request_payments_total($pdo, $requestId);
    return [
        'final_amount' => $finalAmount,
        'paid_amount'  => $paid,
        'balance'      => round($finalAmount - $paid, 2),
    ];
}

/**
 * Payments linked to a service request.
 *
 * @return array<int, array<string, mixed>>
 */
function request_payments(PDO $pdo, int $requestId): array
{
    $statement = $pdo->prepare(
        'SELECT uid, ReceiptNo, PaymentDateTime, Amount, PaymentType, PaymentMethod, ReferenceNo
           FROM CustomerPayments
          WHERE ServiceRequestId = ?
          ORDER BY PaymentDateTime DESC, uid DESC'
    );
    $statement->execute([$requestId]);
    return $statement->fetchAll();
}

/**
 * @return array<string, mixed>|null
 */
function fetch_customer_basic(PDO $pdo, int $customerId): ?array
{
    $statement = $pdo->prepare(
        'SELECT uid, CustomerCode, FullName, MobileNo, IsActive
           FROM Customers
          WHERE uid = ?'
    );
    $statement->execute([$customerId]);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

/**
 * @return array<string, mixed>|null
 */
function fetch_request_for_payment(PDO $pdo, int $requestId): ?array
{
    $statement = $pdo->prepare(
        'SELECT sr.uid, sr.RequestNo, sr.CustomerId, sr.FinalAmount, sr.EstimatedAmount, sr.RequestStatus,
                c.FullName AS CustomerName, c.CustomerCode
           FROM ServiceRequests sr
           INNER JOIN Customers c ON c.uid = sr.CustomerId
          WHERE sr.uid = ?'
    );
    $statement->execute([$requestId]);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}
