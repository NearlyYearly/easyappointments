<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.7.0
 * ---------------------------------------------------------------------------- */

class Migration_Add_free_places_columns_to_services_table extends EA_Migration
{
    /**
     * Upgrade method.
     */
    public function up(): void
    {
        // NULL follows the display_free_places setting, 1 and 0 override it for the service.
        if (!$this->db->field_exists('display_free_places', 'services')) {
            $this->dbforge->add_column('services', [
                'display_free_places' => [
                    'type' => 'TINYINT',
                    'constraint' => '4',
                    'null' => true,
                    'after' => 'attendants_number',
                ],
            ]);
        }

        if (!$this->db->field_exists('display_max_attendants', 'services')) {
            $this->dbforge->add_column('services', [
                'display_max_attendants' => [
                    'type' => 'TINYINT',
                    'constraint' => '4',
                    'default' => '0',
                    'after' => 'display_free_places',
                ],
            ]);
        }
    }

    /**
     * Downgrade method.
     */
    public function down(): void
    {
        foreach (['display_max_attendants', 'display_free_places'] as $field) {
            if ($this->db->field_exists($field, 'services')) {
                $this->dbforge->drop_column('services', $field);
            }
        }
    }
}
