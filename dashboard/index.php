<?php
/**
 * Dashboard: live summary figures, recent activity and monthly summary.
 *
 * All figures come from the database via prepared statements. When a
 * required table is missing the figure shows 0 and the missing dependency
 * is named on the card - no sample or fake numbers are ever displayed.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();

/**
 * Run a scalar query if all required tables exist.
 *
 * @param string[]          $requiredTables
 * @param array<int, mixed> $parameters
 * @return array{value: float, missing: string[], error: bool}
 */
function dashboard_metric(string $sql, array $requiredTables, array $parameters = []): array
{
    $missing = missing_tables($requiredTables);
    if ($missing !== []) {
        return ['value' => 0, 'missing' => $missing, 'error' => false];
    }

    try {
        $statement = db()->prepare($sql);
        $statement->execute($parameters);
        return ['value' => (float) ($statement->fetchColumn() ?: 0), 'missing' => [], 'error' => false];
    } catch (PDOException $exception) {
        error_log('[ServiceManagement] Dashboard metric failed: ' . $exception->getMessage());
        return ['value' => 0, 'missing' => [], 'error' => true];
    }
}

/**
 * Run a list query if all required tables exist.
 *
 * @param string[]          $requiredTables
 * @param array<int, mixed> $parameters
 * @return array{rows: array<int, array<string, mixed>>, missing: string[], error: bool}
 */
function dashboard_rows(string $sql, array $requiredTables, array $parameters = []): array
{
    $missing = missing_tables($requiredTables);
    if ($missing !== []) {
        return ['rows' => [], 'missing' => $missing, 'error' => false];
    }

    try {
        $statement = db()->prepare($sql);
        foreach ($parameters as $position => $value) {
            $statement->bindValue($position + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();
        return ['rows' => $statement->fetchAll(), 'missing' => [], 'error' => false];
    } catch (PDOException $exception) {
        error_log('[ServiceManagement] Dashboard list failed: ' . $exception->getMessage());
        return ['rows' => [], 'missing' => [], 'error' => true];
    }
}

const DASHBOARD_RECENT_LIMIT = 5;
const DASHBOARD_SUMMARY_MONTHS = 6;

/* ---------------------------------------------------------------------
 * Summary cards
 * ------------------------------------------------------------------- */
$requestCountSql = 'SELECT COUNT(*) FROM ServiceRequests WHERE RequestStatus = ?';

$summaryCards = [
    [
        'label'  => 'Total Customers',
        'icon'   => 'bi-people',
        'tone'   => 'primary',
        'money'  => false,
        'link'   => 'modules/customers/index.php',
        'metric' => dashboard_metric('SELECT COUNT(*) FROM Customers', ['Customers']),
    ],
    [
        'label'  => 'Total Service Professionals',
        'icon'   => 'bi-person-badge',
        'tone'   => 'indigo',
        'money'  => false,
        'link'   => 'modules/professionals/index.php',
        'metric' => dashboard_metric('SELECT COUNT(*) FROM ServiceProfessionals', ['ServiceProfessionals']),
    ],
    [
        'label'  => 'Pending Service Requests',
        'icon'   => 'bi-hourglass',
        'tone'   => 'warning',
        'money'  => false,
        'link'   => 'modules/service_requests/index.php',
        'metric' => dashboard_metric($requestCountSql, ['ServiceRequests'], ['Pending']),
    ],
    [
        'label'  => 'Assigned Services',
        'icon'   => 'bi-person-check',
        'tone'   => 'info',
        'money'  => false,
        'link'   => 'modules/service_assignments/index.php',
        'metric' => dashboard_metric($requestCountSql, ['ServiceRequests'], ['Assigned']),
    ],
    [
        'label'  => 'Services In Progress',
        'icon'   => 'bi-gear-wide-connected',
        'tone'   => 'cyan',
        'money'  => false,
        'link'   => 'modules/service_requests/in_progress.php',
        'metric' => dashboard_metric($requestCountSql, ['ServiceRequests'], ['InProgress']),
    ],
    [
        'label'  => 'Completed Services',
        'icon'   => 'bi-check2-circle',
        'tone'   => 'success',
        'money'  => false,
        'link'   => 'modules/service_requests/completed.php',
        'metric' => dashboard_metric($requestCountSql, ['ServiceRequests'], ['Completed']),
    ],
    [
        'label'  => 'Total Salary Payable',
        'icon'   => 'bi-cash-stack',
        'tone'   => 'danger',
        'money'  => true,
        'link'   => 'modules/payroll/index.php',
        'metric' => dashboard_metric(
            'SELECT COALESCE(SUM(BalanceAmount), 0) FROM ProfessionalPayroll
              WHERE PayrollStatus <> ? AND BalanceAmount > 0',
            ['ProfessionalPayroll'],
            ['Cancelled']
        ),
    ],
    [
        'label'  => 'Total Commission Payable',
        'icon'   => 'bi-percent',
        'tone'   => 'purple',
        'money'  => true,
        'link'   => 'modules/commissions/index.php',
        'metric' => dashboard_metric(
            'SELECT COALESCE(SUM(CommissionAmount - PaidAmount), 0) FROM ProfessionalCommissions
              WHERE CommissionStatus NOT IN (?, ?) AND CommissionAmount > PaidAmount',
            ['ProfessionalCommissions'],
            ['Cancelled', 'Paid']
        ),
    ],
];

/* ---------------------------------------------------------------------
 * Recent activity
 * ------------------------------------------------------------------- */
$recentRequests = dashboard_rows(
    'SELECT sr.uid, sr.RequestNo, sr.RequestDateTime, sr.RequestStatus, sr.FinalAmount,
            c.FullName AS CustomerName, c.MobileNo, st.ServiceName
       FROM ServiceRequests sr
       INNER JOIN Customers c     ON c.uid = sr.CustomerId
       INNER JOIN ServiceTypes st ON st.uid = sr.ServiceTypeId
      ORDER BY sr.RequestDateTime DESC, sr.uid DESC
      LIMIT ?',
    ['ServiceRequests', 'Customers', 'ServiceTypes'],
    [DASHBOARD_RECENT_LIMIT]
);

$recentAssignments = dashboard_rows(
    'SELECT sa.uid, sa.AssignedDateTime, sa.AssignmentStatus,
            sr.RequestNo, sp.FullName AS ProfessionalName, sp.ProfessionalCode,
            c.FullName AS CustomerName, st.ServiceName
       FROM ServiceAssignments sa
       INNER JOIN ServiceRequests sr      ON sr.uid = sa.ServiceRequestId
       INNER JOIN ServiceProfessionals sp ON sp.uid = sa.ProfessionalId
       INNER JOIN Customers c             ON c.uid = sr.CustomerId
       INNER JOIN ServiceTypes st         ON st.uid = sr.ServiceTypeId
      ORDER BY sa.AssignedDateTime DESC, sa.uid DESC
      LIMIT ?',
    ['ServiceAssignments', 'ServiceRequests', 'ServiceProfessionals', 'Customers', 'ServiceTypes'],
    [DASHBOARD_RECENT_LIMIT]
);

/* ---------------------------------------------------------------------
 * Monthly service summary (last N months, including the current month)
 * ------------------------------------------------------------------- */
$firstMonth   = new DateTimeImmutable('first day of this month 00:00:00');
$summaryStart = $firstMonth->modify('-' . (DASHBOARD_SUMMARY_MONTHS - 1) . ' months');

$monthlySummary = [];
for ($monthIndex = 0; $monthIndex < DASHBOARD_SUMMARY_MONTHS; $monthIndex++) {
    $month = $summaryStart->modify('+' . $monthIndex . ' months');
    $monthlySummary[$month->format('Y-m')] = [
        'label'     => $month->format('M Y'),
        'total'     => 0,
        'completed' => 0,
        'cancelled' => 0,
        'revenue'   => 0.0,
    ];
}

$monthlyResult = dashboard_rows(
    "SELECT DATE_FORMAT(RequestDateTime, '%Y-%m') AS MonthKey,
            COUNT(*) AS TotalRequests,
            SUM(RequestStatus = ?) AS CompletedRequests,
            SUM(RequestStatus = ?) AS CancelledRequests,
            COALESCE(SUM(CASE WHEN RequestStatus = ? THEN FinalAmount ELSE 0 END), 0) AS CompletedRevenue
       FROM ServiceRequests
      WHERE RequestDateTime >= ?
      GROUP BY MonthKey
      ORDER BY MonthKey",
    ['ServiceRequests'],
    ['Completed', 'Cancelled', 'Completed', $summaryStart->format('Y-m-d H:i:s')]
);

foreach ($monthlyResult['rows'] as $monthRow) {
    $key = (string) $monthRow['MonthKey'];
    if (isset($monthlySummary[$key])) {
        $monthlySummary[$key]['total']     = (int) $monthRow['TotalRequests'];
        $monthlySummary[$key]['completed'] = (int) $monthRow['CompletedRequests'];
        $monthlySummary[$key]['cancelled'] = (int) $monthRow['CancelledRequests'];
        $monthlySummary[$key]['revenue']   = (float) $monthRow['CompletedRevenue'];
    }
}

$maxMonthlyRequests = max(1, ...array_column($monthlySummary, 'total'));
$summaryTotals = [
    'total'     => array_sum(array_column($monthlySummary, 'total')),
    'completed' => array_sum(array_column($monthlySummary, 'completed')),
    'cancelled' => array_sum(array_column($monthlySummary, 'cancelled')),
    'revenue'   => array_sum(array_column($monthlySummary, 'revenue')),
];

/* ---------------------------------------------------------------------
 * Missing dependencies (shown in one notice at the top)
 * ------------------------------------------------------------------- */
$allMissingTables = [];
foreach ($summaryCards as $card) {
    $allMissingTables = array_merge($allMissingTables, $card['metric']['missing']);
}
foreach ([$recentRequests, $recentAssignments, $monthlyResult] as $section) {
    $allMissingTables = array_merge($allMissingTables, $section['missing']);
}
$allMissingTables = array_values(array_unique($allMissingTables));

$quickActions = [
    ['label' => 'Add Customer',        'icon' => 'bi-person-plus',     'path' => 'modules/customers/create.php',        'style' => 'primary'],
    ['label' => 'New Service Request', 'icon' => 'bi-clipboard-plus',  'path' => 'modules/service_requests/create.php', 'style' => 'success'],
    ['label' => 'Add Professional',    'icon' => 'bi-person-badge',    'path' => 'modules/professionals/create.php',    'style' => 'indigo'],
    ['label' => 'View Payroll',        'icon' => 'bi-calendar-check',  'path' => 'modules/payroll/index.php',           'style' => 'dark'],
];

$authUser     = current_user();
$pageTitle    = 'Dashboard';
$pageSubtitle = 'Welcome, ' . ($authUser['full_name'] ?? 'User') . '. Here is an overview of your service operations.';
$activeMenu   = 'dashboard';

require __DIR__ . '/../includes/header.php';
?>

<?php if ($allMissingTables !== []): ?>
    <div class="alert alert-warning d-flex gap-2" role="alert">
        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
        <div>
            <strong>Missing database dependencies.</strong>
            The following tables were not found in <code><?= e(DB_NAME) ?></code>, so related figures show 0:
            <?php foreach ($allMissingTables as $missingTable): ?>
                <code class="ms-1"><?= e($missingTable) ?></code>
            <?php endforeach; ?>.
            Import <code>schema.sql</code> to create them.
        </div>
    </div>
<?php endif; ?>

<!-- Quick actions -->
<section class="quick-actions mb-4" aria-label="Quick actions">
    <?php foreach ($quickActions as $action): ?>
        <a href="<?= e(url($action['path'])) ?>" class="btn btn-quick btn-quick-<?= e($action['style']) ?>">
            <i class="bi <?= e($action['icon']) ?>"></i><span><?= e($action['label']) ?></span>
        </a>
    <?php endforeach; ?>
</section>

<?php
$opsChartLabels = [];
$opsChartValues = [];
$opsChartLinks  = [];
$moneyChartLabels = [];
$moneyChartValues = [];
$moneyChartLinks  = [];
foreach ($summaryCards as $card) {
    $value = (float) $card['metric']['value'];
    if ($card['money']) {
        $moneyChartLabels[] = $card['label'];
        $moneyChartValues[] = round($value, 2);
        $moneyChartLinks[]  = url($card['link']);
    } else {
        $opsChartLabels[] = $card['label'];
        $opsChartValues[] = (int) $value;
        $opsChartLinks[]  = url($card['link']);
    }
}
$dashboardCharts = [
    'ops' => [
        'labels' => $opsChartLabels,
        'values' => $opsChartValues,
        'links'  => $opsChartLinks,
    ],
    'money' => [
        'labels' => $moneyChartLabels,
        'values' => $moneyChartValues,
        'links'  => $moneyChartLinks,
    ],
];
?>

<!-- Summary bar graphs -->
<section class="row g-3 mb-4" aria-label="Summary charts">
    <div class="col-12 col-xl-8">
        <div class="card app-card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="card-title-sm mb-0"><i class="bi bi-bar-chart-fill me-2"></i>Operations Overview</h2>
                <span class="small text-muted">Counts</span>
            </div>
            <div class="card-body chart-panel">
                <canvas id="opsBarChart" aria-label="Operations bar chart" role="img"></canvas>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-4">
        <div class="card app-card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="card-title-sm mb-0"><i class="bi bi-cash-stack me-2"></i>Payables</h2>
                <span class="small text-muted">Amount</span>
            </div>
            <div class="card-body chart-panel">
                <canvas id="moneyBarChart" aria-label="Payables bar chart" role="img"></canvas>
            </div>
        </div>
    </div>
</section>

<div class="row g-3 mb-4">
    <!-- Recent customer requests -->
    <div class="col-12 col-xxl-6">
        <div class="card app-card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="card-title-sm mb-0"><i class="bi bi-clipboard-data me-2"></i>Recent Customer Requests</h2>
                <a href="<?= e(url('modules/service_requests/index.php')) ?>" class="btn btn-sm btn-light">View all</a>
            </div>
            <div class="card-body p-0">
                <?php if ($recentRequests['missing'] !== []): ?>
                    <div class="empty-state"><i class="bi bi-database-exclamation"></i><p>Missing table: <?= e(implode(', ', $recentRequests['missing'])) ?></p></div>
                <?php elseif ($recentRequests['error']): ?>
                    <div class="empty-state text-danger"><i class="bi bi-x-octagon"></i><p>Could not load requests. See the error log.</p></div>
                <?php elseif ($recentRequests['rows'] === []): ?>
                    <div class="empty-state"><i class="bi bi-inbox"></i><p>No customer requests yet.</p></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 app-table">
                            <thead>
                            <tr><th>Request No</th><th>Customer</th><th>Service</th><th>Status</th><th>Date</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recentRequests['rows'] as $request): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e($request['RequestNo']) ?></td>
                                    <td>
                                        <?= e($request['CustomerName']) ?>
                                        <div class="small text-muted"><?= e($request['MobileNo']) ?></div>
                                    </td>
                                    <td><?= e($request['ServiceName']) ?></td>
                                    <td><?= status_badge((string) $request['RequestStatus']) ?></td>
                                    <td class="text-nowrap small"><?= e(format_datetime($request['RequestDateTime'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent service assignments -->
    <div class="col-12 col-xxl-6">
        <div class="card app-card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="card-title-sm mb-0"><i class="bi bi-person-workspace me-2"></i>Recent Service Assignments</h2>
                <a href="<?= e(url('modules/service_assignments/index.php')) ?>" class="btn btn-sm btn-light">View all</a>
            </div>
            <div class="card-body p-0">
                <?php if ($recentAssignments['missing'] !== []): ?>
                    <div class="empty-state"><i class="bi bi-database-exclamation"></i><p>Missing table: <?= e(implode(', ', $recentAssignments['missing'])) ?></p></div>
                <?php elseif ($recentAssignments['error']): ?>
                    <div class="empty-state text-danger"><i class="bi bi-x-octagon"></i><p>Could not load assignments. See the error log.</p></div>
                <?php elseif ($recentAssignments['rows'] === []): ?>
                    <div class="empty-state"><i class="bi bi-inbox"></i><p>No service assignments yet.</p></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 app-table">
                            <thead>
                            <tr><th>Request No</th><th>Professional</th><th>Customer / Service</th><th>Status</th><th>Assigned</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recentAssignments['rows'] as $assignment): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e($assignment['RequestNo']) ?></td>
                                    <td>
                                        <?= e($assignment['ProfessionalName']) ?>
                                        <div class="small text-muted"><?= e($assignment['ProfessionalCode']) ?></div>
                                    </td>
                                    <td>
                                        <?= e($assignment['CustomerName']) ?>
                                        <div class="small text-muted"><?= e($assignment['ServiceName']) ?></div>
                                    </td>
                                    <td><?= status_badge((string) $assignment['AssignmentStatus']) ?></td>
                                    <td class="text-nowrap small"><?= e(format_datetime($assignment['AssignedDateTime'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Monthly service summary -->
<div class="card app-card mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="card-title-sm mb-0"><i class="bi bi-bar-chart me-2"></i>Monthly Service Summary</h2>
        <span class="small text-muted">Last <?= e(DASHBOARD_SUMMARY_MONTHS) ?> months, by request date</span>
    </div>
    <div class="card-body p-0">
        <?php if ($monthlyResult['missing'] !== []): ?>
            <div class="empty-state"><i class="bi bi-database-exclamation"></i><p>Missing table: <?= e(implode(', ', $monthlyResult['missing'])) ?></p></div>
        <?php elseif ($monthlyResult['error']): ?>
            <div class="empty-state text-danger"><i class="bi bi-x-octagon"></i><p>Could not load the monthly summary. See the error log.</p></div>
        <?php else: ?>
            <?php if ($summaryTotals['total'] === 0): ?>
                <div class="alert alert-light border-0 rounded-0 mb-0 small text-muted">
                    <i class="bi bi-info-circle me-1"></i>No service requests recorded in this period yet.
                </div>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0 app-table">
                    <thead>
                    <tr>
                        <th>Month</th>
                        <th class="w-40">Requests</th>
                        <th class="text-end">Completed</th>
                        <th class="text-end">Cancelled</th>
                        <th class="text-end">Revenue (Completed)</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($monthlySummary as $monthData): ?>
                        <?php $barPercent = (int) round($monthData['total'] / $maxMonthlyRequests * 100); ?>
                        <tr>
                            <td class="fw-semibold text-nowrap"><?= e($monthData['label']) ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="summary-bar flex-grow-1" role="img" aria-label="<?= e($monthData['total']) ?> requests">
                                        <span class="summary-bar-fill" data-percent="<?= e($barPercent) ?>"></span>
                                    </div>
                                    <span class="summary-bar-value"><?= e(format_number($monthData['total'])) ?></span>
                                </div>
                            </td>
                            <td class="text-end"><?= e(format_number($monthData['completed'])) ?></td>
                            <td class="text-end"><?= e(format_number($monthData['cancelled'])) ?></td>
                            <td class="text-end text-nowrap"><?= e(format_money($monthData['revenue'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                    <tr class="fw-semibold">
                        <td>Total</td>
                        <td><?= e(format_number($summaryTotals['total'])) ?></td>
                        <td class="text-end"><?= e(format_number($summaryTotals['completed'])) ?></td>
                        <td class="text-end"><?= e(format_number($summaryTotals['cancelled'])) ?></td>
                        <td class="text-end text-nowrap"><?= e(format_money($summaryTotals['revenue'])) ?></td>
                    </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
<script>
(function () {
    var chartData = <?= json_encode($dashboardCharts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var gold = '#ddab46';
    var goldSoft = 'rgba(221, 171, 70, 0.75)';
    var dark = '#1f1808';

    function makeBarChart(canvasId, labels, values, links, isMoney) {
        var canvas = document.getElementById(canvasId);
        if (!canvas || typeof Chart === 'undefined') { return; }

        var chart = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: isMoney ? 'Amount' : 'Count',
                    data: values,
                    backgroundColor: goldSoft,
                    borderColor: gold,
                    borderWidth: 1,
                    borderRadius: 6,
                    maxBarThickness: 42
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                var value = context.parsed.y || 0;
                                if (isMoney) {
                                    return ' ' + Number(value).toLocaleString(undefined, {
                                        minimumFractionDigits: 2,
                                        maximumFractionDigits: 2
                                    });
                                }
                                return ' ' + Number(value).toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: {
                            color: dark,
                            maxRotation: 45,
                            minRotation: 0,
                            font: { size: 11 }
                        },
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            color: dark,
                            precision: 0,
                            callback: function (value) {
                                if (!isMoney) { return value; }
                                return Number(value).toLocaleString();
                            }
                        },
                        grid: { color: 'rgba(0,0,0,0.06)' }
                    }
                },
                onClick: function (event, elements) {
                    if (!elements.length) { return; }
                    var index = elements[0].index;
                    if (links[index]) {
                        window.location.href = links[index];
                    }
                },
                onHover: function (event, elements) {
                    event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
                }
            }
        });

        return chart;
    }

    makeBarChart('opsBarChart', chartData.ops.labels, chartData.ops.values, chartData.ops.links, false);
    makeBarChart('moneyBarChart', chartData.money.labels, chartData.money.values, chartData.money.links, true);
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
