<?php
/**
 * Shared helpers for Commission Entries, Payments, and Professional Ledger.
 */
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * @return list<string>
 */
function commission_statuses(): array
{
    return ['Pending', 'Approved', 'PartiallyPaid', 'Paid', 'Cancelled'];
}

/**
 * @return list<string>
 */
function commission_payment_methods(): array
{
    return ['Cash', 'BankTransfer', 'Cheque', 'MobileWallet', 'Payroll', 'Other'];
}

/**
 * Generate next commission payment number: CP-YYYYMMDD-####
 */
function generate_commission_payment_no(PDO $pdo): string
{
    $prefix = 'CP-' . date('Ymd') . '-';
    $statement = $pdo->prepare(
        'SELECT PaymentNo FROM ProfessionalCommissionPayments
          WHERE PaymentNo LIKE ?
          ORDER BY PaymentNo DESC
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
 * Completed assignments that do not yet have a commission entry.
 *
 * @return array<int, array<string, mixed>>
 */
function commissionable_assignments(PDO $pdo): array
{
    $statement = $pdo->query(
        "SELECT sa.uid, sa.AgreedCommission, sa.CommissionType, sa.CompletionDateTime,
                sa.ProfessionalId, sa.ServiceRequestId,
                sr.RequestNo, sr.FinalAmount, sr.RequestStatus, sr.ServiceTypeId,
                sp.ProfessionalCode, sp.FullName AS ProfessionalName,
                c.FullName AS CustomerName, st.ServiceName
           FROM ServiceAssignments sa
           INNER JOIN ServiceRequests sr ON sr.uid = sa.ServiceRequestId
           INNER JOIN ServiceProfessionals sp ON sp.uid = sa.ProfessionalId
           INNER JOIN Customers c ON c.uid = sr.CustomerId
           INNER JOIN ServiceTypes st ON st.uid = sr.ServiceTypeId
           LEFT JOIN ProfessionalCommissions pc ON pc.AssignmentId = sa.uid
          WHERE sa.AssignmentStatus = 'Completed'
            AND pc.uid IS NULL
          ORDER BY sa.CompletionDateTime DESC, sa.uid DESC"
    );
    return $statement->fetchAll();
}

/**
 * Resolve commission rate: assignment agreed terms first, else rate table.
 *
 * @return array{type: string, rate: float, amount: float, service_amount: float}
 */
function resolve_commission_for_assignment(PDO $pdo, array $assignment): array
{
    $serviceAmount = (float) ($assignment['FinalAmount'] ?? 0);
    $type = (string) ($assignment['CommissionType'] ?? 'Percentage');
    $rate = (float) ($assignment['AgreedCommission'] ?? 0);

    if ($rate <= 0) {
        $lookedUp = lookup_commission_rate(
            $pdo,
            (int) $assignment['ProfessionalId'],
            (int) ($assignment['ServiceTypeId'] ?? 0),
            !empty($assignment['CompletionDateTime'])
                ? date('Y-m-d', strtotime((string) $assignment['CompletionDateTime']))
                : date('Y-m-d')
        );
        if ($lookedUp !== null) {
            $type = (string) $lookedUp['CommissionType'];
            $rate = (float) $lookedUp['CommissionValue'];
        }
    }

    if ($type === 'Percentage') {
        $amount = round($serviceAmount * $rate / 100, 2);
    } else {
        $amount = round($rate, 2);
        $type = 'Fixed';
    }

    return [
        'type'           => $type,
        'rate'           => $rate,
        'amount'         => max(0, $amount),
        'service_amount' => max(0, $serviceAmount),
    ];
}

/**
 * @return array<string, mixed>|null
 */
function lookup_commission_rate(PDO $pdo, int $professionalId, int $serviceTypeId, string $asOfDate): ?array
{
    if ($serviceTypeId > 0) {
        $statement = $pdo->prepare(
            'SELECT *
               FROM ProfessionalCommissionRates
              WHERE ProfessionalId = ?
                AND ServiceTypeId = ?
                AND IsActive = 1
                AND EffectiveFrom <= ?
                AND (EffectiveTo IS NULL OR EffectiveTo >= ?)
              ORDER BY EffectiveFrom DESC
              LIMIT 1'
        );
        $statement->execute([$professionalId, $serviceTypeId, $asOfDate, $asOfDate]);
        $row = $statement->fetch();
        if ($row !== false) {
            return $row;
        }
    }

    $fallback = $pdo->prepare(
        'SELECT *
           FROM ProfessionalCommissionRates
          WHERE ProfessionalId = ?
            AND ServiceTypeId IS NULL
            AND IsActive = 1
            AND EffectiveFrom <= ?
            AND (EffectiveTo IS NULL OR EffectiveTo >= ?)
          ORDER BY EffectiveFrom DESC
          LIMIT 1'
    );
    $fallback->execute([$professionalId, $asOfDate, $asOfDate]);
    $row = $fallback->fetch();
    return $row === false ? null : $row;
}

/**
 * @return array<string, mixed>|null
 */
function fetch_commission(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(
        'SELECT pc.*,
                sr.RequestNo, sr.FinalAmount, sr.uid AS RequestId,
                sp.ProfessionalCode, sp.FullName AS ProfessionalName, sp.MobileNo AS ProfessionalMobile,
                c.FullName AS CustomerName, st.ServiceName, sa.CompletionDateTime
           FROM ProfessionalCommissions pc
           INNER JOIN ServiceAssignments sa ON sa.uid = pc.AssignmentId
           INNER JOIN ServiceRequests sr ON sr.uid = pc.ServiceRequestId
           INNER JOIN ServiceProfessionals sp ON sp.uid = pc.ProfessionalId
           INNER JOIN Customers c ON c.uid = sr.CustomerId
           INNER JOIN ServiceTypes st ON st.uid = sr.ServiceTypeId
          WHERE pc.uid = ?'
    );
    $statement->execute([$id]);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

/**
 * Update commission status from paid amount.
 */
function refresh_commission_status(PDO $pdo, int $commissionId): void
{
    $statement = $pdo->prepare(
        'SELECT CommissionAmount, PaidAmount, CommissionStatus FROM ProfessionalCommissions WHERE uid = ?'
    );
    $statement->execute([$commissionId]);
    $row = $statement->fetch();
    if ($row === false || (string) $row['CommissionStatus'] === 'Cancelled') {
        return;
    }

    $amount = (float) $row['CommissionAmount'];
    $paid = (float) $row['PaidAmount'];
    if ($paid <= 0) {
        $status = 'Approved';
    } elseif ($paid + 0.0001 >= $amount) {
        $status = 'Paid';
    } else {
        $status = 'PartiallyPaid';
    }

    $update = $pdo->prepare(
        'UPDATE ProfessionalCommissions SET CommissionStatus = ? WHERE uid = ? AND CommissionStatus <> \'Cancelled\''
    );
    $update->execute([$status, $commissionId]);
}

/**
 * Build ledger entries for a professional (credits positive, payments negative).
 *
 * @return array{entries: array<int, array<string, mixed>>, summary: array<string, float>}
 */
function professional_ledger(PDO $pdo, int $professionalId, ?string $fromDate = null, ?string $toDate = null): array
{
    $entries = [];

    // Commission earned
    $sql = "SELECT pc.uid, pc.CommissionDate AS EntryDate, pc.CommissionAmount AS Amount,
                   pc.CommissionStatus, sr.RequestNo,
                   CONCAT('Commission — ', sr.RequestNo, ' / ', st.ServiceName) AS Description
              FROM ProfessionalCommissions pc
              INNER JOIN ServiceRequests sr ON sr.uid = pc.ServiceRequestId
              INNER JOIN ServiceTypes st ON st.uid = sr.ServiceTypeId
             WHERE pc.ProfessionalId = ?
               AND pc.CommissionStatus <> 'Cancelled'";
    $params = [$professionalId];
    if ($fromDate) {
        $sql .= ' AND pc.CommissionDate >= ?';
        $params[] = $fromDate;
    }
    if ($toDate) {
        $sql .= ' AND pc.CommissionDate <= ?';
        $params[] = $toDate;
    }
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    foreach ($statement->fetchAll() as $row) {
        $entries[] = [
            'EntryDate'   => (string) $row['EntryDate'],
            'Type'        => 'Commission',
            'Reference'   => (string) $row['RequestNo'],
            'Description' => (string) $row['Description'],
            'Credit'      => (float) $row['Amount'],
            'Debit'       => 0.0,
            'SortKey'     => (string) $row['EntryDate'] . '-1-' . $row['uid'],
            'Link'        => 'modules/commissions/view.php?id=' . (int) $row['uid'],
        ];
    }

    // Commission payments
    $sql = "SELECT cp.uid, DATE(cp.PaymentDateTime) AS EntryDate, cp.Amount, cp.PaymentNo, cp.PaymentMethod,
                   sr.RequestNo
              FROM ProfessionalCommissionPayments cp
              INNER JOIN ProfessionalCommissions pc ON pc.uid = cp.CommissionId
              INNER JOIN ServiceRequests sr ON sr.uid = pc.ServiceRequestId
             WHERE cp.ProfessionalId = ?";
    $params = [$professionalId];
    if ($fromDate) {
        $sql .= ' AND DATE(cp.PaymentDateTime) >= ?';
        $params[] = $fromDate;
    }
    if ($toDate) {
        $sql .= ' AND DATE(cp.PaymentDateTime) <= ?';
        $params[] = $toDate;
    }
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    foreach ($statement->fetchAll() as $row) {
        $entries[] = [
            'EntryDate'   => (string) $row['EntryDate'],
            'Type'        => 'Commission Payment',
            'Reference'   => (string) $row['PaymentNo'],
            'Description' => 'Commission payout (' . $row['PaymentMethod'] . ') — ' . $row['RequestNo'],
            'Credit'      => 0.0,
            'Debit'       => (float) $row['Amount'],
            'SortKey'     => (string) $row['EntryDate'] . '-2-' . $row['uid'],
            'Link'        => 'modules/commissions/payments.php?q=' . urlencode((string) $row['PaymentNo']),
        ];
    }

    // Payroll net payable (approved and beyond, not cancelled)
    $sql = "SELECT pp.uid, pp.PayrollMonth AS EntryDate, pp.NetPayable AS Amount, pp.PayrollNo, pp.PayrollStatus
              FROM ProfessionalPayroll pp
             WHERE pp.ProfessionalId = ?
               AND pp.PayrollStatus IN ('Approved','PartiallyPaid','Paid')";
    $params = [$professionalId];
    if ($fromDate) {
        $sql .= ' AND pp.PayrollMonth >= ?';
        $params[] = $fromDate;
    }
    if ($toDate) {
        $sql .= ' AND pp.PayrollMonth <= ?';
        $params[] = $toDate;
    }
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    foreach ($statement->fetchAll() as $row) {
        $entries[] = [
            'EntryDate'   => (string) $row['EntryDate'],
            'Type'        => 'Salary Payroll',
            'Reference'   => (string) $row['PayrollNo'],
            'Description' => 'Payroll net payable — ' . date('M Y', strtotime((string) $row['EntryDate'])),
            'Credit'      => (float) $row['Amount'],
            'Debit'       => 0.0,
            'SortKey'     => (string) $row['EntryDate'] . '-3-' . $row['uid'],
            'Link'        => 'modules/payroll/view.php?id=' . (int) $row['uid'],
        ];
    }

    // Salary withdrawals
    $sql = "SELECT sp.uid, DATE(sp.PaymentDateTime) AS EntryDate, sp.Amount, sp.PaymentNo, sp.PaymentMethod, pp.PayrollNo
              FROM ProfessionalSalaryPayments sp
              INNER JOIN ProfessionalPayroll pp ON pp.uid = sp.PayrollId
             WHERE sp.ProfessionalId = ?";
    $params = [$professionalId];
    if ($fromDate) {
        $sql .= ' AND DATE(sp.PaymentDateTime) >= ?';
        $params[] = $fromDate;
    }
    if ($toDate) {
        $sql .= ' AND DATE(sp.PaymentDateTime) <= ?';
        $params[] = $toDate;
    }
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    foreach ($statement->fetchAll() as $row) {
        $entries[] = [
            'EntryDate'   => (string) $row['EntryDate'],
            'Type'        => 'Salary Payment',
            'Reference'   => (string) $row['PaymentNo'],
            'Description' => 'Salary withdrawal (' . $row['PaymentMethod'] . ') — ' . $row['PayrollNo'],
            'Credit'      => 0.0,
            'Debit'       => (float) $row['Amount'],
            'SortKey'     => (string) $row['EntryDate'] . '-4-' . $row['uid'],
            'Link'        => 'modules/salary/withdrawals.php',
        ];
    }

    usort($entries, static function (array $a, array $b): int {
        return strcmp($a['SortKey'], $b['SortKey']);
    });

    $running = 0.0;
    $totalCredit = 0.0;
    $totalDebit = 0.0;
    foreach ($entries as &$entry) {
        $running += $entry['Credit'] - $entry['Debit'];
        $entry['Balance'] = $running;
        $totalCredit += $entry['Credit'];
        $totalDebit += $entry['Debit'];
    }
    unset($entry);

    return [
        'entries' => $entries,
        'summary' => [
            'credit'  => $totalCredit,
            'debit'   => $totalDebit,
            'balance' => $running,
        ],
    ];
}
