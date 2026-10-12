-- NON-EXECUTED setup plan for replacing PostgreSQL superuser credentials in
-- the Android PHP backend and Web Admin PHP backend.
--
-- This payment-workflow grant set is intentionally narrow. It is not a complete
-- permission inventory for every Dormigo endpoint. Audit all endpoints and add
-- only required privileges before replacing either backend's current account.
-- The application roles must not own tables, schemas, functions, or triggers.
--
-- Apply while connected as a database administrator to dormigo on port 5432.
-- The roles are created without passwords. Set separate strong credentials
-- interactively with psql's \password dormigo_api_app and
-- \password dormigo_web_admin_app. Do not put passwords in this file.
--
-- Coordinated rollout:
-- 1. Pause payment writes through both backends.
-- 2. Apply and verify the payment-type migration as a DBA.
-- 3. Create/verify these non-owner, non-superuser roles and set credentials.
-- 4. Deploy both PHP backends configured for their own role credentials and
--    payment_type-aware request/response handling.
-- 5. Confirm both backend health checks and payment reads; keep writes paused
--    until all clients are compatible.
-- 6. Deploy Android payment_type/period support, then reopen payment writes.
-- 7. Re-run payment duplicate and legacy-slot verification.
--
-- This file is a setup plan and has NOT been executed.

BEGIN;

DO $roles$
BEGIN
    IF current_database() <> 'dormigo' OR inet_server_port() <> 5432 THEN
        RAISE EXCEPTION 'Wrong database target; expected dormigo on port 5432.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM pg_roles
        WHERE rolname IN ('dormigo_api_app', 'dormigo_web_admin_app')
    ) THEN
        RAISE EXCEPTION
            'One or more application roles already exist; inspect their attributes and grants before continuing.';
    END IF;

    EXECUTE 'CREATE ROLE dormigo_api_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT';
    EXECUTE 'CREATE ROLE dormigo_web_admin_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT';
END
$roles$;

REVOKE CREATE ON SCHEMA public FROM PUBLIC;

GRANT CONNECT ON DATABASE dormigo TO dormigo_api_app, dormigo_web_admin_app;
GRANT USAGE ON SCHEMA public TO dormigo_api_app, dormigo_web_admin_app;

-- Android backend payment workflow: reads the referenced booking/property/user
-- data, updates the initial-payment booking flag, writes payment rows, and
-- creates payment notifications.
GRANT SELECT ON public.bookings, public.rooms, public.boarding_houses,
                public.users, public.property_additional_fees,
                public.property_utilities
TO dormigo_api_app;
GRANT SELECT, INSERT, UPDATE ON public.payments TO dormigo_api_app;
GRANT UPDATE ON public.bookings TO dormigo_api_app;
GRANT INSERT ON public.notifications TO dormigo_api_app;

-- Web Admin payment and booking approval paths need their own login. Scope is
-- still limited to payment-related operations; audit remaining admin features
-- before switching its database configuration.
GRANT SELECT ON public.bookings, public.rooms, public.boarding_houses,
                public.users, public.property_additional_fees,
                public.property_utilities
TO dormigo_web_admin_app;
GRANT SELECT, INSERT, UPDATE ON public.payments TO dormigo_web_admin_app;
GRANT SELECT, INSERT, UPDATE ON public.bookings, public.rooms
TO dormigo_web_admin_app;
GRANT INSERT ON public.notifications TO dormigo_web_admin_app;

-- Grant only sequence access needed for payment and notification inserts.
DO $sequences$
DECLARE
    sequence_name text;
BEGIN
    FOREACH sequence_name IN ARRAY ARRAY[
        pg_catalog.pg_get_serial_sequence('public.payments', 'payment_id'),
        pg_catalog.pg_get_serial_sequence('public.notifications', 'notification_id')
    ]
    LOOP
        IF sequence_name IS NOT NULL THEN
            EXECUTE pg_catalog.format(
                'GRANT USAGE, SELECT ON SEQUENCE %s TO dormigo_api_app, dormigo_web_admin_app',
                sequence_name
            );
        END IF;
    END LOOP;
END
$sequences$;

-- The payment trigger reads this registry through its SECURITY DEFINER
-- function. Application login roles must not read or modify exception rows.
REVOKE ALL ON public.payment_legacy_duplicate_exceptions
FROM PUBLIC, dormigo_api_app, dormigo_web_admin_app;

COMMIT;

-- Rollback guidance (manual, after application credentials are reverted):
-- REVOKE ALL PRIVILEGES ON public.bookings, public.rooms, public.boarding_houses,
--   public.users, public.property_additional_fees, public.property_utilities,
--   public.payments, public.notifications
--   FROM dormigo_api_app, dormigo_web_admin_app;
-- REVOKE CONNECT ON DATABASE dormigo
--   FROM dormigo_api_app, dormigo_web_admin_app;
-- DROP ROLE dormigo_api_app;
-- DROP ROLE dormigo_web_admin_app;
-- Review default privileges and any remaining owned objects before dropping.
