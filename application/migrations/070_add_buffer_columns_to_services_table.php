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

class Migration_Add_buffer_columns_to_services_table extends EA_Migration
{
    /**
     * Upgrade method.
     */
    public function up(): void
    {
        foreach (['buffer_before' => 'duration', 'buffer_after' => 'buffer_before'] as $column => $after) {
            if (!$this->db->field_exists($column, 'services')) {
                $this->dbforge->add_column('services', [
                    $column => [
                        'type' => 'INT',
                        'constraint' => '11',
                        'null' => false,
                        'default' => 0,
                        'after' => $after,
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
        foreach (['buffer_before', 'buffer_after'] as $column) {
            if ($this->db->field_exists($column, 'services')) {
                $this->dbforge->drop_column('services', $column);
            }
        }
    }
}
