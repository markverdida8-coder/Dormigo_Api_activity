-- Read-only verification for 20261010_payment_type.sql.

SELECT current_database() AS database_name,
       inet_server_port() AS server_port,
       current_schema() AS current_schema;

SELECT COUNT(*) AS payment_count,
       COUNT(*) FILTER (WHERE payment_type = 'INITIAL'::public.payment_type_enum) AS initial_count,
       COUNT(*) FILTER (WHERE payment_type = 'MONTHLY'::public.payment_type_enum) AS monthly_count,
       COUNT(*) FILTER (WHERE payment_type IS NULL) AS untyped_count
FROM public.payments;

-- Compare payment_count and preserved_fields_md5 with the exact same query in
-- 20261010_payment_type_preflight.sql. A match demonstrates that every
-- pre-existing payment row and all pre-existing fields are unchanged.
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

-- Existing source fields are listed so IDs, amounts, periods, descriptions,
-- references, statuses and verification times can be reviewed after migration.
SELECT payment_id,
       booking_id,
       payment_type,
       payment_period,
       amount,
       payment_description,
       transaction_ref,
       status::text AS status,
       verified_at
FROM public.payments
ORDER BY booking_id, payment_period, payment_id;

-- The six preserved active duplicate rows must remain exactly these records.
SELECT e.payment_id,
       e.booking_id,
       e.payment_type,
       e.payment_period,
       p.amount,
       p.payment_description,
       p.transaction_ref,
       p.status::text AS status,
       p.verified_at
FROM public.payment_legacy_duplicate_exceptions e
JOIN public.payments p USING (payment_id)
ORDER BY e.payment_id;

-- Must return no rows. All six approved legacy IDs must still occupy their
-- original key and remain PAID.
WITH expected(payment_id, booking_id, payment_type, payment_period) AS (
    VALUES
        (11, 3, 'MONTHLY'::public.payment_type_enum, 1),
        (12, 3, 'MONTHLY'::public.payment_type_enum, 1),
        (13, 3, 'MONTHLY'::public.payment_type_enum, 1),
        (14, 3, 'MONTHLY'::public.payment_type_enum, 1),
        (15, 3, 'MONTHLY'::public.payment_type_enum, 1),
        (17, 4, 'MONTHLY'::public.payment_type_enum, 1)
)
SELECT e.payment_id, e.booking_id, e.payment_type, e.payment_period,
       p.status::text AS status
FROM expected e
LEFT JOIN public.payment_legacy_duplicate_exceptions x
       ON x.payment_id = e.payment_id
      AND x.booking_id = e.booking_id
      AND x.payment_type = e.payment_type
      AND x.payment_period = e.payment_period
LEFT JOIN public.payments p ON p.payment_id = e.payment_id
WHERE x.payment_id IS NULL
   OR p.payment_id IS NULL
   OR p.booking_id <> e.booking_id
   OR p.payment_type <> e.payment_type
   OR p.payment_period <> e.payment_period
   OR p.status::text <> 'PAID'
ORDER BY e.payment_id;

-- Must return no rows. Duplicate active keys other than the explicit legacy
-- exception rows indicate a failed or bypassed protection.
SELECT booking_id,
       payment_type,
       payment_period,
       ARRAY_AGG(payment_id ORDER BY payment_id) AS payment_ids,
       ARRAY_AGG(status::text ORDER BY payment_id) AS statuses
FROM public.payments
WHERE status::text IN ('PENDING', 'SUBMITTED', 'PAID', 'CONFIRMED')
  AND payment_id NOT IN (11, 12, 13, 14, 15, 17)
GROUP BY booking_id, payment_type, payment_period
HAVING COUNT(*) > 1
ORDER BY booking_id, payment_type, payment_period;

-- Must return no rows. No active payment may be added or transitioned into a
-- slot occupied by any of the preserved legacy duplicate records.
SELECT p.payment_id,
       p.booking_id,
       p.payment_type,
       p.payment_period,
       p.status::text AS status
FROM public.payments p
JOIN public.payment_legacy_duplicate_exceptions e
  ON e.booking_id = p.booking_id
 AND e.payment_type = p.payment_type
 AND e.payment_period = p.payment_period
WHERE p.payment_id NOT IN (11, 12, 13, 14, 15, 17)
  AND p.status::text IN ('PENDING', 'SUBMITTED', 'PAID', 'CONFIRMED')
ORDER BY p.payment_id;

SELECT indexname, indexdef
FROM pg_indexes
WHERE schemaname = 'public'
  AND tablename = 'payments'
  AND indexname = 'idx_payments_unique_active_type_period';

SELECT tgname,
       pg_get_triggerdef(oid) AS trigger_definition,
       tgenabled
FROM pg_trigger
WHERE tgrelid = 'public.payments'::regclass
  AND NOT tgisinternal
  AND tgname = 'payments_guard_type_period_and_legacy_exceptions';

SELECT p.proname,
       p.prosecdef AS security_definer,
       pg_get_userbyid(p.proowner) AS function_owner,
       p.proconfig
FROM pg_proc p
JOIN pg_namespace n ON n.oid = p.pronamespace
WHERE n.nspname = 'public'
  AND p.proname = 'guard_payment_type_period_and_legacy_exceptions';

SELECT payment_id, booking_id, payment_type, payment_period, reason
FROM public.payment_legacy_duplicate_exceptions
ORDER BY payment_id;
