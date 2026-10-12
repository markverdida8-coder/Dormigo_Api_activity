-- Read-only verification for 20261010_booking_pricing_snapshot.sql.

SELECT current_database() AS database_name,
       inet_server_port() AS server_port,
       current_schema() AS current_schema;

SELECT COUNT(*) AS booking_count,
       COUNT(*) FILTER (WHERE review_status = 'REVIEW_REQUIRED') AS review_required_count,
       COUNT(*) FILTER (WHERE review_status = 'CAPTURED_VERIFIED') AS captured_count,
       COUNT(*) FILTER (WHERE payment_due_day IS NULL) AS missing_due_day_count
FROM public.booking_pricing_snapshots;

-- Must return no rows. Existing bookings should have a review marker; captured
-- rows must contain a complete, valid immutable snapshot.
SELECT b.booking_id, b.status::text AS booking_status,
       s.review_status, s.captured_at, s.payment_due_day,
       s.agreed_move_in_date, s.agreed_duration_months,
       s.agreed_monthly_rent, s.agreed_total_amount,
       s.advance_months, s.deposit_months, s.utility_deposit,
       s.room_other_fees_amount, s.property_deposit_details
FROM public.bookings b
LEFT JOIN public.booking_pricing_snapshots s USING (booking_id)
WHERE s.booking_id IS NULL
   OR (s.review_status = 'CAPTURED_VERIFIED'
       AND (s.captured_at IS NULL
            OR s.payment_due_day NOT BETWEEN 1 AND 31
            OR s.agreed_move_in_date IS NULL
            OR s.agreed_duration_months IS NULL
            OR s.agreed_monthly_rent IS NULL
            OR s.agreed_total_amount IS NULL
            OR s.advance_months IS NULL
            OR s.deposit_months IS NULL
            OR s.utility_deposit IS NULL
            OR s.room_other_fees_amount IS NULL
            OR s.room_other_fees_amount <> 0
            OR jsonb_typeof(s.property_deposit_details) <> 'array'
            OR jsonb_typeof(s.one_time_fee_details) <> 'array'
            OR jsonb_typeof(s.recurring_fee_details) <> 'array'
            OR jsonb_typeof(s.utility_charging_terms) <> 'object'))
ORDER BY b.booking_id;

-- Current approved historical bookings should remain REVIEW_REQUIRED unless
-- they transitioned to approval after the migration. No current terms should
-- be synthesized for pre-existing approved rows.
SELECT b.booking_id, b.status::text AS booking_status, s.review_status,
       s.captured_at, s.payment_due_day, s.agreed_monthly_rent
FROM public.bookings b
JOIN public.booking_pricing_snapshots s USING (booking_id)
WHERE b.status::text = 'APPROVED'
  AND s.review_status = 'REVIEW_REQUIRED'
ORDER BY b.booking_id;

-- Booking 11 is expected to have only an empty review marker if it existed
-- before migration; its booking and payment records must be unchanged.
SELECT b.booking_id, b.status::text AS booking_status, b.next_due_date,
       s.review_status, s.captured_at, s.payment_due_day,
       s.agreed_move_in_date, s.agreed_duration_months,
       s.agreed_monthly_rent, s.agreed_total_amount,
       p.payment_id, p.payment_period, p.amount, p.status::text AS payment_status,
       p.due_date, p.payment_description, p.transaction_ref, p.verified_at
FROM public.bookings b
LEFT JOIN public.booking_pricing_snapshots s USING (booking_id)
LEFT JOIN public.payments p ON p.booking_id = b.booking_id
WHERE b.booking_id = 11
ORDER BY p.payment_id;

-- Compare both counts and digests to the exact preflight queries. Booking and
-- payment hashes must match; this migration changes neither source table.
SELECT COUNT(*) AS booking_count,
       MD5(COALESCE(
           JSONB_AGG(JSONB_BUILD_ARRAY(booking_id, user_id, room_id, move_in_date,
                                      duration_months, status::text,
                                      agreed_monthly_rent, agreed_total_amount,
                                      initial_payment_completed, next_due_date)
                     ORDER BY booking_id)::text,
           '[]'
       )) AS preserved_booking_fields_md5
FROM public.bookings;

SELECT COUNT(*) AS payment_count,
       MD5(COALESCE(
           JSONB_AGG(JSONB_BUILD_ARRAY(payment_id, booking_id, payment_period,
                                      due_date, amount, payment_method,
                                      payment_date, status::text, transaction_ref,
                                      created_at, payment_description, proof_image,
                                      rejection_reason, verified_at)
                     ORDER BY payment_id)::text,
           '[]'
       )) AS preserved_payment_fields_md5
FROM public.payments;

SELECT conname, pg_get_constraintdef(oid) AS definition, convalidated
FROM pg_constraint
WHERE conrelid = 'public.boarding_houses'::regclass
  AND conname = 'boarding_houses_payment_due_day_range';

SELECT tgname, pg_get_triggerdef(oid) AS definition, tgenabled
FROM pg_trigger
WHERE NOT tgisinternal
  AND (
      tgrelid = 'public.booking_pricing_snapshots'::regclass
      OR tgrelid = 'public.bookings'::regclass
  )
  AND tgname IN (
      'booking_pricing_snapshots_immutable',
      'bookings_capture_pricing_snapshot_after_status_update'
  )
ORDER BY tgrelid::regclass::text, tgname;

SELECT c.relname,
       pg_get_userbyid(c.relowner) AS table_owner,
       owner_role.rolcanlogin AS owner_can_login,
       owner_role.rolsuper AS owner_is_superuser,
       owner_role.rolcreaterole AS owner_can_create_roles
FROM pg_class c
JOIN pg_roles owner_role ON owner_role.oid = c.relowner
WHERE c.oid = 'public.booking_pricing_snapshots'::regclass;

SELECT rolname,
       rolsuper AS is_superuser,
       has_table_privilege(rolname, 'public.booking_pricing_snapshots', 'SELECT') AS can_select,
       has_table_privilege(rolname, 'public.booking_pricing_snapshots', 'INSERT') AS can_insert,
       has_table_privilege(rolname, 'public.booking_pricing_snapshots', 'UPDATE') AS can_update,
       has_table_privilege(rolname, 'public.booking_pricing_snapshots', 'DELETE') AS can_delete,
       has_table_privilege(rolname, 'public.booking_pricing_snapshots', 'TRUNCATE') AS can_truncate
FROM pg_roles
WHERE rolname IN ('dormigo_api_app', 'dormigo_web_admin_app')
ORDER BY rolname;

SELECT app.rolname,
       app.rolsuper AS is_superuser,
       app.rolcreaterole AS can_create_roles,
       app.rolcanlogin AS can_login,
       owner_role.rolname AS owner_role,
       CASE
           WHEN owner_role.oid IS NULL THEN NULL
           ELSE pg_has_role(app.oid, owner_role.oid, 'MEMBER')
       END AS member_of_snapshot_owner,
       has_function_privilege(
           app.oid,
           'public.capture_booking_pricing_snapshot_on_approval()',
           'EXECUTE'
       ) AS can_execute_capture_function,
       has_function_privilege(
           app.oid,
           'public.protect_booking_pricing_snapshot()',
           'EXECUTE'
       ) AS can_execute_protection_function
FROM pg_roles app
LEFT JOIN pg_roles owner_role ON owner_role.rolname = 'dormigo_snapshot_owner'
WHERE app.rolname IN ('dormigo_api_app', 'dormigo_web_admin_app')
ORDER BY app.rolname;

-- Status UPDATE access is distinct from snapshot-table DML. If true, the
-- shared application credential can initiate a PENDING -> APPROVED transition
-- outside the authenticated landlord request path.
SELECT app.rolname,
       has_column_privilege(app.oid, 'public.bookings', 'status', 'UPDATE')
           AS can_update_booking_status
FROM pg_roles app
WHERE app.rolname IN ('dormigo_api_app', 'dormigo_web_admin_app')
ORDER BY app.rolname;

-- Must return no rows. Effective privileges include privileges inherited
-- through group roles.
SELECT app.rolname,
       app.rolsuper AS is_superuser,
       app.rolcreaterole AS can_create_roles,
       has_table_privilege(app.oid, 'public.booking_pricing_snapshots', 'INSERT') AS can_insert,
       has_table_privilege(app.oid, 'public.booking_pricing_snapshots', 'UPDATE') AS can_update,
       has_table_privilege(app.oid, 'public.booking_pricing_snapshots', 'DELETE') AS can_delete,
       has_table_privilege(app.oid, 'public.booking_pricing_snapshots', 'TRUNCATE') AS can_truncate,
       has_function_privilege(
           app.oid,
           'public.capture_booking_pricing_snapshot_on_approval()',
           'EXECUTE'
       ) AS can_execute_capture
FROM pg_roles app
WHERE app.rolname IN ('dormigo_api_app', 'dormigo_web_admin_app')
  AND (
      app.rolsuper
      OR app.rolcreaterole
      OR has_table_privilege(app.oid, 'public.booking_pricing_snapshots', 'INSERT')
      OR has_table_privilege(app.oid, 'public.booking_pricing_snapshots', 'UPDATE')
      OR has_table_privilege(app.oid, 'public.booking_pricing_snapshots', 'DELETE')
      OR has_table_privilege(app.oid, 'public.booking_pricing_snapshots', 'TRUNCATE')
      OR has_function_privilege(
          app.oid,
          'public.capture_booking_pricing_snapshot_on_approval()',
          'EXECUTE'
      )
      OR pg_has_role(
          app.oid,
          'dormigo_snapshot_owner',
          'MEMBER'
      )
      OR EXISTS (
          SELECT 1
          FROM pg_roles super_role
          WHERE super_role.rolsuper
            AND pg_has_role(app.oid, super_role.oid, 'MEMBER')
      )
  )
ORDER BY app.rolname;

SELECT p.proname,
       p.prosecdef AS security_definer,
       pg_get_userbyid(p.proowner) AS function_owner,
       p.proconfig
FROM pg_proc p
JOIN pg_namespace n ON n.oid = p.pronamespace
WHERE n.nspname = 'public'
  AND p.proname IN (
      'capture_booking_pricing_snapshot_on_approval',
      'protect_booking_pricing_snapshot'
  )
ORDER BY p.proname;
