<?php
/**
 * Customer Requests list with search and status filters.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_login();

$activeMenu   = 'service_requests';
$pageTitle    = 'Customer Requests';
$pageSubtitle = 'Create and track customer service requests.';
$forcedStatus = null;

$search         = get_string('q', 100);
$statusFilter   = get_string('status', 20);
$priorityFilter = get_string('priority', 20);

if ($statusFilter !== '' && !in_array($statusFilter, request_statuses(), true)) {
    $statusFilter = '';
}
if ($priorityFilter !== '' && !in_array($priorityFilter, request_priorities(), true)) {
    $priorityFilter = '';
}

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(sr.RequestNo LIKE ? OR c.FullName LIKE ? OR c.MobileNo LIKE ? OR c.CustomerCode LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($statusFilter !== '') {
    $where[] = 'sr.RequestStatus = ?';
    $params[] = $statusFilter;
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
    error_log('[ServiceManagement] service_requests list failed: ' . $exception->getMessage());
    flash('danger', 'Unable to load service requests right now.');
    $requests = [];
}

require __DIR__ . '/_list.php';
