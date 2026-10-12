<?php

function paymentTypeColumnExists(PDO $pdo): bool
{
    $stmt = $pdo->query(
        "SELECT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = 'payments'
              AND column_name = 'payment_type'
        )"
    );

    return in_array($stmt->fetchColumn(), [true, 't', '1', 1], true);
}

function paymentTypeExpression(bool $hasPaymentType, string $alias = 'p'): string
{
    if ($hasPaymentType) {
        return $alias . '.payment_type::text';
    }

    return "CASE
        WHEN BTRIM(COALESCE({$alias}.payment_description, '')) = 'Initial Move-in Payment'
            THEN 'INITIAL'
        WHEN BTRIM(COALESCE({$alias}.payment_description, '')) = 'Monthly Rent'
          OR BTRIM(COALESCE({$alias}.payment_description, '')) LIKE 'Monthly Rent – %'
            THEN 'MONTHLY'
        ELSE NULL
    END";
}

function paymentSnapshotSchemaReady(PDO $pdo): bool
{
    $stmt = $pdo->query(
        "SELECT COUNT(*) = 16
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name = 'booking_pricing_snapshots'
           AND column_name IN (
               'booking_id', 'review_status', 'captured_at',
               'agreed_move_in_date', 'agreed_duration_months',
               'agreed_monthly_rent', 'agreed_total_amount',
               'advance_months', 'deposit_months', 'utility_deposit',
               'room_other_fees_amount', 'property_deposit_details',
               'one_time_fee_details', 'recurring_fee_details',
               'utility_charging_terms', 'payment_due_day'
           )"
    );

    return in_array($stmt->fetchColumn(), [true, 't', '1', 1], true);
}

function paymentPricingSnapshot(PDO $pdo, int $bookingId): array
{
    if (!paymentSnapshotSchemaReady($pdo)) {
        throw new RuntimeException('The verified booking pricing snapshot schema is unavailable.');
    }

    $stmt = $pdo->prepare(
        "SELECT *
         FROM public.booking_pricing_snapshots
         WHERE booking_id = :booking_id"
    );
    $stmt->execute(['booking_id' => $bookingId]);
    $snapshot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$snapshot || $snapshot['review_status'] !== 'CAPTURED_VERIFIED') {
        throw new RuntimeException('The booking pricing snapshot requires review.');
    }
    foreach (['property_deposit_details', 'one_time_fee_details', 'recurring_fee_details', 'utility_charging_terms'] as $field) {
        $decoded = is_string($snapshot[$field]) ? json_decode($snapshot[$field], true) : $snapshot[$field];
        if (!is_array($decoded)) {
            throw new RuntimeException('The booking pricing snapshot is incomplete.');
        }
        $snapshot[$field] = $decoded;
    }
    if (
        (float)$snapshot['room_other_fees_amount'] !== 0.0
        || (int)$snapshot['payment_due_day'] < 1
        || (int)$snapshot['payment_due_day'] > 31
        || (int)$snapshot['advance_months'] < 0
        || (int)$snapshot['deposit_months'] < 0
        || (int)$snapshot['agreed_duration_months'] <= 0
    ) {
        throw new RuntimeException('The booking pricing snapshot contains unsupported fee terms.');
    }
    foreach ([
        'agreed_monthly_rent', 'agreed_total_amount', 'utility_deposit',
        'room_other_fees_amount'
    ] as $amountField) {
        if (!is_numeric($snapshot[$amountField]) || (float)$snapshot[$amountField] < 0) {
            throw new RuntimeException('The booking pricing snapshot contains an invalid amount.');
        }
    }
    if (
        empty($snapshot['captured_at'])
        || empty($snapshot['agreed_move_in_date'])
        || (int)$snapshot['snapshot_version'] !== 1
    ) {
        throw new RuntimeException('The booking pricing snapshot is incomplete.');
    }

    return $snapshot;
}

function paymentInitialAmount(array $snapshot): float
{
    $rent = (float)$snapshot['agreed_monthly_rent'];
    $amount = $rent * (1 + (int)$snapshot['advance_months'] + (int)$snapshot['deposit_months'])
        + (float)$snapshot['utility_deposit'];

    foreach ($snapshot['property_deposit_details'] as $deposit) {
        if (
            !is_array($deposit)
            || strtoupper((string)($deposit['deposit_type'] ?? '')) !== 'FIXED'
            || strtoupper((string)($deposit['applies_to'] ?? '')) !== 'ALL_ROOMS'
            || !is_numeric($deposit['amount'] ?? null)
            || (float)$deposit['amount'] < 0
        ) {
            throw new RuntimeException('A captured property deposit has unsupported terms.');
        }
        $amount += (float)$deposit['amount'];
    }
    foreach ($snapshot['one_time_fee_details'] as $fee) {
        if (!is_array($fee) || !is_numeric($fee['amount'] ?? null) || (float)$fee['amount'] < 0) {
            throw new RuntimeException('A captured one-time fee is invalid.');
        }
        $amount += (float)$fee['amount'];
    }

    return round($amount, 2);
}

function paymentMonthlyAmount(array $snapshot): float
{
    $amount = (float)$snapshot['agreed_monthly_rent'];
    foreach ($snapshot['recurring_fee_details'] as $fee) {
        if (!is_array($fee) || !is_numeric($fee['amount'] ?? null) || (float)$fee['amount'] < 0) {
            throw new RuntimeException('A captured recurring fee is invalid.');
        }
        $amount += (float)$fee['amount'];
    }
    $utilities = $snapshot['utility_charging_terms']['configured_utilities'] ?? null;
    if (!is_array($utilities)) {
        throw new RuntimeException('Captured utility terms are incomplete.');
    }
    foreach ($utilities as $utility) {
        if (!is_array($utility)) {
            throw new RuntimeException('A captured utility term is invalid.');
        }
        if (
            !empty($utility['is_enabled'])
            && strtoupper((string)($utility['charging_method'] ?? '')) === 'FIXED'
        ) {
            if (
                strtoupper((string)($utility['applies_to'] ?? '')) !== 'ALL_ROOMS'
                || !is_numeric($utility['fixed_amount'] ?? null)
                || (float)$utility['fixed_amount'] < 0
            ) {
                throw new RuntimeException('A captured fixed utility amount is invalid.');
            }
            $amount += (float)$utility['fixed_amount'];
        }
    }

    return round($amount, 2);
}


function paymentBookingForUpdate(PDO $pdo, int $bookingId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT b.booking_id, b.user_id, b.room_id, b.status::text AS booking_status,
                b.move_in_date, b.duration_months,
                b.initial_payment_completed, b.next_due_date,
                bh.house_id, bh.landlord_id, bh.house_name,
                u.full_name AS student_name
         FROM bookings b
         JOIN rooms r ON r.room_id = b.room_id
         JOIN boarding_houses bh ON bh.house_id = r.house_id
         JOIN users u ON u.user_id = b.user_id
         WHERE b.booking_id = :booking_id
         FOR UPDATE OF b"
    );
    $stmt->execute(['booking_id' => $bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    return $booking ?: null;
}

function paymentHasConfirmedInitial(PDO $pdo, int $bookingId, bool $hasPaymentType): bool
{
    $typeExpression = paymentTypeExpression($hasPaymentType);
    $stmt = $pdo->prepare(
        "SELECT EXISTS (
            SELECT 1
            FROM payments p
            WHERE p.booking_id = :booking_id
              AND {$typeExpression} = 'INITIAL'
              AND UPPER(p.status::text) IN ('PAID', 'CONFIRMED')
              AND p.verified_at IS NOT NULL
        )"
    );
    $stmt->execute(['booking_id' => $bookingId]);

    return in_array($stmt->fetchColumn(), [true, 't', '1', 1], true);
}

function paymentActiveForTypeAndPeriod(
    PDO $pdo,
    int $bookingId,
    string $paymentType,
    int $period,
    bool $hasPaymentType,
    int $excludePaymentId = 0
): ?array {
    $typeExpression = paymentTypeExpression($hasPaymentType);
    $wherePeriod = $paymentType === 'INITIAL'
        ? ''
        : 'AND p.payment_period = :payment_period';
    $sql = "SELECT p.payment_id, p.payment_period, p.status::text AS status,
                   {$typeExpression} AS resolved_payment_type
            FROM payments p
            WHERE p.booking_id = :booking_id
              AND {$typeExpression} = :payment_type
              {$wherePeriod}
              AND UPPER(p.status::text) IN ('PENDING', 'SUBMITTED', 'PAID', 'CONFIRMED')
              AND p.payment_id <> :exclude_payment_id
            ORDER BY p.payment_id DESC
            LIMIT 1
            FOR UPDATE";
    $stmt = $pdo->prepare($sql);
    $params = [
        'booking_id' => $bookingId,
        'payment_type' => $paymentType,
        'exclude_payment_id' => $excludePaymentId
    ];
    if ($paymentType !== 'INITIAL') {
        $params['payment_period'] = $period;
    }
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function paymentNextMonthlyPeriod(PDO $pdo, int $bookingId, int $advanceMonths, bool $hasPaymentType): int
{
    $typeExpression = paymentTypeExpression($hasPaymentType);
    $stmt = $pdo->prepare(
        "SELECT COALESCE(MAX(p.payment_period), 0)
         FROM payments p
         WHERE p.booking_id = :booking_id
           AND {$typeExpression} = 'MONTHLY'
           AND UPPER(p.status::text) IN ('PENDING', 'SUBMITTED', 'PAID', 'CONFIRMED')"
    );
    $stmt->execute(['booking_id' => $bookingId]);
    $highestPeriod = (int)$stmt->fetchColumn();

    return max($advanceMonths + 2, $highestPeriod + 1);
}

function paymentLatestConfirmedMonthlyPeriod(PDO $pdo, int $bookingId, bool $hasPaymentType): int
{
    $typeExpression = paymentTypeExpression($hasPaymentType);
    $stmt = $pdo->prepare(
        "SELECT COALESCE(MAX(p.payment_period), 0)
         FROM payments p
         WHERE p.booking_id = :booking_id
           AND {$typeExpression} = 'MONTHLY'
           AND UPPER(p.status::text) IN ('PAID', 'CONFIRMED')
           AND p.verified_at IS NOT NULL"
    );
    $stmt->execute(['booking_id' => $bookingId]);

    return (int)$stmt->fetchColumn();
}

function paymentDueDateForPeriod(string $moveInDate, int $period, int $paymentDueDay): string
{
    if ($period < 1 || $paymentDueDay < 1 || $paymentDueDay > 31) {
        throw new InvalidArgumentException('A valid rental period and payment due day are required.');
    }
    $month = DateTimeImmutable::createFromFormat('!Y-m-d', $moveInDate);
    if (!$month || $month->format('Y-m-d') !== $moveInDate) {
        throw new InvalidArgumentException('The captured move-in date is invalid.');
    }
    $month = $month->modify('first day of this month')->modify('+' . ($period - 1) . ' months');
    $lastDay = (int)$month->format('t');

    return $month->setDate(
        (int)$month->format('Y'),
        (int)$month->format('m'),
        min($paymentDueDay, $lastDay)
    )->format('Y-m-d');
}

function paymentTypeFromRecord(array $payment): ?string
{
    $type = strtoupper(trim((string)($payment['resolved_payment_type'] ?? '')));
    if (in_array($type, ['INITIAL', 'MONTHLY'], true)) {
        return $type;
    }

    return null;
}
