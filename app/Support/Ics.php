<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Builds .ics calendar files that Google Calendar, Apple Calendar and Outlook all read.
 *
 * Shared by everything that puts something in a learner's diary — live classes and
 * booked mentor sessions — so the RFC 5545 details (CRLF endings, escaping, UTC
 * stamps) live in one place.
 */
class Ics
{
    /** One VEVENT, ready to hand to calendar(). */
    public static function event(
        string $uid,
        Carbon $start,
        Carbon $end,
        string $summary,
        ?string $description = null,
        ?string $location = null,
        ?string $url = null,
        ?int $alarmMinutes = null,
        ?string $alarmText = null,
    ): array {
        $lines = [
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . self::stamp(now()),
            'DTSTART:' . self::stamp($start),
            'DTEND:' . self::stamp($end),
            'SUMMARY:' . self::escape($summary),
            'DESCRIPTION:' . self::escape((string) $description),
            'SEQUENCE:0',
            'STATUS:CONFIRMED',
        ];

        if (filled($location)) {
            $lines[] = 'LOCATION:' . self::escape($location);
        }

        if (filled($url)) {
            $lines[] = 'URL:' . self::escape($url);
        }

        if ($alarmMinutes !== null) {
            $lines = array_merge($lines, [
                'BEGIN:VALARM',
                'TRIGGER:-PT' . $alarmMinutes . 'M',
                'ACTION:DISPLAY',
                'DESCRIPTION:' . self::escape($alarmText ?? $summary),
                'END:VALARM',
            ]);
        }

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /** Wraps events in the calendar envelope. */
    public static function calendar(array $events, string $prodId = '-//Classroom//Classroom//EN'): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . $prodId,
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];

        foreach ($events as $event) {
            $lines = array_merge($lines, $event);
        }

        $lines[] = 'END:VCALENDAR';

        // RFC 5545 wants CRLF, including on the final line.
        return implode("\r\n", $lines) . "\r\n";
    }

    /** A UID that stays stable for the same record, so re-importing updates rather than duplicates. */
    public static function uid(string $prefix, $id): string
    {
        return $prefix . '-' . $id . '@' . (parse_url(config('app.url'), PHP_URL_HOST) ?: 'classroom');
    }

    public static function stamp(Carbon $date): string
    {
        return $date->copy()->utc()->format('Ymd\THis\Z');
    }

    /** RFC 5545 escaping: backslashes, semicolons, commas and newlines. */
    public static function escape(?string $value): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n"],
            ['\\\\', '\;', '\,', '\n', '\n'],
            (string) $value
        );
    }
}
