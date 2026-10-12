BEGIN;

LOCK TABLE public.bookings IN SHARE ROW EXCLUSIVE MODE;

DO $migration$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM public.bookings
        WHERE status IN (
            'PENDING'::booking_status_enum,
            'APPROVED'::booking_status_enum,
            'ACTIVE'::booking_status_enum
        )
        GROUP BY user_id
        HAVING COUNT(*) > 1
    ) THEN
        RAISE EXCEPTION
            'Cannot enforce one ongoing booking per student: duplicate ongoing bookings exist';
    END IF;
END
$migration$;

DROP INDEX IF EXISTS public.idx_unique_active_student_booking;

CREATE UNIQUE INDEX idx_unique_active_student_booking
    ON public.bookings (user_id)
    WHERE status IN (
        'PENDING'::booking_status_enum,
        'APPROVED'::booking_status_enum,
        'ACTIVE'::booking_status_enum
    );

COMMIT;
