-- READ-ONLY preflight for 20261010_booking_pricing_snapshot.sql.
-- Run against the intended PostgreSQL connection before scheduling the migration.

SELECT current_database() AS database_name,
       inet_server_port() AS server_port,
       current_schema() AS current_schema;

-- Required source columns for approval-time snapshot capture.
SELECT table_name, column_name, data_type, udt_name, is_nullable
FROM information_schema.columns
WHERE table_schema = 'public'
  AND (
      (table_name = 'bookings' AND column_name IN (
          'booking_id', 'room_id', 'status', 'move_in_date',
          'duration_months', 'agreed_monthly_rent', 'agreed_total_amount'
      ))
      OR (table_name = 'rooms' AND column_name IN (
          'room_id', 'house_id', 'monthly_rent', 'advance_months',
          'deposit_months', 'utility_deposit', 'other_fees',
          'other_fees_description'
      ))
      OR (table_name = 'boarding_houses' AND column_name IN (
          'house_id', 'payment_due_day', 'free_electricity',
          'electricity_rate', 'free_water', 'water_rate'
      ))
      OR (table_name = 'property_additional_fees' AND column_name IN (
          'fee_id', 'house_id', 'fee_name', 'fee_type', 'amount',
          'description', 'is_enabled'
      ))
      OR (table_name = 'property_deposits' AND column_name IN (
          'deposit_id', 'house_id', 'deposit_name', 'deposit_type',
          'amount', 'description', 'is_enabled', 'applies_to'
      ))
      OR (table_name = 'property_utilities' AND column_name IN (
          'utility_id', 'house_id', 'utility_name', 'charging_method',
          'fixed_amount', 'is_enabled', 'applies_to'
      ))
  )
ORDER BY table_name, ordinal_position;

-- Must return no rows before the payment_due_day constraint can be validated.
SELECT house_id, payment_due_day
FROM public.boarding_houses
WHERE payment_due_day IS NULL
   OR payment_due_day NOT BETWEEN 1 AND 31
ORDER BY house_id;

-- Pending bookings that would fail the approval-time snapshot capture checks.
-- Room-level NULL utility_deposit/other_fees are treated as zero by the
-- capture function; NULL advance/deposit values are not.
SELECT b.booking_id, b.room_id, b.status::text AS booking_status,
       b.move_in_date, b.duration_months, b.agreed_monthly_rent,
       b.agreed_total_amount, r.advance_months, r.deposit_months,
       r.utility_deposit, r.other_fees, bh.payment_due_day
FROM public.bookings b
LEFT JOIN public.rooms r ON r.room_id = b.room_id
LEFT JOIN public.boarding_houses bh ON bh.house_id = r.house_id
WHERE b.status::text = 'PENDING'
  AND (
      r.room_id IS NULL
      OR bh.house_id IS NULL
      OR b.move_in_date IS NULL
      OR b.duration_months IS NULL
      OR b.duration_months <= 0
      OR b.agreed_monthly_rent IS NULL
      OR b.agreed_monthly_rent < 0
      OR b.agreed_total_amount IS NULL
      OR b.agreed_total_amount < 0
      OR r.advance_months IS NULL
      OR r.advance_months < 0
      OR r.deposit_months IS NULL
      OR r.deposit_months < 0
      OR COALESCE(r.utility_deposit, 0) < 0
      OR COALESCE(r.other_fees, 0) < 0
      OR COALESCE(r.other_fees, 0) <> 0
      OR bh.payment_due_day IS NULL
      OR bh.payment_due_day NOT BETWEEN 1 AND 31
  )
ORDER BY b.booking_id;

-- Nonzero room other_fees is deliberately blocking until explicitly itemized
-- into the property_deposits or property_additional_fees source. Its free-text
-- description is not used to infer a charge category.
SELECT room_id, house_id, monthly_rent, advance_months, deposit_months,
       utility_deposit, other_fees, other_fees_description
FROM public.rooms
WHERE monthly_rent < 0
   OR advance_months IS NULL
   OR advance_months < 0
   OR deposit_months IS NULL
   OR deposit_months < 0
   OR COALESCE(utility_deposit, 0) < 0
   OR COALESCE(other_fees, 0) < 0
ORDER BY house_id, room_id;

SELECT room_id, house_id, other_fees, other_fees_description
FROM public.rooms
WHERE COALESCE(other_fees, 0) <> 0
ORDER BY house_id, room_id;

SELECT deposit_id, house_id, deposit_name, deposit_type, amount,
       is_enabled, applies_to
FROM public.property_deposits
WHERE COALESCE(is_enabled, TRUE)
  AND (deposit_name IS NULL
       OR COALESCE(UPPER(deposit_type::text), '') <> 'FIXED'
       OR COALESCE(UPPER(applies_to), '') <> 'ALL_ROOMS'
       OR amount IS NULL
       OR amount < 0)
ORDER BY house_id, deposit_id;

SELECT d.house_id, d.deposit_id, d.deposit_name,
       f.fee_id, f.fee_name,
       'Same-name enabled deposit and ONE_TIME fee' AS possible_duplicate
FROM public.property_deposits d
JOIN public.property_additional_fees f
  ON f.house_id = d.house_id
 AND LOWER(BTRIM(f.fee_name)) = LOWER(BTRIM(d.deposit_name))
 AND UPPER(f.fee_type::text) = 'ONE_TIME'
 AND COALESCE(f.is_enabled, TRUE)
WHERE COALESCE(d.is_enabled, TRUE)
ORDER BY d.house_id, d.deposit_id, f.fee_id;

SELECT r.room_id, r.house_id, d.deposit_id, d.deposit_name,
       'Property deposit overlaps a room-level rent/utility deposit' AS possible_duplicate
FROM public.rooms r
JOIN public.property_deposits d ON d.house_id = r.house_id
WHERE COALESCE(d.is_enabled, TRUE)
  AND (
      (LOWER(BTRIM(d.deposit_name)) = 'advance payment'
       AND COALESCE(r.advance_months, 0) > 0)
      OR (LOWER(BTRIM(d.deposit_name)) = 'security deposit'
          AND COALESCE(r.deposit_months, 0) > 0)
      OR (LOWER(BTRIM(d.deposit_name)) = 'utility deposit'
          AND COALESCE(r.utility_deposit, 0) > 0)
  )
ORDER BY r.house_id, r.room_id, d.deposit_id;

-- Enabled property fees must have a recognized cadence and nonnegative amount.
SELECT fee_id, house_id, fee_name, fee_type, amount, is_enabled
FROM public.property_additional_fees
WHERE COALESCE(is_enabled, TRUE)
  AND (
      UPPER(fee_type::text) NOT IN ('ONE_TIME', 'MONTHLY')
      OR amount IS NULL
      OR amount < 0
  )
ORDER BY house_id, fee_id;

-- A FIXED utility needs a nonnegative fixed amount. Other utility charging
-- methods are captured as terms but remain usage/billing-policy inputs.
SELECT utility_id, house_id, utility_name, charging_method,
       fixed_amount, is_enabled, applies_to
FROM public.property_utilities
WHERE COALESCE(is_enabled, TRUE)
  AND (
      charging_method IS NULL
      OR UPPER(charging_method::text) NOT IN ('FREE', 'FIXED', 'CONSUMPTION_BASED')
      OR COALESCE(UPPER(applies_to), '') <> 'ALL_ROOMS'
      OR (UPPER(charging_method::text) = 'FIXED'
          AND (fixed_amount IS NULL OR fixed_amount < 0))
  )
ORDER BY house_id, utility_id;

-- Review how many existing bookings will receive an explicit REVIEW_REQUIRED
-- placeholder. No source pricing is inferred for these rows.
SELECT status::text AS booking_status,
       COUNT(*) AS booking_count,
       COUNT(*) FILTER (WHERE next_due_date IS NOT NULL) AS rows_with_next_due_date
FROM public.bookings
GROUP BY status::text
ORDER BY status::text;

-- The migration expects these objects not to exist. Any result requires
-- inspection; do not rerun or partially apply the migration.
SELECT object_name, object_kind
FROM (
    SELECT 'booking_pricing_snapshots' AS object_name, 'table' AS object_kind
    WHERE to_regclass('public.booking_pricing_snapshots') IS NOT NULL
    UNION ALL
    SELECT 'booking_pricing_snapshots_immutable', 'trigger'
    WHERE EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgrelid = to_regclass('public.booking_pricing_snapshots')
          AND tgname = 'booking_pricing_snapshots_immutable'
          AND NOT tgisinternal
    )
    UNION ALL
    SELECT 'bookings_capture_pricing_snapshot_after_status_update', 'trigger'
    WHERE EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgrelid = 'public.bookings'::regclass
          AND tgname = 'bookings_capture_pricing_snapshot_after_status_update'
          AND NOT tgisinternal
    )
    UNION ALL
    SELECT 'protect_booking_pricing_snapshot', 'function'
    WHERE to_regprocedure('public.protect_booking_pricing_snapshot()') IS NOT NULL
    UNION ALL
    SELECT 'capture_booking_pricing_snapshot_on_approval', 'function'
    WHERE to_regprocedure('public.capture_booking_pricing_snapshot_on_approval()') IS NOT NULL
) existing
ORDER BY object_kind, object_name;

-- Application-role review. The migration expects a dedicated non-login owner;
-- application roles must not be superusers or members of that owner role.
SELECT app.rolname,
       app.rolsuper,
       app.rolcreaterole,
       app.rolcanlogin,
       owner_role.rolname AS snapshot_owner_role,
       CASE
           WHEN owner_role.oid IS NULL THEN NULL
           ELSE pg_has_role(app.oid, owner_role.oid, 'MEMBER')
       END AS member_of_snapshot_owner,
       EXISTS (
           SELECT 1
           FROM pg_roles super_role
           WHERE super_role.rolsuper
             AND pg_has_role(app.oid, super_role.oid, 'MEMBER')
       ) AS can_assume_superuser
FROM pg_roles app
LEFT JOIN pg_roles owner_role ON owner_role.rolname = 'dormigo_snapshot_owner'
WHERE app.rolname IN ('dormigo_api_app', 'dormigo_web_admin_app')
ORDER BY app.rolname;

-- Must return one row per required backend login role. Missing roles mean the
-- protections cannot be verified for the account used by that backend.
SELECT required.rolname AS required_app_role,
       app.rolcanlogin,
       app.rolsuper,
       app.rolcreaterole,
       CASE
           WHEN app.oid IS NULL THEN 'MISSING'
           WHEN NOT app.rolcanlogin THEN 'NOT_LOGIN'
           WHEN app.rolsuper OR app.rolcreaterole THEN 'PRIVILEGED'
           ELSE 'REVIEW_MEMBERSHIP_AND_GRANTS'
       END AS preflight_result
FROM (VALUES ('dormigo_api_app'), ('dormigo_web_admin_app')) required(rolname)
LEFT JOIN pg_roles app USING (rolname)
ORDER BY required.rolname;

-- Existing application roles with status-column update privileges can trigger
-- approval-time capture directly. The current shared-role model cannot prove
-- landlord identity to PostgreSQL; resolve this with a trusted approval API
-- or a role/privilege design before treating the trigger as DB-authenticated.
SELECT app.rolname,
       has_column_privilege(app.oid, 'public.bookings', 'status', 'UPDATE')
           AS can_update_booking_status
FROM pg_roles app
WHERE app.rolname IN ('dormigo_api_app', 'dormigo_web_admin_app')
ORDER BY app.rolname;

-- Save these baselines and compare with the same queries in the verification
-- file. The migration must not modify bookings or payments.
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

-- Inspect Booking 11's existing booking and payment values for comparison.
-- This query is read-only; the migration does not update either table.
SELECT b.booking_id, b.status::text AS status, b.move_in_date,
       b.duration_months, b.agreed_monthly_rent, b.agreed_total_amount,
       b.next_due_date, p.payment_id, p.payment_period, p.amount,
       p.status::text AS payment_status, p.due_date, p.payment_description,
       p.transaction_ref, p.verified_at
FROM public.bookings b
LEFT JOIN public.payments p ON p.booking_id = b.booking_id
WHERE b.booking_id = 11
ORDER BY p.payment_id;
