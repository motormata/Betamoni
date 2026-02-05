-- Example Queries for Understanding the Micro-Lending System
-- These queries help you see how the calculation logic works

-- ============================================
-- 1. CASH IN HAND
-- ============================================
-- This should match what the API returns for cash position
SELECT 
    SUM(amount) as cash_in_hand,
    SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as total_inflow,
    SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END) as total_outflow
FROM cash_ledger;

-- Breakdown by transaction type
SELECT 
    transaction_type,
    COUNT(*) as transaction_count,
    SUM(amount) as total_amount
FROM cash_ledger
GROUP BY transaction_type
ORDER BY transaction_type;


-- ============================================
-- 2. LOAN RECOVERED PER DAY
-- ============================================
-- Collections for a specific date (change the date as needed)
SELECT 
    DATE(payment_date) as collection_date,
    COUNT(*) as payment_count,
    SUM(amount) as total_collected
FROM payments
WHERE payment_date = '2026-01-26'
  AND is_verified = true
GROUP BY DATE(payment_date);

-- Breakdown by loan type for the day
SELECT 
    l.repayment_frequency,
    COUNT(DISTINCT p.id) as payment_count,
    SUM(p.amount) as total_amount
FROM payments p
JOIN loans l ON p.loan_id = l.id
WHERE p.payment_date = '2026-01-26'
  AND p.is_verified = true
GROUP BY l.repayment_frequency;


-- ============================================
-- 3. ACTIVE LOANS
-- ============================================
-- Loans that have unpaid schedules
SELECT 
    l.id,
    l.loan_number,
    l.repayment_frequency,
    l.principal_amount,
    COUNT(rs.id) as total_schedules,
    SUM(CASE WHEN rs.status = 'paid' THEN 1 ELSE 0 END) as paid_schedules,
    COUNT(rs.id) - SUM(CASE WHEN rs.status = 'paid' THEN 1 ELSE 0 END) as unpaid_schedules
FROM loans l
JOIN repayment_schedules rs ON l.id = rs.loan_id
WHERE l.status IN ('disbursed', 'active')
GROUP BY l.id, l.loan_number, l.repayment_frequency, l.principal_amount
HAVING unpaid_schedules > 0;

-- Summary by loan type
SELECT 
    l.repayment_frequency,
    COUNT(DISTINCT l.id) as active_loan_count,
    SUM(l.principal_amount) as total_principal
FROM loans l
WHERE l.status IN ('disbursed', 'active')
  AND EXISTS (
      SELECT 1 FROM repayment_schedules rs 
      WHERE rs.loan_id = l.id 
        AND rs.status != 'paid'
  )
GROUP BY l.repayment_frequency;


-- ============================================
-- 4. REPAYMENTS EXPECTED TODAY
-- ============================================
-- All schedules due today
SELECT 
    rs.id,
    rs.loan_id,
    l.loan_number,
    b.first_name || ' ' || b.last_name as borrower_name,
    rs.expected_amount,
    COALESCE(SUM(p.amount), 0) as amount_paid,
    rs.expected_amount - COALESCE(SUM(p.amount), 0) as outstanding,
    rs.status
FROM repayment_schedules rs
JOIN loans l ON rs.loan_id = l.id
JOIN borrowers b ON l.borrower_id = b.id
LEFT JOIN payments p ON p.repayment_schedule_id = rs.id
WHERE rs.due_date = CURRENT_DATE
GROUP BY rs.id, rs.loan_id, l.loan_number, b.first_name, b.last_name, 
         rs.expected_amount, rs.status;

-- Summary for today
SELECT 
    COUNT(*) as total_schedules,
    SUM(CASE WHEN rs.status = 'paid' THEN 1 ELSE 0 END) as paid_count,
    SUM(CASE WHEN rs.status != 'paid' THEN 1 ELSE 0 END) as pending_count,
    SUM(rs.expected_amount) as total_expected,
    COALESCE(SUM(p.amount), 0) as total_collected
FROM repayment_schedules rs
LEFT JOIN payments p ON p.repayment_schedule_id = rs.id 
    AND p.payment_date = CURRENT_DATE
WHERE rs.due_date = CURRENT_DATE;


-- ============================================
-- 5. TOTAL EXPOSURE (PENDING PAYMENTS)
-- ============================================
-- Complete view of what's owed across all active loans
SELECT 
    l.id,
    l.loan_number,
    b.first_name || ' ' || b.last_name as borrower_name,
    l.total_amount as original_loan,
    SUM(rs.expected_amount) as total_expected,
    COALESCE(SUM(p.amount), 0) as total_paid,
    SUM(rs.expected_amount) - COALESCE(SUM(p.amount), 0) as balance_outstanding,
    SUM(CASE 
        WHEN rs.due_date < CURRENT_DATE AND rs.status != 'paid' 
        THEN rs.expected_amount - COALESCE(p.amount, 0)
        ELSE 0 
    END) as overdue_amount
FROM loans l
JOIN borrowers b ON l.borrower_id = b.id
JOIN repayment_schedules rs ON l.id = rs.loan_id
LEFT JOIN payments p ON p.repayment_schedule_id = rs.id
WHERE l.status IN ('disbursed', 'active')
GROUP BY l.id, l.loan_number, b.first_name, b.last_name, l.total_amount
HAVING balance_outstanding > 0
ORDER BY balance_outstanding DESC;

-- Portfolio exposure summary
SELECT 
    SUM(rs.expected_amount) as total_expected,
    COALESCE(SUM(p.amount), 0) as total_received,
    SUM(rs.expected_amount) - COALESCE(SUM(p.amount), 0) as total_outstanding,
    COUNT(DISTINCT l.id) as active_loan_count,
    ROUND(
        CASE 
            WHEN SUM(rs.expected_amount) > 0 
            THEN (COALESCE(SUM(p.amount), 0) / SUM(rs.expected_amount)) * 100 
            ELSE 0 
        END, 
        2
    ) as recovery_rate_percentage
FROM loans l
JOIN repayment_schedules rs ON l.id = rs.loan_id
LEFT JOIN payments p ON p.loan_id = l.id
WHERE l.status IN ('disbursed', 'active');


-- ============================================
-- 6. INDIVIDUAL LOAN BALANCE
-- ============================================
-- Detailed breakdown for a specific loan (change loan_id)
WITH loan_summary AS (
    SELECT 
        l.id,
        l.loan_number,
        l.principal_amount,
        l.interest_amount,
        l.total_amount,
        b.first_name || ' ' || b.last_name as borrower_name
    FROM loans l
    JOIN borrowers b ON l.borrower_id = b.id
    WHERE l.id = 1  -- Change this loan ID
),
schedule_summary AS (
    SELECT 
        loan_id,
        SUM(expected_amount) as total_expected
    FROM repayment_schedules
    WHERE loan_id = 1  -- Change this loan ID
    GROUP BY loan_id
),
payment_summary AS (
    SELECT 
        loan_id,
        SUM(amount) as total_paid,
        COUNT(*) as payment_count
    FROM payments
    WHERE loan_id = 1  -- Change this loan ID
    GROUP BY loan_id
)
SELECT 
    ls.*,
    COALESCE(ss.total_expected, 0) as total_expected,
    COALESCE(ps.total_paid, 0) as total_paid,
    COALESCE(ss.total_expected, 0) - COALESCE(ps.total_paid, 0) as balance,
    COALESCE(ps.payment_count, 0) as payments_made
FROM loan_summary ls
LEFT JOIN schedule_summary ss ON ls.id = ss.loan_id
LEFT JOIN payment_summary ps ON ls.id = ps.loan_id;

-- Schedule details for the loan
SELECT 
    rs.installment_number,
    rs.due_date,
    rs.expected_amount,
    COALESCE(SUM(p.amount), 0) as paid,
    rs.expected_amount - COALESCE(SUM(p.amount), 0) as outstanding,
    rs.status,
    CASE 
        WHEN rs.due_date < CURRENT_DATE AND rs.status != 'paid' THEN 'OVERDUE'
        WHEN rs.status = 'paid' THEN 'PAID'
        ELSE 'PENDING'
    END as payment_status
FROM repayment_schedules rs
LEFT JOIN payments p ON p.repayment_schedule_id = rs.id
WHERE rs.loan_id = 1  -- Change this loan ID
GROUP BY rs.id, rs.installment_number, rs.due_date, rs.expected_amount, rs.status
ORDER BY rs.installment_number;


-- ============================================
-- 7. COLLECTION PERFORMANCE
-- ============================================
-- Daily collection trends (last 30 days)
SELECT 
    DATE(p.payment_date) as collection_date,
    COUNT(*) as payment_count,
    SUM(p.amount) as total_collected,
    COUNT(DISTINCT p.loan_id) as unique_loans
FROM payments p
WHERE p.payment_date >= CURRENT_DATE - INTERVAL '30 days'
  AND p.is_verified = true
GROUP BY DATE(p.payment_date)
ORDER BY collection_date DESC;

-- Collection rate by due date
SELECT 
    DATE(rs.due_date) as due_date,
    COUNT(*) as schedules_due,
    SUM(rs.expected_amount) as total_expected,
    COALESCE(SUM(p.amount), 0) as total_collected,
    ROUND(
        CASE 
            WHEN SUM(rs.expected_amount) > 0 
            THEN (COALESCE(SUM(p.amount), 0) / SUM(rs.expected_amount)) * 100 
            ELSE 0 
        END, 
        2
    ) as collection_rate
FROM repayment_schedules rs
LEFT JOIN payments p ON p.repayment_schedule_id = rs.id
WHERE rs.due_date >= CURRENT_DATE - INTERVAL '7 days'
  AND rs.due_date <= CURRENT_DATE
GROUP BY DATE(rs.due_date)
ORDER BY due_date DESC;


-- ============================================
-- 8. AGENT PERFORMANCE
-- ============================================
-- How much each agent has collected
SELECT 
    u.id,
    u.name as agent_name,
    COUNT(DISTINCT p.id) as payments_collected,
    SUM(p.amount) as total_collected,
    COUNT(DISTINCT p.loan_id) as loans_served,
    COUNT(DISTINCT DATE(p.payment_date)) as days_active
FROM users u
JOIN payments p ON u.id = p.collected_by
WHERE u.id IN (SELECT id FROM users WHERE market_id IS NOT NULL)
  AND p.payment_date >= CURRENT_DATE - INTERVAL '30 days'
GROUP BY u.id, u.name
ORDER BY total_collected DESC;


-- ============================================
-- 9. OVERDUE ANALYSIS
-- ============================================
-- All overdue schedules
SELECT 
    b.first_name || ' ' || b.last_name as borrower_name,
    l.loan_number,
    rs.due_date,
    rs.expected_amount,
    COALESCE(SUM(p.amount), 0) as amount_paid,
    rs.expected_amount - COALESCE(SUM(p.amount), 0) as overdue_amount,
    CURRENT_DATE - rs.due_date as days_overdue
FROM repayment_schedules rs
JOIN loans l ON rs.loan_id = l.id
JOIN borrowers b ON l.borrower_id = b.id
LEFT JOIN payments p ON p.repayment_schedule_id = rs.id
WHERE rs.due_date < CURRENT_DATE
  AND rs.status != 'paid'
GROUP BY b.first_name, b.last_name, l.loan_number, rs.due_date, rs.expected_amount
HAVING rs.expected_amount - COALESCE(SUM(p.amount), 0) > 0
ORDER BY days_overdue DESC;

-- Overdue summary by loan type
SELECT 
    l.repayment_frequency,
    COUNT(DISTINCT rs.id) as overdue_schedules,
    COUNT(DISTINCT l.id) as loans_with_overdue,
    SUM(rs.expected_amount - COALESCE(p.amount, 0)) as total_overdue_amount
FROM repayment_schedules rs
JOIN loans l ON rs.loan_id = l.id
LEFT JOIN payments p ON p.repayment_schedule_id = rs.id
WHERE rs.due_date < CURRENT_DATE
  AND rs.status != 'paid'
GROUP BY l.repayment_frequency;


-- ============================================
-- 10. VERIFICATION
-- ============================================
-- Verify that stored balance matches calculated balance
-- This should show zero discrepancy for all loans
SELECT 
    l.id,
    l.loan_number,
    l.balance as stored_balance,
    (
        SELECT COALESCE(SUM(rs.expected_amount), 0) 
        FROM repayment_schedules rs 
        WHERE rs.loan_id = l.id
    ) - (
        SELECT COALESCE(SUM(p.amount), 0) 
        FROM payments p 
        WHERE p.loan_id = l.id
    ) as calculated_balance,
    l.balance - (
        (
            SELECT COALESCE(SUM(rs.expected_amount), 0) 
            FROM repayment_schedules rs 
            WHERE rs.loan_id = l.id
        ) - (
            SELECT COALESCE(SUM(p.amount), 0) 
            FROM payments p 
            WHERE p.loan_id = l.id
        )
    ) as discrepancy
FROM loans l
WHERE l.status IN ('disbursed', 'active')
HAVING discrepancy != 0;  -- Should return ZERO rows if system is correct
