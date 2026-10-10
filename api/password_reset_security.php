<?php

function passwordResetBoolean($value): bool
{
    return $value === true || $value === 't' || $value === '1' || $value === 1;
}

function passwordResetRequestAllowed(PDO $pdo, string $email, string $ipAddress): bool
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Password reset rate limits require a transaction.');
    }

    $normalizedEmail = strtolower(trim($email));
    $packedIp = filter_var($ipAddress, FILTER_VALIDATE_IP) ? inet_pton($ipAddress) : false;
    $normalizedIp = $packedIp === false ? 'unknown' : inet_ntop($packedIp);
    $subjects = [
        ['scope' => 'IP', 'hash' => hash('sha256', $normalizedIp), 'limit' => 10],
        ['scope' => 'EMAIL', 'hash' => hash('sha256', $normalizedEmail), 'limit' => 3]
    ];

    foreach ($subjects as $subject) {
        $insert = $pdo->prepare(
            'INSERT INTO password_reset_rate_limits
                (scope, subject_hash, window_started_at, request_count, last_requested_at)
             VALUES (:scope, :subject_hash, CURRENT_TIMESTAMP, 0, CURRENT_TIMESTAMP)
             ON CONFLICT (scope, subject_hash) DO NOTHING'
        );
        $insert->execute([
            'scope' => $subject['scope'],
            'subject_hash' => $subject['hash']
        ]);

        $select = $pdo->prepare(
            "SELECT request_count,
                    (
                        SELECT COUNT(*)
                        FROM unnest(request_timestamps) AS recent(requested_at)
                        WHERE requested_at > CURRENT_TIMESTAMP - INTERVAL '15 minutes'
                    ) AS recent_count,
                    request_count > 0
                        AND last_requested_at > CURRENT_TIMESTAMP - INTERVAL '60 seconds' AS cooldown_active
             FROM password_reset_rate_limits
             WHERE scope = :scope AND subject_hash = :subject_hash
             FOR UPDATE"
        );
        $select->execute([
            'scope' => $subject['scope'],
            'subject_hash' => $subject['hash']
        ]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Unable to lock password reset rate limit.');
        }

        $cooldownActive = $subject['scope'] === 'EMAIL'
            && passwordResetBoolean($row['cooldown_active']);
        $requestCount = min((int)$row['recent_count'] + 1, $subject['limit'] + 1);

        $update = $pdo->prepare(
            "UPDATE password_reset_rate_limits
             SET request_timestamps = ARRAY(
                    SELECT recent.requested_at
                    FROM unnest(request_timestamps || ARRAY[CURRENT_TIMESTAMP]::TIMESTAMP WITHOUT TIME ZONE[])
                        AS recent(requested_at)
                    WHERE recent.requested_at > CURRENT_TIMESTAMP - INTERVAL '15 minutes'
                    ORDER BY recent.requested_at DESC
                    LIMIT :retention_limit
                 ),
                 request_count = :request_count,
                 window_started_at = CASE
                    WHEN :request_count = 1 THEN CURRENT_TIMESTAMP
                    ELSE window_started_at
                 END,
                 last_requested_at = CURRENT_TIMESTAMP
             WHERE scope = :scope AND subject_hash = :subject_hash"
        );
        $update->execute([
            'request_count' => $requestCount,
            'retention_limit' => $subject['limit'] + 1,
            'scope' => $subject['scope'],
            'subject_hash' => $subject['hash']
        ]);

        if ($cooldownActive || $requestCount > $subject['limit']) {
            return false;
        }
    }

    return true;
}

function passwordResetCheckOtp(PDO $pdo, string $email, string $otpCode): ?array
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('OTP checks require a transaction.');
    }

    $select = $pdo->prepare(
        "SELECT id, user_id, otp_code, attempts,
                expires_at > CURRENT_TIMESTAMP AS is_unexpired
         FROM password_reset_tokens
         WHERE LOWER(email) = :email AND used IS DISTINCT FROM TRUE
         ORDER BY id DESC
         LIMIT 1
         FOR UPDATE"
    );
    $select->execute(['email' => strtolower(trim($email))]);
    $token = $select->fetch(PDO::FETCH_ASSOC);
    if (!$token) {
        return null;
    }

    if (!passwordResetBoolean($token['is_unexpired'])) {
        $expire = $pdo->prepare('UPDATE password_reset_tokens SET used = TRUE WHERE id = :id');
        $expire->execute(['id' => $token['id']]);
        return null;
    }

    if ((int)$token['attempts'] >= 5) {
        $invalidate = $pdo->prepare('UPDATE password_reset_tokens SET used = TRUE WHERE id = :id');
        $invalidate->execute(['id' => $token['id']]);
        return null;
    }

    $matches = preg_match('/^[0-9]{6}$/D', $otpCode) === 1
        && password_verify($otpCode, $token['otp_code']);
    if (!$matches) {
        $attempt = $pdo->prepare(
            'UPDATE password_reset_tokens
             SET attempts = attempts + 1,
                 used = CASE WHEN attempts + 1 >= 5 THEN TRUE ELSE used END
             WHERE id = :id AND attempts < 5
             RETURNING attempts'
        );
        $attempt->execute(['id' => $token['id']]);
        return null;
    }

    return $token;
}
