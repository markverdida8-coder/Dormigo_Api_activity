BEGIN;

ALTER TABLE public.password_reset_tokens
    ALTER COLUMN otp_code TYPE VARCHAR(255),
    ADD COLUMN IF NOT EXISTS attempts SMALLINT NOT NULL DEFAULT 0;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'password_reset_tokens_attempts_check'
          AND conrelid = 'public.password_reset_tokens'::regclass
    ) THEN
        ALTER TABLE public.password_reset_tokens
            ADD CONSTRAINT password_reset_tokens_attempts_check
            CHECK (attempts BETWEEN 0 AND 5);
    END IF;
END
$$;

UPDATE public.password_reset_tokens
SET used = TRUE
WHERE used IS DISTINCT FROM TRUE;

UPDATE public.password_reset_tokens
SET otp_code = ''
WHERE otp_code <> '';

CREATE TABLE IF NOT EXISTS public.password_reset_rate_limits (
    scope VARCHAR(5) NOT NULL CHECK (scope IN ('EMAIL', 'IP')),
    subject_hash CHAR(64) NOT NULL CHECK (subject_hash ~ '^[a-f0-9]{64}$'),
    window_started_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    request_count INTEGER NOT NULL DEFAULT 0 CHECK (request_count >= 0),
    last_requested_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    request_timestamps TIMESTAMP WITHOUT TIME ZONE[] NOT NULL DEFAULT ARRAY[]::TIMESTAMP WITHOUT TIME ZONE[],
    PRIMARY KEY (scope, subject_hash)
);

ALTER TABLE public.password_reset_rate_limits
    ADD COLUMN IF NOT EXISTS request_timestamps TIMESTAMP WITHOUT TIME ZONE[]
        NOT NULL DEFAULT ARRAY[]::TIMESTAMP WITHOUT TIME ZONE[];

COMMIT;
