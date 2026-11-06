-- =====================================================================
-- Service Management System - Database Schema
-- Database : ServiceManagementDB
-- Engine   : InnoDB, utf8mb4
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

CREATE DATABASE IF NOT EXISTS ServiceManagementDB
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE ServiceManagementDB;


-- =====================================================================
-- MASTER TABLES
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. Customers
-- ---------------------------------------------------------------------
CREATE TABLE Customers (
    uid                 INT AUTO_INCREMENT PRIMARY KEY,
    CustomerCode        VARCHAR(20)  NOT NULL,
    FullName            VARCHAR(150) NOT NULL,
    MobileNo            VARCHAR(20)  NOT NULL,
    AlternateMobileNo   VARCHAR(20)  NULL,
    Email               VARCHAR(150) NULL,
    CNIC                VARCHAR(20)  NULL,
    Address             VARCHAR(500) NULL,
    Area                VARCHAR(100) NULL,
    City                VARCHAR(100) NULL,
    Notes               VARCHAR(1000) NULL,
    IsActive            TINYINT(1)   NOT NULL DEFAULT 1,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT UQ_Customers_CustomerCode UNIQUE (CustomerCode),
    INDEX IX_Customers_MobileNo (MobileNo),
    INDEX IX_Customers_FullName (FullName),
    INDEX IX_Customers_City_Area (City, Area),
    INDEX IX_Customers_IsActive (IsActive)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 2. ServiceCategories
-- ---------------------------------------------------------------------
CREATE TABLE ServiceCategories (
    uid                 INT AUTO_INCREMENT PRIMARY KEY,
    CategoryName        VARCHAR(100) NOT NULL,
    Description         VARCHAR(500) NULL,
    IsActive            TINYINT(1)   NOT NULL DEFAULT 1,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT UQ_ServiceCategories_CategoryName UNIQUE (CategoryName),
    INDEX IX_ServiceCategories_IsActive (IsActive)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 3. ServiceTypes
-- ---------------------------------------------------------------------
CREATE TABLE ServiceTypes (
    uid                       INT AUTO_INCREMENT PRIMARY KEY,
    CategoryId                INT           NOT NULL,
    ServiceName               VARCHAR(150)  NOT NULL,
    Description               VARCHAR(500)  NULL,
    BasePrice                 DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    EstimatedDurationMinutes  INT           NULL,
    IsActive                  TINYINT(1)    NOT NULL DEFAULT 1,
    created_at                DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT UQ_ServiceTypes_Category_Name UNIQUE (CategoryId, ServiceName),
    CONSTRAINT FK_ServiceTypes_Category
        FOREIGN KEY (CategoryId) REFERENCES ServiceCategories(uid)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT CK_ServiceTypes_BasePrice CHECK (BasePrice >= 0),
    INDEX IX_ServiceTypes_CategoryId (CategoryId),
    INDEX IX_ServiceTypes_ServiceName (ServiceName),
    INDEX IX_ServiceTypes_IsActive (IsActive)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 4. ServiceProfessionals
-- ---------------------------------------------------------------------
CREATE TABLE ServiceProfessionals (
    uid                 INT AUTO_INCREMENT PRIMARY KEY,
    ProfessionalCode    VARCHAR(20)  NOT NULL,
    FullName            VARCHAR(150) NOT NULL,
    MobileNo            VARCHAR(20)  NOT NULL,
    AlternateMobileNo   VARCHAR(20)  NULL,
    Email               VARCHAR(150) NULL,
    CNIC                VARCHAR(20)  NULL,
    Address             VARCHAR(500) NULL,
    City                VARCHAR(100) NULL,
    PrimaryCategoryId   INT          NULL,
    EmploymentType      ENUM('Salaried','Commission','SalaryPlusCommission') NOT NULL DEFAULT 'SalaryPlusCommission',
    JoiningDate         DATE         NOT NULL,
    LeavingDate         DATE         NULL,
    BankName            VARCHAR(100) NULL,
    BankAccountNo       VARCHAR(50)  NULL,
    IsActive            TINYINT(1)   NOT NULL DEFAULT 1,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT UQ_ServiceProfessionals_Code UNIQUE (ProfessionalCode),
    CONSTRAINT FK_ServiceProfessionals_PrimaryCategory
        FOREIGN KEY (PrimaryCategoryId) REFERENCES ServiceCategories(uid)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT CK_ServiceProfessionals_Dates
        CHECK (LeavingDate IS NULL OR LeavingDate >= JoiningDate),
    INDEX IX_ServiceProfessionals_MobileNo (MobileNo),
    INDEX IX_ServiceProfessionals_FullName (FullName),
    INDEX IX_ServiceProfessionals_PrimaryCategoryId (PrimaryCategoryId),
    INDEX IX_ServiceProfessionals_IsActive (IsActive)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- SERVICE MANAGEMENT
-- =====================================================================

-- ---------------------------------------------------------------------
-- 5. ServiceRequests
-- ---------------------------------------------------------------------
CREATE TABLE ServiceRequests (
    uid                 INT AUTO_INCREMENT PRIMARY KEY,
    RequestNo           VARCHAR(30)   NOT NULL,
    CustomerId          INT           NOT NULL,
    ServiceTypeId       INT           NOT NULL,
    RequestDateTime     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PreferredDate       DATE          NULL,
    PreferredTimeSlot   VARCHAR(50)   NULL,
    ServiceAddress      VARCHAR(500)  NOT NULL,
    ProblemDescription  VARCHAR(2000) NULL,
    Priority            ENUM('Low','Normal','High','Urgent') NOT NULL DEFAULT 'Normal',
    EstimatedAmount     DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    DiscountAmount      DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    FinalAmount         DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    RequestStatus       ENUM('Pending','Assigned','InProgress','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
    CompletedDateTime   DATETIME      NULL,
    CancelledDateTime   DATETIME      NULL,
    CancellationReason  VARCHAR(500)  NULL,
    Remarks             VARCHAR(1000) NULL,
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT UQ_ServiceRequests_RequestNo UNIQUE (RequestNo),
    CONSTRAINT FK_ServiceRequests_Customer
        FOREIGN KEY (CustomerId) REFERENCES Customers(uid)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT FK_ServiceRequests_ServiceType
        FOREIGN KEY (ServiceTypeId) REFERENCES ServiceTypes(uid)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT CK_ServiceRequests_Amounts
        CHECK (EstimatedAmount >= 0 AND DiscountAmount >= 0 AND FinalAmount >= 0),
    INDEX IX_ServiceRequests_CustomerId (CustomerId),
    INDEX IX_ServiceRequests_ServiceTypeId (ServiceTypeId),
    INDEX IX_ServiceRequests_RequestStatus (RequestStatus),
    INDEX IX_ServiceRequests_RequestDateTime (RequestDateTime),
    INDEX IX_ServiceRequests_Status_Date (RequestStatus, RequestDateTime),
    INDEX IX_ServiceRequests_PreferredDate (PreferredDate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 6. ServiceAssignments
-- ---------------------------------------------------------------------
CREATE TABLE ServiceAssignments (
    uid                   INT AUTO_INCREMENT PRIMARY KEY,
    ServiceRequestId      INT           NOT NULL,
    ProfessionalId        INT           NOT NULL,
    AssignedDateTime      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ServiceStartDateTime  DATETIME      NULL,
    CompletionDateTime    DATETIME      NULL,
    AgreedCommission      DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    CommissionType        ENUM('Percentage','Fixed') NOT NULL DEFAULT 'Percentage',
    AssignmentStatus      ENUM('Assigned','Accepted','InProgress','Completed','Cancelled','Reassigned') NOT NULL DEFAULT 'Assigned',
    Remarks               VARCHAR(1000) NULL,
    created_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT FK_ServiceAssignments_Request
        FOREIGN KEY (ServiceRequestId) REFERENCES ServiceRequests(uid)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT FK_ServiceAssignments_Professional
        FOREIGN KEY (ProfessionalId) REFERENCES ServiceProfessionals(uid)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT CK_ServiceAssignments_Commission CHECK (AgreedCommission >= 0),
    CONSTRAINT CK_ServiceAssignments_Percentage
        CHECK (CommissionType <> 'Percentage' OR AgreedCommission <= 100),
    CONSTRAINT CK_ServiceAssignments_Dates CHECK (
        (ServiceStartDateTime IS NULL OR ServiceStartDateTime >= AssignedDateTime) AND
        (CompletionDateTime   IS NULL OR ServiceStartDateTime IS NULL OR CompletionDateTime >= ServiceStartDateTime)
    ),
    INDEX IX_ServiceAssignments_ServiceRequestId (ServiceRequestId),
    INDEX IX_ServiceAssignments_ProfessionalId (ProfessionalId),
    INDEX IX_ServiceAssignments_AssignmentStatus (AssignmentStatus),
    INDEX IX_ServiceAssignments_Professional_Status (ProfessionalId, AssignmentStatus),
    INDEX IX_ServiceAssignments_AssignedDateTime (AssignedDateTime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- SALARY & COMMISSION
-- =====================================================================

-- ---------------------------------------------------------------------
-- 7. ProfessionalSalaryHistory
--    One row per salary revision. EffectiveTo NULL = current salary.
-- ---------------------------------------------------------------------
CREATE TABLE ProfessionalSalaryHistory (
    uid                 INT AUTO_INCREMENT PRIMARY KEY,
    ProfessionalId      INT           NOT NULL,
    BasicSalary         DECIMAL(18,2) NOT NULL,
    Allowances          DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    EffectiveFrom       DATE          NOT NULL,
    EffectiveTo         DATE          NULL,
    RevisionReason      VARCHAR(500)  NULL,
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT UQ_ProfessionalSalaryHistory_Prof_From UNIQUE (ProfessionalId, EffectiveFrom),
    CONSTRAINT FK_ProfessionalSalaryHistory_Professional
        FOREIGN KEY (ProfessionalId) REFERENCES ServiceProfessionals(uid)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT CK_ProfessionalSalaryHistory_Amounts CHECK (BasicSalary >= 0 AND Allowances >= 0),
    CONSTRAINT CK_ProfessionalSalaryHistory_Dates
        CHECK (EffectiveTo IS NULL OR EffectiveTo >= EffectiveFrom),
    INDEX IX_ProfessionalSalaryHistory_ProfessionalId (ProfessionalId),
    INDEX IX_ProfessionalSalaryHistory_Effective (ProfessionalId, EffectiveFrom, EffectiveTo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 8. ProfessionalCommissionRates
--    ServiceTypeId NULL = default rate for all service types.
-- ---------------------------------------------------------------------
CREATE TABLE ProfessionalCommissionRates (
    uid                 INT AUTO_INCREMENT PRIMARY KEY,
    ProfessionalId      INT           NOT NULL,
    ServiceTypeId       INT           NULL,
    CommissionType      ENUM('Percentage','Fixed') NOT NULL DEFAULT 'Percentage',
    CommissionValue     DECIMAL(18,2) NOT NULL,
    EffectiveFrom       DATE          NOT NULL,
    EffectiveTo         DATE          NULL,
    IsActive            TINYINT(1)    NOT NULL DEFAULT 1,
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT FK_ProfessionalCommissionRates_Professional
        FOREIGN KEY (ProfessionalId) REFERENCES ServiceProfessionals(uid)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT FK_ProfessionalCommissionRates_ServiceType
        FOREIGN KEY (ServiceTypeId) REFERENCES ServiceTypes(uid)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT CK_ProfessionalCommissionRates_Value CHECK (CommissionValue >= 0),
    CONSTRAINT CK_ProfessionalCommissionRates_Percentage
        CHECK (CommissionType <> 'Percentage' OR CommissionValue <= 100),
    CONSTRAINT CK_ProfessionalCommissionRates_Dates
        CHECK (EffectiveTo IS NULL OR EffectiveTo >= EffectiveFrom),
    INDEX IX_ProfessionalCommissionRates_ProfessionalId (ProfessionalId),
    INDEX IX_ProfessionalCommissionRates_ServiceTypeId (ServiceTypeId),
    INDEX IX_ProfessionalCommissionRates_Lookup (ProfessionalId, ServiceTypeId, EffectiveFrom, EffectiveTo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 9. ProfessionalCommissions
--    Commission earned per completed assignment (one row per assignment).
-- ---------------------------------------------------------------------
CREATE TABLE ProfessionalCommissions (
    uid                 INT AUTO_INCREMENT PRIMARY KEY,
    AssignmentId        INT           NOT NULL,
    ProfessionalId      INT           NOT NULL,
    ServiceRequestId    INT           NOT NULL,
    ServiceAmount       DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    CommissionType      ENUM('Percentage','Fixed') NOT NULL,
    CommissionRate      DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    CommissionAmount    DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    PaidAmount          DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    CommissionDate      DATE          NOT NULL,
    CommissionStatus    ENUM('Pending','Approved','PartiallyPaid','Paid','Cancelled') NOT NULL DEFAULT 'Pending',
    Remarks             VARCHAR(500)  NULL,
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT UQ_ProfessionalCommissions_AssignmentId UNIQUE (AssignmentId),
    CONSTRAINT FK_ProfessionalCommissions_Assignment
        FOREIGN KEY (AssignmentId) REFERENCES ServiceAssignments(uid)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT FK_ProfessionalCommissions_Professional
        FOREIGN KEY (ProfessionalId) REFERENCES ServiceProfessionals(uid)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT FK_ProfessionalCommissions_Request
        FOREIGN KEY (ServiceRequestId) REFERENCES ServiceRequests(uid)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT CK_ProfessionalCommissions_Amounts CHECK (
        ServiceAmount >= 0 AND CommissionRate >= 0 AND CommissionAmount >= 0 AND
        PaidAmount >= 0 AND PaidAmount <= CommissionAmount
    ),
    INDEX IX_ProfessionalCommissions_ProfessionalId (ProfessionalId),
    INDEX IX_ProfessionalCommissions_ServiceRequestId (ServiceRequestId),
    INDEX IX_ProfessionalCommissions_Status (CommissionStatus),
    INDEX IX_ProfessionalCommissions_Prof_Date (ProfessionalId, CommissionDate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- PAYROLL
-- =====================================================================

-- ---------------------------------------------------------------------
-- 10. ProfessionalPayroll
--     One payroll record per professional per month.
--     PayrollMonth stores the first day of the month (e.g. 2026-11-01).
-- ---------------------------------------------------------------------
CREATE TABLE ProfessionalPayroll (
    uid                 INT AUTO_INCREMENT PRIMARY KEY,
    PayrollNo           VARCHAR(30)   NOT NULL,
    ProfessionalId      INT           NOT NULL,
    SalaryHistoryId     INT           NULL,
    PayrollMonth        DATE          NOT NULL,
    PeriodStart         DATE          NOT NULL,
    PeriodEnd           DATE          NOT NULL,
    BasicSalary         DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    Allowances          DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    CommissionAmount    DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    Bonus               DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    Deductions          DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    NetPayable          DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    PaidAmount          DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    BalanceAmount       DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    PayrollStatus       ENUM('Draft','Approved','PartiallyPaid','Paid','Cancelled') NOT NULL DEFAULT 'Draft',
    Remarks             VARCHAR(500)  NULL,
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT UQ_ProfessionalPayroll_PayrollNo UNIQUE (PayrollNo),
    CONSTRAINT UQ_ProfessionalPayroll_Prof_Month UNIQUE (ProfessionalId, PayrollMonth),
    CONSTRAINT FK_ProfessionalPayroll_Professional
        FOREIGN KEY (ProfessionalId) REFERENCES ServiceProfessionals(uid)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT FK_ProfessionalPayroll_SalaryHistory
        FOREIGN KEY (SalaryHistoryId) REFERENCES ProfessionalSalaryHistory(uid)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT CK_ProfessionalPayroll_Period CHECK (PeriodEnd >= PeriodStart),
    CONSTRAINT CK_ProfessionalPayroll_Amounts CHECK (
        BasicSalary >= 0 AND Allowances >= 0 AND CommissionAmount >= 0 AND
        Bonus >= 0 AND Deductions >= 0 AND PaidAmount >= 0
    ),
    INDEX IX_ProfessionalPayroll_ProfessionalId (ProfessionalId),
    INDEX IX_ProfessionalPayroll_SalaryHistoryId (SalaryHistoryId),
    INDEX IX_ProfessionalPayroll_PayrollMonth (PayrollMonth),
    INDEX IX_ProfessionalPayroll_Status (PayrollStatus)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 11. ProfessionalSalaryPayments
--     Many rows per payroll record = multiple partial salary withdrawals.
-- ---------------------------------------------------------------------
CREATE TABLE ProfessionalSalaryPayments (
    uid                 INT AUTO_INCREMENT PRIMARY KEY,
    PaymentNo           VARCHAR(30)   NOT NULL,
    PayrollId           INT           NOT NULL,
    ProfessionalId      INT           NOT NULL,
    PaymentDateTime     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Amount              DECIMAL(18,2) NOT NULL,
    PaymentMethod       ENUM('Cash','BankTransfer','Cheque','MobileWallet','Other') NOT NULL DEFAULT 'Cash',
    ReferenceNo         VARCHAR(100)  NULL,
    Remarks             VARCHAR(500)  NULL,
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT UQ_ProfessionalSalaryPayments_PaymentNo UNIQUE (PaymentNo),
    CONSTRAINT FK_ProfessionalSalaryPayments_Payroll
        FOREIGN KEY (PayrollId) REFERENCES ProfessionalPayroll(uid)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT FK_ProfessionalSalaryPayments_Professional
        FOREIGN KEY (ProfessionalId) REFERENCES ServiceProfessionals(uid)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT CK_ProfessionalSalaryPayments_Amount CHECK (Amount > 0),
    INDEX IX_ProfessionalSalaryPayments_PayrollId (PayrollId),
    INDEX IX_ProfessionalSalaryPayments_ProfessionalId (ProfessionalId),
    INDEX IX_ProfessionalSalaryPayments_PaymentDateTime (PaymentDateTime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 12. ProfessionalCommissionPayments
--     Payments against earned commissions; optionally settled via payroll.
-- ---------------------------------------------------------------------
CREATE TABLE ProfessionalCommissionPayments (
    uid                 INT AUTO_INCREMENT PRIMARY KEY,
    PaymentNo           VARCHAR(30)   NOT NULL,
    CommissionId        INT           NOT NULL,
    ProfessionalId      INT           NOT NULL,
    PayrollId           INT           NULL,
    PaymentDateTime     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Amount              DECIMAL(18,2) NOT NULL,
    PaymentMethod       ENUM('Cash','BankTransfer','Cheque','MobileWallet','Payroll','Other') NOT NULL DEFAULT 'Cash',
    ReferenceNo         VARCHAR(100)  NULL,
    Remarks             VARCHAR(500)  NULL,
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT UQ_ProfessionalCommissionPayments_PaymentNo UNIQUE (PaymentNo),
    CONSTRAINT FK_ProfessionalCommissionPayments_Commission
        FOREIGN KEY (CommissionId) REFERENCES ProfessionalCommissions(uid)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT FK_ProfessionalCommissionPayments_Professional
        FOREIGN KEY (ProfessionalId) REFERENCES ServiceProfessionals(uid)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT FK_ProfessionalCommissionPayments_Payroll
        FOREIGN KEY (PayrollId) REFERENCES ProfessionalPayroll(uid)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT CK_ProfessionalCommissionPayments_Amount CHECK (Amount > 0),
    INDEX IX_ProfessionalCommissionPayments_CommissionId (CommissionId),
    INDEX IX_ProfessionalCommissionPayments_ProfessionalId (ProfessionalId),
    INDEX IX_ProfessionalCommissionPayments_PayrollId (PayrollId),
    INDEX IX_ProfessionalCommissionPayments_PaymentDateTime (PaymentDateTime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- FINANCE
-- =====================================================================

-- ---------------------------------------------------------------------
-- 13. CustomerPayments
-- ---------------------------------------------------------------------
CREATE TABLE CustomerPayments (
    uid                 INT AUTO_INCREMENT PRIMARY KEY,
    ReceiptNo           VARCHAR(30)   NOT NULL,
    CustomerId          INT           NOT NULL,
    ServiceRequestId    INT           NULL,
    PaymentDateTime     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Amount              DECIMAL(18,2) NOT NULL,
    PaymentType         ENUM('Advance','Partial','Full','Refund') NOT NULL DEFAULT 'Full',
    PaymentMethod       ENUM('Cash','BankTransfer','Cheque','Card','MobileWallet','Other') NOT NULL DEFAULT 'Cash',
    ReferenceNo         VARCHAR(100)  NULL,
    Remarks             VARCHAR(500)  NULL,
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT UQ_CustomerPayments_ReceiptNo UNIQUE (ReceiptNo),
    CONSTRAINT FK_CustomerPayments_Customer
        FOREIGN KEY (CustomerId) REFERENCES Customers(uid)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT FK_CustomerPayments_Request
        FOREIGN KEY (ServiceRequestId) REFERENCES ServiceRequests(uid)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT CK_CustomerPayments_Amount CHECK (Amount > 0),
    INDEX IX_CustomerPayments_CustomerId (CustomerId),
    INDEX IX_CustomerPayments_ServiceRequestId (ServiceRequestId),
    INDEX IX_CustomerPayments_PaymentDateTime (PaymentDateTime),
    INDEX IX_CustomerPayments_PaymentType (PaymentType)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 14. Expenses
-- ---------------------------------------------------------------------
CREATE TABLE Expenses (
    uid                 INT AUTO_INCREMENT PRIMARY KEY,
    ExpenseNo           VARCHAR(30)   NOT NULL,
    ExpenseDate         DATE          NOT NULL,
    ExpenseCategory     VARCHAR(100)  NOT NULL,
    Description         VARCHAR(500)  NULL,
    Amount              DECIMAL(18,2) NOT NULL,
    PaidTo              VARCHAR(150)  NULL,
    PaymentMethod       ENUM('Cash','BankTransfer','Cheque','Card','MobileWallet','Other') NOT NULL DEFAULT 'Cash',
    ReferenceNo         VARCHAR(100)  NULL,
    ServiceRequestId    INT           NULL,
    ProfessionalId      INT           NULL,
    Remarks             VARCHAR(500)  NULL,
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT UQ_Expenses_ExpenseNo UNIQUE (ExpenseNo),
    CONSTRAINT FK_Expenses_Request
        FOREIGN KEY (ServiceRequestId) REFERENCES ServiceRequests(uid)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT FK_Expenses_Professional
        FOREIGN KEY (ProfessionalId) REFERENCES ServiceProfessionals(uid)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT CK_Expenses_Amount CHECK (Amount > 0),
    INDEX IX_Expenses_ExpenseDate (ExpenseDate),
    INDEX IX_Expenses_ExpenseCategory (ExpenseCategory),
    INDEX IX_Expenses_ServiceRequestId (ServiceRequestId),
    INDEX IX_Expenses_ProfessionalId (ProfessionalId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- End of script
-- =====================================================================
