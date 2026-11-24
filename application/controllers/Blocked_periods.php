<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.0.0
 * ---------------------------------------------------------------------------- */

/**
 * Blocked_periods controller.
 *
 * Handles the blocked-periods related operations.
 *
 * @package Controllers
 */
class Blocked_periods extends EA_Controller
{
    public array $allowed_blocked_period_fields = ['id', 'name', 'start_datetime', 'end_datetime', 'notes'];

    public array $optional_blocked_period_fields = [
        //
    ];

    /**
     * Blocked_periods constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->model('blocked_periods_model');
        $this->load->model('providers_model');
        $this->load->model('roles_model');
        $this->load->model('unavailabilities_model');

        $this->load->library('accounts');
        $this->load->library('ics_import');
        $this->load->library('synchronization');
        $this->load->library('timezones');
        $this->load->library('webhooks_client');
    }

    /**
     * Render the backend blocked-periods page.
     *
     * On this page admin users will be able to manage blocked-periods, which are eventually selected by customers during the
     * booking process.
     */
    public function index(): void
    {
        method('get');

        session(['dest_url' => site_url('blocked_periods')]);

        $user_id = session('user_id');

        if (cannot('view', PRIV_BLOCKED_PERIODS)) {
            if ($user_id) {
                abort(403, 'Forbidden');
            }

            redirect('login');

            return;
        }

        $role_slug = session('role_slug');

        script_vars([
            'user_id' => $user_id,
            'role_slug' => $role_slug,
            'date_format' => setting('date_format'),
            'time_format' => setting('time_format'),
            'first_weekday' => setting('first_weekday'),
        ]);

        html_vars([
            'page_title' => lang('blocked_periods'),
            'active_menu' => PRIV_BLOCKED_PERIODS,
            'user_display_name' => $this->accounts->get_user_display_name($user_id),
            'timezones' => $this->timezones->to_array(),
            'privileges' => $this->roles_model->get_permissions_by_slug($role_slug),
            'providers' => can('add', PRIV_APPOINTMENTS)
                ? $this->providers_model->get(null, null, null, 'first_name ASC, last_name ASC')
                : [],
        ]);

        $this->load->view('pages/blocked_periods');
    }

    /**
     * Filter blocked-periods by the provided keyword.
     */
    public function search(): void
    {
        try {
            method('post');

            if (cannot('view', PRIV_BLOCKED_PERIODS)) {
                abort(403, 'Forbidden');
            }

            check('keyword', 'string|null');
            check('order_by', 'string|null');
            check('limit', 'numeric|null');
            check('offset', 'numeric|null');

            $keyword = request('keyword', '');

            $order_by = request('order_by', 'update_datetime DESC');

            $limit = max(1, (int) request('limit', 1000));

            $offset = max(0, (int) request('offset', '0'));

            $blocked_periods = $this->blocked_periods_model->search($keyword, $limit, $offset, $order_by);

            json_response($blocked_periods);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Store a new service-category.
     */
    public function store(): void
    {
        try {
            method('post');

            if (cannot('add', PRIV_BLOCKED_PERIODS)) {
                abort(403, 'Forbidden');
            }

            check('blocked_period', 'array');

            $blocked_period = request('blocked_period');

            $this->blocked_periods_model->only($blocked_period, $this->allowed_blocked_period_fields);

            $this->blocked_periods_model->optional($blocked_period, $this->optional_blocked_period_fields);

            $blocked_period_id = $this->blocked_periods_model->save($blocked_period);

            $blocked_period = $this->blocked_periods_model->find($blocked_period_id);

            $this->webhooks_client->trigger(WEBHOOK_BLOCKED_PERIOD_SAVE, $blocked_period);

            json_response([
                'success' => true,
                'id' => $blocked_period_id,
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Import the events of an iCalendar file as blocked periods (system-wide) or provider unavailabilities.
     */
    public function import(): void
    {
        try {
            method('post');

            if (cannot('add', PRIV_BLOCKED_PERIODS)) {
                abort(403, 'Forbidden');
            }

            check('ics', 'string');
            check('provider_id', 'numeric|null');

            $provider_id = (int) request('provider_id') ?: null;

            if ($provider_id) {
                // Per provider periods are stored as unavailabilities, which have their own permission.
                if (cannot('add', PRIV_APPOINTMENTS)) {
                    abort(403, 'Forbidden');
                }

                authorize_event_write(null, $provider_id);

                $provider = $this->providers_model->find($provider_id);
            }

            $timezone = new DateTimeZone($provider['timezone'] ?? setting('default_timezone', 'UTC'));

            // ponytail: fixed import window, recurring events are expanded up to two years ahead.
            $from = new DateTime('today', $timezone);
            $to = (clone $from)->modify('+2 years');

            $periods = $this->ics_import->parse((string) request('ics'), $timezone, $from, $to);

            $created = 0;

            foreach ($periods as $period) {
                $period['name'] = $period['name'] ?: lang('blocked_period');

                $unique = [
                    'start_datetime' => $period['start_datetime'],
                    'end_datetime' => $period['end_datetime'],
                ];

                // Importing the same file again must not create duplicates.
                if (!$provider_id) {
                    if ($this->blocked_periods_model->get($unique + ['name' => $period['name']])) {
                        continue;
                    }

                    $blocked_period_id = $this->blocked_periods_model->save($period);

                    $this->webhooks_client->trigger(
                        WEBHOOK_BLOCKED_PERIOD_SAVE,
                        $this->blocked_periods_model->find($blocked_period_id),
                    );
                } else {
                    if ($this->unavailabilities_model->get($unique + ['id_users_provider' => $provider_id])) {
                        continue;
                    }

                    $unavailability_id = $this->unavailabilities_model->save([
                        'id_users_provider' => $provider_id,
                        'start_datetime' => $period['start_datetime'],
                        'end_datetime' => $period['end_datetime'],
                        'notes' => trim($period['name'] . PHP_EOL . $period['notes']),
                    ]);

                    $unavailability = $this->unavailabilities_model->find($unavailability_id);

                    $this->synchronization->sync_unavailability_saved($unavailability, $provider);

                    $this->webhooks_client->trigger(WEBHOOK_UNAVAILABILITY_SAVE, $unavailability);
                }

                $created++;
            }

            json_response([
                'success' => true,
                'created' => $created,
                'skipped' => count($periods) - $created,
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Find a service-category.
    public function find(): void
    {
        try {
            method('get');

            if (cannot('view', PRIV_BLOCKED_PERIODS)) {
                abort(403, 'Forbidden');
            }

            check('blocked_period_id', 'numeric');

            $blocked_period_id = request('blocked_period_id');

            // Validate blocked_period_id is a positive integer
            if (
                empty($blocked_period_id) ||
                !filter_var($blocked_period_id, FILTER_VALIDATE_INT) ||
                $blocked_period_id <= 0
            ) {
                throw new InvalidArgumentException('Invalid blocked period ID provided.');
            }

            $blocked_period = $this->blocked_periods_model->find($blocked_period_id);

            json_response($blocked_period);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Update a service-category.
     */
    public function update(): void
    {
        try {
            method('post');

            if (cannot('edit', PRIV_BLOCKED_PERIODS)) {
                abort(403, 'Forbidden');
            }

            check('blocked_period', 'array');

            $blocked_period = request('blocked_period');

            $this->blocked_periods_model->only($blocked_period, $this->allowed_blocked_period_fields);

            $this->blocked_periods_model->optional($blocked_period, $this->optional_blocked_period_fields);

            $blocked_period_id = $this->blocked_periods_model->save($blocked_period);

            $blocked_period = $this->blocked_periods_model->find($blocked_period_id);

            $this->webhooks_client->trigger(WEBHOOK_BLOCKED_PERIOD_SAVE, $blocked_period);

            json_response([
                'success' => true,
                'id' => $blocked_period_id,
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Remove a service-category.
     */
    public function destroy(): void
    {
        try {
            method('post');

            if (cannot('delete', PRIV_BLOCKED_PERIODS)) {
                abort(403, 'Forbidden');
            }

            check('blocked_period_id', 'numeric');

            $blocked_period_id = request('blocked_period_id');

            // Validate blocked_period_id is a positive integer
            if (
                empty($blocked_period_id) ||
                !filter_var($blocked_period_id, FILTER_VALIDATE_INT) ||
                $blocked_period_id <= 0
            ) {
                throw new InvalidArgumentException('Invalid blocked period ID provided.');
            }

            $blocked_period = $this->blocked_periods_model->find($blocked_period_id);

            $this->blocked_periods_model->delete($blocked_period_id);

            $this->webhooks_client->trigger(WEBHOOK_BLOCKED_PERIOD_DELETE, $blocked_period);

            json_response([
                'success' => true,
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }
}
