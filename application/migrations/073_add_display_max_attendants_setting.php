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

class Migration_Add_display_max_attendants_setting extends EA_Migration
{
    /**
     * Upgrade method.
     */
    public function up(): void
    {
        if (!$this->db->get_where('settings', ['name' => 'display_max_attendants'])->num_rows()) {
            $this->db->insert('settings', [
                'name' => 'display_max_attendants',
                'value' => '0',
            ]);
        }

        // NULL now follows the new setting. The services that kept the old default 0 follow it from now on, which
        // shows the same as before as long as the setting stays off.
        $this->dbforge->modify_column('services', [
            'display_max_attendants' => [
                'name' => 'display_max_attendants',
                'type' => 'TINYINT',
                'constraint' => '4',
                'null' => true,
                'default' => null,
            ],
        ]);

        $this->db->update('services', ['display_max_attendants' => null], ['display_max_attendants' => 0]);
    }

    /**
     * Downgrade method.
     */
    public function down(): void
    {
        $this->db->update('services', ['display_max_attendants' => 0], ['display_max_attendants' => null]);

        $this->dbforge->modify_column('services', [
            'display_max_attendants' => [
                'name' => 'display_max_attendants',
                'type' => 'TINYINT',
                'constraint' => '4',
                'null' => false,
                'default' => '0',
            ],
        ]);

        if ($this->db->get_where('settings', ['name' => 'display_max_attendants'])->num_rows()) {
            $this->db->delete('settings', ['name' => 'display_max_attendants']);
        }
    }
}
