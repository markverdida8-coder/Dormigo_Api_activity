-- READ-ONLY preflight for 20261010_payment_type.sql.
-- Run against the intended PostgreSQL connection before scheduling the migration.

SELECT current_database() AS database_name,
       inet_server_port() AS server_port,
       current_schema() AS current_schema;

SELECT column_name, data_type, udt_name, is_nullable
FROM information_schema.columns
WHERE table_schema = 'public'
  AND table_name = 'payments'
ORDER BY ordinal_position;

SELECT indexname, indexdef
FROM pg_indexes
WHERE schemaname = 'public'
  AND tablename = 'payments'
ORDER BY indexname;

SELECT e.enumlabel AS payment_status
FROM pg_type t
JOIN pg_enum e ON e.enumtypid = t.oid
WHERE t.typname = 'payment_status_enum'
ORDER BY e.enumsortorder;

-- These exact descriptions are the only descriptions this migration classifies.
SELECT payment_id,
       booking_id,
       payment_period,
       payment_description,
       status::text AS status,
       verified_at,
       transaction_ref,
       CASE
           WHEN BTRIM(COALESCE(payment_description, '')) = 'Initial Move-in Payment'
               THEN 'INITIAL'
           WHEN BTRIM(COALESCE(payment_description, '')) = 'Monthly Rent'
             OR BTRIM(COALESCE(payment_description, '')) LIKE 'Monthly Rent – %'
               THEN 'MONTHLY'
           ELSE NULL
       END AS proposed_payment_type
FROM public.payments
ORDER BY booking_id, payment_period, payment_id;

-- Must return no rows. Do not infer a type for any result.
SELECT payment_id,
       booking_id,
       payment_period,
       payment_description,
       status::text AS status
FROM public.payments
WHERE CASE
          WHEN BTRIM(COALESCE(payment_description, '')) = 'Initial Move-in Payment'
              THEN FALSE
          WHEN BTRIM(COALESCE(payment_description, '')) = 'Monthly Rent'
            OR BTRIM(COALESCE(payment_description, '')) LIKE 'Monthly Rent – %'
              THEN FALSE
          ELSE TRUE
      END
ORDER BY payment_id;

-- Active duplicate keys expected to require historical exceptions:
-- booking 3, MONTHLY, period 1: payment IDs 10-15 (ID 9 is REJECTED);
-- booking 4, MONTHLY, period 1: payment IDs 16-17.
WITH classified AS (
    SELECT payment_id,
           booking_id,
           payment_period,
           status::text AS status,
           CASE
               WHEN BTRIM(COALESCE(payment_description, '')) = 'Initial Move-in Payment'
                   THEN 'INITIAL'
               WHEN BTRIM(COALESCE(payment_description, '')) = 'Monthly Rent'
                 OR BTRIM(COALESCE(payment_description, '')) LIKE 'Monthly Rent – %'
                   THEN 'MONTHLY'
               ELSE NULL
           END AS payment_type
    FROM public.payments
),
ranked AS (
    SELECT *,
           ROW_NUMBER() OVER (
               PARTITION BY booking_id, payment_type, payment_period
               ORDER BY payment_id
           ) AS slot_rank
    FROM classified
    WHERE payment_type IS NOT NULL
      AND status IN ('PENDING', 'SUBMITTED', 'PAID', 'CONFIRMED')
)
SELECT payment_id,
       booking_id,
       payment_type,
       payment_period,
       status
FROM ranked
WHERE slot_rank > 1
ORDER BY payment_id;

-- Summary for quick comparison with the migration's fail-closed guards.
SELECT COUNT(*) AS payment_count,
       COUNT(*) FILTER (
           WHERE BTRIM(COALESCE(payment_description, '')) = 'Initial Move-in Payment'
       ) AS classifiable_initial_count,
       COUNT(*) FILTER (
           WHERE BTRIM(COALESCE(payment_description, '')) = 'Monthly Rent'
              OR BTRIM(COALESCE(payment_description, '')) LIKE 'Monthly Rent – %'
       ) AS classifiable_monthly_count,
       COUNT(*) FILTER (
           WHERE NOT (
               BTRIM(COALESCE(payment_description, '')) = 'Initial Move-in Payment'
               OR BTRIM(COALESCE(payment_description, '')) = 'Monthly Rent'
               OR BTRIM(COALESCE(payment_description, '')) LIKE 'Monthly Rent – %'
           )
       ) AS unclassified_count
FROM public.payments;

-- Record this row before migration. The matching post-migration query is in
-- 20261010_payment_type_verify.sql. Compare both payment_count and
-- preserved_fields_md5; the digest excludes only the new payment_type column.
SELECT COUNT(*) AS payment_count,
       MD5(
           COALESCE(
               JSONB_AGG(
                   JSONB_BUILD_ARRAY(
                       payment_id,
                       booking_id,
                       payment_period,
                       due_date,
                       amount,
                       payment_method,
                       payment_date,
                       status::text,
                       transaction_ref,
                       created_at,
                       payment_description,
                       proof_image,
                       rejection_reason,
                       verified_at
                   )
                   ORDER BY payment_id
               )::text,
               '[]'
           )
       ) AS preserved_fields_md5
FROM public.payments;
