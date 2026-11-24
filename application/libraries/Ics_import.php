<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.6.2
 * ---------------------------------------------------------------------------- */

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

/**
 * ICS import library.
 *
 * Converts the events of an iCalendar file (e.g. public holidays or a vacation calendar) to blocking periods.
 *
 * @package Libraries
 */
class Ics_import
{
    /**
     * Parse the events of an iCalendar file that fall in the provided window.
     *
     * Recurring events are expanded. All-day events cover their days from 00:00 to 23:59, so that the booking page
     * treats them as fully blocked dates.
     *
     * @param string $ics Contents of the iCalendar file.
     * @param DateTimeZone $timezone Timezone the returned date-times are converted to.
     * @param DateTimeInterface $from Window start.
     * @param DateTimeInterface $to Window end.
     *
     * @return array Returns a list of ['name', 'start_datetime', 'end_datetime', 'notes'] records.
     *
     * @throws InvalidArgumentException When the contents are not a valid iCalendar file.
     */
    public function parse(string $ics, DateTimeZone $timezone, DateTimeInterface $from, DateTimeInterface $to): array
    {
        try {
            $vcalendar = Reader::read($ics);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('The provided file is not a valid iCalendar (.ics) file.');
        }

        if (!($vcalendar instanceof VCalendar)) {
            throw new InvalidArgumentException('The provided file is not a valid iCalendar (.ics) file.');
        }

        $periods = [];

        foreach ($vcalendar->expand($from, $to, $timezone)->VEVENT ?? [] as $vevent) {
            if (strtoupper((string) $vevent->STATUS) === 'CANCELLED') {
                continue;
            }

            [$start, $end] = $this->get_start_and_end($vevent, $timezone);

            if ($start >= $end) {
                continue;
            }

            $periods[] = [
                'name' => trim((string) $vevent->SUMMARY),
                'start_datetime' => $start->format('Y-m-d H:i:s'),
                'end_datetime' => $end->format('Y-m-d H:i:s'),
                'notes' => trim((string) $vevent->DESCRIPTION),
            ];
        }

        return $periods;
    }

    /**
     * Get the start and end of an event in the provided timezone.
     *
     * @param VEvent $vevent Event component.
     * @param DateTimeZone $timezone Target timezone.
     *
     * @return DateTime[] Returns the start and end date-times.
     */
    protected function get_start_and_end(VEvent $vevent, DateTimeZone $timezone): array
    {
        $start = DateTime::createFromInterface($vevent->DTSTART->getDateTime($timezone));

        if (!$vevent->DTSTART->hasTime()) {
            // All-day events end at the start of the day after their last day (DTEND is exclusive).
            $start->setTime(0, 0);

            $end = isset($vevent->DTEND)
                ? DateTime::createFromInterface($vevent->DTEND->getDateTime($timezone))->modify('-1 day')
                : clone $start;

            return [$start, $end->setTime(23, 59)];
        }

        $start->setTimezone($timezone);

        if (isset($vevent->DTEND)) {
            $end = DateTime::createFromInterface($vevent->DTEND->getDateTime($timezone));
        } elseif (isset($vevent->DURATION)) {
            $end = (clone $start)->add($vevent->DURATION->getDateInterval());
        } else {
            $end = clone $start;
        }

        return [$start, $end->setTimezone($timezone)];
    }
}
