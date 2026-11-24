<?php

namespace Tests\Unit\Library;

use DateTime;
use DateTimeZone;
use Ics_import;
use InvalidArgumentException;
use Tests\TestCase;

class IcsImportTest extends TestCase
{
    private function parse(string $events, string $timezone = 'Europe/Berlin'): array
    {
        require_once APPPATH . 'libraries/Ics_import.php';

        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\n" . $events . "END:VCALENDAR\r\n";

        $tz = new DateTimeZone($timezone);

        return (new Ics_import())->parse(
            $ics,
            $tz,
            new DateTime('2026-01-01 00:00', $tz),
            new DateTime('2027-12-31 23:59', $tz),
        );
    }

    public function testAllDayEventsCoverTheirWholeDays(): void
    {
        $result = $this->parse(
            "BEGIN:VEVENT\r\nUID:1\r\nSUMMARY:Christmas\r\nDTSTART;VALUE=DATE:20261225\r\n" .
                "DTEND;VALUE=DATE:20261227\r\nEND:VEVENT\r\n",
        );

        $this->assertSame(
            [
                [
                    'name' => 'Christmas',
                    'start_datetime' => '2026-12-25 00:00:00',
                    'end_datetime' => '2026-12-26 23:59:00',
                    'notes' => '',
                ],
            ],
            $result,
        );
    }

    public function testTimedEventsAreConvertedToTheTargetTimezone(): void
    {
        $result = $this->parse(
            "BEGIN:VEVENT\r\nUID:2\r\nSUMMARY:Training\r\nDESCRIPTION:Offsite\r\n" .
                "DTSTART:20260701T080000Z\r\nDTEND:20260701T100000Z\r\nEND:VEVENT\r\n",
        );

        $this->assertCount(1, $result);
        $this->assertSame('2026-07-01 10:00:00', $result[0]['start_datetime']);
        $this->assertSame('2026-07-01 12:00:00', $result[0]['end_datetime']);
        $this->assertSame('Offsite', $result[0]['notes']);
    }

    public function testRecurringEventsAreExpandedInsideTheWindowOnly(): void
    {
        $result = $this->parse(
            "BEGIN:VEVENT\r\nUID:3\r\nSUMMARY:New Year\r\nDTSTART;VALUE=DATE:20250101\r\n" .
                "DTEND;VALUE=DATE:20250102\r\nRRULE:FREQ=YEARLY\r\nEND:VEVENT\r\n",
        );

        $this->assertSame(['2026-01-01 00:00:00', '2027-01-01 00:00:00'], array_column($result, 'start_datetime'));
    }

    public function testCancelledAndOutOfWindowEventsAreSkipped(): void
    {
        $result = $this->parse(
            "BEGIN:VEVENT\r\nUID:4\r\nSTATUS:CANCELLED\r\nDTSTART;VALUE=DATE:20260301\r\nEND:VEVENT\r\n" .
                "BEGIN:VEVENT\r\nUID:5\r\nDTSTART;VALUE=DATE:20200301\r\nEND:VEVENT\r\n",
        );

        $this->assertSame([], $result);
    }

    public function testInvalidContentsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        require_once APPPATH . 'libraries/Ics_import.php';

        (new Ics_import())->parse('not a calendar', new DateTimeZone('UTC'), new DateTime(), new DateTime());
    }
}
