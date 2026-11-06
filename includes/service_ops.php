<?php
/**
 * Shared helpers for Service Requests and Service Assignments.
 */
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Generate the next unique customer code: CUS-####
 */
function generate_customer_code(PDO $pdo): string
{
    $statement = $pdo->query(
        "SELECT CustomerCode FROM Customers
          WHERE CustomerCode REGEXP '^CUS-[0-9]{4}$'
          ORDER BY CustomerCode DESC
          LIMIT 1"
    );
    $latest = $statement->fetchColumn();
    $sequence = 1;
    if (is_string($latest) && preg_match('/(\d{4})$/', $latest, $matches) === 1) {
        $sequence = ((int) $matches[1]) + 1;
    }
    return 'CUS-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
}

/**
 * Generate the next unique request number: SR-YYYYMMDD-####
 */
function generate_request_no(PDO $pdo): string
{
    $prefix = 'SR-' . date('Ymd') . '-';
    $statement = $pdo->prepare(
        'SELECT RequestNo FROM ServiceRequests
          WHERE RequestNo LIKE ?
          ORDER BY RequestNo DESC
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
 * @return array<int, array<string, mixed>>
 */
function active_customers(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT uid, CustomerCode, FullName, MobileNo, Address, Area, City
           FROM Customers
          WHERE IsActive = 1
          ORDER BY FullName'
    );
    return $statement->fetchAll();
}

/**
 * @return array<int, array<string, mixed>>
 */
function active_service_types(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT st.uid, st.ServiceName, st.BasePrice, st.EstimatedDurationMinutes,
                sc.CategoryName
           FROM ServiceTypes st
           INNER JOIN ServiceCategories sc ON sc.uid = st.CategoryId
          WHERE st.IsActive = 1 AND sc.IsActive = 1
          ORDER BY sc.CategoryName, st.ServiceName'
    );
    return $statement->fetchAll();
}

/**
 * @return array<int, array<string, mixed>>
 */
function active_professionals(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT uid, ProfessionalCode, FullName, MobileNo, EmploymentType
           FROM ServiceProfessionals
          WHERE IsActive = 1
            AND (LeavingDate IS NULL OR LeavingDate >= CURDATE())
          ORDER BY FullName'
    );
    return $statement->fetchAll();
}

/**
 * Requests that can still receive a new assignment.
 *
 * @return array<int, array<string, mixed>>
 */
function assignable_service_requests(PDO $pdo): array
{
    $statement = $pdo->query(
        "SELECT sr.uid, sr.RequestNo, sr.RequestStatus, sr.ServiceAddress, sr.Priority,
                c.FullName AS CustomerName, c.MobileNo AS CustomerMobile,
                st.ServiceName
           FROM ServiceRequests sr
           INNER JOIN Customers c ON c.uid = sr.CustomerId
           INNER JOIN ServiceTypes st ON st.uid = sr.ServiceTypeId
          WHERE sr.RequestStatus IN ('Pending', 'Assigned')
          ORDER BY sr.RequestDateTime DESC"
    );
    return $statement->fetchAll();
}

/**
 * @return array<string, mixed>|null
 */
function fetch_service_request(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(
        'SELECT sr.*,
                c.CustomerCode, c.FullName AS CustomerName, c.MobileNo AS CustomerMobile,
                st.ServiceName, st.BasePrice, sc.CategoryName
           FROM ServiceRequests sr
           INNER JOIN Customers c ON c.uid = sr.CustomerId
           INNER JOIN ServiceTypes st ON st.uid = sr.ServiceTypeId
           INNER JOIN ServiceCategories sc ON sc.uid = st.CategoryId
          WHERE sr.uid = ?'
    );
    $statement->execute([$id]);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

/**
 * @return array<string, mixed>|null
 */
function fetch_service_assignment(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(
        'SELECT sa.*,
                sr.RequestNo, sr.RequestStatus, sr.ServiceAddress, sr.Priority, sr.FinalAmount,
                c.FullName AS CustomerName, c.MobileNo AS CustomerMobile,
                st.ServiceName,
                sp.ProfessionalCode, sp.FullName AS ProfessionalName, sp.MobileNo AS ProfessionalMobile
           FROM ServiceAssignments sa
           INNER JOIN ServiceRequests sr ON sr.uid = sa.ServiceRequestId
           INNER JOIN Customers c ON c.uid = sr.CustomerId
           INNER JOIN ServiceTypes st ON st.uid = sr.ServiceTypeId
           INNER JOIN ServiceProfessionals sp ON sp.uid = sa.ProfessionalId
          WHERE sa.uid = ?'
    );
    $statement->execute([$id]);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

/**
 * @return list<string>
 */
function request_statuses(): array
{
    return ['Pending', 'Assigned', 'InProgress', 'Completed', 'Cancelled'];
}

/**
 * @return list<string>
 */
function assignment_statuses(): array
{
    return ['Assigned', 'Accepted', 'InProgress', 'Completed', 'Cancelled', 'Reassigned'];
}

/**
 * @return list<string>
 */
function request_priorities(): array
{
    return ['Low', 'Normal', 'High', 'Urgent'];
}

/**
 * @return list<string>
 */
function employment_types(): array
{
    return ['Salaried', 'Commission', 'SalaryPlusCommission'];
}

/**
 * Generate next professional code: PRO-####
 */
function generate_professional_code(PDO $pdo): string
{
    $statement = $pdo->query(
        "SELECT ProfessionalCode FROM ServiceProfessionals
          WHERE ProfessionalCode REGEXP '^PRO-[0-9]{4}$'
          ORDER BY ProfessionalCode DESC
          LIMIT 1"
    );
    $latest = $statement->fetchColumn();
    $sequence = 1;
    if (is_string($latest) && preg_match('/(\d{4})$/', $latest, $matches) === 1) {
        $sequence = ((int) $matches[1]) + 1;
    }
    return 'PRO-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
}

/**
 * @return array<int, array<string, mixed>>
 */
function active_service_categories(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT uid, CategoryName
           FROM ServiceCategories
          WHERE IsActive = 1
          ORDER BY CategoryName'
    );
    return $statement->fetchAll();
}

/**
 * @return array<string, mixed>|null
 */
function fetch_professional(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(
        'SELECT sp.*, sc.CategoryName
           FROM ServiceProfessionals sp
           LEFT JOIN ServiceCategories sc ON sc.uid = sp.PrimaryCategoryId
          WHERE sp.uid = ?'
    );
    $statement->execute([$id]);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

/**
 * @return array<string, mixed>|null
 */
function fetch_service_type(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(
        'SELECT st.*, sc.CategoryName
           FROM ServiceTypes st
           INNER JOIN ServiceCategories sc ON sc.uid = st.CategoryId
          WHERE st.uid = ?'
    );
    $statement->execute([$id]);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

/**
 * @return array<string, mixed>|null
 */
function fetch_service_category(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(
        'SELECT *
           FROM ServiceCategories
          WHERE uid = ?'
    );
    $statement->execute([$id]);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}
