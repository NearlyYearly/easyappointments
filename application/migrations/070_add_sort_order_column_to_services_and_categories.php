<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.5.0
 * ---------------------------------------------------------------------------- */

class Migration_Add_sort_order_column_to_services_and_categories extends EA_Migration
{
    /**
     * Upgrade method.
     */
    public function up(): void
    {
        foreach (['services', 'service_categories'] as $table) {
            if (!$this->db->field_exists('sort_order', $table)) {
                $this->dbforge->add_column($table, [
                    'sort_order' => [
                        'type' => 'INT',
                        'constraint' => '11',
                        'null' => false,
                        'default' => 0,
                    ],
                ]);
            }
        }
    }

    /**
     * Downgrade method.
     */
    public function down(): void
    {
        foreach (['services', 'service_categories'] as $table) {
            if ($this->db->field_exists('sort_order', $table)) {
                $this->dbforge->drop_column($table, 'sort_order');
            }
        }
    }
}
