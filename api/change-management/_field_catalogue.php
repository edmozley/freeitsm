<?php
/**
 * Field catalogue for the Change form layout — the canonical list of
 * field keys the change editor supports, with their UI labels. Required
 * by api/change-management/get_field_layout.php and save_field_layout.php
 * so both endpoints share one source of truth.
 *
 * To add a new field to the change form:
 *   1. Add the key here with its human label
 *   2. Insert a row into change_field_layout pointing it at a section
 *      (or let the settings UI's "unplaced fields" surface it)
 *   3. Make sure the matching DOM template exists in
 *      change-management/index.php's #changeFieldTemplates pool
 */
if (!defined('FIELD_CATALOGUE')) {
    define('FIELD_CATALOGUE', [
        'title'        => 'Title',
        'change_type'  => 'Change type',
        'status'       => 'Status',
        'priority'     => 'Priority',
        'impact'       => 'Impact',
        'category'     => 'Category',
        'requester'    => 'Requester',
        'assigned_to'  => 'Assigned to',
        'approver'     => 'Approver',
        'cab'          => 'CAB',
        'work_start'   => 'Work start',
        'work_end'     => 'Work end',
        'outage_start' => 'Outage start',
        'outage_end'   => 'Outage end',
        'description'  => 'Description',
        'reason'       => 'Reason for change',
        'risk'         => 'Risk evaluation',
        'testplan'     => 'Test plan',
        'rollback'     => 'Rollback plan',
        'pir'          => 'Post-implementation review',
        'attachments'  => 'Attachments',
    ]);
}
