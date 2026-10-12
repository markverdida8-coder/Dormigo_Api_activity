-- Dormigo payment type and duplicate-slot protection.
--
-- NOT EXECUTED as part of preparation. Run only after explicit approval and a
-- payment-write maintenance window. This migration deliberately fails closed
-- if any description or active duplicate differs from the reviewed live data.
--
-- Historical payment_period values are never changed. New INITIAL rows use 0;
-- MONTHLY rows use 1..N. Status, amount, description, reference, due date,
-- payment date, proof, rejection reason, and verification timestamp are untouched.
--
-- The exact legacy duplicates 11-15 and 17 are excluded from the unique index.
-- These are exact legacy rows, not an ID cutoff. Their occupied slots are
-- separately blocked for all new active attempts by the trigger, even if the
-- non-exempt anchor payment later becomes rejected/cancelled. PostgreSQL's
-- unique index arbitrates concurrent writes for every other active slot.
--
-- To preserve these historical payment rows, DELETE of these six payment rows
-- is intentionally rejected. Since bookings delete payments by ON DELETE
-- CASCADE, deleting booking 3 or 4 will also be rejected. Remove this protection
-- only after an explicit history-retention policy decision.
--
-- Any error before COMMIT rolls back this migration's DDL and backfill. After
-- COMMIT, do not apply a blind reverse migration: pause writers, reconcile rows
-- created with payment_type, then perform an explicitly reviewed schema rollback.

BEGIN;

DO $migration$
BEGIN
    IF current_database() <> 'dormigo' OR inet_server_port() <> 5432 THEN
        RAISE EXCEPTION 'Wrong database target; expected dormigo on port 5432.';
    END IF;

    IF to_regclass('public.payments') IS NULL THEN
        RAISE EXCEPTION 'Expected public.payments table does not exist.';
    END IF;

    IF to_regtype('public.payment_type_enum') IS NOT NULL
       OR EXISTS (
           SELECT 1
           FROM information_schema.columns
           WHERE table_schema = 'public'
             AND table_name = 'payments'
             AND column_name IN ('payment_type')
       )
       OR to_regclass('public.payment_legacy_duplicate_exceptions') IS NOT NULL
       OR to_regclass('public.idx_payments_unique_active_type_period') IS NOT NULL
    THEN
        RAISE EXCEPTION 'Payment type migration objects already exist; inspect state before proceeding.';
    END IF;
END
$migration$;

-- Serialize with all payment writers and classification checks.
LOCK TABLE public.payments IN ACCESS EXCLUSIVE MODE;

DO $migration$
DECLARE
    active_statuses text[];
BEGIN
    SELECT ARRAY_AGG(e.enumlabel ORDER BY e.enumsortorder)
    INTO active_statuses
    FROM pg_type t
    JOIN pg_enum e ON e.enumtypid = t.oid
    WHERE t.oid = to_regtype('public.payment_status_enum');

    IF active_statuses IS NULL
       OR NOT (active_statuses @> ARRAY['PENDING', 'SUBMITTED', 'PAID', 'CONFIRMED']::text[]) THEN
        RAISE EXCEPTION 'payment_status_enum is missing a required protected status.';
    END IF;

    IF (SELECT COUNT(*) FROM public.payments) <> 22
       OR (
           SELECT COUNT(*)
           FROM public.payments
           WHERE BTRIM(COALESCE(payment_description, '')) = 'Initial Move-in Payment'
       ) <> 8
       OR (
           SELECT COUNT(*)
           FROM public.payments
           WHERE BTRIM(COALESCE(payment_description, '')) = 'Monthly Rent'
              OR BTRIM(COALESCE(payment_description, '')) LIKE 'Monthly Rent – %'
       ) <> 14 THEN
        RAISE EXCEPTION
            'Payment history differs from reviewed 22-row baseline (8 initial, 14 monthly). Transaction rolled back.';
    END IF;
END
$migration$;

CREATE TYPE public.payment_type_enum AS ENUM ('INITIAL', 'MONTHLY');

ALTER TABLE public.payments
    ADD COLUMN payment_type public.payment_type_enum;

-- Exact-description classification only. Unknown descriptions remain NULL and
-- are rejected by the following guard; the migration does not guess.
UPDATE public.payments
SET payment_type = CASE
    WHEN BTRIM(COALESCE(payment_description, '')) = 'Initial Move-in Payment'
        THEN 'INITIAL'::public.payment_type_enum
    WHEN BTRIM(COALESCE(payment_description, '')) = 'Monthly Rent'
      OR BTRIM(COALESCE(payment_description, '')) LIKE 'Monthly Rent – %'
        THEN 'MONTHLY'::public.payment_type_enum
    ELSE NULL
END;

DO $migration$
DECLARE
    actual_exceptions integer[];
    expected_exceptions constant integer[] := ARRAY[11, 12, 13, 14, 15, 17]::integer[];
BEGIN
    IF EXISTS (SELECT 1 FROM public.payments WHERE payment_type IS NULL) THEN
        RAISE EXCEPTION
            'Unclassified payment descriptions exist. Review them manually; transaction rolled back.';
    END IF;

    SELECT COALESCE(
               ARRAY_AGG(payment_id ORDER BY payment_id),
               ARRAY[]::integer[]
           )
    INTO actual_exceptions
    FROM (
        SELECT payment_id,
               ROW_NUMBER() OVER (
                   PARTITION BY booking_id, payment_type, payment_period
                   ORDER BY payment_id
               ) AS slot_rank
        FROM public.payments
        WHERE status::text IN ('PENDING', 'SUBMITTED', 'PAID', 'CONFIRMED')
    ) ranked
    WHERE slot_rank > 1;

    IF actual_exceptions IS DISTINCT FROM expected_exceptions THEN
        RAISE EXCEPTION
            'Active duplicate payment rows differ from reviewed legacy set. Found %, expected %. Review before migration.',
            actual_exceptions,
            expected_exceptions;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.payments
        WHERE payment_id IN (11, 12, 13, 14, 15, 17)
          AND status::text <> 'PAID'
    ) THEN
        RAISE EXCEPTION
            'A reviewed historical duplicate is no longer PAID. Reassess the exception list before migration.';
    END IF;
END
$migration$;

ALTER TABLE public.payments
    ALTER COLUMN payment_type SET NOT NULL;

CREATE TABLE public.payment_legacy_duplicate_exceptions (
    payment_id integer PRIMARY KEY
        REFERENCES public.payments(payment_id) ON DELETE RESTRICT,
    booking_id integer NOT NULL,
    payment_type public.payment_type_enum NOT NULL,
    payment_period integer NOT NULL,
    reason text NOT NULL,
    recorded_at timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT payment_legacy_duplicate_exceptions_exact_rows CHECK (
        (payment_id IN (11, 12, 13, 14, 15) AND booking_id = 3
         AND payment_type = 'MONTHLY'::public.payment_type_enum
         AND payment_period = 1)
        OR
        (payment_id = 17 AND booking_id = 4
         AND payment_type = 'MONTHLY'::public.payment_type_enum
         AND payment_period = 1)
    )
);

COMMENT ON TABLE public.payment_legacy_duplicate_exceptions IS
    'Audit list of exact pre-migration duplicate payment rows preserved without weakening protection for new rows.';

INSERT INTO public.payment_legacy_duplicate_exceptions
    (payment_id, booking_id, payment_type, payment_period, reason)
SELECT payment_id,
       booking_id,
       payment_type,
       payment_period,
       'Pre-migration PAID duplicate preserved; payment identity and status are immutable.'
FROM public.payments
WHERE payment_id IN (11, 12, 13, 14, 15, 17);

DO $migration$
DECLARE
    exception_count integer;
BEGIN
    SELECT COUNT(*) INTO exception_count
    FROM public.payment_legacy_duplicate_exceptions;

    IF exception_count <> 6 THEN
        RAISE EXCEPTION 'Expected six exact legacy duplicate rows; found %. Transaction rolled back.', exception_count;
    END IF;
END
$migration$;

CREATE FUNCTION public.guard_payment_type_period_and_legacy_exceptions()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $function$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF EXISTS (
            SELECT 1
            FROM public.payment_legacy_duplicate_exceptions e
            WHERE e.payment_id = OLD.payment_id
        ) THEN
            RAISE EXCEPTION 'Reviewed legacy duplicate payment rows cannot be deleted.';
        END IF;
        RETURN OLD;
    END IF;

    IF TG_OP = 'INSERT' THEN
        IF NEW.status IS NULL THEN
            RAISE EXCEPTION 'New payment status is required.';
        END IF;

        IF NEW.payment_type = 'INITIAL'::public.payment_type_enum
           AND NEW.payment_period <> 0 THEN
            RAISE EXCEPTION 'New INITIAL payments must use payment_period 0.';
        END IF;

        IF NEW.payment_type = 'MONTHLY'::public.payment_type_enum
           AND NEW.payment_period < 1 THEN
            RAISE EXCEPTION 'New MONTHLY payments must use payment_period 1 or greater.';
        END IF;

        -- Protect exception slots on INSERT too. The partial unique index
        -- excludes the exact historical duplicate rows and therefore cannot
        -- alone prevent a new row from using one of their occupied slots.
        IF NEW.status::text IN ('PENDING', 'SUBMITTED', 'PAID', 'CONFIRMED')
           AND EXISTS (
               SELECT 1
               FROM public.payment_legacy_duplicate_exceptions e
               WHERE e.booking_id = NEW.booking_id
                 AND e.payment_type = NEW.payment_type
                 AND e.payment_period = NEW.payment_period
           ) THEN
            RAISE EXCEPTION
                'This booking/payment period has preserved legacy duplicate history and cannot accept a new active payment.';
        END IF;

        RETURN NEW;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.payment_legacy_duplicate_exceptions e
        WHERE e.payment_id = OLD.payment_id
    ) THEN
        IF pg_catalog.to_jsonb(NEW) IS DISTINCT FROM pg_catalog.to_jsonb(OLD) THEN
            RAISE EXCEPTION 'Reviewed legacy duplicate payment rows are immutable.';
        END IF;
        RETURN NEW;
    END IF;

    -- Check every UPDATE, including status-only transitions into an active
    -- state and key changes into a protected slot.
    IF NEW.status::text IN ('PENDING', 'SUBMITTED', 'PAID', 'CONFIRMED')
       AND (
           NEW.booking_id IS DISTINCT FROM OLD.booking_id
           OR NEW.payment_type IS DISTINCT FROM OLD.payment_type
           OR NEW.payment_period IS DISTINCT FROM OLD.payment_period
           OR OLD.status::text NOT IN ('PENDING', 'SUBMITTED', 'PAID', 'CONFIRMED')
       )
       AND EXISTS (
           SELECT 1
           FROM public.payment_legacy_duplicate_exceptions e
           WHERE e.booking_id = NEW.booking_id
             AND e.payment_type = NEW.payment_type
             AND e.payment_period = NEW.payment_period
       ) THEN
        RAISE EXCEPTION
            'This booking/payment period has preserved legacy duplicate history and cannot accept a new active payment.';
    END IF;

    IF NEW.status IS DISTINCT FROM OLD.status AND NEW.status IS NULL THEN
        RAISE EXCEPTION 'Payment status cannot be cleared.';
    END IF;

    IF NEW.booking_id IS DISTINCT FROM OLD.booking_id
       OR NEW.payment_type IS DISTINCT FROM OLD.payment_type
       OR NEW.payment_period IS DISTINCT FROM OLD.payment_period THEN
        IF NEW.payment_type = 'INITIAL'::public.payment_type_enum
           AND NEW.payment_period <> 0 THEN
            RAISE EXCEPTION 'INITIAL payments must use payment_period 0.';
        END IF;

        IF NEW.payment_type = 'MONTHLY'::public.payment_type_enum
           AND NEW.payment_period < 1 THEN
            RAISE EXCEPTION 'MONTHLY payments must use payment_period 1 or greater.';
        END IF;
    END IF;

    RETURN NEW;
END
$function$;

CREATE TRIGGER payments_guard_type_period_and_legacy_exceptions
BEFORE INSERT OR UPDATE OR DELETE ON public.payments
FOR EACH ROW
EXECUTE FUNCTION public.guard_payment_type_period_and_legacy_exceptions();

REVOKE ALL ON public.payment_legacy_duplicate_exceptions FROM PUBLIC;

CREATE UNIQUE INDEX idx_payments_unique_active_type_period
    ON public.payments (booking_id, payment_type, payment_period)
    WHERE status IN (
        'PENDING'::public.payment_status_enum,
        'SUBMITTED'::public.payment_status_enum,
        'PAID'::public.payment_status_enum,
        'CONFIRMED'::public.payment_status_enum
    )
      AND payment_id NOT IN (11, 12, 13, 14, 15, 17);

COMMENT ON INDEX public.idx_payments_unique_active_type_period IS
    'Allows one PENDING, SUBMITTED, PAID, or CONFIRMED payment per booking/type/period; exact legacy duplicate rows 11-15 and 17 are immutable exceptions.';

ROLLBACK;
