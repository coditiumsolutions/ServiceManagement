<?php
/**
 * In-progress service requests.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_login();

$activeMenu   = 'in_progress';
$pageTitle    = 'In Progress Services';
$pageSubtitle = 'All service requests that are not yet completed.';
$forcedStatus = 'Active';

$search         = get_string('q', 100);
$statusFilter   = '';
$priorityFilter = get_string('priority', 20);

if ($priorityFilter !== '' && !in_array($priorityFilter, request_priorities(), true)) {
    $priorityFilter = '';
}

$where  = ['sr.RequestStatus <> ?'];
$params = ['Completed'];

if ($search !== '') {
    $where[] = '(sr.RequestNo LIKE ? OR c.FullName LIKE ? OR c.MobileNo LIKE ? OR c.CustomerCode LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($priorityFilter !== '') {
    $where[] = 'sr.Priority = ?';
    $params[] = $priorityFilter;
}

$sql = 'SELECT sr.uid, sr.RequestNo, sr.RequestDateTime, sr.PreferredDate, sr.PreferredTimeSlot,
               sr.Priority, sr.FinalAmount, sr.RequestStatus,
               c.FullName AS CustomerName, c.MobileNo AS CustomerMobile,
               st.ServiceName
          FROM ServiceRequests sr
          INNER JOIN Customers c ON c.uid = sr.CustomerId
          INNER JOIN ServiceTypes st ON st.uid = sr.ServiceTypeId
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY sr.RequestDateTime DESC
         LIMIT 200';

try {
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $requests = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log('[ServiceManagement] in_progress list failed: ' . $exception->getMessage());
    flash('danger', 'Unable to load in-progress services right now.');
    $requests = [];
}

require __DIR__ . '/_list.php';
