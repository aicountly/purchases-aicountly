<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * The company's calendar date (launch readiness E8-PUR: F388, F482).
 *
 * Documents defaulted their date to the server's UTC day, so a goods receipt keyed before 05:30
 * IST on 1 April was dated 31 March — the previous financial year — while posted in the new
 * year's context. Indian companies keep Indian dates: Asia/Kolkata, the zone the dashboards
 * already use, unless the deployment names another (COMPANY_TIMEZONE).
 */
final class LocalDate
{
    public const DEFAULT_ZONE = 'Asia/Kolkata';

    public static function zone(): \DateTimeZone
    {
        $name = trim(Env::get('COMPANY_TIMEZONE'));
        try {
            return new \DateTimeZone($name !== '' ? $name : self::DEFAULT_ZONE);
        } catch (\Throwable) {
            return new \DateTimeZone(self::DEFAULT_ZONE);
        }
    }

    /** Today, as the company's calendar has it (Y-m-d). */
    public static function today(): string
    {
        return (new \DateTimeImmutable('now', self::zone()))->format('Y-m-d');
    }
}
