<?php
/**
 * Shared helpers for Salary History and Monthly Payroll.
 */
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * @return list<string>
 */
function payroll_statuses(): array
{
    return ['Draft', 'Approved', 'PartiallyPaid', 'Paid', 'Cancelled'];
}

/**
 * Professionals eligible for salary payroll.
 * Includes salaried employment types, and anyone with an active salary package.
 *
 * @return array<int, array<string, mixed>>
 */
function salaried_professionals(PDO $pdo, ?string $asOfDate = null): array
{
    $asOf = $asOfDate ?? date('Y-m-d');
    $statement = $pdo->prepare(
        "SELECT DISTINCT sp.uid, sp.ProfessionalCode, sp.FullName, sp.MobileNo, sp.EmploymentType
           FROM ServiceProfessionals sp
           LEFT JOIN ProfessionalSalaryHistory sh
             ON sh.ProfessionalId = sp.uid
            AND sh.EffectiveFrom <= ?
            AND (sh.EffectiveTo IS NULL OR sh.EffectiveTo >= ?)
          WHERE sp.IsActive = 1
            AND (sp.LeavingDate IS NULL OR sp.LeavingDate >= ?)
            AND (
                sp.EmploymentType IN ('Salaried', 'SalaryPlusCommission')
                OR sh.uid IS NOT NULL
            )
          ORDER BY sp.FullName"
    );
    $statement->execute([$asOf, $asOf, $asOf]);
    return $statement->fetchAll();
}

/**
 * Current open salary revision for a professional (EffectiveTo IS NULL).
 *
 * @return array<string, mixed>|null
 */
function current_salary_history(PDO $pdo, int $professionalId, ?string $asOfDate = null): ?array
{
    $asOf = $asOfDate ?? date('Y-m-d');
    $statement = $pdo->prepare(
        'SELECT *
           FROM ProfessionalSalaryHistory
          WHERE ProfessionalId = ?
            AND EffectiveFrom <= ?
            AND (EffectiveTo IS NULL OR EffectiveTo >= ?)
          ORDER BY EffectiveFrom DESC
          LIMIT 1'
    );
    $statement->execute([$professionalId, $asOf, $asOf]);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

/**
 * Generate next payroll number: PR-YYYYMM-####
 */
function generate_payroll_no(PDO $pdo, string $payrollMonth): string
{
    $ym = date('Ym', strtotime($payrollMonth));
    $prefix = 'PR-' . $ym . '-';
    $statement = $pdo->prepare(
        'SELECT PayrollNo FROM ProfessionalPayroll
          WHERE PayrollNo LIKE ?
          ORDER BY PayrollNo DESC
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
 * First and last day of a month given YYYY-MM or YYYY-MM-DD.
 *
 * @return array{month: string, start: string, end: string}
 */
function payroll_month_bounds(string $monthInput): array
{
    $ts = strtotime($monthInput . (preg_match('/^\d{4}-\d{2}$/', $monthInput) === 1 ? '-01' : ''));
    if ($ts === false) {
        $ts = strtotime(date('Y-m-01'));
    }
    return [
        'month' => date('Y-m-01', $ts),
        'start' => date('Y-m-01', $ts),
        'end'   => date('Y-m-t', $ts),
    ];
}

/**
 * Commission amount earned in a period for a professional (non-cancelled).
 */
function commission_total_for_period(PDO $pdo, int $professionalId, string $periodStart, string $periodEnd): float
{
    try {
        $statement = $pdo->prepare(
            "SELECT COALESCE(SUM(CommissionAmount), 0)
               FROM ProfessionalCommissions
              WHERE ProfessionalId = ?
                AND CommissionDate BETWEEN ? AND ?
                AND CommissionStatus <> 'Cancelled'"
        );
        $statement->execute([$professionalId, $periodStart, $periodEnd]);
        return (float) $statement->fetchColumn();
    } catch (PDOException $exception) {
        error_log('[ServiceManagement] commission total failed: ' . $exception->getMessage());
        return 0.0;
    }
}

/**
 * Recalculate net/balance for a payroll row in memory.
 *
 * @param array<string, mixed> $row
 * @return array{NetPayable: float, BalanceAmount: float}
 */
function payroll_recalc_amounts(array $row): array
{
    $net = (float) $row['BasicSalary']
        + (float) $row['Allowances']
        + (float) $row['CommissionAmount']
        + (float) $row['Bonus']
        - (float) $row['Deductions'];
    $net = max(0, $net);
    $paid = max(0, (float) $row['PaidAmount']);
    $balance = max(0, $net - $paid);
    return ['NetPayable' => $net, 'BalanceAmount' => $balance];
}

/**
 * @return array<string, mixed>|null
 */
function fetch_payroll(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(
        'SELECT pp.*,
                sp.ProfessionalCode, sp.FullName AS ProfessionalName, sp.MobileNo AS ProfessionalMobile,
                sp.EmploymentType
           FROM ProfessionalPayroll pp
           INNER JOIN ServiceProfessionals sp ON sp.uid = pp.ProfessionalId
          WHERE pp.uid = ?'
    );
    $statement->execute([$id]);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

/**
 * Close previous open salary revisions when adding a new one.
 */
function close_previous_salary_revisions(PDO $pdo, int $professionalId, string $effectiveFrom): void
{
    $dayBefore = date('Y-m-d', strtotime($effectiveFrom . ' -1 day'));
    $statement = $pdo->prepare(
        'UPDATE ProfessionalSalaryHistory
            SET EffectiveTo = ?
          WHERE ProfessionalId = ?
            AND EffectiveTo IS NULL
            AND EffectiveFrom < ?'
    );
    $statement->execute([$dayBefore, $professionalId, $effectiveFrom]);
}

/**
 * @return list<string>
 */
function salary_payment_methods(): array
{
    return ['Cash', 'BankTransfer', 'Cheque', 'MobileWallet', 'Other'];
}

/**
 * Generate next salary payment number: SP-YYYYMMDD-####
 */
function generate_salary_payment_no(PDO $pdo): string
{
    $prefix = 'SP-' . date('Ymd') . '-';
    $statement = $pdo->prepare(
        'SELECT PaymentNo FROM ProfessionalSalaryPayments
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
 * Refresh payroll status from paid vs net amounts.
 */
function refresh_payroll_status(PDO $pdo, int $payrollId): void
{
    $statement = $pdo->prepare(
        'SELECT NetPayable, PaidAmount, PayrollStatus FROM ProfessionalPayroll WHERE uid = ?'
    );
    $statement->execute([$payrollId]);
    $row = $statement->fetch();
    if ($row === false || (string) $row['PayrollStatus'] === 'Cancelled') {
        return;
    }

    $net = (float) $row['NetPayable'];
    $paid = (float) $row['PaidAmount'];
    if ($paid <= 0) {
        $status = 'Approved';
    } elseif ($paid + 0.009 >= $net) {
        $status = 'Paid';
    } else {
        $status = 'PartiallyPaid';
    }

    $update = $pdo->prepare(
        "UPDATE ProfessionalPayroll
            SET PayrollStatus = ?, BalanceAmount = GREATEST(0, NetPayable - PaidAmount)
          WHERE uid = ? AND PayrollStatus <> 'Cancelled'"
    );
    $update->execute([$status, $payrollId]);
}

/**
 * Sync a payroll row from current salary package + commission for the month.
 * Preserves PaidAmount; recalculates NetPayable and BalanceAmount.
 *
 * @return array<string, mixed>|null Updated payroll row
 */
function sync_payroll_totals(PDO $pdo, int $payrollId): ?array
{
    $payroll = fetch_payroll($pdo, $payrollId);
    if ($payroll === null || (string) $payroll['PayrollStatus'] === 'Cancelled') {
        return $payroll;
    }

    $salary = current_salary_history(
        $pdo,
        (int) $payroll['ProfessionalId'],
        (string) $payroll['PeriodEnd']
    );
    $basic = $salary !== null ? (float) $salary['BasicSalary'] : (float) $payroll['BasicSalary'];
    $allowances = $salary !== null ? (float) $salary['Allowances'] : (float) $payroll['Allowances'];
    $commission = commission_total_for_period(
        $pdo,
        (int) $payroll['ProfessionalId'],
        (string) $payroll['PeriodStart'],
        (string) $payroll['PeriodEnd']
    );

    $calc = payroll_recalc_amounts([
        'BasicSalary'      => $basic,
        'Allowances'       => $allowances,
        'CommissionAmount' => $commission,
        'Bonus'            => $payroll['Bonus'],
        'Deductions'       => $payroll['Deductions'],
        'PaidAmount'       => $payroll['PaidAmount'],
    ]);

    $update = $pdo->prepare(
        'UPDATE ProfessionalPayroll
            SET SalaryHistoryId = ?, BasicSalary = ?, Allowances = ?, CommissionAmount = ?,
                NetPayable = ?, BalanceAmount = ?
          WHERE uid = ? AND PayrollStatus <> \'Cancelled\''
    );
    $update->execute([
        $salary !== null ? (int) $salary['uid'] : null,
        $basic,
        $allowances,
        $commission,
        $calc['NetPayable'],
        $calc['BalanceAmount'],
        $payrollId,
    ]);
    refresh_payroll_status($pdo, $payrollId);
    return fetch_payroll($pdo, $payrollId);
}

/**
 * Ensure every salaried professional has a payroll row for the month.
 * Creates missing rows and syncs totals for open (non-cancelled) rows.
 *
 * @return array{created: int, synced: int}
 */
function ensure_month_payrolls(PDO $pdo, string $monthInput): array
{
    $bounds = payroll_month_bounds($monthInput);
    $created = 0;
    $synced = 0;
    $professionals = salaried_professionals($pdo, $bounds['end']);

    foreach ($professionals as $professional) {
        $profId = (int) $professional['uid'];
        $exists = $pdo->prepare(
            'SELECT uid FROM ProfessionalPayroll WHERE ProfessionalId = ? AND PayrollMonth = ?'
        );
        $exists->execute([$profId, $bounds['month']]);
        $existingId = $exists->fetchColumn();

        if ($existingId) {
            sync_payroll_totals($pdo, (int) $existingId);
            $synced++;
            continue;
        }

        $salary = current_salary_history($pdo, $profId, $bounds['end']);
        if ($salary === null) {
            continue;
        }

        $commission = commission_total_for_period($pdo, $profId, $bounds['start'], $bounds['end']);
        $row = [
            'BasicSalary'      => (float) $salary['BasicSalary'],
            'Allowances'       => (float) $salary['Allowances'],
            'CommissionAmount' => $commission,
            'Bonus'            => 0.0,
            'Deductions'       => 0.0,
            'PaidAmount'       => 0.0,
        ];
        $amounts = payroll_recalc_amounts($row);
        $payrollNo = generate_payroll_no($pdo, $bounds['month']);

        $insert = $pdo->prepare(
            'INSERT INTO ProfessionalPayroll
                (PayrollNo, ProfessionalId, SalaryHistoryId, PayrollMonth, PeriodStart, PeriodEnd,
                 BasicSalary, Allowances, CommissionAmount, Bonus, Deductions,
                 NetPayable, PaidAmount, BalanceAmount, PayrollStatus, Remarks)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, 0, ?, \'Approved\', ?)'
        );
        $insert->execute([
            $payrollNo,
            $profId,
            (int) $salary['uid'],
            $bounds['month'],
            $bounds['start'],
            $bounds['end'],
            $row['BasicSalary'],
            $row['Allowances'],
            $row['CommissionAmount'],
            $amounts['NetPayable'],
            $amounts['BalanceAmount'],
            'Auto-synced salary total',
        ]);
        $created++;
    }

    return ['created' => $created, 'synced' => $synced];
}

/**
 * @return array<int, array<string, mixed>>
 */
function payroll_payments(PDO $pdo, int $payrollId): array
{
    $statement = $pdo->prepare(
        'SELECT * FROM ProfessionalSalaryPayments
          WHERE PayrollId = ?
          ORDER BY PaymentDateTime DESC, uid DESC'
    );
    $statement->execute([$payrollId]);
    return $statement->fetchAll();
}
