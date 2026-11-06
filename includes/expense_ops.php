<?php
/**
 * Shared helpers for Expenses and Income & Expense reports.
 */
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * @return list<string>
 */
function expense_payment_methods(): array
{
    return ['Cash', 'BankTransfer', 'Cheque', 'Card', 'MobileWallet', 'Other'];
}

/**
 * Suggested expense categories (free-text field; users may type custom values).
 *
 * @return list<string>
 */
function suggested_expense_categories(): array
{
    return [
        'Materials',
        'Tools & Equipment',
        'Transport / Fuel',
        'Office Supplies',
        'Utilities',
        'Rent',
        'Marketing',
        'Maintenance',
        'Professional Fees',
        'Miscellaneous',
    ];
}

/**
 * Generate next expense number: EXP-YYYYMMDD-####
 */
function generate_expense_no(PDO $pdo): string
{
    $prefix = 'EXP-' . date('Ymd') . '-';
    $statement = $pdo->prepare(
        'SELECT ExpenseNo FROM Expenses
          WHERE ExpenseNo LIKE ?
          ORDER BY ExpenseNo DESC
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
 * Distinct categories already used, merged with suggested defaults.
 *
 * @return list<string>
 */
function expense_category_options(PDO $pdo): array
{
    $used = [];
    try {
        $statement = $pdo->query(
            'SELECT DISTINCT ExpenseCategory
               FROM Expenses
              WHERE ExpenseCategory IS NOT NULL AND ExpenseCategory <> \'\'
              ORDER BY ExpenseCategory'
        );
        $used = $statement->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $exception) {
        error_log('[ServiceManagement] expense categories failed: ' . $exception->getMessage());
    }

    $merged = array_values(array_unique(array_merge(suggested_expense_categories(), $used)));
    natcasesort($merged);
    return array_values($merged);
}

/**
 * Recent service requests for optional expense linking.
 *
 * @return array<int, array<string, mixed>>
 */
function expense_linkable_requests(PDO $pdo, int $limit = 200): array
{
    $limit = max(1, min(500, $limit));
    $statement = $pdo->query(
        'SELECT sr.uid, sr.RequestNo, sr.RequestStatus, sr.RequestDateTime,
                c.FullName AS CustomerName, st.ServiceName
           FROM ServiceRequests sr
           INNER JOIN Customers c ON c.uid = sr.CustomerId
           INNER JOIN ServiceTypes st ON st.uid = sr.ServiceTypeId
          ORDER BY sr.RequestDateTime DESC
          LIMIT ' . $limit
    );
    return $statement->fetchAll();
}

/**
 * @return array<string, mixed>|null
 */
function fetch_expense(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(
        'SELECT e.*,
                sr.RequestNo, sr.RequestStatus,
                c.FullName AS CustomerName,
                sp.ProfessionalCode, sp.FullName AS ProfessionalName
           FROM Expenses e
           LEFT JOIN ServiceRequests sr ON sr.uid = e.ServiceRequestId
           LEFT JOIN Customers c ON c.uid = sr.CustomerId
           LEFT JOIN ServiceProfessionals sp ON sp.uid = e.ProfessionalId
          WHERE e.uid = ?'
    );
    $statement->execute([$id]);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

/**
 * Sum customer payments (income) for a date range (inclusive).
 */
function report_income_total(PDO $pdo, string $fromDate, string $toDate): float
{
    $statement = $pdo->prepare(
        'SELECT COALESCE(SUM(Amount), 0)
           FROM CustomerPayments
          WHERE DATE(PaymentDateTime) BETWEEN ? AND ?'
    );
    $statement->execute([$fromDate, $toDate]);
    return (float) $statement->fetchColumn();
}

/**
 * Sum operating expenses for a date range (inclusive).
 */
function report_expense_total(PDO $pdo, string $fromDate, string $toDate): float
{
    $statement = $pdo->prepare(
        'SELECT COALESCE(SUM(Amount), 0)
           FROM Expenses
          WHERE ExpenseDate BETWEEN ? AND ?'
    );
    $statement->execute([$fromDate, $toDate]);
    return (float) $statement->fetchColumn();
}

/**
 * Sum salary + commission payouts for a date range (inclusive).
 */
function report_labor_payout_total(PDO $pdo, string $fromDate, string $toDate): float
{
    $salary = $pdo->prepare(
        'SELECT COALESCE(SUM(Amount), 0)
           FROM ProfessionalSalaryPayments
          WHERE DATE(PaymentDateTime) BETWEEN ? AND ?'
    );
    $salary->execute([$fromDate, $toDate]);

    $commission = $pdo->prepare(
        'SELECT COALESCE(SUM(Amount), 0)
           FROM ProfessionalCommissionPayments
          WHERE DATE(PaymentDateTime) BETWEEN ? AND ?'
    );
    $commission->execute([$fromDate, $toDate]);

    return (float) $salary->fetchColumn() + (float) $commission->fetchColumn();
}

/**
 * @return array<int, array{ExpenseCategory: string, TotalAmount: float|string, EntryCount: int|string}>
 */
function report_expenses_by_category(PDO $pdo, string $fromDate, string $toDate): array
{
    $statement = $pdo->prepare(
        'SELECT ExpenseCategory, SUM(Amount) AS TotalAmount, COUNT(*) AS EntryCount
           FROM Expenses
          WHERE ExpenseDate BETWEEN ? AND ?
          GROUP BY ExpenseCategory
          ORDER BY TotalAmount DESC, ExpenseCategory ASC'
    );
    $statement->execute([$fromDate, $toDate]);
    return $statement->fetchAll();
}

/**
 * @return array<int, array{PaymentMethod: string, TotalAmount: float|string, EntryCount: int|string}>
 */
function report_income_by_method(PDO $pdo, string $fromDate, string $toDate): array
{
    $statement = $pdo->prepare(
        'SELECT PaymentMethod, SUM(Amount) AS TotalAmount, COUNT(*) AS EntryCount
           FROM CustomerPayments
          WHERE DATE(PaymentDateTime) BETWEEN ? AND ?
          GROUP BY PaymentMethod
          ORDER BY TotalAmount DESC'
    );
    $statement->execute([$fromDate, $toDate]);
    return $statement->fetchAll();
}
