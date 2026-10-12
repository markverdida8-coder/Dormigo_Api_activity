-- Immutable booking-level pricing terms and property due-day snapshot.
--
-- PREPARED ONLY: do not execute until reviewed and explicitly approved.
-- This migration does not alter bookings or payments. Existing bookings get
-- only an empty REVIEW_REQUIRED row in the new snapshot table; no pricing or
-- due date is inferred from current room/property settings.
--
-- New snapshots are captured automatically in the same transaction as a
-- PENDING -> APPROVED transition. The application must authenticate and
-- authorize the landlord before changing status. The database role cannot
-- independently identify the authenticated HTTP user; see deployment notes.
--
-- Consistent pricing-source lock order:
--   1. transaction advisory lock (namespace 164731, house_id)
--   2. booking row (approval workflow)
--   3. boarding-house row
--   4. room row
--   5. property-deposit rows ordered by deposit_id
--   6. additional-fee rows ordered by fee_id
--   7. utility rows ordered by utility_id
-- Approval code must take the advisory lock before locking the booking row.
-- Every PHP room/property/fee/utility edit path must take the same advisory
-- lock before locking or changing any pricing-source row, then follow steps
-- 3-6 in order. The trigger reacquires the transaction lock defensively.
--
-- New approval writers should read this snapshot for all payment terms. The
-- migration is additive and does not depend on the separate payment_type
-- migration. Neither migration is activated by preparing these files.
--
-- Rollback: pause booking writes first. Do not drop this table if any booking
-- has a CAPTURED_VERIFIED snapshot. If no snapshots have been captured, an
-- explicitly reviewed reverse migration may remove the booking trigger,
-- the immutability trigger, functions, table, and due-day constraint. Never
-- delete captured snapshots to make rollback convenient. Reverting after
-- captures requires a separately approved data-retention and compatibility
-- plan; preserve a verified backup before any such operation.

BEGIN;

DO $migration$
BEGIN
    IF current_database() <> 'dormigo_migration_test' OR inet_server_port() <> 5432 THEN
        RAISE EXCEPTION 'Wrong database target; expected dormigo on port 5432.';
    END IF;

    IF to_regclass('public.bookings') IS NULL
       OR to_regclass('public.rooms') IS NULL
       OR to_regclass('public.boarding_houses') IS NULL
       OR to_regclass('public.property_additional_fees') IS NULL
       OR to_regclass('public.property_deposits') IS NULL
       OR to_regclass('public.property_utilities') IS NULL
       OR to_regclass('public.payments') IS NULL THEN
        RAISE EXCEPTION 'A required Dormigo source table is missing.';
    END IF;

    IF to_regclass('public.booking_pricing_snapshots') IS NOT NULL
       OR to_regprocedure('public.protect_booking_pricing_snapshot()') IS NOT NULL
       OR to_regprocedure('public.capture_booking_pricing_snapshot_on_approval()') IS NOT NULL
       OR EXISTS (
           SELECT 1 FROM pg_constraint
           WHERE conrelid = 'public.boarding_houses'::regclass
             AND conname = 'boarding_houses_payment_due_day_range'
       )
       OR EXISTS (
           SELECT 1 FROM pg_trigger
           WHERE tgrelid = 'public.bookings'::regclass
             AND tgname IN (
                 'bookings_capture_pricing_snapshot_after_insert',
                 'bookings_capture_pricing_snapshot_after_status_update'
             )
             AND NOT tgisinternal
       )
       OR EXISTS (
           SELECT 1 FROM pg_trigger
           WHERE tgrelid = 'public.boarding_houses'::regclass
             AND tgname = 'boarding_houses_validate_payment_due_day'
             AND NOT tgisinternal
       ) THEN
        RAISE EXCEPTION 'Snapshot migration objects already exist; inspect before proceeding.';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_roles WHERE rolname = 'dormigo_snapshot_owner'
    ) THEN
        EXECUTE 'CREATE ROLE dormigo_snapshot_owner NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS';
    ELSE
        IF EXISTS (
            SELECT 1 FROM pg_roles
            WHERE rolname = 'dormigo_snapshot_owner'
              AND (rolcanlogin OR rolsuper OR rolcreatedb OR rolcreaterole OR rolbypassrls)
        ) OR EXISTS (
            SELECT 1
            FROM pg_roles super_role
            WHERE super_role.rolsuper
              AND pg_has_role(
                  'dormigo_snapshot_owner',
                  super_role.oid,
                  'MEMBER'
              )
        ) THEN
            RAISE EXCEPTION
                'dormigo_snapshot_owner exists with unsafe attributes or superuser membership; inspect before proceeding.';
        END IF;
    END IF;

    IF (
        SELECT COUNT(*)
        FROM pg_roles
        WHERE rolname IN ('dormigo_api_app', 'dormigo_web_admin_app')
          AND rolcanlogin
    ) <> 2 THEN
        RAISE EXCEPTION
            'Both non-superuser login roles dormigo_api_app and dormigo_web_admin_app must exist before installing snapshot protections.';
    END IF;

    IF NOT (
        SELECT rolsuper
        FROM pg_roles
        WHERE rolname = CURRENT_USER
    ) AND NOT pg_has_role(CURRENT_USER, 'dormigo_snapshot_owner', 'MEMBER') THEN
        RAISE EXCEPTION
            'Migration executor must be a superuser or an explicit member of dormigo_snapshot_owner to transfer snapshot ownership.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM pg_roles app_role
        WHERE app_role.rolname IN ('dormigo_api_app', 'dormigo_web_admin_app')
          AND (
              app_role.rolsuper
              OR app_role.rolcreaterole
              OR app_role.rolname = 'dormigo_snapshot_owner'
              OR pg_has_role(app_role.oid, 'dormigo_snapshot_owner', 'MEMBER')
              OR EXISTS (
                  SELECT 1
                  FROM pg_roles super_role
                  WHERE super_role.rolsuper
                    AND pg_has_role(app_role.oid, super_role.oid, 'MEMBER')
              )
          )
    ) THEN
        RAISE EXCEPTION
            'An application database role can create roles, is superuser, can assume a superuser/snapshot-owner role, or is the snapshot owner.';
    END IF;

    IF EXISTS (
        SELECT 1 FROM public.boarding_houses
        WHERE payment_due_day IS NULL
           OR payment_due_day NOT BETWEEN 1 AND 31
    ) THEN
        RAISE EXCEPTION
            'Existing boarding houses contain a NULL or invalid payment_due_day. Review and resolve before migration; no values were changed.';
    END IF;
END
$migration$;

LOCK TABLE public.bookings,
           public.rooms,
           public.boarding_houses,
           public.property_additional_fees,
           public.property_utilities
IN SHARE ROW EXCLUSIVE MODE;

DO $migration$
BEGIN
    IF EXISTS (
        SELECT 1 FROM public.boarding_houses
        WHERE payment_due_day IS NULL
           OR payment_due_day NOT BETWEEN 1 AND 31
    ) THEN
        RAISE EXCEPTION
            'Existing boarding houses contain a NULL or invalid payment_due_day. Review and resolve before migration; no values were changed.';
    END IF;
END
$migration$;

ALTER TABLE public.boarding_houses
    ADD CONSTRAINT boarding_houses_payment_due_day_range
    CHECK (payment_due_day IS NOT NULL AND payment_due_day BETWEEN 1 AND 31);

CREATE TABLE public.booking_pricing_snapshots (
    booking_id integer PRIMARY KEY
        REFERENCES public.bookings(booking_id) ON DELETE RESTRICT,
    snapshot_version smallint NOT NULL DEFAULT 1
        CHECK (snapshot_version = 1),
    captured_at timestamp with time zone,
    agreed_move_in_date date,
    agreed_duration_months integer,
    agreed_monthly_rent numeric(12, 2),
    agreed_total_amount numeric(12, 2),
    advance_months integer,
    deposit_months integer,
    utility_deposit numeric(12, 2),
    room_other_fees_amount numeric(12, 2),
    room_other_fees_description text,
    property_deposit_details jsonb,
    one_time_fee_details jsonb,
    recurring_fee_details jsonb,
    utility_charging_terms jsonb,
    payment_due_day smallint,
    review_status text NOT NULL,
    CONSTRAINT booking_pricing_snapshot_review_status
        CHECK (review_status IN ('REVIEW_REQUIRED', 'CAPTURED_VERIFIED')),
    CONSTRAINT booking_pricing_snapshot_values
        CHECK (
            (
                review_status = 'REVIEW_REQUIRED'
                AND captured_at IS NULL
                AND agreed_move_in_date IS NULL
                AND agreed_duration_months IS NULL
                AND agreed_monthly_rent IS NULL
                AND agreed_total_amount IS NULL
                AND advance_months IS NULL
                AND deposit_months IS NULL
                AND utility_deposit IS NULL
                AND room_other_fees_amount IS NULL
                AND room_other_fees_description IS NULL
                AND property_deposit_details IS NULL
                AND one_time_fee_details IS NULL
                AND recurring_fee_details IS NULL
                AND utility_charging_terms IS NULL
                AND payment_due_day IS NULL
            )
            OR
            (
                review_status = 'CAPTURED_VERIFIED'
                AND captured_at IS NOT NULL
                AND agreed_move_in_date IS NOT NULL
                AND agreed_duration_months IS NOT NULL
                AND agreed_duration_months > 0
                AND agreed_monthly_rent IS NOT NULL
                AND agreed_monthly_rent >= 0
                AND agreed_total_amount IS NOT NULL
                AND agreed_total_amount >= 0
                AND advance_months IS NOT NULL
                AND advance_months >= 0
                AND deposit_months IS NOT NULL
                AND deposit_months >= 0
                AND utility_deposit IS NOT NULL
                AND utility_deposit >= 0
                AND room_other_fees_amount IS NOT NULL
                AND room_other_fees_amount >= 0
                AND property_deposit_details IS NOT NULL
                AND jsonb_typeof(property_deposit_details) = 'array'
                AND one_time_fee_details IS NOT NULL
                AND jsonb_typeof(one_time_fee_details) = 'array'
                AND recurring_fee_details IS NOT NULL
                AND jsonb_typeof(recurring_fee_details) = 'array'
                AND utility_charging_terms IS NOT NULL
                AND jsonb_typeof(utility_charging_terms) = 'object'
                AND payment_due_day IS NOT NULL
                AND payment_due_day BETWEEN 1 AND 31
            )
        )
);

COMMENT ON TABLE public.booking_pricing_snapshots IS
    'Approval-time immutable agreed pricing terms. Legacy rows are deliberately REVIEW_REQUIRED until independently reconciled.';
COMMENT ON COLUMN public.booking_pricing_snapshots.payment_due_day IS
    'Captured boarding-house due day (1-31); each rental month clamps it to that month''s final calendar day.';
COMMENT ON COLUMN public.booking_pricing_snapshots.one_time_fee_details IS
    'JSON array of enabled ONE_TIME property additional fees. Room-level legacy other fees are stored separately without inferring their meaning.';
COMMENT ON COLUMN public.booking_pricing_snapshots.room_other_fees_amount IS
    'Raw room-level other_fees amount captured separately; do not infer its fee category from the free-text description.';
COMMENT ON COLUMN public.booking_pricing_snapshots.room_other_fees_description IS
    'Raw room-level other_fees_description captured as supplied; it may mix advance, deposit, recurring, and one-time concepts.';
COMMENT ON COLUMN public.booking_pricing_snapshots.property_deposit_details IS
    'Enabled property_deposits captured as itemized fixed initial charges. These are not added again through agreed_total_amount or room other_fees.';
COMMENT ON COLUMN public.booking_pricing_snapshots.recurring_fee_details IS
    'JSON array of enabled MONTHLY property additional fees at booking approval.';
COMMENT ON COLUMN public.booking_pricing_snapshots.utility_charging_terms IS
    'Captured utility configuration and legacy house-level utility rate/free settings; consumption remains usage-based.';

-- Existing rows receive status only. Do not infer historical prices from
-- mutable room/property records, including for Booking 11.
INSERT INTO public.booking_pricing_snapshots (booking_id, review_status)
SELECT booking_id, 'REVIEW_REQUIRED'
FROM public.bookings
ORDER BY booking_id;

CREATE FUNCTION public.protect_booking_pricing_snapshot()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $function$
DECLARE
    capture_context text;
BEGIN
    capture_context := current_setting('dormigo.snapshot_capture_context', TRUE);
    IF capture_context IS DISTINCT FROM 'approval-trigger' THEN
        RAISE EXCEPTION
            'Booking pricing snapshots can only be changed by the approval capture trigger.';
    END IF;

    IF TG_OP = 'INSERT'
       AND NEW.review_status = 'CAPTURED_VERIFIED'
       AND NEW.captured_at IS NOT NULL
       AND NEW.agreed_move_in_date IS NOT NULL
       AND NEW.agreed_duration_months > 0
       AND NEW.agreed_monthly_rent >= 0
       AND NEW.agreed_total_amount >= 0
       AND NEW.advance_months >= 0
       AND NEW.deposit_months >= 0
       AND NEW.utility_deposit >= 0
       AND NEW.room_other_fees_amount >= 0
       AND NEW.room_other_fees_amount = 0
       AND jsonb_typeof(NEW.property_deposit_details) = 'array'
       AND jsonb_typeof(NEW.one_time_fee_details) = 'array'
       AND jsonb_typeof(NEW.recurring_fee_details) = 'array'
       AND jsonb_typeof(NEW.utility_charging_terms) = 'object'
       AND NEW.payment_due_day BETWEEN 1 AND 31 THEN
        RETURN NEW;
    END IF;

    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Booking pricing snapshots cannot be deleted.';
    END IF;

    IF TG_OP = 'UPDATE'
       AND OLD.review_status = 'REVIEW_REQUIRED'
       AND OLD.captured_at IS NULL
       AND OLD.agreed_move_in_date IS NULL
       AND OLD.agreed_duration_months IS NULL
       AND OLD.agreed_monthly_rent IS NULL
       AND OLD.agreed_total_amount IS NULL
       AND OLD.advance_months IS NULL
       AND OLD.deposit_months IS NULL
       AND OLD.utility_deposit IS NULL
       AND OLD.room_other_fees_amount IS NULL
       AND OLD.room_other_fees_description IS NULL
       AND OLD.one_time_fee_details IS NULL
       AND OLD.recurring_fee_details IS NULL
       AND OLD.utility_charging_terms IS NULL
       AND OLD.payment_due_day IS NULL
       AND NEW.booking_id = OLD.booking_id
       AND NEW.snapshot_version = OLD.snapshot_version
       AND NEW.review_status = 'CAPTURED_VERIFIED'
       AND NEW.captured_at IS NOT NULL
       AND NEW.agreed_move_in_date IS NOT NULL
       AND NEW.agreed_duration_months > 0
       AND NEW.agreed_monthly_rent >= 0
       AND NEW.agreed_total_amount >= 0
       AND NEW.advance_months >= 0
       AND NEW.deposit_months >= 0
       AND NEW.utility_deposit >= 0
       AND NEW.room_other_fees_amount >= 0
       AND NEW.room_other_fees_amount = 0
       AND jsonb_typeof(NEW.property_deposit_details) = 'array'
       AND jsonb_typeof(NEW.one_time_fee_details) = 'array'
       AND jsonb_typeof(NEW.recurring_fee_details) = 'array'
       AND jsonb_typeof(NEW.utility_charging_terms) = 'object'
       AND NEW.payment_due_day BETWEEN 1 AND 31 THEN
        RETURN NEW;
    END IF;

    RAISE EXCEPTION 'Booking pricing snapshots are immutable after capture.';
END
$function$;

CREATE TRIGGER booking_pricing_snapshots_immutable
BEFORE UPDATE OR DELETE ON public.booking_pricing_snapshots
FOR EACH ROW
EXECUTE FUNCTION public.protect_booking_pricing_snapshot();

CREATE FUNCTION public.capture_booking_pricing_snapshot_on_approval()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $function$
DECLARE
    v_booking_id integer := NEW.booking_id;
    v_room_id integer;
    v_house_id integer;
    v_existing_status text;
    v_room record;
    v_property_deposits jsonb;
    v_one_time_fees jsonb;
    v_recurring_fees jsonb;
    v_utility_terms jsonb;
    v_now timestamp with time zone := CURRENT_TIMESTAMP;
    v_previous_context text;
BEGIN
    IF NEW.status::text <> 'APPROVED' THEN
        RETURN NEW;
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF OLD.status::text <> 'PENDING' THEN
            RETURN NEW;
        END IF;
    ELSE
        RAISE EXCEPTION 'Pricing snapshots are captured only by a PENDING to APPROVED transition.';
    END IF;

    SELECT room_id INTO v_room_id
    FROM public.bookings
    WHERE booking_id = v_booking_id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Cannot capture pricing snapshot: booking is missing.';
    END IF;

    SELECT house_id INTO v_house_id
    FROM public.rooms
    WHERE room_id = v_room_id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Cannot capture pricing snapshot: room is missing.';
    END IF;

    PERFORM pg_advisory_xact_lock(164731, v_house_id);

    PERFORM 1
    FROM public.boarding_houses
    WHERE house_id = v_house_id
    FOR SHARE;

    PERFORM 1
    FROM public.rooms
    WHERE room_id = v_room_id
      AND house_id = v_house_id
    FOR SHARE;

    PERFORM d.deposit_id
    FROM public.property_deposits d
    WHERE d.house_id = v_house_id
    ORDER BY d.deposit_id
    FOR SHARE OF d;

    PERFORM f.fee_id
    FROM public.property_additional_fees f
    WHERE f.house_id = v_house_id
    ORDER BY f.fee_id
    FOR SHARE OF f;

    PERFORM u.utility_id
    FROM public.property_utilities u
    WHERE u.house_id = v_house_id
    ORDER BY u.utility_id
    FOR SHARE OF u;

    SELECT b.move_in_date,
           b.duration_months,
           b.agreed_monthly_rent,
           b.agreed_total_amount,
           r.advance_months,
           r.deposit_months,
           r.utility_deposit,
           r.other_fees,
           r.other_fees_description,
           bh.house_id,
           bh.payment_due_day,
           bh.free_electricity,
           bh.electricity_rate,
           bh.free_water,
           bh.water_rate
    INTO v_room
    FROM public.bookings b
    JOIN public.rooms r ON r.room_id = b.room_id
    JOIN public.boarding_houses bh ON bh.house_id = r.house_id
    WHERE b.booking_id = v_booking_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Cannot capture pricing snapshot: booking or pricing source is missing.';
    END IF;

    IF v_room.payment_due_day IS NULL OR v_room.payment_due_day NOT BETWEEN 1 AND 31
       OR v_room.advance_months IS NULL OR v_room.advance_months < 0
       OR v_room.deposit_months IS NULL OR v_room.deposit_months < 0
       OR v_room.agreed_monthly_rent IS NULL OR v_room.agreed_monthly_rent < 0
       OR v_room.agreed_total_amount IS NULL OR v_room.agreed_total_amount < 0
       OR v_room.duration_months IS NULL OR v_room.duration_months <= 0
       OR COALESCE(v_room.utility_deposit, 0) < 0
       OR COALESCE(v_room.other_fees, 0) < 0 THEN
        RAISE EXCEPTION 'Cannot capture pricing snapshot: agreed terms are incomplete or invalid.';
    END IF;

    IF COALESCE(v_room.other_fees, 0) <> 0 THEN
        RAISE EXCEPTION
            'Cannot approve booking: room-level other_fees must be itemized into an authoritative fee category first.';
    END IF;

    SELECT COALESCE(jsonb_agg(
               jsonb_build_object(
                   'source', 'PROPERTY_DEPOSIT',
                   'deposit_id', d.deposit_id,
                   'name', d.deposit_name,
                   'deposit_type', d.deposit_type,
                   'applies_to', d.applies_to,
                   'description', d.description,
                   'amount', d.amount
               )
               ORDER BY d.deposit_id
           ), '[]'::jsonb)
    INTO v_property_deposits
    FROM public.property_deposits d
    WHERE d.house_id = v_room.house_id
      AND COALESCE(d.is_enabled, TRUE);

    IF EXISTS (
        SELECT 1
        FROM public.property_deposits d
        WHERE d.house_id = v_room.house_id
          AND COALESCE(d.is_enabled, TRUE)
          AND (d.amount IS NULL OR d.amount < 0
               OR COALESCE(UPPER(d.deposit_type::text), '') <> 'FIXED'
               OR COALESCE(UPPER(d.applies_to), '') <> 'ALL_ROOMS')
    ) THEN
        RAISE EXCEPTION
            'Cannot approve booking: an enabled property deposit must have a valid fixed amount.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.property_additional_fees f
        WHERE f.house_id = v_room.house_id
          AND COALESCE(f.is_enabled, TRUE)
          AND (
              COALESCE(UPPER(f.fee_type::text), '') NOT IN ('ONE_TIME', 'MONTHLY')
              OR f.amount IS NULL
              OR f.amount < 0
          )
    ) THEN
        RAISE EXCEPTION
            'Cannot approve booking: an enabled property fee has invalid category or amount.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.property_utilities u
        WHERE u.house_id = v_room.house_id
          AND COALESCE(u.is_enabled, TRUE)
          AND (
              COALESCE(UPPER(u.charging_method::text), '') NOT IN ('FREE', 'FIXED', 'CONSUMPTION_BASED')
              OR COALESCE(UPPER(u.applies_to), '') <> 'ALL_ROOMS'
              OR (UPPER(u.charging_method::text) = 'FIXED'
                  AND (u.fixed_amount IS NULL OR u.fixed_amount < 0))
          )
    ) THEN
        RAISE EXCEPTION
            'Cannot approve booking: an enabled utility has invalid charging terms.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.property_deposits d
        JOIN public.property_additional_fees f
          ON f.house_id = d.house_id
         AND LOWER(BTRIM(f.fee_name)) = LOWER(BTRIM(d.deposit_name))
         AND UPPER(f.fee_type::text) = 'ONE_TIME'
         AND COALESCE(f.is_enabled, TRUE)
        WHERE d.house_id = v_room.house_id
          AND COALESCE(d.is_enabled, TRUE)
    ) OR EXISTS (
        SELECT 1
        FROM public.property_deposits d
        WHERE d.house_id = v_room.house_id
          AND COALESCE(d.is_enabled, TRUE)
          AND (
              (LOWER(BTRIM(d.deposit_name)) = 'advance payment'
               AND v_room.advance_months > 0)
              OR (LOWER(BTRIM(d.deposit_name)) = 'security deposit'
                  AND v_room.deposit_months > 0)
              OR (LOWER(BTRIM(d.deposit_name)) = 'utility deposit'
                  AND COALESCE(v_room.utility_deposit, 0) > 0)
          )
    ) THEN
        RAISE EXCEPTION
            'Cannot approve booking: duplicate or overlapping deposit sources require reconciliation.';
    END IF;

    SELECT COALESCE((
               SELECT jsonb_agg(
                   jsonb_build_object(
                       'source', 'PROPERTY_ADDITIONAL_FEE',
                       'fee_id', f.fee_id,
                       'name', f.fee_name,
                       'fee_type', f.fee_type,
                       'description', f.description,
                       'amount', f.amount
                   )
                   ORDER BY f.fee_id
               )
               FROM public.property_additional_fees f
               WHERE f.house_id = v_room.house_id
                 AND UPPER(f.fee_type::text) = 'ONE_TIME'
                 AND COALESCE(f.is_enabled, TRUE)
           ), '[]'::jsonb)
    INTO v_one_time_fees;

    SELECT COALESCE(jsonb_agg(
               jsonb_build_object(
                   'source', 'PROPERTY_ADDITIONAL_FEE',
                   'fee_id', f.fee_id,
                   'name', f.fee_name,
                   'fee_type', f.fee_type,
                   'description', f.description,
                   'amount', f.amount
               )
               ORDER BY f.fee_id
           ), '[]'::jsonb)
    INTO v_recurring_fees
    FROM public.property_additional_fees f
    WHERE f.house_id = v_room.house_id
      AND UPPER(f.fee_type::text) = 'MONTHLY'
      AND COALESCE(f.is_enabled, TRUE);

    SELECT jsonb_build_object(
               'house_free_electricity', v_room.free_electricity,
               'house_electricity_rate', v_room.electricity_rate,
               'house_free_water', v_room.free_water,
               'house_water_rate', v_room.water_rate,
               'configured_utilities', COALESCE((
                   SELECT jsonb_agg(
                       jsonb_build_object(
                           'utility_id', u.utility_id,
                           'name', u.utility_name,
                           'charging_method', u.charging_method,
                           'fixed_amount', u.fixed_amount,
                           'is_enabled', u.is_enabled,
                           'applies_to', u.applies_to
                       )
                       ORDER BY u.utility_id
                   )
                   FROM public.property_utilities u
                   WHERE u.house_id = v_room.house_id
                     AND COALESCE(u.is_enabled, TRUE)
               ), '[]'::jsonb)
           )
    INTO v_utility_terms;

    SELECT review_status
    INTO v_existing_status
    FROM public.booking_pricing_snapshots
    WHERE booking_id = v_booking_id
    FOR UPDATE;

    v_previous_context := current_setting('dormigo.snapshot_capture_context', TRUE);
    PERFORM set_config('dormigo.snapshot_capture_context', 'approval-trigger', TRUE);

    IF NOT FOUND THEN
        INSERT INTO public.booking_pricing_snapshots (
            booking_id, snapshot_version, captured_at, agreed_move_in_date,
            agreed_duration_months, agreed_monthly_rent, agreed_total_amount,
            advance_months, deposit_months, utility_deposit,
            room_other_fees_amount, room_other_fees_description,
            property_deposit_details, one_time_fee_details,
            recurring_fee_details, utility_charging_terms,
            payment_due_day, review_status
        )
        VALUES (
            v_booking_id, 1, v_now, v_room.move_in_date,
            v_room.duration_months, v_room.agreed_monthly_rent,
            v_room.agreed_total_amount,
            v_room.advance_months, v_room.deposit_months,
            COALESCE(v_room.utility_deposit, 0),
            COALESCE(v_room.other_fees, 0), v_room.other_fees_description,
            v_property_deposits, v_one_time_fees, v_recurring_fees, v_utility_terms,
            v_room.payment_due_day, 'CAPTURED_VERIFIED'
        );
        PERFORM set_config(
            'dormigo.snapshot_capture_context',
            COALESCE(v_previous_context, ''),
            TRUE
        );
        RETURN NEW;
    END IF;

    IF v_existing_status <> 'REVIEW_REQUIRED' THEN
        RAISE EXCEPTION 'Pricing snapshot already exists and is not eligible for first capture.';
    END IF;

    UPDATE public.booking_pricing_snapshots
    SET captured_at = v_now,
        agreed_move_in_date = v_room.move_in_date,
        agreed_duration_months = v_room.duration_months,
        agreed_monthly_rent = v_room.agreed_monthly_rent,
        agreed_total_amount = v_room.agreed_total_amount,
        advance_months = v_room.advance_months,
        deposit_months = v_room.deposit_months,
        utility_deposit = COALESCE(v_room.utility_deposit, 0),
        room_other_fees_amount = COALESCE(v_room.other_fees, 0),
        room_other_fees_description = v_room.other_fees_description,
        property_deposit_details = v_property_deposits,
        one_time_fee_details = v_one_time_fees,
        recurring_fee_details = v_recurring_fees,
        utility_charging_terms = v_utility_terms,
        payment_due_day = v_room.payment_due_day,
        review_status = 'CAPTURED_VERIFIED'
    WHERE booking_id = v_booking_id;

    PERFORM set_config(
        'dormigo.snapshot_capture_context',
        COALESCE(v_previous_context, ''),
        TRUE
    );

    RETURN NEW;
END
$function$;

CREATE TRIGGER bookings_capture_pricing_snapshot_after_status_update
AFTER UPDATE OF status ON public.bookings
FOR EACH ROW
WHEN (OLD.status::text = 'PENDING' AND NEW.status::text = 'APPROVED')
EXECUTE FUNCTION public.capture_booking_pricing_snapshot_on_approval();

REVOKE ALL ON public.booking_pricing_snapshots FROM PUBLIC;
REVOKE ALL ON FUNCTION public.protect_booking_pricing_snapshot() FROM PUBLIC;
REVOKE ALL ON FUNCTION public.capture_booking_pricing_snapshot_on_approval() FROM PUBLIC;

-- A dedicated NOLOGIN owner keeps application roles from bypassing snapshot
-- triggers through ownership. Superusers remain inherently privileged and are
-- rejected for the known application-role names above.
GRANT USAGE ON SCHEMA public TO dormigo_snapshot_owner;
GRANT CREATE ON SCHEMA public TO dormigo_snapshot_owner;
GRANT SELECT ON public.bookings, public.rooms, public.boarding_houses,
                public.property_deposits, public.property_additional_fees,
                public.property_utilities
TO dormigo_snapshot_owner;
ALTER TABLE public.booking_pricing_snapshots OWNER TO dormigo_snapshot_owner;
ALTER FUNCTION public.protect_booking_pricing_snapshot() OWNER TO dormigo_snapshot_owner;
ALTER FUNCTION public.capture_booking_pricing_snapshot_on_approval()
    OWNER TO dormigo_snapshot_owner;
REVOKE CREATE ON SCHEMA public FROM dormigo_snapshot_owner;

REVOKE ALL ON public.booking_pricing_snapshots FROM PUBLIC;

-- App roles can read snapshots, but cannot insert, update, delete, truncate,
-- own the table, or SET ROLE to its non-login owner.
DO $grants$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'dormigo_api_app') THEN
        EXECUTE 'REVOKE ALL ON public.booking_pricing_snapshots FROM dormigo_api_app';
        EXECUTE 'GRANT SELECT ON public.booking_pricing_snapshots TO dormigo_api_app';
        EXECUTE 'REVOKE ALL ON FUNCTION public.capture_booking_pricing_snapshot_on_approval() FROM dormigo_api_app';
        EXECUTE 'REVOKE ALL ON FUNCTION public.protect_booking_pricing_snapshot() FROM dormigo_api_app';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'dormigo_web_admin_app') THEN
        EXECUTE 'REVOKE ALL ON public.booking_pricing_snapshots FROM dormigo_web_admin_app';
        EXECUTE 'GRANT SELECT ON public.booking_pricing_snapshots TO dormigo_web_admin_app';
        EXECUTE 'REVOKE ALL ON FUNCTION public.capture_booking_pricing_snapshot_on_approval() FROM dormigo_web_admin_app';
        EXECUTE 'REVOKE ALL ON FUNCTION public.protect_booking_pricing_snapshot() FROM dormigo_web_admin_app';
    END IF;
END
$grants$;

DO $privileges$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_roles app_role
        WHERE app_role.rolname IN ('dormigo_api_app', 'dormigo_web_admin_app')
          AND (
              has_table_privilege(
                  app_role.oid,
                  'public.booking_pricing_snapshots',
                  'INSERT'
              )
              OR has_table_privilege(
                  app_role.oid,
                  'public.booking_pricing_snapshots',
                  'UPDATE'
              )
              OR has_table_privilege(
                  app_role.oid,
                  'public.booking_pricing_snapshots',
                  'DELETE'
              )
              OR has_table_privilege(
                  app_role.oid,
                  'public.booking_pricing_snapshots',
                  'TRUNCATE'
              )
              OR has_function_privilege(
                  app_role.oid,
                  'public.capture_booking_pricing_snapshot_on_approval()',
                  'EXECUTE'
              )
              OR has_function_privilege(
                  app_role.oid,
                  'public.protect_booking_pricing_snapshot()',
                  'EXECUTE'
              )
          )
    ) THEN
        RAISE EXCEPTION
            'An application role retains effective snapshot DML or capture-function privileges through a grant or role membership.';
    END IF;
END
$privileges$;

COMMIT;
