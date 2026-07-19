<?php
/**
 * FreeITSM REST API v1 — typed component schemas + per-endpoint response bindings
 * for the OpenAPI generator. Derived from the resource serializers and verified
 * against live responses (api/v1/dev/openapi_verify.php). Consumed by lib/openapi.php.
 */
return array (
  'schemas' => 
  array (
    'Analyst' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'email' => 
        array (
          'type' => 'string',
        ),
      ),
    ),
    'Asset' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'company' => 
        array (
          'type' => 'object',
          'description' => 'The company that owns this asset. null = not assigned to one.',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'hostname' => 
        array (
          'type' => 'string',
        ),
        'type' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
            ),
          ),
        ),
        'status' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
            ),
          ),
        ),
        'location' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
            ),
            'path' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'hardware' => 
        array (
          'type' => 'object',
          'properties' => 
          array (
            'manufacturer' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'model' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'service_tag' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'memory' => 
            array (
              'type' => 'integer',
              'nullable' => true,
            ),
            'cpu_name' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'speed' => 
            array (
              'type' => 'integer',
              'nullable' => true,
            ),
            'gpu_name' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'bios_version' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'tpm_version' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'bitlocker_status' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'os' => 
        array (
          'type' => 'object',
          'properties' => 
          array (
            'operating_system' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'feature_release' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'build_number' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'network' => 
        array (
          'type' => 'object',
          'properties' => 
          array (
            'domain' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'logged_in_user' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'lifecycle' => 
        array (
          'type' => 'object',
          'properties' => 
          array (
            'purchase_date' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'purchase_cost' => 
            array (
              'type' => 'number',
              'nullable' => true,
            ),
            'supplier' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'id' => 
                array (
                  'type' => 'integer',
                ),
                'name' => 
                array (
                  'type' => 'string',
                ),
              ),
            ),
            'order_number' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'warranty_expiry' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'assigned_users_count' => 
        array (
          'type' => 'integer',
        ),
        'first_seen' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'last_seen' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'last_boot_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'assigned_users' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            'type' => 'object',
            'nullable' => true,
            'properties' => 
            array (
              'user_id' => 
              array (
                'type' => 'integer',
              ),
              'name' => 
              array (
                'type' => 'string',
                'nullable' => true,
              ),
              'email' => 
              array (
                'type' => 'string',
                'nullable' => true,
              ),
              'assigned_at' => 
              array (
                'type' => 'string',
                'nullable' => true,
              ),
              'expected_return_date' => 
              array (
                'type' => 'string',
                'nullable' => true,
              ),
              'notes' => 
              array (
                'type' => 'string',
                'nullable' => true,
              ),
            ),
          ),
        ),
      ),
    ),
    'AssetAssignedUser' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'user_id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'email' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'assigned_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'expected_return_date' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'notes' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
      ),
    ),
    'AssetAssignment' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'user_id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'email' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'assigned_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'expected_return_date' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'notes' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'assigned_by' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
      ),
    ),
    'AssetAssignmentCreated' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'asset_id' => 
        array (
          'type' => 'integer',
        ),
        'user_id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'expected_return_date' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'notes' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
      ),
    ),
    'AssetAssignmentDeleted' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'asset_id' => 
        array (
          'type' => 'integer',
        ),
        'user_id' => 
        array (
          'type' => 'integer',
        ),
        'unassigned' => 
        array (
          'type' => 'boolean',
        ),
      ),
    ),
    'AssetCustodyEntry' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'action' => 
        array (
          'type' => 'string',
        ),
        'user_id' => 
        array (
          'type' => 'integer',
          'nullable' => true,
        ),
        'user_name' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'expected_return_date' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'notes' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'by' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'AssetDetail' => 
    array (
      'allOf' => 
      array (
        0 => 
        array (
          '$ref' => '#/components/schemas/Asset',
        ),
        1 => 
        array (
          'type' => 'object',
          'properties' => 
          array (
            'assigned_users' => 
            array (
              'type' => 'array',
              'items' => 
              array (
                '$ref' => '#/components/schemas/AssetAssignedUser',
              ),
            ),
            'id' => 
            array (
              'type' => 'integer',
            ),
            'hostname' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'type' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'id' => 
                array (
                  'type' => 'integer',
                ),
                'name' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
              ),
            ),
            'status' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'id' => 
                array (
                  'type' => 'integer',
                ),
                'name' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
              ),
            ),
            'location' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'id' => 
                array (
                  'type' => 'integer',
                ),
                'name' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'path' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
              ),
            ),
            'hardware' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'manufacturer' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'model' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'service_tag' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'memory' => 
                array (
                  'type' => 'integer',
                ),
                'cpu_name' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'speed' => 
                array (
                  'type' => 'integer',
                ),
                'gpu_name' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'bios_version' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'tpm_version' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'bitlocker_status' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
              ),
            ),
            'os' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'operating_system' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'feature_release' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'build_number' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
              ),
            ),
            'network' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'domain' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'logged_in_user' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
              ),
            ),
            'lifecycle' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'purchase_date' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'purchase_cost' => 
                array (
                  'type' => 'number',
                  'nullable' => true,
                ),
                'supplier' => 
                array (
                  'type' => 'object',
                  'nullable' => true,
                  'properties' => 
                  array (
                    'id' => 
                    array (
                      'type' => 'integer',
                    ),
                    'name' => 
                    array (
                      'type' => 'string',
                      'nullable' => true,
                    ),
                  ),
                ),
                'order_number' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'warranty_expiry' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
              ),
            ),
            'assigned_users_count' => 
            array (
              'type' => 'integer',
            ),
            'first_seen' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'last_seen' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'last_boot_at' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
      ),
    ),
    'AssetDevice' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'device_class' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'device_name' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'status' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'manufacturer' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'driver_version' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'driver_date' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
      ),
    ),
    'AssetDisk' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'drive' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'label' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'file_system' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'size_bytes' => 
        array (
          'type' => 'integer',
          'nullable' => true,
        ),
        'free_bytes' => 
        array (
          'type' => 'integer',
          'nullable' => true,
        ),
        'used_percent' => 
        array (
          'type' => 'number',
          'nullable' => true,
        ),
      ),
    ),
    'AssetHistoryEntry' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'field' => 
        array (
          'type' => 'string',
        ),
        'old_value' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'new_value' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'analyst' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'AssetLocation' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'parent_id' => 
        array (
          'type' => 'integer',
          'nullable' => true,
        ),
        'path' => 
        array (
          'type' => 'string',
          'description' => 'Full breadcrumb path, e.g. "UK › London › Office 1".',
        ),
      ),
    ),
    'AssetNetworkAdapter' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'mac_address' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'ip_address' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'subnet_mask' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'gateway' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'dhcp_enabled' => 
        array (
          'type' => 'boolean',
          'nullable' => true,
        ),
      ),
    ),
    'AssetSoftware' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'publisher' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'version' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'install_date' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'system_component' => 
        array (
          'type' => 'boolean',
        ),
        'last_seen' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'CabMember' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'analyst_id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'is_required' => 
        array (
          'type' => 'boolean',
        ),
        'vote' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'enum' => 
          array (
            0 => 'Approve',
            1 => 'Reject',
            2 => 'Abstain',
          ),
        ),
        'vote_comment' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'voted_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'CalendarCategory' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'color' => 
        array (
          'type' => 'string',
        ),
        'description' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'is_active' => 
        array (
          'type' => 'boolean',
        ),
        'event_count' => 
        array (
          'type' => 'integer',
        ),
      ),
    ),
    'CalendarEvent' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'title' => 
        array (
          'type' => 'string',
        ),
        'description' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'category' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/CalendarEventCategory',
            ),
          ),
          'nullable' => true,
        ),
        'start_at' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'Naive server-local datetime (no timezone).',
        ),
        'end_at' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'Naive server-local datetime (no timezone).',
        ),
        'all_day' => 
        array (
          'type' => 'boolean',
        ),
        'location' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'contract_id' => 
        array (
          'type' => 'integer',
          'nullable' => true,
        ),
        'source' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'null = manual; a generator name (e.g. "asset_warranty") = generated and read-only.',
        ),
        'created_by' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/CalendarEventCreatedBy',
            ),
          ),
          'nullable' => true,
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'Naive server-local datetime (no timezone).',
        ),
        'updated_at' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'Naive server-local datetime (no timezone).',
        ),
      ),
    ),
    'CalendarEventCategory' => 
    array (
      'type' => 'object',
      'description' => 'The event\'s calendar category (null if uncategorised).',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'color' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
      ),
    ),
    'CalendarEventCreatedBy' => 
    array (
      'type' => 'object',
      'description' => 'The analyst who created the event (null for the generated-event 0 sentinel).',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
      ),
    ),
    'CalendarEventDeleted' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'deleted' => 
        array (
          'type' => 'boolean',
          'example' => true,
        ),
      ),
    ),
    'Change' => 
    array (
      'type' => 'object',
      'description' => 'A change record (Change Management). Returned by list/create/update; GET by id returns the richer ChangeDetail.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'change_number' =>
        array (
          'type' => 'string',
          'example' => 'CHG-0001',
        ),
        'company' =>
        array (
          'type' => 'object',
          'allOf' =>
          array (
            0 =>
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'title' =>
        array (
          'type' => 'string',
        ),
        'change_type' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'status' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/StatusRef',
            ),
          ),
          'nullable' => true,
        ),
        'priority' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'impact' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'category' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/ChangeCategoryRef',
            ),
          ),
          'nullable' => true,
        ),
        'requester' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'assigned_to' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'approver' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'approval_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'cab' => 
        array (
          'type' => 'object',
          'properties' => 
          array (
            'required' => 
            array (
              'type' => 'boolean',
            ),
            'approval_type' => 
            array (
              'type' => 'string',
              'nullable' => true,
              'example' => 'all',
            ),
          ),
        ),
        'risk' => 
        array (
          'type' => 'object',
          'properties' => 
          array (
            'likelihood' => 
            array (
              'type' => 'integer',
              'nullable' => true,
              'minimum' => 1,
              'maximum' => 5,
            ),
            'impact' => 
            array (
              'type' => 'integer',
              'nullable' => true,
              'minimum' => 1,
              'maximum' => 5,
            ),
            'score' => 
            array (
              'type' => 'integer',
              'nullable' => true,
            ),
            'level' => 
            array (
              'type' => 'string',
              'nullable' => true,
              'enum' => 
              array (
                0 => 'Low',
                1 => 'Medium',
                2 => 'High',
                3 => 'Very High',
                4 => 'Critical',
              ),
            ),
          ),
        ),
        'schedule' => 
        array (
          'type' => 'object',
          'properties' => 
          array (
            'work_start_at' => 
            array (
              'type' => 'string',
              'format' => 'date-time',
              'nullable' => true,
            ),
            'work_end_at' => 
            array (
              'type' => 'string',
              'format' => 'date-time',
              'nullable' => true,
            ),
            'outage_start_at' => 
            array (
              'type' => 'string',
              'format' => 'date-time',
              'nullable' => true,
            ),
            'outage_end_at' => 
            array (
              'type' => 'string',
              'format' => 'date-time',
              'nullable' => true,
            ),
          ),
        ),
        'created_by' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'modified_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'ChangeAttachment' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'file_name' => 
        array (
          'type' => 'string',
        ),
        'file_size' => 
        array (
          'type' => 'integer',
          'nullable' => true,
        ),
        'file_type' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'uploaded_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'ChangeAuditEntry' => 
    array (
      'type' => 'object',
      'description' => 'A per-field audit row on a change (action_type is status_change, field_change, cab_vote or comment).',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'action' => 
        array (
          'type' => 'string',
          'example' => 'field_change',
        ),
        'field' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'old_value' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'new_value' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'analyst' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'ChangeCab' => 
    array (
      'type' => 'object',
      'description' => 'The CAB roster for a change, returned by GET /changes/{id}/cab and POST /changes/{id}/cab (which replaces the roster then returns this same shape).',
      'properties' => 
      array (
        'cab_required' => 
        array (
          'type' => 'boolean',
        ),
        'approval_type' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'example' => 'all',
        ),
        'members' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/CabMember',
          ),
        ),
        'progress' => 
        array (
          'type' => 'object',
          'properties' => 
          array (
            'required_total' => 
            array (
              'type' => 'integer',
            ),
            'required_approved' => 
            array (
              'type' => 'integer',
            ),
            'required_rejected' => 
            array (
              'type' => 'integer',
            ),
          ),
        ),
      ),
    ),
    'ChangeCabVoteResult' => 
    array (
      'type' => 'object',
      'description' => 'Returned by POST /changes/{id}/cab/vote. new_status is set only when the vote triggered an auto-transition (Draft on a required Reject, Approved once the threshold is met).',
      'properties' => 
      array (
        'change_id' => 
        array (
          'type' => 'integer',
        ),
        'vote' => 
        array (
          'type' => 'string',
          'enum' => 
          array (
            0 => 'Approve',
            1 => 'Reject',
            2 => 'Abstain',
          ),
        ),
        'status_changed' => 
        array (
          'type' => 'boolean',
        ),
        'new_status' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'enum' => 
          array (
            0 => 'Draft',
            1 => 'Approved',
          ),
        ),
      ),
    ),
    'ChangeCategoryRef' => 
    array (
      'type' => 'object',
      'nullable' => true,
      'description' => 'The change category — a lookup row (id set) or a legacy free-text value (id null, pre-lookup installs). Null when neither is set.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
          'nullable' => true,
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
      ),
    ),
    'ChangeComment' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'text' => 
        array (
          'type' => 'string',
        ),
        'analyst' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'ChangeCommentCreateResult' => 
    array (
      'type' => 'object',
      'description' => 'Returned by POST /changes/{id}/comments.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'change_id' => 
        array (
          'type' => 'integer',
        ),
        'text' => 
        array (
          'type' => 'string',
        ),
      ),
    ),
    'ChangeCommentDeleteResult' => 
    array (
      'type' => 'object',
      'description' => 'Returned by DELETE /changes/{id}/comments/{comment_id}.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'deleted' => 
        array (
          'type' => 'boolean',
          'example' => true,
        ),
      ),
    ),
    'ChangeDeleteResult' => 
    array (
      'type' => 'object',
      'description' => 'Returned by DELETE /changes/{id}.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'deleted' => 
        array (
          'type' => 'boolean',
          'example' => true,
        ),
      ),
    ),
    'ChangeDetail' => 
    array (
      'type' => 'object',
      'description' => 'The full change detail returned by GET /changes/{id}: every Change field plus bodies, PIR, attachments and linked problems.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'change_number' =>
        array (
          'type' => 'string',
          'example' => 'CHG-0001',
        ),
        'company' =>
        array (
          'type' => 'object',
          'allOf' =>
          array (
            0 =>
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'title' =>
        array (
          'type' => 'string',
        ),
        'change_type' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'status' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/StatusRef',
            ),
          ),
          'nullable' => true,
        ),
        'priority' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'impact' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'category' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/ChangeCategoryRef',
            ),
          ),
          'nullable' => true,
        ),
        'requester' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'assigned_to' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'approver' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'approval_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'cab' => 
        array (
          'type' => 'object',
          'properties' => 
          array (
            'required' => 
            array (
              'type' => 'boolean',
            ),
            'approval_type' => 
            array (
              'type' => 'string',
              'nullable' => true,
              'example' => 'all',
            ),
          ),
        ),
        'risk' => 
        array (
          'type' => 'object',
          'description' => 'Includes evaluation, which is only present on the detail view.',
          'properties' => 
          array (
            'likelihood' => 
            array (
              'type' => 'integer',
              'nullable' => true,
              'minimum' => 1,
              'maximum' => 5,
            ),
            'impact' => 
            array (
              'type' => 'integer',
              'nullable' => true,
              'minimum' => 1,
              'maximum' => 5,
            ),
            'score' => 
            array (
              'type' => 'integer',
              'nullable' => true,
            ),
            'level' => 
            array (
              'type' => 'string',
              'nullable' => true,
              'enum' => 
              array (
                0 => 'Low',
                1 => 'Medium',
                2 => 'High',
                3 => 'Very High',
                4 => 'Critical',
              ),
            ),
            'evaluation' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'schedule' => 
        array (
          'type' => 'object',
          'properties' => 
          array (
            'work_start_at' => 
            array (
              'type' => 'string',
              'format' => 'date-time',
              'nullable' => true,
            ),
            'work_end_at' => 
            array (
              'type' => 'string',
              'format' => 'date-time',
              'nullable' => true,
            ),
            'outage_start_at' => 
            array (
              'type' => 'string',
              'format' => 'date-time',
              'nullable' => true,
            ),
            'outage_end_at' => 
            array (
              'type' => 'string',
              'format' => 'date-time',
              'nullable' => true,
            ),
          ),
        ),
        'created_by' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'modified_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'description' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'reason_for_change' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'test_plan' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'rollback_plan' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'pir' => 
        array (
          '$ref' => '#/components/schemas/ChangePir',
        ),
        'attachments' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/ChangeAttachment',
          ),
        ),
        'linked_problems' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/ChangeLinkedProblem',
          ),
        ),
      ),
    ),
    'ChangeLinkedProblem' => 
    array (
      'type' => 'object',
      'description' => 'A problem this change fixes (read from change_relations).',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'problem_number' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'title' => 
        array (
          'type' => 'string',
        ),
        'relation_type' => 
        array (
          'type' => 'string',
          'example' => 'fixes',
        ),
      ),
    ),
    'ChangePir' => 
    array (
      'type' => 'object',
      'description' => 'Post-implementation review fields.',
      'properties' => 
      array (
        'review' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'was_successful' => 
        array (
          'type' => 'boolean',
          'nullable' => true,
        ),
        'actual_start_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'actual_end_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'lessons_learned' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'follow_up' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
      ),
    ),
    'CmdbClass' => 
    array (
      'type' => 'object',
      'description' => 'A CI class as listed by GET /cmdb/classes (no property definitions — see GET /cmdb/classes/{id}).',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'class_key' => 
        array (
          'type' => 'string',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'description' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'icon' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'Icon key from GET /cmdb-icons, or null if the class has none set.',
        ),
        'is_active' => 
        array (
          'type' => 'boolean',
        ),
        'object_count' => 
        array (
          'type' => 'integer',
          'description' => 'Number of CMDB objects currently using this class.',
        ),
      ),
    ),
    'CmdbClassDetail' => 
    array (
      'type' => 'object',
      'description' => 'A CI class with its full typed property schema (GET /cmdb/classes/{id}).',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'class_key' => 
        array (
          'type' => 'string',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'description' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'icon' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'is_active' => 
        array (
          'type' => 'boolean',
        ),
        'properties' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/CmdbClassProperty',
          ),
        ),
      ),
    ),
    'CmdbClassProperty' => 
    array (
      'type' => 'object',
      'description' => 'A typed property definition on a CI class.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'property_key' => 
        array (
          'type' => 'string',
        ),
        'label' => 
        array (
          'type' => 'string',
        ),
        'type' => 
        array (
          'type' => 'string',
          'enum' => 
          array (
            0 => 'text',
            1 => 'number',
            2 => 'date',
            3 => 'boolean',
            4 => 'dropdown',
            5 => 'object_ref',
          ),
        ),
        'is_required' => 
        array (
          'type' => 'boolean',
        ),
        'target_class' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/CmdbClassPropertyTargetClass',
            ),
          ),
          'nullable' => true,
        ),
        'options' => 
        array (
          'type' => 'array',
          'description' => 'Dropdown options; empty for every property type other than dropdown.',
          'items' => 
          array (
            '$ref' => '#/components/schemas/CmdbClassPropertyOption',
          ),
        ),
      ),
    ),
    'CmdbClassPropertyOption' => 
    array (
      'type' => 'object',
      'description' => 'One dropdown option for a property whose type is dropdown.',
      'properties' => 
      array (
        'value' => 
        array (
          'type' => 'string',
        ),
        'colour' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'Hex colour (e.g. "#22c55e"), or null for the plain-text fallback.',
        ),
      ),
    ),
    'CmdbClassPropertyTargetClass' => 
    array (
      'type' => 'object',
      'nullable' => true,
      'description' => 'Only present when the property\'s type is object_ref; the class the reference must point at.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
      ),
    ),
    'CmdbIcon' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'icon_key' => 
        array (
          'type' => 'string',
        ),
        'label' => 
        array (
          'type' => 'string',
        ),
        'is_active' => 
        array (
          'type' => 'boolean',
        ),
      ),
    ),
    'CmdbImpact' => 
    array (
      'type' => 'object',
      'description' => 'Blast radius for GET /cmdb/objects/{id}/impact: descendants, property references, and incoming relationships.',
      'properties' => 
      array (
        'descendants' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/CmdbImpactDescendant',
          ),
        ),
        'referenced_by_property' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/CmdbImpactPropertyRef',
          ),
        ),
        'incoming_relationships' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/CmdbImpactRelationshipRef',
          ),
        ),
      ),
    ),
    'CmdbImpactDescendant' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'class_name' => 
        array (
          'type' => 'string',
        ),
        'depth' => 
        array (
          'type' => 'integer',
          'description' => 'Hops below the root object (1 = direct child).',
        ),
      ),
    ),
    'CmdbImpactPropertyRef' => 
    array (
      'type' => 'object',
      'description' => 'An object that references the queried object via one of its own object_ref properties.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'class_name' => 
        array (
          'type' => 'string',
        ),
        'property' => 
        array (
          'type' => 'string',
          'description' => 'Label of the referencing property.',
        ),
      ),
    ),
    'CmdbImpactRelationshipRef' => 
    array (
      'type' => 'object',
      'description' => 'An object with an incoming relationship pointing at the queried object (i.e. something that depends on it).',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'class_name' => 
        array (
          'type' => 'string',
        ),
        'relationship' => 
        array (
          'type' => 'string',
          'description' => 'The inverse verb read from the dependent object\'s side (e.g. "is depended on by").',
        ),
      ),
    ),
    'CmdbLinkedTicket' => 
    array (
      'type' => 'object',
      'description' => 'A ticket linked to a CMDB object, scoped to the API key\'s companies.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'ticket_number' => 
        array (
          'type' => 'string',
        ),
        'subject' => 
        array (
          'type' => 'string',
        ),
        'status' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'is_closed' => 
        array (
          'type' => 'boolean',
        ),
        'linked_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'CmdbObject' => 
    array (
      'type' => 'object',
      'description' => 'A CMDB object (CI) as returned by list/create/update. See CmdbObjectDetail for the fully hydrated GET-by-id shape.',
      'properties' =>
      array (
        'id' =>
        array (
          'type' => 'integer',
        ),
        'name' =>
        array (
          'type' => 'string',
        ),
        'company' =>
        array (
          'type' => 'object',
          'description' => 'The company this configuration item belongs to. null = the Default company. A CI belongs to exactly one company: its parent, its relationships and any object_ref properties must all stay within it.',
          'allOf' =>
          array (
            0 =>
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'class' =>
        array (
          '$ref' => '#/components/schemas/CmdbObjectClassRef',
        ),
        'parent_id' => 
        array (
          'type' => 'integer',
          'nullable' => true,
        ),
        'is_planned' => 
        array (
          'type' => 'boolean',
          'description' => 'True for a staged/not-yet-live CI.',
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'updated_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'CmdbObjectClassRef' => 
    array (
      'type' => 'object',
      'description' => 'The object\'s class, embedded on every object representation. Class is immutable after object creation.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'class_key' => 
        array (
          'type' => 'string',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
      ),
    ),
    'CmdbObjectDeleteResult' => 
    array (
      'type' => 'object',
      'description' => 'Acknowledgement for DELETE /cmdb/objects/{id}. The whole descendant subtree was removed too.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'deleted' => 
        array (
          'type' => 'boolean',
          'example' => true,
        ),
        'deleted_descendants' => 
        array (
          'type' => 'integer',
          'description' => 'Count of descendant objects deleted along with this one.',
        ),
      ),
    ),
    'CmdbObjectDetail' => 
    array (
      'type' => 'object',
      'description' => 'A fully hydrated CMDB object (GET /cmdb/objects/{id}): every class property with its typed value, parent, children, relationships in both directions, and the cached AI summary. Neighbours (parent, children, object_ref targets, relationship counterparties) are themselves company-scoped, so one outside the key\'s companies is omitted rather than named.',
      'properties' =>
      array (
        'id' =>
        array (
          'type' => 'integer',
        ),
        'name' =>
        array (
          'type' => 'string',
        ),
        'company' =>
        array (
          'type' => 'object',
          'description' => 'The company this configuration item belongs to. null = the Default company.',
          'allOf' =>
          array (
            0 =>
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'class' => 
        array (
          '$ref' => '#/components/schemas/CmdbObjectClassRef',
        ),
        'parent_id' => 
        array (
          'type' => 'integer',
          'nullable' => true,
        ),
        'is_planned' => 
        array (
          'type' => 'boolean',
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'updated_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'ai_summary' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'Cached AI-generated summary of this CI, or null if none has been generated yet.',
        ),
        'properties' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/CmdbObjectPropertyValue',
          ),
        ),
        'parent' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/CmdbObjectRef',
            ),
          ),
          'nullable' => true,
        ),
        'children' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/CmdbObjectRef',
          ),
        ),
        'relationships' => 
        array (
          '$ref' => '#/components/schemas/CmdbObjectRelationships',
        ),
      ),
    ),
    'CmdbObjectPropertyValue' => 
    array (
      'type' => 'object',
      'description' => 'One typed property value on a hydrated object.',
      'properties' => 
      array (
        'property_key' => 
        array (
          'type' => 'string',
        ),
        'label' => 
        array (
          'type' => 'string',
        ),
        'type' => 
        array (
          'type' => 'string',
          'enum' => 
          array (
            0 => 'text',
            1 => 'number',
            2 => 'date',
            3 => 'boolean',
            4 => 'dropdown',
            5 => 'object_ref',
          ),
        ),
        'is_required' => 
        array (
          'type' => 'boolean',
        ),
        'value' => 
        array (
          'description' => 'Polymorphic value, typed per the property definition (string, number, boolean, date or an object reference); null when unset.',
        ),
        'value_object' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/CmdbObjectRef',
            ),
          ),
          'nullable' => true,
          'description' => 'Only populated when type is object_ref and a value is set — the referenced object\'s id/name/class_name.',
        ),
      ),
    ),
    'CmdbObjectRef' => 
    array (
      'type' => 'object',
      'description' => 'A lightweight reference to another CMDB object (parent, child, or an object_ref property\'s target).',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'class_name' => 
        array (
          'type' => 'string',
        ),
      ),
      'nullable' => true,
    ),
    'CmdbObjectRelationships' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'outgoing' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/CmdbRelationshipEntry',
          ),
        ),
        'incoming' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/CmdbRelationshipEntry',
          ),
        ),
      ),
    ),
    'CmdbRelationshipCreateResult' => 
    array (
      'type' => 'object',
      'description' => 'Acknowledgement for POST /cmdb/objects/{id}/relationships.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
          'description' => 'The new relationship row id.',
        ),
        'from_object_id' => 
        array (
          'type' => 'integer',
        ),
        'to_object_id' => 
        array (
          'type' => 'integer',
        ),
        'verb' => 
        array (
          'type' => 'string',
        ),
      ),
    ),
    'CmdbRelationshipDeleteResult' => 
    array (
      'type' => 'object',
      'description' => 'Acknowledgement for DELETE /cmdb/objects/{id}/relationships/{rel_id}.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'deleted' => 
        array (
          'type' => 'boolean',
          'example' => true,
        ),
      ),
    ),
    'CmdbRelationshipEntry' => 
    array (
      'type' => 'object',
      'description' => 'One relationship edge from an object\'s point of view, in either the outgoing or incoming direction.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
          'description' => 'The relationship row id (usable with DELETE .../relationships/{rel_id}).',
        ),
        'type_id' => 
        array (
          'type' => 'integer',
        ),
        'verb' => 
        array (
          'type' => 'string',
          'description' => 'The forward verb (e.g. "depends on"), regardless of which direction this entry is.',
        ),
        'inverse_verb' => 
        array (
          'type' => 'string',
        ),
        'other_id' => 
        array (
          'type' => 'integer',
        ),
        'other_name' => 
        array (
          'type' => 'string',
        ),
        'other_class_name' => 
        array (
          'type' => 'string',
        ),
      ),
    ),
    'CmdbRelationshipType' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'verb' => 
        array (
          'type' => 'string',
        ),
        'inverse_verb' => 
        array (
          'type' => 'string',
        ),
        'description' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'is_active' => 
        array (
          'type' => 'boolean',
        ),
      ),
    ),
    'CmdbTicketLinkResult' => 
    array (
      'type' => 'object',
      'description' => 'Acknowledgement for POST /cmdb/objects/{id}/tickets.',
      'properties' => 
      array (
        'object_id' => 
        array (
          'type' => 'integer',
        ),
        'ticket_id' => 
        array (
          'type' => 'integer',
        ),
        'linked' => 
        array (
          'type' => 'boolean',
          'example' => true,
        ),
      ),
    ),
    'CmdbTicketUnlinkResult' => 
    array (
      'type' => 'object',
      'description' => 'Acknowledgement for DELETE /cmdb/objects/{id}/tickets/{ticket_id}.',
      'properties' => 
      array (
        'object_id' => 
        array (
          'type' => 'integer',
        ),
        'ticket_id' => 
        array (
          'type' => 'integer',
        ),
        'unlinked' => 
        array (
          'type' => 'boolean',
          'example' => true,
        ),
      ),
    ),
    'ColouredLookupItem' => 
    array (
      'type' => 'object',
      'description' => 'A coloured lookup item with a default flag, shared by change types/priorities/impacts, task priorities and problem priorities.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'colour' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'is_default' => 
        array (
          'type' => 'boolean',
        ),
        'is_active' => 
        array (
          'type' => 'boolean',
        ),
      ),
    ),
    'Company' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'is_default' => 
        array (
          'type' => 'boolean',
        ),
      ),
    ),
    'Contract' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
          'example' => 14,
        ),
        'contract_number' => 
        array (
          'type' => 'string',
          'example' => 'CN-2026-014',
        ),
        'title' => 
        array (
          'type' => 'string',
          'example' => 'Managed print services',
        ),
        'description' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'supplier' => 
        array (
          '$ref' => '#/components/schemas/LookupRef',
        ),
        'owner' => 
        array (
          '$ref' => '#/components/schemas/LookupRef',
        ),
        'status' => 
        array (
          '$ref' => '#/components/schemas/LookupRef',
        ),
        'payment_schedule' => 
        array (
          '$ref' => '#/components/schemas/LookupRef',
        ),
        'dates' => 
        array (
          '$ref' => '#/components/schemas/ContractDates',
        ),
        'value' => 
        array (
          '$ref' => '#/components/schemas/ContractValue',
        ),
        'cost_centre' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'dms_link' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'External document URL.',
        ),
        'governance' => 
        array (
          '$ref' => '#/components/schemas/ContractGovernance',
        ),
        'is_active' => 
        array (
          'type' => 'boolean',
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
      'required' => 
      array (
        0 => 'id',
        1 => 'contract_number',
        2 => 'title',
        3 => 'is_active',
      ),
    ),
    'ContractDates' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'start' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'YYYY-MM-DD.',
          'example' => '2026-08-01',
        ),
        'end' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'YYYY-MM-DD.',
          'example' => '2028-07-31',
        ),
        'notice_date' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'YYYY-MM-DD.',
          'example' => '2028-05-02',
        ),
        'notice_period_days' => 
        array (
          'type' => 'integer',
          'nullable' => true,
          'example' => 90,
        ),
      ),
    ),
    'ContractGovernance' => 
    array (
      'type' => 'object',
      'description' => 'GDPR / data-protection governance fields.',
      'properties' => 
      array (
        'terms_status' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'personal_data_transferred' => 
        array (
          'type' => 'boolean',
          'nullable' => true,
        ),
        'dpia_required' => 
        array (
          'type' => 'boolean',
          'nullable' => true,
        ),
        'dpia_completed_date' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'YYYY-MM-DD.',
          'example' => '2026-06-01',
        ),
        'dpia_dms_link' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
      ),
    ),
    'ContractTerm' => 
    array (
      'type' => 'object',
      'description' => 'One term tab with this contract\'s recorded content for it.',
      'properties' => 
      array (
        'term_tab_id' => 
        array (
          'type' => 'integer',
          'example' => 1,
        ),
        'name' => 
        array (
          'type' => 'string',
          'example' => 'Termination',
        ),
        'content' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'Null where nothing has been recorded for this tab yet.',
        ),
        'updated_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
      'required' => 
      array (
        0 => 'term_tab_id',
        1 => 'name',
      ),
    ),
    'ContractValue' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'amount' => 
        array (
          'type' => 'number',
          'nullable' => true,
          'example' => 24000,
        ),
        'currency' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => '3-letter code.',
          'example' => 'GBP',
        ),
      ),
    ),
    'DeleteAck' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'string',
          'description' => 'Echoes the id/entry_id path segment verbatim (not cast to int).',
        ),
        'deleted' => 
        array (
          'type' => 'boolean',
        ),
      ),
    ),
    'Form' => 
    array (
      'type' => 'object',
      'description' => 'A form with its full field definitions — returned by get/create/update/version-create.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'title' => 
        array (
          'type' => 'string',
        ),
        'description' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'is_active' => 
        array (
          'type' => 'boolean',
        ),
        'version' => 
        array (
          '$ref' => '#/components/schemas/FormVersionInfo',
        ),
        'field_count' => 
        array (
          'type' => 'integer',
        ),
        'submission_count' => 
        array (
          'type' => 'integer',
        ),
        'created_by' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'modified_by' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'modified_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'fields' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/FormField',
          ),
        ),
      ),
    ),
    'FormDeleted' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'deleted' => 
        array (
          'type' => 'boolean',
        ),
        'versions_deleted' => 
        array (
          'type' => 'integer',
          'description' => 'Count of version rows removed — 1 unless ?chain=true wiped the whole chain.',
        ),
      ),
    ),
    'FormField' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'field_type' => 
        array (
          'type' => 'string',
          'description' => 'One of: text, textarea, email, number, checkbox, checkboxes, dropdown, radio.',
        ),
        'label' => 
        array (
          'type' => 'string',
        ),
        'options' => 
        array (
          'nullable' => true,
          'description' => 'Array of choice labels for dropdown / radio / checkboxes fields; null for other field types (loosely typed — falls back to the raw stored string if it is not valid JSON).',
          'type' => 'array',
          'items' => 
          array (
            'type' => 'string',
          ),
        ),
        'is_required' => 
        array (
          'type' => 'boolean',
        ),
        'sort_order' => 
        array (
          'type' => 'integer',
        ),
      ),
    ),
    'FormSubmission' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'form' => 
        array (
          'type' => 'object',
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'title' => 
            array (
              'type' => 'string',
            ),
          ),
        ),
        'submitted_by' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'submitted_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'answers' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/FormSubmissionAnswer',
          ),
        ),
      ),
    ),
    'FormSubmissionAnswer' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'field_id' => 
        array (
          'type' => 'integer',
        ),
        'label' => 
        array (
          'type' => 'string',
        ),
        'field_type' => 
        array (
          'type' => 'string',
        ),
        'value' => 
        array (
          'description' => 'Polymorphic value, typed per the field or property definition.',
        ),
      ),
    ),
    'FormSubmissionDeleted' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'deleted' => 
        array (
          'type' => 'boolean',
        ),
      ),
    ),
    'FormSummary' => 
    array (
      'type' => 'object',
      'description' => 'A form without its field definitions — used by list endpoints (GET /forms, GET /forms/{id}/versions).',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'title' => 
        array (
          'type' => 'string',
        ),
        'description' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'is_active' => 
        array (
          'type' => 'boolean',
        ),
        'version' => 
        array (
          '$ref' => '#/components/schemas/FormVersionInfo',
        ),
        'field_count' => 
        array (
          'type' => 'integer',
        ),
        'submission_count' => 
        array (
          'type' => 'integer',
        ),
        'created_by' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'modified_by' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'modified_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'FormVersionInfo' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'number' => 
        array (
          'type' => 'integer',
        ),
        'parent_form_id' => 
        array (
          'type' => 'integer',
          'nullable' => true,
        ),
        'is_current' => 
        array (
          'type' => 'boolean',
          'description' => 'True when this row is the chain leaf (editable); false for frozen historical versions.',
        ),
      ),
    ),
    'IdNameRef' => 
    array (
      'type' => 'object',
      'nullable' => true,
      'description' => 'Common lookup shape: {id, name}. Null when the ticket has no value set for this field.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
      ),
    ),
    'KnowledgeArticle' =>
    array (
      'type' => 'object',
      'description' => 'The full article as returned by get/create/update/restore — full HTML body instead of a preview.',
      'required' =>
      array (
        0 => 'id',
        1 => 'title',
        2 => 'tags',
        3 => 'author',
        4 => 'owner',
        5 => 'version',
        6 => 'view_count',
        7 => 'next_review_date',
        8 => 'company',
        9 => 'audience',
        10 => 'is_archived',
        11 => 'created_at',
        12 => 'modified_at',
        13 => 'body_html',
        14 => 'has_embedding',
      ),
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'title' => 
        array (
          'type' => 'string',
        ),
        'tags' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            'type' => 'string',
          ),
        ),
        'author' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'owner' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
            ),
          ),
        ),
        'version' => 
        array (
          'type' => 'integer',
        ),
        'view_count' => 
        array (
          'type' => 'integer',
        ),
        'next_review_date' => 
        array (
          'type' => 'string',
          'format' => 'date',
          'nullable' => true,
        ),
        'company' => 
        array (
          'type' => 'object',
          'description' => 'The company that owns this article. null = shared with every company.',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'audience' => 
        array (
          'type' => 'string',
          'description' => 'Who may read the article. internal = analysts only; customer = also signed-in self-service users; public = also anonymous web chat visitors.',
          'enum' => 
          array (
            0 => 'internal',
            1 => 'customer',
            2 => 'public',
          ),
        ),
        'is_archived' => 
        array (
          'type' => 'boolean',
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'modified_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'body_html' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'Raw TinyMCE HTML, stored and returned verbatim.',
        ),
        'has_embedding' => 
        array (
          'type' => 'boolean',
          'description' => 'Whether a search embedding has been generated (requires the module\'s OpenAI key to be configured).',
        ),
        'archived_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
          'description' => 'Only present when is_archived is true.',
        ),
        'archived_by' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'description' => 'Only present when is_archived is true.',
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
            ),
          ),
        ),
      ),
    ),
    'KnowledgeArticleArchiveAck' => 
    array (
      'type' => 'object',
      'description' => 'Acknowledgement for moving an article to the recycle bin.',
      'required' => 
      array (
        0 => 'id',
        1 => 'archived',
      ),
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'archived' => 
        array (
          'type' => 'boolean',
          'example' => true,
        ),
      ),
    ),
    'KnowledgeArticlePurgeAck' => 
    array (
      'type' => 'object',
      'description' => 'Acknowledgement for permanently deleting an archived article.',
      'required' => 
      array (
        0 => 'id',
        1 => 'deleted',
      ),
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'deleted' => 
        array (
          'type' => 'boolean',
          'example' => true,
        ),
      ),
    ),
    'KnowledgeArticleSummary' =>
    array (
      'type' => 'object',
      'description' => 'A knowledge article as returned by the list/search endpoint — a preview instead of the full body.',
      'required' =>
      array (
        0 => 'id',
        1 => 'title',
        2 => 'tags',
        3 => 'author',
        4 => 'owner',
        5 => 'version',
        6 => 'view_count',
        7 => 'next_review_date',
        8 => 'company',
        9 => 'audience',
        10 => 'is_archived',
        11 => 'created_at',
        12 => 'modified_at',
        13 => 'preview',
      ),
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'title' => 
        array (
          'type' => 'string',
        ),
        'tags' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            'type' => 'string',
          ),
        ),
        'author' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'owner' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'version' => 
        array (
          'type' => 'integer',
        ),
        'view_count' => 
        array (
          'type' => 'integer',
        ),
        'next_review_date' => 
        array (
          'type' => 'string',
          'format' => 'date',
          'nullable' => true,
        ),
        'company' => 
        array (
          'type' => 'object',
          'description' => 'The company that owns this article. null = shared with every company.',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'audience' => 
        array (
          'type' => 'string',
          'description' => 'Who may read the article. internal = analysts only; customer = also signed-in self-service users; public = also anonymous web chat visitors.',
          'enum' => 
          array (
            0 => 'internal',
            1 => 'customer',
            2 => 'public',
          ),
        ),
        'is_archived' => 
        array (
          'type' => 'boolean',
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'modified_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'preview' => 
        array (
          'type' => 'string',
          'description' => 'Plain-text excerpt of the body, tags stripped, truncated to 300 characters.',
        ),
        'archived_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
          'description' => 'Only present when is_archived is true.',
        ),
        'archived_by' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'description' => 'Only present when is_archived is true.',
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
            ),
          ),
        ),
      ),
    ),
    'KnowledgeArticleVersion' => 
    array (
      'type' => 'object',
      'description' => 'A version-history entry as returned by the list endpoint (no body).',
      'required' => 
      array (
        0 => 'version',
        1 => 'title',
        2 => 'saved_by',
        3 => 'saved_at',
      ),
      'properties' => 
      array (
        'version' => 
        array (
          'type' => 'integer',
        ),
        'title' => 
        array (
          'type' => 'string',
        ),
        'saved_by' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'saved_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'KnowledgeArticleVersionDetail' => 
    array (
      'type' => 'object',
      'description' => 'A single version snapshot with its full HTML body.',
      'required' => 
      array (
        0 => 'version',
        1 => 'title',
        2 => 'body_html',
        3 => 'saved_by',
        4 => 'saved_at',
      ),
      'properties' => 
      array (
        'version' => 
        array (
          'type' => 'integer',
        ),
        'title' => 
        array (
          'type' => 'string',
        ),
        'body_html' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'saved_by' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
            ),
          ),
        ),
        'saved_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'KnowledgeTag' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'article_count' => 
        array (
          'type' => 'integer',
        ),
      ),
    ),
    'LookupIdNameActive' => 
    array (
      'type' => 'object',
      'description' => 'A simple tenant-overridable lookup item (GET /ticket-types, GET /origins).',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'is_active' => 
        array (
          'type' => 'boolean',
        ),
      ),
    ),
    'LookupRef' => 
    array (
      'type' => 'object',
      'nullable' => true,
      'description' => 'A referenced lookup row rendered as {id, name}; null when unset.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
          'example' => 1,
        ),
        'name' => 
        array (
          'type' => 'string',
          'example' => 'Acme Print Ltd',
          'nullable' => true,
        ),
      ),
      'required' => 
      array (
        0 => 'id',
        1 => 'name',
      ),
    ),
    'LookupWithDescription' => 
    array (
      'type' => 'object',
      'description' => 'A lookup item with an optional description, shared by several small reference lists (departments, asset types/statuses, change categories, contract/payment/supplier lookups).',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'description' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'is_active' => 
        array (
          'type' => 'boolean',
        ),
      ),
    ),
    'MorningCheck' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'description' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'is_active' => 
        array (
          'type' => 'boolean',
        ),
        'sort_order' => 
        array (
          'type' => 'integer',
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'modified_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'MorningCheckBoard' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'date' => 
        array (
          'type' => 'string',
          'description' => 'Bare YYYY-MM-DD — defaults to server-local today.',
        ),
        'checks' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/MorningCheckBoardRow',
          ),
        ),
      ),
    ),
    'MorningCheckBoardResult' => 
    array (
      'type' => 'object',
      'nullable' => true,
      'description' => 'Null when this check has no result recorded for the board date yet.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'status' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'label' => 
            array (
              'type' => 'string',
            ),
            'colour' => 
            array (
              'type' => 'string',
            ),
          ),
        ),
        'is_orphan' => 
        array (
          'type' => 'boolean',
        ),
        'orphan_label' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'notes' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
      ),
    ),
    'MorningCheckBoardRow' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'description' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'sort_order' => 
        array (
          'type' => 'integer',
        ),
        'result' => 
        array (
          '$ref' => '#/components/schemas/MorningCheckBoardResult',
        ),
      ),
    ),
    'MorningCheckDeleted' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'deleted' => 
        array (
          'type' => 'boolean',
        ),
      ),
    ),
    'MorningCheckResult' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'check' => 
        array (
          'type' => 'object',
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
            ),
          ),
        ),
        'date' => 
        array (
          'type' => 'string',
          'description' => 'Bare YYYY-MM-DD (no time component).',
        ),
        'status' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'description' => 'Null when the result has no resolvable status (see is_orphan).',
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'label' => 
            array (
              'type' => 'string',
            ),
            'colour' => 
            array (
              'type' => 'string',
            ),
          ),
        ),
        'is_orphan' => 
        array (
          'type' => 'boolean',
          'description' => 'True when the status this result was saved against has since been deleted.',
        ),
        'orphan_label' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'The original status label snapshot, present only when is_orphan is true.',
        ),
        'notes' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'created_by' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'modified_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'MorningCheckStatus' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'label' => 
        array (
          'type' => 'string',
        ),
        'colour' => 
        array (
          'type' => 'string',
        ),
        'requires_notes' => 
        array (
          'type' => 'boolean',
        ),
        'sort_order' => 
        array (
          'type' => 'integer',
        ),
        'is_active' => 
        array (
          'type' => 'boolean',
        ),
      ),
    ),
    'NamedRef' => 
    array (
      'type' => 'object',
      'nullable' => true,
      'description' => 'A lightweight id/name reference to a lookup row. Null when unset.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
      ),
    ),
    'NetworkBranding' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'header' => 
        array (
          '$ref' => '#/components/schemas/NetworkBrandingSlot',
        ),
        'footer' => 
        array (
          '$ref' => '#/components/schemas/NetworkBrandingSlot',
        ),
      ),
    ),
    'NetworkBrandingSlot' => 
    array (
      'type' => 'object',
      'description' => 'One header/footer band. Each slot is null = inherit the org-wide branding default.',
      'properties' => 
      array (
        'left' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'center' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'right' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
      ),
    ),
    'NetworkConnector' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'from' => 
        array (
          '$ref' => '#/components/schemas/NetworkConnectorEndpoint',
        ),
        'to' => 
        array (
          '$ref' => '#/components/schemas/NetworkConnectorEndpoint',
        ),
        'relationship' => 
        array (
          '$ref' => '#/components/schemas/NetworkRelationshipRef',
        ),
        'label' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'line_style' => 
        array (
          'type' => 'string',
          'enum' => 
          array (
            0 => 'solid',
            1 => 'dashed',
          ),
        ),
      ),
    ),
    'NetworkConnectorDeleteAck' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'deleted' => 
        array (
          'type' => 'boolean',
        ),
      ),
    ),
    'NetworkConnectorEndpoint' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'node_id' => 
        array (
          'type' => 'integer',
        ),
        'object_id' => 
        array (
          'type' => 'integer',
        ),
        'object_name' => 
        array (
          'type' => 'string',
        ),
      ),
    ),
    'NetworkDiagram' => 
    array (
      'description' => 'A fully hydrated diagram version: metadata, print/branding settings, every node and connector, and computed layout metadata.',
      'allOf' => 
      array (
        0 => 
        array (
          '$ref' => '#/components/schemas/NetworkDiagramSummary',
        ),
        1 => 
        array (
          'type' => 'object',
          'properties' => 
          array (
            'paper' => 
            array (
              '$ref' => '#/components/schemas/NetworkPaper',
            ),
            'branding' => 
            array (
              '$ref' => '#/components/schemas/NetworkBranding',
            ),
            'nodes' => 
            array (
              'type' => 'array',
              'items' => 
              array (
                '$ref' => '#/components/schemas/NetworkNode',
              ),
            ),
            'connectors' => 
            array (
              'type' => 'array',
              'items' => 
              array (
                '$ref' => '#/components/schemas/NetworkConnector',
              ),
            ),
            'layout' => 
            array (
              '$ref' => '#/components/schemas/NetworkLayout',
            ),
            'id' => 
            array (
              'type' => 'integer',
            ),
            'title' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'description' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'version_label' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'parent_diagram_id' => 
            array (
              'type' => 'integer',
              'nullable' => true,
            ),
            'is_current' => 
            array (
              'type' => 'boolean',
            ),
            'created_by' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'id' => 
                array (
                  'type' => 'integer',
                ),
                'name' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
              ),
            ),
            'created_at' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'updated_at' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'node_count' => 
            array (
              'type' => 'integer',
            ),
            'connector_count' => 
            array (
              'type' => 'integer',
            ),
          ),
        ),
      ),
    ),
    'NetworkDiagramAuthor' => 
    array (
      'type' => 'object',
      'nullable' => true,
      'description' => 'The analyst who created this diagram version (null if the creating key/analyst no longer exists).',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
      ),
    ),
    'NetworkDiagramDeleteAck' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'deleted' => 
        array (
          'type' => 'boolean',
        ),
        'versions_deleted' => 
        array (
          'type' => 'integer',
          'description' => '1 unless ?chain=true, in which case every version in the chain.',
        ),
      ),
    ),
    'NetworkDiagramSummary' => 
    array (
      'type' => 'object',
      'description' => 'A diagram version without its contents (list / version-chain rows).',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'title' => 
        array (
          'type' => 'string',
        ),
        'description' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'version_label' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'parent_diagram_id' => 
        array (
          'type' => 'integer',
          'nullable' => true,
          'description' => 'Null for the root version of the chain.',
        ),
        'is_current' => 
        array (
          'type' => 'boolean',
          'description' => 'true when this version has no children — the only editable version.',
        ),
        'created_by' => 
        array (
          '$ref' => '#/components/schemas/NetworkDiagramAuthor',
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'updated_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'node_count' => 
        array (
          'type' => 'integer',
        ),
        'connector_count' => 
        array (
          'type' => 'integer',
        ),
        'paper' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'size' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'orientation' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'branding' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'header' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'left' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'center' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'right' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
              ),
            ),
            'footer' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'left' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'center' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'right' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
              ),
            ),
          ),
        ),
        'nodes' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            'type' => 'object',
            'nullable' => true,
            'properties' => 
            array (
              'id' => 
              array (
                'type' => 'integer',
              ),
              'x' => 
              array (
                'type' => 'integer',
              ),
              'y' => 
              array (
                'type' => 'integer',
              ),
              'size' => 
              array (
                'type' => 'string',
                'nullable' => true,
              ),
              'size_px' => 
              array (
                'type' => 'integer',
              ),
              'icon' => 
              array (
                'type' => 'string',
                'nullable' => true,
              ),
              'icon_override' => 
              array (
                'type' => 'string',
                'nullable' => true,
              ),
              'object' => 
              array (
                'type' => 'object',
                'nullable' => true,
                'properties' => 
                array (
                  'id' => 
                  array (
                    'type' => 'integer',
                  ),
                  'name' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                  'is_planned' => 
                  array (
                    'type' => 'boolean',
                  ),
                  'class' => 
                  array (
                    'type' => 'object',
                    'nullable' => true,
                    'properties' => 
                    array (
                      'id' => 
                      array (
                        'type' => 'integer',
                      ),
                      'name' => 
                      array (
                        'type' => 'string',
                        'nullable' => true,
                      ),
                      'icon' => 
                      array (
                        'type' => 'string',
                        'nullable' => true,
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
        'connectors' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            'type' => 'object',
            'nullable' => true,
            'properties' => 
            array (
              'id' => 
              array (
                'type' => 'integer',
              ),
              'from' => 
              array (
                'type' => 'object',
                'nullable' => true,
                'properties' => 
                array (
                  'node_id' => 
                  array (
                    'type' => 'integer',
                  ),
                  'object_id' => 
                  array (
                    'type' => 'integer',
                  ),
                  'object_name' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
              ),
              'to' => 
              array (
                'type' => 'object',
                'nullable' => true,
                'properties' => 
                array (
                  'node_id' => 
                  array (
                    'type' => 'integer',
                  ),
                  'object_id' => 
                  array (
                    'type' => 'integer',
                  ),
                  'object_name' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
              ),
              'relationship' => 
              array (
                'type' => 'object',
                'nullable' => true,
                'properties' => 
                array (
                  'id' => 
                  array (
                    'type' => 'integer',
                  ),
                  'verb' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                  'inverse_verb' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
              ),
              'label' => 
              array (
                'type' => 'string',
                'nullable' => true,
              ),
              'line_style' => 
              array (
                'type' => 'string',
                'nullable' => true,
              ),
            ),
          ),
        ),
        'layout' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'bounds' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'min_x' => 
                array (
                  'type' => 'integer',
                ),
                'min_y' => 
                array (
                  'type' => 'integer',
                ),
                'max_x' => 
                array (
                  'type' => 'integer',
                ),
                'max_y' => 
                array (
                  'type' => 'integer',
                ),
              ),
            ),
            'width' => 
            array (
              'type' => 'integer',
            ),
            'height' => 
            array (
              'type' => 'integer',
            ),
            'node_sizes_px' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'small' => 
                array (
                  'type' => 'integer',
                ),
                'medium' => 
                array (
                  'type' => 'integer',
                ),
                'large' => 
                array (
                  'type' => 'integer',
                ),
              ),
            ),
          ),
        ),
      ),
    ),
    'NetworkLayout' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'bounds' => 
        array (
          '$ref' => '#/components/schemas/NetworkLayoutBounds',
        ),
        'width' => 
        array (
          'type' => 'integer',
          'description' => '0 when the diagram has no nodes.',
        ),
        'height' => 
        array (
          'type' => 'integer',
          'description' => '0 when the diagram has no nodes.',
        ),
        'node_sizes_px' => 
        array (
          '$ref' => '#/components/schemas/NetworkNodeSizesPx',
        ),
      ),
    ),
    'NetworkLayoutBounds' => 
    array (
      'type' => 'object',
      'nullable' => true,
      'description' => 'The canvas bounding box in raw x/y units (top-left origin per node, expanded by each node\'s pixel size). Null when the diagram has no nodes.',
      'properties' => 
      array (
        'min_x' => 
        array (
          'type' => 'integer',
        ),
        'min_y' => 
        array (
          'type' => 'integer',
        ),
        'max_x' => 
        array (
          'type' => 'integer',
        ),
        'max_y' => 
        array (
          'type' => 'integer',
        ),
      ),
    ),
    'NetworkNode' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'x' => 
        array (
          'type' => 'integer',
        ),
        'y' => 
        array (
          'type' => 'integer',
        ),
        'size' => 
        array (
          'type' => 'string',
          'enum' => 
          array (
            0 => 'small',
            1 => 'medium',
            2 => 'large',
          ),
        ),
        'size_px' => 
        array (
          'type' => 'integer',
          'description' => 'Pixel footprint for this size class (small=40, medium=56, large=80).',
        ),
        'icon' => 
        array (
          'type' => 'string',
          'description' => 'What actually renders: icon_override, else the class\'s icon, else \'box\'.',
        ),
        'icon_override' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'object' => 
        array (
          '$ref' => '#/components/schemas/NetworkNodeObject',
        ),
      ),
    ),
    'NetworkNodeClass' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'icon' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'The class\'s default icon key (null if the class has none set).',
        ),
      ),
    ),
    'NetworkNodeDeleteAck' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'deleted' => 
        array (
          'type' => 'boolean',
        ),
        'connectors_deleted' => 
        array (
          'type' => 'integer',
          'description' => 'How many connectors touching this node were removed with it.',
        ),
      ),
    ),
    'NetworkNodeObject' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'is_planned' => 
        array (
          'type' => 'boolean',
        ),
        'class' => 
        array (
          '$ref' => '#/components/schemas/NetworkNodeClass',
        ),
        'properties' => 
        array (
          'type' => 'array',
          'description' => 'Only present when the diagram was fetched with ?include_properties=true.',
          'items' => 
          array (
            '$ref' => '#/components/schemas/NetworkObjectProperty',
          ),
        ),
      ),
    ),
    'NetworkNodeSizesPx' => 
    array (
      'type' => 'object',
      'description' => 'Pixel footprint for each node size class.',
      'properties' => 
      array (
        'small' => 
        array (
          'type' => 'integer',
          'example' => 40,
        ),
        'medium' => 
        array (
          'type' => 'integer',
          'example' => 56,
        ),
        'large' => 
        array (
          'type' => 'integer',
          'example' => 80,
        ),
      ),
    ),
    'NetworkObjectProperty' => 
    array (
      'type' => 'object',
      'description' => 'One typed CI property value (only present when a node was fetched with ?include_properties=true).',
      'properties' => 
      array (
        'property_key' => 
        array (
          'type' => 'string',
        ),
        'label' => 
        array (
          'type' => 'string',
        ),
        'type' => 
        array (
          'type' => 'string',
          'enum' => 
          array (
            0 => 'text',
            1 => 'dropdown',
            2 => 'number',
            3 => 'date',
            4 => 'boolean',
            5 => 'object_ref',
          ),
        ),
        'value' => 
        array (
          'description' => 'Polymorphic value, typed per the field or property definition.',
        ),
      ),
    ),
    'NetworkPaper' => 
    array (
      'type' => 'object',
      'description' => 'Print layout. Both fields null = no paper overlay in the editor.',
      'properties' => 
      array (
        'size' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'enum' => 
          array (
            0 => 'A4',
            1 => 'A3',
            2 => 'A2',
            3 => 'Letter',
            4 => 'Tabloid',
            5 => NULL,
          ),
        ),
        'orientation' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'enum' => 
          array (
            0 => 'portrait',
            1 => 'landscape',
            2 => NULL,
          ),
        ),
      ),
    ),
    'NetworkRelationshipRef' => 
    array (
      'type' => 'object',
      'nullable' => true,
      'description' => 'The CMDB relationship this connector represents, if any.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'verb' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'Null if the underlying CMDB relationship was since deleted.',
        ),
        'inverse_verb' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
      ),
    ),
    'NetworkSuggestion' => 
    array (
      'type' => 'object',
      'description' => 'One CMDB neighbour of an on-diagram object that isn\'t drawn yet.',
      'properties' => 
      array (
        'object' => 
        array (
          '$ref' => '#/components/schemas/NetworkSuggestionObject',
        ),
        'via' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/NetworkSuggestionVia',
          ),
        ),
      ),
    ),
    'NetworkSuggestionObject' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'is_planned' => 
        array (
          'type' => 'boolean',
        ),
        'class' => 
        array (
          '$ref' => '#/components/schemas/NetworkNodeClass',
        ),
      ),
    ),
    'NetworkSuggestionVia' => 
    array (
      'type' => 'object',
      'description' => 'One path linking the suggested object back to something already on the diagram.',
      'properties' => 
      array (
        'kind' => 
        array (
          'type' => 'string',
          'enum' => 
          array (
            0 => 'relationship',
            1 => 'property',
          ),
          'description' => '\'relationship\' = a CMDB object relationship (either direction); \'property\' = an object_ref property link (either direction).',
        ),
        'from_object_id' => 
        array (
          'type' => 'integer',
          'description' => 'The on-diagram object this path originates from.',
        ),
        'label' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'The relationship verb (or its inverse) or the property label.',
        ),
        'relationship_id' => 
        array (
          'type' => 'integer',
          'nullable' => true,
          'description' => 'Only set for kind=\'relationship\'.',
        ),
      ),
    ),
    'Problem' => 
    array (
      'type' => 'object',
      'description' => 'A problem record (Problem Management). Returned by list/create/update; GET by id returns the richer ProblemDetail.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'problem_number' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'example' => 'PRB-00001',
        ),
        'title' => 
        array (
          'type' => 'string',
        ),
        'description' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'status' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/StatusRef',
            ),
          ),
          'nullable' => true,
        ),
        'priority' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'assigned_analyst' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'is_known_error' => 
        array (
          'type' => 'boolean',
        ),
        'root_cause' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'workaround' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'company' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'created_by' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'linked_tickets_count' => 
        array (
          'type' => 'integer',
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'updated_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'closed_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'ProblemAuditEntry' => 
    array (
      'type' => 'object',
      'description' => 'A per-field audit row on a problem.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'action' => 
        array (
          'type' => 'string',
          'example' => 'modified',
        ),
        'field' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'old_value' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'new_value' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'analyst' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'ProblemChangeLinkResult' => 
    array (
      'type' => 'object',
      'description' => 'Returned by POST /problems/{id}/changes.',
      'properties' => 
      array (
        'problem_id' => 
        array (
          'type' => 'integer',
        ),
        'change_id' => 
        array (
          'type' => 'integer',
        ),
        'title' => 
        array (
          'type' => 'string',
        ),
        'linked' => 
        array (
          'type' => 'boolean',
          'example' => true,
        ),
      ),
    ),
    'ProblemChangeUnlinkResult' => 
    array (
      'type' => 'object',
      'description' => 'Returned by DELETE /problems/{id}/changes/{change_id}.',
      'properties' => 
      array (
        'problem_id' => 
        array (
          'type' => 'integer',
        ),
        'change_id' => 
        array (
          'type' => 'integer',
        ),
        'unlinked' => 
        array (
          'type' => 'boolean',
          'example' => true,
        ),
      ),
    ),
    'ProblemDeleteResult' => 
    array (
      'type' => 'object',
      'description' => 'Returned by DELETE /problems/{id}.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'deleted' => 
        array (
          'type' => 'boolean',
          'example' => true,
        ),
      ),
    ),
    'ProblemDetail' => 
    array (
      'type' => 'object',
      'description' => 'The full problem detail returned by GET /problems/{id}: every Problem field plus linked incidents and changes.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'problem_number' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'example' => 'PRB-00001',
        ),
        'title' => 
        array (
          'type' => 'string',
        ),
        'description' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'status' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/StatusRef',
            ),
          ),
          'nullable' => true,
        ),
        'priority' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'assigned_analyst' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'is_known_error' => 
        array (
          'type' => 'boolean',
        ),
        'root_cause' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'workaround' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'company' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'created_by' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'linked_tickets_count' => 
        array (
          'type' => 'integer',
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'updated_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'closed_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'linked_tickets' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/ProblemLinkedTicket',
          ),
        ),
        'linked_changes' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/ProblemLinkedChange',
          ),
        ),
      ),
    ),
    'ProblemLinkedChange' => 
    array (
      'type' => 'object',
      'description' => 'A change linked to a problem (via change_relations, related_type=problem).',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'title' => 
        array (
          'type' => 'string',
        ),
        'status' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'relation_type' => 
        array (
          'type' => 'string',
          'example' => 'fixes',
        ),
      ),
    ),
    'ProblemLinkedTicket' => 
    array (
      'type' => 'object',
      'description' => 'An incident (ticket) linked to a problem.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'ticket_number' => 
        array (
          'type' => 'string',
        ),
        'subject' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'status' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'linked_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'ProblemNote' => 
    array (
      'type' => 'object',
      'description' => 'An append-only journal entry on a problem.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'note' => 
        array (
          'type' => 'string',
        ),
        'analyst' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/NamedRef',
            ),
          ),
          'nullable' => true,
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'ProblemNoteCreated' => 
    array (
      'type' => 'object',
      'description' => 'Returned by POST /problems/{id}/notes.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'problem_id' => 
        array (
          'type' => 'integer',
        ),
        'note' => 
        array (
          'type' => 'string',
        ),
      ),
    ),
    'ProblemTicketLinkResult' => 
    array (
      'type' => 'object',
      'description' => 'Returned by POST /problems/{id}/tickets.',
      'properties' => 
      array (
        'problem_id' => 
        array (
          'type' => 'integer',
        ),
        'ticket_id' => 
        array (
          'type' => 'integer',
        ),
        'ticket_number' => 
        array (
          'type' => 'string',
        ),
        'linked' => 
        array (
          'type' => 'boolean',
          'example' => true,
        ),
      ),
    ),
    'ProblemTicketUnlinkResult' => 
    array (
      'type' => 'object',
      'description' => 'Returned by DELETE /problems/{id}/tickets/{ticket_id}.',
      'properties' => 
      array (
        'problem_id' => 
        array (
          'type' => 'integer',
        ),
        'ticket_id' => 
        array (
          'type' => 'integer',
        ),
        'unlinked' => 
        array (
          'type' => 'boolean',
          'example' => true,
        ),
      ),
    ),
    'Requester' =>
    array (
      'type' => 'object',
      'properties' =>
      array (
        'id' =>
        array (
          'type' => 'integer',
        ),
        'email' =>
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'Null when the requester has no mailbox — staff who sign in through a directory are often never given one. Use `username` or `display_name` to identify them.',
        ),
        'username' =>
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'Directory sign-in name. Null for locally registered requesters.',
        ),
        'display_name' =>
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'preferred_name' =>
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'tenant_id' =>
        array (
          'type' => 'integer',
          'nullable' => true,
          'description' => 'Company this requester belongs to. null = not known; tickets they raise in the self-service portal go to triage.',
        ),
        'created_at' =>
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'tickets' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'total' => 
            array (
              'type' => 'integer',
            ),
            'open' => 
            array (
              'type' => 'integer',
            ),
          ),
        ),
      ),
    ),
    'RequesterDetail' => 
    array (
      'description' => 'GET /users/{id} — Requester plus ticket counts scoped to the key\'s companies.',
      'allOf' => 
      array (
        0 => 
        array (
          '$ref' => '#/components/schemas/Requester',
        ),
        1 => 
        array (
          'type' => 'object',
          'properties' => 
          array (
            'tickets' => 
            array (
              '$ref' => '#/components/schemas/RequesterTicketCounts',
            ),
            'id' => 
            array (
              'type' => 'integer',
            ),
            'email' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'display_name' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'preferred_name' =>
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'tenant_id' =>
            array (
              'type' => 'integer',
              'nullable' => true,
              'description' => 'Company this requester belongs to. null = not known; tickets they raise in the self-service portal go to triage.',
            ),
            'created_at' =>
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
      ),
    ),
    'RequesterTicketCounts' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'total' => 
        array (
          'type' => 'integer',
        ),
        'open' => 
        array (
          'type' => 'integer',
        ),
      ),
    ),
    'ServiceImpactLevel' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'colour' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'is_default' => 
        array (
          'type' => 'boolean',
        ),
        'severity_order' => 
        array (
          'type' => 'integer',
          'description' => 'Worst-first; 1 = most severe (e.g. Major Outage).',
        ),
        'is_active' => 
        array (
          'type' => 'boolean',
        ),
      ),
    ),
    'ServiceIncidentStatus' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'is_resolved' => 
        array (
          'type' => 'boolean',
        ),
        'colour' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'is_default' => 
        array (
          'type' => 'boolean',
        ),
        'is_active' => 
        array (
          'type' => 'boolean',
        ),
      ),
    ),
    'SlaCalendarHoliday' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'holiday_date' => 
        array (
          'type' => 'string',
          'format' => 'date',
        ),
      ),
    ),
    'SlaCalendarHour' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'weekday' => 
        array (
          'type' => 'integer',
        ),
        'start_time' => 
        array (
          'type' => 'string',
        ),
        'end_time' => 
        array (
          'type' => 'string',
        ),
      ),
    ),
    'SlaCalendarRef' => 
    array (
      'type' => 'object',
      'nullable' => true,
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'timezone' => 
        array (
          'type' => 'string',
        ),
        'hours' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/SlaCalendarHour',
          ),
        ),
        'holidays' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/SlaCalendarHoliday',
          ),
        ),
      ),
    ),
    'SlaPriorityRef' => 
    array (
      'type' => 'object',
      'nullable' => true,
      'description' => 'Raw ticket_priorities row (not passed through an API serializer).',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'colour' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'sla_response_minutes' => 
        array (
          'type' => 'integer',
          'nullable' => true,
        ),
        'sla_resolution_minutes' => 
        array (
          'type' => 'integer',
          'nullable' => true,
        ),
        'sla_calendar_id' => 
        array (
          'type' => 'integer',
          'nullable' => true,
        ),
      ),
    ),
    'SlaWindow' => 
    array (
      'type' => 'object',
      'nullable' => true,
      'description' => 'Response or resolution SLA computation for one clock; null if the priority has no target for that clock.',
      'properties' => 
      array (
        'target_minutes' => 
        array (
          'type' => 'integer',
        ),
        'elapsed_minutes' => 
        array (
          'type' => 'integer',
        ),
        'remaining_minutes' => 
        array (
          'type' => 'integer',
        ),
        'percent' => 
        array (
          'type' => 'number',
        ),
        'breached' => 
        array (
          'type' => 'boolean',
        ),
        'achieved_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'achieved_minutes' => 
        array (
          'type' => 'integer',
          'nullable' => true,
        ),
      ),
    ),
    'SoftwareApp' => 
    array (
      'type' => 'object',
      'description' => 'An inventory application (list row) with compliance-relevant counts.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'publisher' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'install_count' => 
        array (
          'type' => 'integer',
          'description' => 'Distinct machines with this app installed.',
        ),
        'system_component' => 
        array (
          'type' => 'boolean',
        ),
        'licence_count' => 
        array (
          'type' => 'integer',
        ),
        'first_detected' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'SoftwareAppCompliance' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'installs' => 
        array (
          'type' => 'integer',
          'description' => 'Distinct non-system-component hosts with this app installed.',
        ),
        'licensed_seats' => 
        array (
          'type' => 'integer',
          'description' => 'Sum of quantity across Active licences.',
        ),
        'unmetered_licences' => 
        array (
          'type' => 'boolean',
          'description' => 'true if an Active licence exists with no seat count (e.g. a site licence).',
        ),
        'seats_available' => 
        array (
          'type' => 'integer',
          'nullable' => true,
          'description' => 'licensed_seats - installs; null when unmetered_licences is true.',
        ),
      ),
    ),
    'SoftwareAppComplianceLicence' => 
    array (
      'type' => 'object',
      'description' => 'A licence row summarised inside an app detail response.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'licence_type' => 
        array (
          'type' => 'string',
        ),
        'quantity' => 
        array (
          'type' => 'integer',
          'nullable' => true,
        ),
        'renewal_date' => 
        array (
          'type' => 'string',
          'format' => 'date',
          'nullable' => true,
        ),
        'status' => 
        array (
          'type' => 'string',
        ),
      ),
    ),
    'SoftwareAppDetail' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'publisher' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'first_detected' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'compliance' => 
        array (
          '$ref' => '#/components/schemas/SoftwareAppCompliance',
        ),
        'licences' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/SoftwareAppComplianceLicence',
          ),
        ),
      ),
    ),
    'SoftwareLicence' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'app' => 
        array (
          '$ref' => '#/components/schemas/SoftwareLicenceApp',
        ),
        'licence_type' => 
        array (
          'type' => 'string',
        ),
        'licence_key' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'quantity' => 
        array (
          'type' => 'integer',
          'nullable' => true,
          'description' => 'Seat count; null for unmetered (e.g. site) licences.',
        ),
        'app_installs' => 
        array (
          'type' => 'integer',
          'description' => 'Distinct non-system-component machines running the app.',
        ),
        'renewal_date' => 
        array (
          'type' => 'string',
          'format' => 'date',
          'nullable' => true,
        ),
        'renewal_status' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'enum' => 
          array (
            0 => 'ok',
            1 => 'due_soon',
            2 => 'overdue',
            3 => NULL,
          ),
          'description' => 'Computed from renewal_date vs today and notice_period_days; null if no renewal_date.',
        ),
        'notice_period_days' => 
        array (
          'type' => 'integer',
          'nullable' => true,
          'description' => 'Defaults to 30 (applied at read time) when null.',
        ),
        'portal_url' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'cost' => 
        array (
          'type' => 'number',
          'nullable' => true,
        ),
        'currency' => 
        array (
          'type' => 'string',
          'example' => 'GBP',
        ),
        'purchase_date' => 
        array (
          'type' => 'string',
          'format' => 'date',
          'nullable' => true,
        ),
        'vendor_contact' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'notes' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'status' => 
        array (
          'type' => 'string',
          'example' => 'Active',
        ),
        'created_by' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/SoftwareLicenceCreatedBy',
            ),
          ),
          'nullable' => true,
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'updated_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'SoftwareLicenceApp' => 
    array (
      'type' => 'object',
      'description' => 'The application a licence belongs to.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'publisher' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
      ),
    ),
    'SoftwareLicenceCreatedBy' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
      ),
    ),
    'SoftwareLicenceDeleted' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'deleted' => 
        array (
          'type' => 'boolean',
          'example' => true,
        ),
      ),
    ),
    'SoftwareMachine' => 
    array (
      'type' => 'object',
      'description' => 'A machine an application is installed on.',
      'properties' => 
      array (
        'asset_id' => 
        array (
          'type' => 'integer',
          'description' => 'Joins to /assets.',
        ),
        'hostname' => 
        array (
          'type' => 'string',
        ),
        'version' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'install_date' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'Agent-reported install date, free-form.',
        ),
        'system_component' => 
        array (
          'type' => 'boolean',
        ),
        'last_seen' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'StatusImpactLevel' => 
    array (
      'type' => 'object',
      'description' => 'A reference impact level; severity_order drives worst-impact ordering (1 = worst).',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'colour' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'is_default' => 
        array (
          'type' => 'boolean',
        ),
        'severity_order' => 
        array (
          'type' => 'integer',
        ),
        'is_active' => 
        array (
          'type' => 'boolean',
        ),
      ),
    ),
    'StatusIncident' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'title' => 
        array (
          'type' => 'string',
        ),
        'status' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/StatusIncidentStatusRef',
            ),
          ),
          'nullable' => true,
        ),
        'comment' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'services' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/StatusIncidentService',
          ),
        ),
        'created_by' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/StatusIncidentCreatedBy',
            ),
          ),
          'nullable' => true,
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'updated_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'resolved_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'StatusIncidentCreatedBy' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
      ),
    ),
    'StatusIncidentDeleted' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'deleted' => 
        array (
          'type' => 'boolean',
          'example' => true,
        ),
      ),
    ),
    'StatusIncidentService' => 
    array (
      'type' => 'object',
      'description' => 'One affected service on an incident, with its impact level.',
      'properties' => 
      array (
        'service_id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'impact' => 
        array (
          'type' => 'object',
          'allOf' => 
          array (
            0 => 
            array (
              '$ref' => '#/components/schemas/StatusIncidentServiceImpact',
            ),
          ),
          'nullable' => true,
        ),
      ),
    ),
    'StatusIncidentServiceImpact' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'colour' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'severity_order' => 
        array (
          'type' => 'integer',
        ),
      ),
    ),
    'StatusIncidentStatus' => 
    array (
      'type' => 'object',
      'description' => 'A reference incident-lifecycle status.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'is_resolved' => 
        array (
          'type' => 'boolean',
        ),
        'colour' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'is_default' => 
        array (
          'type' => 'boolean',
        ),
        'is_active' => 
        array (
          'type' => 'boolean',
        ),
      ),
    ),
    'StatusIncidentStatusRef' => 
    array (
      'type' => 'object',
      'description' => 'The incident\'s lifecycle status.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'is_resolved' => 
        array (
          'type' => 'boolean',
        ),
        'colour' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
      ),
    ),
    'StatusLookupItem' => 
    array (
      'type' => 'object',
      'description' => 'A closeable, coloured status lookup, shared by change statuses and problem statuses.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'is_closed' => 
        array (
          'type' => 'boolean',
        ),
        'is_default' => 
        array (
          'type' => 'boolean',
        ),
        'colour' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'is_active' => 
        array (
          'type' => 'boolean',
        ),
      ),
    ),
    'StatusRef' => 
    array (
      'type' => 'object',
      'nullable' => true,
      'description' => 'An id/name status reference with its closed flag. Null when unset.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'is_closed' => 
        array (
          'type' => 'boolean',
        ),
      ),
    ),
    'StatusService' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'description' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'is_active' => 
        array (
          'type' => 'boolean',
        ),
        'display_order' => 
        array (
          'type' => 'integer',
        ),
        'current_status' => 
        array (
          '$ref' => '#/components/schemas/StatusServiceCurrentStatus',
        ),
        'open_incidents' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            'type' => 'object',
            'nullable' => true,
            'properties' => 
            array (
              'id' => 
              array (
                'type' => 'integer',
              ),
              'title' => 
              array (
                'type' => 'string',
                'nullable' => true,
              ),
              'status' => 
              array (
                'type' => 'object',
                'nullable' => true,
                'properties' => 
                array (
                  'id' => 
                  array (
                    'type' => 'integer',
                  ),
                  'name' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                  'is_resolved' => 
                  array (
                    'type' => 'boolean',
                  ),
                  'colour' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
              ),
              'comment' => 
              array (
                'type' => 'string',
                'nullable' => true,
              ),
              'services' => 
              array (
                'type' => 'array',
                'items' => 
                array (
                  'type' => 'object',
                  'nullable' => true,
                  'properties' => 
                  array (
                    'service_id' => 
                    array (
                      'type' => 'integer',
                    ),
                    'name' => 
                    array (
                      'type' => 'string',
                      'nullable' => true,
                    ),
                    'impact' => 
                    array (
                      'type' => 'object',
                      'nullable' => true,
                      'properties' => 
                      array (
                        'id' => 
                        array (
                          'type' => 'integer',
                        ),
                        'name' => 
                        array (
                          'type' => 'string',
                          'nullable' => true,
                        ),
                        'colour' => 
                        array (
                          'type' => 'string',
                          'nullable' => true,
                        ),
                        'severity_order' => 
                        array (
                          'type' => 'integer',
                        ),
                      ),
                    ),
                  ),
                ),
              ),
              'created_by' => 
              array (
                'type' => 'object',
                'nullable' => true,
                'properties' => 
                array (
                  'id' => 
                  array (
                    'type' => 'integer',
                  ),
                  'name' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
              ),
              'created_at' => 
              array (
                'type' => 'string',
                'nullable' => true,
              ),
              'updated_at' => 
              array (
                'type' => 'string',
                'nullable' => true,
              ),
              'resolved_at' => 
              array (
                'type' => 'string',
                'nullable' => true,
              ),
            ),
          ),
        ),
      ),
    ),
    'StatusServiceCurrentStatus' => 
    array (
      'type' => 'object',
      'description' => 'Derived worst-open-impact status (Operational when no open incident affects the service).',
      'properties' => 
      array (
        'name' => 
        array (
          'type' => 'string',
          'example' => 'Operational',
        ),
        'colour' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'severity_order' => 
        array (
          'type' => 'integer',
          'nullable' => true,
        ),
      ),
    ),
    'StatusServiceDeleted' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'deleted' => 
        array (
          'type' => 'boolean',
          'example' => true,
        ),
      ),
    ),
    'StatusServiceDetail' => 
    array (
      'allOf' => 
      array (
        0 => 
        array (
          '$ref' => '#/components/schemas/StatusService',
        ),
        1 => 
        array (
          'type' => 'object',
          'properties' => 
          array (
            'open_incidents' => 
            array (
              'type' => 'array',
              'description' => 'Open incidents currently touching this service.',
              'items' => 
              array (
                '$ref' => '#/components/schemas/StatusIncident',
              ),
            ),
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'description' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'is_active' => 
            array (
              'type' => 'boolean',
            ),
            'display_order' => 
            array (
              'type' => 'integer',
            ),
            'current_status' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'name' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'colour' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'severity_order' => 
                array (
                  'type' => 'integer',
                ),
              ),
            ),
          ),
        ),
      ),
    ),
    'Supplier' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'supplies_assets' => 
        array (
          'type' => 'boolean',
        ),
        'legal_name' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'trading_name' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'display_name' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'reg_number' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'vat_number' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'type' => 
        array (
          'type' => 'object',
          'nullable' => true,
        ),
        'status' => 
        array (
          'type' => 'object',
          'nullable' => true,
        ),
        'address' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'line_1' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'line_2' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'city' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'county' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'postcode' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'country' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'questionnaire' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'date_issued' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'date_received' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'comments' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'is_active' => 
        array (
          'type' => 'boolean',
        ),
        'contact_count' => 
        array (
          'type' => 'integer',
        ),
        'contract_count' => 
        array (
          'type' => 'integer',
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'contacts' => 
        array (
          'type' => 'array',
        ),
      ),
    ),
    'SupplierAddress' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'line_1' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'line_2' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'city' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'county' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'postcode' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'country' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
      ),
    ),
    'SupplierContact' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
          'example' => 12,
        ),
        'first_name' => 
        array (
          'type' => 'string',
          'example' => 'Jo',
        ),
        'surname' => 
        array (
          'type' => 'string',
          'example' => 'Bates',
        ),
        'email' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'mobile' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'job_title' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'direct_dial' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'switchboard' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'is_active' => 
        array (
          'type' => 'boolean',
        ),
      ),
      'required' => 
      array (
        0 => 'id',
        1 => 'first_name',
        2 => 'surname',
        3 => 'is_active',
      ),
    ),
    'SupplierQuestionnaire' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'date_issued' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'YYYY-MM-DD.',
        ),
        'date_received' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'YYYY-MM-DD.',
        ),
      ),
    ),
    'SupplierWithContacts' => 
    array (
      'allOf' => 
      array (
        0 => 
        array (
          '$ref' => '#/components/schemas/Supplier',
        ),
        1 => 
        array (
          'type' => 'object',
          'properties' => 
          array (
            'contacts' => 
            array (
              'type' => 'array',
              'items' => 
              array (
                '$ref' => '#/components/schemas/SupplierContact',
              ),
            ),
            'id' => 
            array (
              'type' => 'integer',
            ),
            'legal_name' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'trading_name' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'display_name' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'reg_number' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'vat_number' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'type' => 
            array (
              'type' => 'object',
              'nullable' => true,
            ),
            'status' => 
            array (
              'type' => 'object',
              'nullable' => true,
            ),
            'address' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'line_1' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'line_2' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'city' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'county' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'postcode' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'country' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
              ),
            ),
            'questionnaire' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'date_issued' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'date_received' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
              ),
            ),
            'comments' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'supplies_assets' => 
            array (
              'type' => 'boolean',
            ),
            'is_active' => 
            array (
              'type' => 'boolean',
            ),
            'contact_count' => 
            array (
              'type' => 'integer',
            ),
            'contract_count' => 
            array (
              'type' => 'integer',
            ),
            'created_at' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
      ),
    ),
    'Task' => 
    array (
      'type' => 'object',
      'description' => 'A task as returned by list/create/update/move.',
      'required' => 
      array (
        0 => 'id',
        1 => 'title',
        2 => 'description',
        3 => 'status',
        4 => 'priority',
        5 => 'assigned_analyst',
        6 => 'assigned_team',
        7 => 'start_date',
        8 => 'due_date',
        9 => 'parent_task_id',
        10 => 'ticket_id',
        11 => 'change_id',
        12 => 'contract_id',
        13 => 'board_position',
        14 => 'tags',
        15 => 'subtasks',
        16 => 'created_by',
        17 => 'created_at',
        18 => 'updated_at',
        19 => 'completed_at',
      ),
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'title' => 
        array (
          'type' => 'string',
        ),
        'description' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'status' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
            ),
            'is_closed' => 
            array (
              'type' => 'boolean',
            ),
            'colour' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'priority' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
            ),
            'colour' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'assigned_analyst' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'assigned_team' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'start_date' => 
        array (
          'type' => 'string',
          'format' => 'date',
          'nullable' => true,
        ),
        'due_date' => 
        array (
          'type' => 'string',
          'format' => 'date',
          'nullable' => true,
        ),
        'parent_task_id' => 
        array (
          'type' => 'integer',
          'nullable' => true,
        ),
        'ticket_id' => 
        array (
          'type' => 'integer',
          'nullable' => true,
        ),
        'change_id' => 
        array (
          'type' => 'integer',
          'nullable' => true,
        ),
        'contract_id' => 
        array (
          'type' => 'integer',
          'nullable' => true,
        ),
        'board_position' => 
        array (
          'type' => 'integer',
        ),
        'tags' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            'type' => 'string',
          ),
        ),
        'subtasks' => 
        array (
          'type' => 'object',
          'description' => 'Done/total counts of this task\'s direct subtasks.',
          'required' => 
          array (
            0 => 'total',
            1 => 'done',
          ),
          'properties' => 
          array (
            'total' => 
            array (
              'type' => 'integer',
            ),
            'done' => 
            array (
              'type' => 'integer',
            ),
          ),
        ),
        'created_by' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'updated_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'completed_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'parent' => 
        array (
          'type' => 'object',
          'nullable' => true,
        ),
        'subtask_list' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            'type' => 'object',
            'nullable' => true,
            'properties' => 
            array (
              'id' => 
              array (
                'type' => 'integer',
              ),
              'title' => 
              array (
                'type' => 'string',
                'nullable' => true,
              ),
              'status' => 
              array (
                'type' => 'string',
                'nullable' => true,
              ),
              'is_closed' => 
              array (
                'type' => 'boolean',
              ),
              'board_position' => 
              array (
                'type' => 'integer',
              ),
            ),
          ),
        ),
        'linked_ticket' => 
        array (
          'type' => 'object',
          'nullable' => true,
        ),
        'linked_change' => 
        array (
          'type' => 'object',
          'nullable' => true,
        ),
        'comments' => 
        array (
          'type' => 'array',
        ),
      ),
    ),
    'TaskComment' => 
    array (
      'type' => 'object',
      'required' => 
      array (
        0 => 'id',
        1 => 'text',
        2 => 'analyst',
        3 => 'created_at',
      ),
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'text' => 
        array (
          'type' => 'string',
        ),
        'analyst' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
            ),
          ),
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'TaskCommentCreateAck' => 
    array (
      'type' => 'object',
      'description' => 'Response to posting a new comment — comments have no edit/delete in the product, so this is not the full TaskComment shape.',
      'required' => 
      array (
        0 => 'id',
        1 => 'task_id',
        2 => 'text',
      ),
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'task_id' => 
        array (
          'type' => 'integer',
        ),
        'text' => 
        array (
          'type' => 'string',
        ),
      ),
    ),
    'TaskDeleteAck' => 
    array (
      'type' => 'object',
      'description' => 'Acknowledgement for a hard delete — subtasks, comments and tag links are removed with it.',
      'required' => 
      array (
        0 => 'id',
        1 => 'deleted',
        2 => 'subtasks_deleted',
      ),
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'deleted' => 
        array (
          'type' => 'boolean',
          'example' => true,
        ),
        'subtasks_deleted' => 
        array (
          'type' => 'integer',
          'description' => 'Count of descendant subtasks deleted along with this task.',
        ),
      ),
    ),
    'TaskDetail' => 
    array (
      'description' => 'The full single-task view: everything in Task plus parent/subtask/comment/link detail.',
      'allOf' => 
      array (
        0 => 
        array (
          '$ref' => '#/components/schemas/Task',
        ),
        1 => 
        array (
          'type' => 'object',
          'required' => 
          array (
            0 => 'parent',
            1 => 'subtask_list',
            2 => 'linked_ticket',
            3 => 'linked_change',
            4 => 'comments',
          ),
          'properties' => 
          array (
            'parent' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'id' => 
                array (
                  'type' => 'integer',
                ),
                'title' => 
                array (
                  'type' => 'string',
                ),
              ),
            ),
            'subtask_list' => 
            array (
              'type' => 'array',
              'items' => 
              array (
                '$ref' => '#/components/schemas/TaskSubtaskListItem',
              ),
            ),
            'linked_ticket' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'description' => 'Only populated when the API key\'s company scope could read this ticket directly.',
              'properties' => 
              array (
                'id' => 
                array (
                  'type' => 'integer',
                ),
                'ticket_number' => 
                array (
                  'type' => 'string',
                ),
                'subject' => 
                array (
                  'type' => 'string',
                ),
              ),
            ),
            'linked_change' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'id' => 
                array (
                  'type' => 'integer',
                ),
                'title' => 
                array (
                  'type' => 'string',
                ),
              ),
            ),
            'comments' => 
            array (
              'type' => 'array',
              'items' => 
              array (
                '$ref' => '#/components/schemas/TaskComment',
              ),
            ),
            'id' => 
            array (
              'type' => 'integer',
            ),
            'title' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'description' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'status' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'id' => 
                array (
                  'type' => 'integer',
                ),
                'name' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'is_closed' => 
                array (
                  'type' => 'boolean',
                ),
                'colour' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
              ),
            ),
            'priority' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'id' => 
                array (
                  'type' => 'integer',
                ),
                'name' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'colour' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
              ),
            ),
            'assigned_analyst' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'id' => 
                array (
                  'type' => 'integer',
                ),
                'name' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
              ),
            ),
            'assigned_team' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'id' => 
                array (
                  'type' => 'integer',
                ),
                'name' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
              ),
            ),
            'start_date' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'due_date' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'parent_task_id' => 
            array (
              'type' => 'integer',
              'nullable' => true,
            ),
            'ticket_id' => 
            array (
              'type' => 'integer',
              'nullable' => true,
            ),
            'change_id' => 
            array (
              'type' => 'integer',
              'nullable' => true,
            ),
            'contract_id' => 
            array (
              'type' => 'integer',
              'nullable' => true,
            ),
            'board_position' => 
            array (
              'type' => 'integer',
            ),
            'tags' => 
            array (
              'type' => 'array',
            ),
            'subtasks' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'total' => 
                array (
                  'type' => 'integer',
                ),
                'done' => 
                array (
                  'type' => 'integer',
                ),
              ),
            ),
            'created_by' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'id' => 
                array (
                  'type' => 'integer',
                ),
                'name' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
              ),
            ),
            'created_at' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'updated_at' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'completed_at' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
      ),
    ),
    'TaskPriority' => 
    array (
      'type' => 'object',
      'required' => 
      array (
        0 => 'id',
        1 => 'name',
        2 => 'is_default',
        3 => 'colour',
        4 => 'is_active',
      ),
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'is_default' => 
        array (
          'type' => 'boolean',
        ),
        'colour' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'is_active' => 
        array (
          'type' => 'boolean',
        ),
      ),
    ),
    'TaskStatus' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'is_closed' => 
        array (
          'type' => 'boolean',
        ),
        'is_default' => 
        array (
          'type' => 'boolean',
        ),
        'colour' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'is_active' => 
        array (
          'type' => 'boolean',
        ),
        'display_order' => 
        array (
          'type' => 'integer',
        ),
      ),
    ),
    'TaskSubtaskListItem' => 
    array (
      'type' => 'object',
      'required' => 
      array (
        0 => 'id',
        1 => 'title',
        2 => 'status',
        3 => 'is_closed',
        4 => 'board_position',
      ),
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'title' => 
        array (
          'type' => 'string',
        ),
        'status' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'is_closed' => 
        array (
          'type' => 'boolean',
        ),
        'board_position' => 
        array (
          'type' => 'integer',
        ),
      ),
    ),
    'TaskTag' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'colour' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'task_count' => 
        array (
          'type' => 'integer',
        ),
      ),
    ),
    'Ticket' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'ticket_number' => 
        array (
          'type' => 'string',
        ),
        'subject' => 
        array (
          'type' => 'string',
        ),
        'status' => 
        array (
          '$ref' => '#/components/schemas/TicketStatusRef',
        ),
        'priority' => 
        array (
          '$ref' => '#/components/schemas/IdNameRef',
        ),
        'ticket_type' => 
        array (
          '$ref' => '#/components/schemas/IdNameRef',
        ),
        'origin' => 
        array (
          '$ref' => '#/components/schemas/IdNameRef',
        ),
        'department' => 
        array (
          '$ref' => '#/components/schemas/IdNameRef',
        ),
        'assigned_analyst' => 
        array (
          '$ref' => '#/components/schemas/IdNameRef',
        ),
        'requester' => 
        array (
          '$ref' => '#/components/schemas/TicketRequesterRef',
        ),
        'company' => 
        array (
          '$ref' => '#/components/schemas/IdNameRef',
        ),
        'first_time_fix' => 
        array (
          'type' => 'boolean',
          'nullable' => true,
        ),
        'it_training_provided' => 
        array (
          'type' => 'boolean',
          'nullable' => true,
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'updated_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'closed_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'work_start_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'deleted_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'description_html' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
      ),
    ),
    'TicketAuditEntry' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'field' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'old_value' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'new_value' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'analyst' => 
        array (
          '$ref' => '#/components/schemas/TicketNoteAuthorRef',
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'TicketDetail' => 
    array (
      'description' => 'GET /tickets/{id} — full Ticket plus the original request body.',
      'allOf' => 
      array (
        0 => 
        array (
          '$ref' => '#/components/schemas/Ticket',
        ),
        1 => 
        array (
          'type' => 'object',
          'properties' => 
          array (
            'description_html' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'id' => 
            array (
              'type' => 'integer',
            ),
            'ticket_number' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'subject' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'status' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'id' => 
                array (
                  'type' => 'integer',
                ),
                'name' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'is_closed' => 
                array (
                  'type' => 'boolean',
                ),
              ),
            ),
            'priority' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'id' => 
                array (
                  'type' => 'integer',
                ),
                'name' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
              ),
            ),
            'ticket_type' => 
            array (
              'type' => 'object',
              'nullable' => true,
            ),
            'origin' => 
            array (
              'type' => 'object',
              'nullable' => true,
            ),
            'department' => 
            array (
              'type' => 'object',
              'nullable' => true,
            ),
            'assigned_analyst' => 
            array (
              'type' => 'object',
              'nullable' => true,
            ),
            'requester' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'id' => 
                array (
                  'type' => 'integer',
                ),
                'email' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'name' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
              ),
            ),
            'company' => 
            array (
              'type' => 'object',
              'nullable' => true,
            ),
            'first_time_fix' => 
            array (
              'type' => 'boolean',
              'nullable' => true,
            ),
            'it_training_provided' => 
            array (
              'type' => 'boolean',
              'nullable' => true,
            ),
            'created_at' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'updated_at' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'closed_at' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'work_start_at' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'deleted_at' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
      ),
    ),
    'TicketNote' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'text' => 
        array (
          'type' => 'string',
        ),
        'is_internal' => 
        array (
          'type' => 'boolean',
        ),
        'analyst' => 
        array (
          '$ref' => '#/components/schemas/TicketNoteAuthorRef',
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'TicketNoteAuthorRef' => 
    array (
      'type' => 'object',
      'nullable' => true,
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
      ),
    ),
    'TicketNoteCreated' => 
    array (
      'description' => 'POST /tickets/{id}/notes response — a narrower shape than TicketNote (no analyst/created_at).',
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'ticket_id' => 
        array (
          'type' => 'string',
          'description' => 'Echoes the {id} path segment verbatim (not cast to int).',
        ),
        'text' => 
        array (
          'type' => 'string',
        ),
        'is_internal' => 
        array (
          'type' => 'boolean',
        ),
      ),
    ),
    'TicketPriority' => 
    array (
      'type' => 'object',
      'description' => 'A ticket priority with its SLA targets (GET /priorities).',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'sla_response_minutes' => 
        array (
          'type' => 'integer',
          'nullable' => true,
        ),
        'sla_resolution_minutes' => 
        array (
          'type' => 'integer',
          'nullable' => true,
        ),
      ),
    ),
    'TicketRequesterRef' => 
    array (
      'type' => 'object',
      'nullable' => true,
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'email' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'name' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
      ),
    ),
    'TicketSla' => 
    array (
      'type' => 'object',
      'description' => 'Live, compute-on-read SLA state for a ticket (same engine the UI uses).',
      'properties' => 
      array (
        'enabled' => 
        array (
          'type' => 'boolean',
        ),
        'reason_disabled' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'Why SLA is not enforced/computed for this ticket; informational only when enabled=false.',
        ),
        'priority' => 
        array (
          '$ref' => '#/components/schemas/SlaPriorityRef',
        ),
        'calendar' => 
        array (
          '$ref' => '#/components/schemas/SlaCalendarRef',
        ),
        'response' => 
        array (
          '$ref' => '#/components/schemas/SlaWindow',
        ),
        'resolution' => 
        array (
          '$ref' => '#/components/schemas/SlaWindow',
        ),
      ),
    ),
    'TicketStatus' => 
    array (
      'type' => 'object',
      'description' => 'A ticket status (GET /statuses).',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'is_closed' => 
        array (
          'type' => 'boolean',
        ),
        'is_default' => 
        array (
          'type' => 'boolean',
        ),
        'pauses_sla' => 
        array (
          'type' => 'boolean',
        ),
        'colour' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
      ),
    ),
    'TicketStatusRef' => 
    array (
      'type' => 'object',
      'nullable' => true,
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'is_closed' => 
        array (
          'type' => 'boolean',
        ),
      ),
    ),
    'TicketThreadMessage' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'direction' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'is_initial' => 
        array (
          'type' => 'boolean',
        ),
        'channel' => 
        array (
          'type' => 'string',
        ),
        'subject' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'from' => 
        array (
          '$ref' => '#/components/schemas/TicketThreadMessageFromRef',
        ),
        'to' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'cc' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'body_preview' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'body_html' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'received_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'TicketThreadMessageFromRef' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'address' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'name' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
      ),
    ),
    'TimeEntry' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'minutes' => 
        array (
          'type' => 'integer',
        ),
        'notes' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'analyst' => 
        array (
          '$ref' => '#/components/schemas/TicketNoteAuthorRef',
        ),
        'entry_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'TimeEntryCreated' => 
    array (
      'description' => 'POST /tickets/{id}/time-entries response — a narrower shape than TimeEntry (no notes/analyst).',
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'ticket_id' => 
        array (
          'type' => 'string',
          'description' => 'Echoes the {id} path segment verbatim (not cast to int).',
        ),
        'minutes' => 
        array (
          'type' => 'integer',
        ),
        'entry_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
      ),
    ),
    'Workflow' => 
    array (
      'type' => 'object',
      'description' => 'A workflow with its full rule body — used by get/create/update.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'description' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'trigger_event' => 
        array (
          'type' => 'string',
        ),
        'is_active' => 
        array (
          'type' => 'boolean',
        ),
        'created_by' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'updated_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'last_run' => 
        array (
          '$ref' => '#/components/schemas/WorkflowLastRun',
        ),
        'run_count' => 
        array (
          'type' => 'integer',
        ),
        'conditions' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/WorkflowCondition',
          ),
        ),
        'actions' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/WorkflowActionStep',
          ),
        ),
      ),
    ),
    'WorkflowAction' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'key' => 
        array (
          'type' => 'string',
          'description' => 'The action type key, e.g. "ticket.set_status".',
        ),
        'label' => 
        array (
          'type' => 'string',
        ),
        'description' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'args' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/WorkflowActionArg',
          ),
        ),
      ),
    ),
    'WorkflowActionArg' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'name' => 
        array (
          'type' => 'string',
        ),
        'type' => 
        array (
          'type' => 'string',
          'description' => 'Input type, e.g. text, select, lookup.',
        ),
        'label' => 
        array (
          'type' => 'string',
        ),
        'required' => 
        array (
          'type' => 'boolean',
        ),
        'default' => 
        array (
          'nullable' => true,
          'description' => 'The arg\'s default value; shape depends on its type, or null if none.',
          'type' => 'string',
        ),
        'supports_vars' => 
        array (
          'type' => 'boolean',
          'description' => 'Whether {{template}} variables may be used in this arg.',
        ),
        'lookup' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'Reference-data source to populate this arg from (e.g. "statuses"), if applicable.',
        ),
      ),
    ),
    'WorkflowActionStep' => 
    array (
      'type' => 'object',
      'description' => 'One action step. type must be one of the engine\'s known action types (see GET /workflow-actions); args is a free-form map of that action\'s named arguments, values may contain {{template.vars}}.',
      'properties' => 
      array (
        'type' => 
        array (
          'type' => 'string',
        ),
        'args' => 
        array (
          'type' => 'object',
          'description' => 'Action-specific named arguments (dynamic — see GET /workflow-actions for the shape per action type).',
          'properties' => 
          array (
            'ticket_id' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'to' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'subject' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
            'body' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'x' => 
        array (
          'type' => 'integer',
        ),
        'y' => 
        array (
          'type' => 'integer',
        ),
      ),
    ),
    'WorkflowCondition' => 
    array (
      'type' => 'object',
      'description' => 'One condition clause. field is a dotted path into the trigger payload (see GET /workflow-triggers); op must be one of the engine\'s known operators for that field\'s type; value shape depends on the operator (e.g. a single value, or an array for "in").',
      'properties' => 
      array (
        'field' => 
        array (
          'type' => 'string',
        ),
        'op' => 
        array (
          'type' => 'string',
        ),
        'value' => 
        array (
          'nullable' => true,
          'type' => 'array',
          'items' => 
          array (
            'type' => 'string',
          ),
        ),
        'x' => 
        array (
          'type' => 'integer',
        ),
        'y' => 
        array (
          'type' => 'integer',
        ),
      ),
    ),
    'WorkflowDeleted' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'deleted' => 
        array (
          'type' => 'boolean',
        ),
      ),
    ),
    'WorkflowExecution' => 
    array (
      'type' => 'object',
      'description' => 'Full execution detail, including the trigger payload snapshot and per-step log — used by GET /workflow-executions/{id}.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'workflow' => 
        array (
          'type' => 'object',
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
              'nullable' => true,
            ),
            'name' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'trigger_event' => 
        array (
          'type' => 'string',
        ),
        'status' => 
        array (
          'type' => 'string',
          'description' => 'One of: running, success, failed, skipped, aborted.',
        ),
        'started_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'finished_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'error_message' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'trigger_payload' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'description' => 'Event payload snapshot (dynamic).',
          'properties' => 
          array (
            'ticket' => 
            array (
              'type' => 'object',
              'nullable' => true,
              'properties' => 
              array (
                'id' => 
                array (
                  'type' => 'integer',
                ),
                'subject' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
                'priority_id' => 
                array (
                  'type' => 'integer',
                ),
                'status_id' => 
                array (
                  'type' => 'integer',
                ),
                'department_id' => 
                array (
                  'type' => 'integer',
                  'nullable' => true,
                ),
                'type_id' => 
                array (
                  'type' => 'integer',
                  'nullable' => true,
                ),
                'assigned_analyst_id' => 
                array (
                  'type' => 'integer',
                ),
                'owner_id' => 
                array (
                  'type' => 'integer',
                ),
                'origin_id' => 
                array (
                  'type' => 'integer',
                  'nullable' => true,
                ),
                'created_by' => 
                array (
                  'type' => 'integer',
                ),
                'requester_email' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
              ),
            ),
            'analyst_id' => 
            array (
              'type' => 'integer',
            ),
            'team_id' => 
            array (
              'type' => 'integer',
              'nullable' => true,
            ),
          ),
        ),
        'step_log' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            'type' => 'object',
            'properties' => 
            array (
              'kind' => 
              array (
                'type' => 'string',
                'nullable' => true,
              ),
              'index' => 
              array (
                'type' => 'integer',
              ),
              'field' => 
              array (
                'type' => 'string',
                'nullable' => true,
              ),
              'op' => 
              array (
                'type' => 'string',
                'nullable' => true,
              ),
              'value' => 
              array (
                'type' => 'array',
                'items' => 
                array (
                  'type' => 'string',
                  'nullable' => true,
                ),
              ),
              'actual' => 
              array (
                'type' => 'integer',
              ),
              'passed' => 
              array (
                'type' => 'boolean',
              ),
            ),
          ),
          'description' => 'Per-step results (dynamic).',
        ),
      ),
    ),
    'WorkflowExecutionSummary' => 
    array (
      'type' => 'object',
      'description' => 'An execution without its payload/step-log detail — used by the list endpoints.',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'workflow' => 
        array (
          'type' => 'object',
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
              'nullable' => true,
              'description' => 'Null when the parent workflow has been deleted (see name for the attribution snapshot).',
            ),
            'name' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'trigger_event' => 
        array (
          'type' => 'string',
        ),
        'status' => 
        array (
          'type' => 'string',
          'description' => 'One of: running, success, failed, skipped, aborted.',
        ),
        'started_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'finished_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'error_message' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
      ),
    ),
    'WorkflowFireResult' => 
    array (
      'type' => 'object',
      'description' => 'The manual test-fire result — a real execution that does NOT bump the workflow\'s run_count / last_run.',
      'properties' => 
      array (
        'execution_id' => 
        array (
          'type' => 'integer',
        ),
        'workflow_id' => 
        array (
          'type' => 'integer',
        ),
        'status' => 
        array (
          'type' => 'string',
          'description' => 'One of: success, failed, skipped, aborted.',
        ),
        'step_log' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            'type' => 'object',
          ),
          'description' => 'Per-step results (dynamic shape per action type).',
        ),
        'error_message' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
      ),
    ),
    'WorkflowLastRun' => 
    array (
      'type' => 'object',
      'nullable' => true,
      'description' => 'Null until the workflow has run at least once (manual test-fires do not update this).',
      'properties' => 
      array (
        'at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'status' => 
        array (
          'type' => 'string',
          'nullable' => true,
          'description' => 'One of: success, failed, skipped, aborted.',
        ),
      ),
    ),
    'WorkflowSummary' => 
    array (
      'type' => 'object',
      'description' => 'A workflow without its rule body — used by GET /workflows (the landing-page list).',
      'properties' => 
      array (
        'id' => 
        array (
          'type' => 'integer',
        ),
        'name' => 
        array (
          'type' => 'string',
        ),
        'description' => 
        array (
          'type' => 'string',
          'nullable' => true,
        ),
        'trigger_event' => 
        array (
          'type' => 'string',
        ),
        'is_active' => 
        array (
          'type' => 'boolean',
        ),
        'created_by' => 
        array (
          'type' => 'object',
          'nullable' => true,
          'properties' => 
          array (
            'id' => 
            array (
              'type' => 'integer',
            ),
            'name' => 
            array (
              'type' => 'string',
              'nullable' => true,
            ),
          ),
        ),
        'created_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'updated_at' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
          'nullable' => true,
        ),
        'last_run' => 
        array (
          '$ref' => '#/components/schemas/WorkflowLastRun',
        ),
        'run_count' => 
        array (
          'type' => 'integer',
        ),
        'conditions_count' => 
        array (
          'type' => 'integer',
        ),
        'actions_count' => 
        array (
          'type' => 'integer',
        ),
      ),
    ),
    'WorkflowTrigger' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'key' => 
        array (
          'type' => 'string',
          'description' => 'The trigger event key, e.g. "ticket.created".',
        ),
        'label' => 
        array (
          'type' => 'string',
        ),
        'fields' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/WorkflowTriggerField',
          ),
        ),
      ),
    ),
    'WorkflowTriggerField' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'path' => 
        array (
          'type' => 'string',
          'description' => 'Dotted condition-field path, e.g. "ticket.priority_id".',
        ),
        'type' => 
        array (
          'type' => 'string',
          'description' => 'The field\'s data type (e.g. string, number, boolean, select, date).',
        ),
        'operators' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            'type' => 'string',
          ),
          'description' => 'The comparison operators valid for this field type.',
        ),
      ),
    ),
  ),
  'responses' => 
  array (
    'DELETE /assets/{id}/assignments/{user_id}' => 
    array (
      '$ref' => '#/components/schemas/AssetAssignmentDeleted',
    ),
    'DELETE /calendar/events/{id}' => 
    array (
      '$ref' => '#/components/schemas/CalendarEventDeleted',
    ),
    'DELETE /changes/{id}' => 
    array (
      '$ref' => '#/components/schemas/ChangeDeleteResult',
    ),
    'DELETE /changes/{id}/comments/{comment_id}' => 
    array (
      '$ref' => '#/components/schemas/ChangeCommentDeleteResult',
    ),
    'DELETE /cmdb/objects/{id}' => 
    array (
      '$ref' => '#/components/schemas/CmdbObjectDeleteResult',
    ),
    'DELETE /cmdb/objects/{id}/relationships/{rel_id}' => 
    array (
      '$ref' => '#/components/schemas/CmdbRelationshipDeleteResult',
    ),
    'DELETE /cmdb/objects/{id}/tickets/{ticket_id}' => 
    array (
      '$ref' => '#/components/schemas/CmdbTicketUnlinkResult',
    ),
    'DELETE /contracts/{id}' => 
    array (
      '$ref' => '#/components/schemas/DeleteAck',
    ),
    'DELETE /forms/{id}' => 
    array (
      '$ref' => '#/components/schemas/FormDeleted',
    ),
    'DELETE /forms/{id}/submissions/{submission_id}' => 
    array (
      '$ref' => '#/components/schemas/FormSubmissionDeleted',
    ),
    'DELETE /knowledge/articles/{id}' => 
    array (
      '$ref' => '#/components/schemas/KnowledgeArticleArchiveAck',
    ),
    'DELETE /knowledge/articles/{id}/permanent' => 
    array (
      '$ref' => '#/components/schemas/KnowledgeArticlePurgeAck',
    ),
    'DELETE /morning-checks/checks/{id}' => 
    array (
      '$ref' => '#/components/schemas/MorningCheckDeleted',
    ),
    'DELETE /network-diagrams/{id}' => 
    array (
      '$ref' => '#/components/schemas/NetworkDiagramDeleteAck',
    ),
    'DELETE /network-diagrams/{id}/connectors/{connector_id}' => 
    array (
      '$ref' => '#/components/schemas/NetworkConnectorDeleteAck',
    ),
    'DELETE /network-diagrams/{id}/nodes/{node_id}' => 
    array (
      '$ref' => '#/components/schemas/NetworkNodeDeleteAck',
    ),
    'DELETE /problems/{id}' => 
    array (
      '$ref' => '#/components/schemas/ProblemDeleteResult',
    ),
    'DELETE /problems/{id}/changes/{change_id}' => 
    array (
      '$ref' => '#/components/schemas/ProblemChangeUnlinkResult',
    ),
    'DELETE /problems/{id}/tickets/{ticket_id}' => 
    array (
      '$ref' => '#/components/schemas/ProblemTicketUnlinkResult',
    ),
    'DELETE /service-status/incidents/{id}' => 
    array (
      '$ref' => '#/components/schemas/StatusIncidentDeleted',
    ),
    'DELETE /service-status/services/{id}' => 
    array (
      '$ref' => '#/components/schemas/StatusServiceDeleted',
    ),
    'DELETE /software/licences/{id}' => 
    array (
      '$ref' => '#/components/schemas/SoftwareLicenceDeleted',
    ),
    'DELETE /suppliers/{id}' => 
    array (
      '$ref' => '#/components/schemas/DeleteAck',
    ),
    'DELETE /suppliers/{id}/contacts/{contact_id}' => 
    array (
      '$ref' => '#/components/schemas/DeleteAck',
    ),
    'DELETE /tasks/{id}' => 
    array (
      '$ref' => '#/components/schemas/TaskDeleteAck',
    ),
    'DELETE /tickets/{id}' => 
    array (
      '$ref' => '#/components/schemas/DeleteAck',
    ),
    'DELETE /tickets/{id}/time-entries/{entry_id}' => 
    array (
      '$ref' => '#/components/schemas/DeleteAck',
    ),
    'DELETE /workflows/{id}' => 
    array (
      '$ref' => '#/components/schemas/WorkflowDeleted',
    ),
    'GET /' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'name' => 
        array (
          'type' => 'string',
        ),
        'version' => 
        array (
          'type' => 'integer',
        ),
        'endpoints' => 
        array (
          'type' => 'array',
          'items' => 
          array (
            'type' => 'string',
          ),
          'description' => 'Every route, formatted as "METHOD /path" (e.g. "GET /tickets/{id}").',
        ),
      ),
    ),
    'GET /analysts' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/Analyst',
      ),
    ),
    'GET /asset-locations' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/AssetLocation',
      ),
    ),
    'GET /asset-statuses' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/LookupWithDescription',
      ),
    ),
    'GET /asset-types' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/LookupWithDescription',
      ),
    ),
    'GET /assets' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/Asset',
      ),
    ),
    'GET /assets/{id}' => 
    array (
      '$ref' => '#/components/schemas/AssetDetail',
    ),
    'GET /assets/{id}/assignments' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/AssetAssignment',
      ),
    ),
    'GET /assets/{id}/custody' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/AssetCustodyEntry',
      ),
    ),
    'GET /assets/{id}/devices' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/AssetDevice',
      ),
    ),
    'GET /assets/{id}/disks' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/AssetDisk',
      ),
    ),
    'GET /assets/{id}/history' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/AssetHistoryEntry',
      ),
    ),
    'GET /assets/{id}/network-adapters' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/AssetNetworkAdapter',
      ),
    ),
    'GET /assets/{id}/software' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/AssetSoftware',
      ),
    ),
    'GET /calendar-categories' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/CalendarCategory',
      ),
    ),
    'GET /calendar/events' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/CalendarEvent',
      ),
    ),
    'GET /calendar/events/{id}' => 
    array (
      '$ref' => '#/components/schemas/CalendarEvent',
    ),
    'GET /change-categories' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/LookupWithDescription',
      ),
    ),
    'GET /change-impacts' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/ColouredLookupItem',
      ),
    ),
    'GET /change-priorities' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/ColouredLookupItem',
      ),
    ),
    'GET /change-statuses' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/StatusLookupItem',
      ),
    ),
    'GET /change-types' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/ColouredLookupItem',
      ),
    ),
    'GET /changes' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/Change',
      ),
    ),
    'GET /changes/{id}' => 
    array (
      '$ref' => '#/components/schemas/ChangeDetail',
    ),
    'GET /changes/{id}/audit' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/ChangeAuditEntry',
      ),
    ),
    'GET /changes/{id}/cab' => 
    array (
      '$ref' => '#/components/schemas/ChangeCab',
    ),
    'GET /changes/{id}/comments' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/ChangeComment',
      ),
    ),
    'GET /cmdb-icons' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/CmdbIcon',
      ),
    ),
    'GET /cmdb-relationship-types' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/CmdbRelationshipType',
      ),
    ),
    'GET /cmdb/classes' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/CmdbClass',
      ),
    ),
    'GET /cmdb/classes/{id}' => 
    array (
      '$ref' => '#/components/schemas/CmdbClassDetail',
    ),
    'GET /cmdb/objects' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/CmdbObject',
      ),
    ),
    'GET /cmdb/objects/{id}' => 
    array (
      '$ref' => '#/components/schemas/CmdbObjectDetail',
    ),
    'GET /cmdb/objects/{id}/impact' => 
    array (
      '$ref' => '#/components/schemas/CmdbImpact',
    ),
    'GET /cmdb/objects/{id}/tickets' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/CmdbLinkedTicket',
      ),
    ),
    'GET /companies' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/Company',
      ),
    ),
    'GET /contract-statuses' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/LookupWithDescription',
      ),
    ),
    'GET /contract-term-tabs' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/LookupWithDescription',
      ),
    ),
    'GET /contracts' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/Contract',
      ),
    ),
    'GET /contracts/{id}' => 
    array (
      '$ref' => '#/components/schemas/Contract',
    ),
    'GET /contracts/{id}/terms' => 
    array (
      'type' => 'array',
      'description' => 'Every active term tab with this contract\'s content for it.',
      'items' => 
      array (
        '$ref' => '#/components/schemas/ContractTerm',
      ),
    ),
    'GET /departments' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/LookupWithDescription',
      ),
    ),
    'GET /forms' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/FormSummary',
      ),
    ),
    'GET /forms/{id}' => 
    array (
      '$ref' => '#/components/schemas/Form',
    ),
    'GET /forms/{id}/submissions' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/FormSubmission',
      ),
    ),
    'GET /forms/{id}/submissions/{submission_id}' => 
    array (
      '$ref' => '#/components/schemas/FormSubmission',
    ),
    'GET /forms/{id}/versions' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/FormSummary',
      ),
    ),
    'GET /knowledge/articles' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/KnowledgeArticleSummary',
      ),
    ),
    'GET /knowledge/articles/{id}' => 
    array (
      '$ref' => '#/components/schemas/KnowledgeArticle',
    ),
    'GET /knowledge/articles/{id}/versions' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/KnowledgeArticleVersion',
      ),
    ),
    'GET /knowledge/articles/{id}/versions/{version}' => 
    array (
      '$ref' => '#/components/schemas/KnowledgeArticleVersionDetail',
    ),
    'GET /knowledge/tags' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/KnowledgeTag',
      ),
    ),
    'GET /morning-check-statuses' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/MorningCheckStatus',
      ),
    ),
    'GET /morning-checks/board' => 
    array (
      '$ref' => '#/components/schemas/MorningCheckBoard',
    ),
    'GET /morning-checks/checks' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/MorningCheck',
      ),
    ),
    'GET /morning-checks/checks/{id}' => 
    array (
      '$ref' => '#/components/schemas/MorningCheck',
    ),
    'GET /morning-checks/results' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/MorningCheckResult',
      ),
    ),
    'GET /morning-checks/results/{id}' => 
    array (
      '$ref' => '#/components/schemas/MorningCheckResult',
    ),
    'GET /network-diagrams' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/NetworkDiagramSummary',
      ),
    ),
    'GET /network-diagrams/{id}' => 
    array (
      '$ref' => '#/components/schemas/NetworkDiagram',
    ),
    'GET /network-diagrams/{id}/suggestions' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/NetworkSuggestion',
      ),
    ),
    'GET /network-diagrams/{id}/versions' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/NetworkDiagramSummary',
      ),
    ),
    'GET /origins' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/LookupIdNameActive',
      ),
    ),
    'GET /payment-schedules' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/LookupWithDescription',
      ),
    ),
    'GET /ping' => 
    array (
      'type' => 'object',
      'properties' => 
      array (
        'ok' => 
        array (
          'type' => 'boolean',
        ),
        'key' => 
        array (
          'type' => 'object',
          'properties' => 
          array (
            'name' => 
            array (
              'type' => 'string',
            ),
            'acts_as' => 
            array (
              'type' => 'string',
              'nullable' => true,
              'description' => 'The analyst this key acts as, if it is bound to one.',
            ),
            'permissions' => 
            array (
              'type' => 'object',
              'description' => 'Map of resource name to the granted actions for it, e.g. {"tickets": ["read", "create"]}.',
              'additionalProperties' => 
              array (
                'type' => 'array',
                'items' => 
                array (
                  'type' => 'string',
                ),
              ),
              'properties' => 
              array (
                'tickets' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'ticket_notes' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'ticket_thread' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'ticket_audit' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'ticket_sla' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'ticket_time_entries' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'assets' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'asset_assignments' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'asset_history' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'asset_inventory' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'problems' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'problem_notes' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'problem_audit' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'problem_links' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'changes' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'change_comments' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'change_audit' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'change_cab' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'knowledge' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'knowledge_versions' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'tasks' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'task_comments' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'cmdb_classes' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'cmdb_objects' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'cmdb_relationships' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'cmdb_ticket_links' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'contracts' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'contract_terms' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'suppliers' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'supplier_contacts' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'calendar_events' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'software_inventory' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'software_licences' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'services' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'service_incidents' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'morning_checks' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'morning_check_results' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'forms' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'form_submissions' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'workflows' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'workflow_executions' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'network_diagrams' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'users' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'analysts' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'companies' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
                'reference' => 
                array (
                  'type' => 'array',
                  'items' => 
                  array (
                    'type' => 'string',
                    'nullable' => true,
                  ),
                ),
              ),
            ),
            'companies' => 
            array (
              'type' => 'array',
              'nullable' => true,
              'description' => 'The companies this key is scoped to, or null if it can see all companies.',
              'items' => 
              array (
                'type' => 'object',
                'properties' => 
                array (
                  'id' => 
                  array (
                    'type' => 'integer',
                  ),
                  'name' => 
                  array (
                    'type' => 'string',
                  ),
                ),
              ),
            ),
            'expires_at' => 
            array (
              'type' => 'string',
              'format' => 'date-time',
              'nullable' => true,
            ),
          ),
        ),
        'server_time' => 
        array (
          'type' => 'string',
          'format' => 'date-time',
        ),
      ),
    ),
    'GET /priorities' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/TicketPriority',
      ),
    ),
    'GET /problem-priorities' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/ColouredLookupItem',
      ),
    ),
    'GET /problem-statuses' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/StatusLookupItem',
      ),
    ),
    'GET /problems' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/Problem',
      ),
    ),
    'GET /problems/{id}' => 
    array (
      '$ref' => '#/components/schemas/ProblemDetail',
    ),
    'GET /problems/{id}/audit' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/ProblemAuditEntry',
      ),
    ),
    'GET /problems/{id}/notes' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/ProblemNote',
      ),
    ),
    'GET /service-impact-levels' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/ServiceImpactLevel',
      ),
    ),
    'GET /service-incident-statuses' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/ServiceIncidentStatus',
      ),
    ),
    'GET /service-status/incidents' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/StatusIncident',
      ),
    ),
    'GET /service-status/incidents/{id}' => 
    array (
      '$ref' => '#/components/schemas/StatusIncident',
    ),
    'GET /service-status/services' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/StatusService',
      ),
    ),
    'GET /service-status/services/{id}' => 
    array (
      '$ref' => '#/components/schemas/StatusServiceDetail',
    ),
    'GET /software/apps' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/SoftwareApp',
      ),
    ),
    'GET /software/apps/{id}' => 
    array (
      '$ref' => '#/components/schemas/SoftwareAppDetail',
    ),
    'GET /software/apps/{id}/machines' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/SoftwareMachine',
      ),
    ),
    'GET /software/licences' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/SoftwareLicence',
      ),
    ),
    'GET /software/licences/{id}' => 
    array (
      '$ref' => '#/components/schemas/SoftwareLicence',
    ),
    'GET /statuses' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/TicketStatus',
      ),
    ),
    'GET /supplier-statuses' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/LookupWithDescription',
      ),
    ),
    'GET /supplier-types' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/LookupWithDescription',
      ),
    ),
    'GET /suppliers' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/Supplier',
      ),
    ),
    'GET /suppliers/{id}' => 
    array (
      '$ref' => '#/components/schemas/SupplierWithContacts',
    ),
    'GET /suppliers/{id}/contacts' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/SupplierContact',
      ),
    ),
    'GET /task-priorities' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/ColouredLookupItem',
      ),
    ),
    'GET /task-statuses' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/TaskStatus',
      ),
    ),
    'GET /task-tags' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/TaskTag',
      ),
    ),
    'GET /tasks' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/Task',
      ),
    ),
    'GET /tasks/{id}' => 
    array (
      '$ref' => '#/components/schemas/TaskDetail',
    ),
    'GET /tasks/{id}/comments' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/TaskComment',
      ),
    ),
    'GET /ticket-types' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/LookupIdNameActive',
      ),
    ),
    'GET /tickets' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/Ticket',
      ),
    ),
    'GET /tickets/{id}' => 
    array (
      '$ref' => '#/components/schemas/TicketDetail',
    ),
    'GET /tickets/{id}/audit' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/TicketAuditEntry',
      ),
    ),
    'GET /tickets/{id}/notes' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/TicketNote',
      ),
    ),
    'GET /tickets/{id}/sla' => 
    array (
      '$ref' => '#/components/schemas/TicketSla',
    ),
    'GET /tickets/{id}/thread' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/TicketThreadMessage',
      ),
    ),
    'GET /tickets/{id}/time-entries' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/TimeEntry',
      ),
    ),
    'GET /users' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/Requester',
      ),
    ),
    'GET /users/{id}' => 
    array (
      '$ref' => '#/components/schemas/RequesterDetail',
    ),
    'GET /workflow-actions' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/WorkflowAction',
      ),
    ),
    'GET /workflow-executions' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/WorkflowExecutionSummary',
      ),
    ),
    'GET /workflow-executions/{id}' => 
    array (
      '$ref' => '#/components/schemas/WorkflowExecution',
    ),
    'GET /workflow-triggers' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/WorkflowTrigger',
      ),
    ),
    'GET /workflows' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/WorkflowSummary',
      ),
    ),
    'GET /workflows/{id}' => 
    array (
      '$ref' => '#/components/schemas/Workflow',
    ),
    'GET /workflows/{id}/executions' => 
    array (
      'type' => 'array',
      'items' => 
      array (
        '$ref' => '#/components/schemas/WorkflowExecutionSummary',
      ),
    ),
    'PATCH /assets/{id}' => 
    array (
      '$ref' => '#/components/schemas/Asset',
    ),
    'PATCH /calendar/events/{id}' => 
    array (
      '$ref' => '#/components/schemas/CalendarEvent',
    ),
    'PATCH /changes/{id}' => 
    array (
      '$ref' => '#/components/schemas/Change',
    ),
    'PATCH /cmdb/objects/{id}' => 
    array (
      '$ref' => '#/components/schemas/CmdbObject',
    ),
    'PATCH /contracts/{id}' => 
    array (
      '$ref' => '#/components/schemas/Contract',
    ),
    'PATCH /forms/{id}' => 
    array (
      '$ref' => '#/components/schemas/Form',
    ),
    'PATCH /knowledge/articles/{id}' => 
    array (
      '$ref' => '#/components/schemas/KnowledgeArticle',
    ),
    'PATCH /morning-checks/checks/{id}' => 
    array (
      '$ref' => '#/components/schemas/MorningCheck',
    ),
    'PATCH /network-diagrams/{id}' => 
    array (
      '$ref' => '#/components/schemas/NetworkDiagram',
    ),
    'PATCH /network-diagrams/{id}/connectors/{connector_id}' => 
    array (
      '$ref' => '#/components/schemas/NetworkConnector',
    ),
    'PATCH /network-diagrams/{id}/nodes/{node_id}' => 
    array (
      '$ref' => '#/components/schemas/NetworkNode',
    ),
    'PATCH /problems/{id}' => 
    array (
      '$ref' => '#/components/schemas/Problem',
    ),
    'PATCH /service-status/incidents/{id}' => 
    array (
      '$ref' => '#/components/schemas/StatusIncident',
    ),
    'PATCH /service-status/services/{id}' => 
    array (
      '$ref' => '#/components/schemas/StatusService',
    ),
    'PATCH /software/licences/{id}' => 
    array (
      '$ref' => '#/components/schemas/SoftwareLicence',
    ),
    'PATCH /suppliers/{id}' => 
    array (
      '$ref' => '#/components/schemas/Supplier',
    ),
    'PATCH /suppliers/{id}/contacts/{contact_id}' => 
    array (
      '$ref' => '#/components/schemas/SupplierContact',
    ),
    'PATCH /tasks/{id}' => 
    array (
      '$ref' => '#/components/schemas/Task',
    ),
    'PATCH /tickets/{id}' => 
    array (
      '$ref' => '#/components/schemas/Ticket',
    ),
    'PATCH /users/{id}' => 
    array (
      '$ref' => '#/components/schemas/Requester',
    ),
    'PATCH /workflows/{id}' => 
    array (
      '$ref' => '#/components/schemas/Workflow',
    ),
    'POST /assets' => 
    array (
      '$ref' => '#/components/schemas/Asset',
    ),
    'POST /assets/{id}/assignments' => 
    array (
      '$ref' => '#/components/schemas/AssetAssignmentCreated',
    ),
    'POST /calendar/events' => 
    array (
      '$ref' => '#/components/schemas/CalendarEvent',
    ),
    'POST /changes' => 
    array (
      '$ref' => '#/components/schemas/Change',
    ),
    'POST /changes/{id}/cab' => 
    array (
      '$ref' => '#/components/schemas/ChangeCab',
    ),
    'POST /changes/{id}/cab/vote' => 
    array (
      '$ref' => '#/components/schemas/ChangeCabVoteResult',
    ),
    'POST /changes/{id}/comments' => 
    array (
      '$ref' => '#/components/schemas/ChangeCommentCreateResult',
    ),
    'POST /cmdb/objects' => 
    array (
      '$ref' => '#/components/schemas/CmdbObject',
    ),
    'POST /cmdb/objects/{id}/relationships' => 
    array (
      '$ref' => '#/components/schemas/CmdbRelationshipCreateResult',
    ),
    'POST /cmdb/objects/{id}/tickets' => 
    array (
      '$ref' => '#/components/schemas/CmdbTicketLinkResult',
    ),
    'POST /contracts' => 
    array (
      '$ref' => '#/components/schemas/Contract',
    ),
    'POST /contracts/{id}/terms' => 
    array (
      'type' => 'array',
      'description' => 'The full term set after the upsert (same shape as GET .../terms).',
      'items' => 
      array (
        '$ref' => '#/components/schemas/ContractTerm',
      ),
    ),
    'POST /forms' => 
    array (
      '$ref' => '#/components/schemas/Form',
    ),
    'POST /forms/{id}/submissions' => 
    array (
      '$ref' => '#/components/schemas/FormSubmission',
    ),
    'POST /forms/{id}/versions' => 
    array (
      '$ref' => '#/components/schemas/Form',
    ),
    'POST /knowledge/articles' => 
    array (
      '$ref' => '#/components/schemas/KnowledgeArticle',
    ),
    'POST /knowledge/articles/{id}/restore' => 
    array (
      '$ref' => '#/components/schemas/KnowledgeArticle',
    ),
    'POST /morning-checks/checks' => 
    array (
      '$ref' => '#/components/schemas/MorningCheck',
    ),
    'POST /morning-checks/results' => 
    array (
      '$ref' => '#/components/schemas/MorningCheckResult',
    ),
    'POST /network-diagrams' => 
    array (
      '$ref' => '#/components/schemas/NetworkDiagram',
    ),
    'POST /network-diagrams/{id}/connectors' => 
    array (
      '$ref' => '#/components/schemas/NetworkConnector',
    ),
    'POST /network-diagrams/{id}/nodes' => 
    array (
      'description' => 'A single node object (body = one node), or an array of nodes when the body was {"nodes": [...]}.',
      'oneOf' => 
      array (
        0 => 
        array (
          '$ref' => '#/components/schemas/NetworkNode',
        ),
        1 => 
        array (
          'type' => 'array',
          'items' => 
          array (
            '$ref' => '#/components/schemas/NetworkNode',
          ),
        ),
      ),
    ),
    'POST /network-diagrams/{id}/versions' => 
    array (
      '$ref' => '#/components/schemas/NetworkDiagram',
    ),
    'POST /problems' => 
    array (
      '$ref' => '#/components/schemas/Problem',
    ),
    'POST /problems/{id}/changes' => 
    array (
      '$ref' => '#/components/schemas/ProblemChangeLinkResult',
    ),
    'POST /problems/{id}/notes' => 
    array (
      '$ref' => '#/components/schemas/ProblemNoteCreated',
    ),
    'POST /problems/{id}/tickets' => 
    array (
      '$ref' => '#/components/schemas/ProblemTicketLinkResult',
    ),
    'POST /service-status/incidents' => 
    array (
      '$ref' => '#/components/schemas/StatusIncident',
    ),
    'POST /service-status/services' => 
    array (
      '$ref' => '#/components/schemas/StatusService',
    ),
    'POST /software/licences' => 
    array (
      '$ref' => '#/components/schemas/SoftwareLicence',
    ),
    'POST /suppliers' => 
    array (
      '$ref' => '#/components/schemas/Supplier',
    ),
    'POST /suppliers/{id}/contacts' => 
    array (
      '$ref' => '#/components/schemas/SupplierContact',
    ),
    'POST /tasks' => 
    array (
      '$ref' => '#/components/schemas/Task',
    ),
    'POST /tasks/{id}/comments' => 
    array (
      '$ref' => '#/components/schemas/TaskCommentCreateAck',
    ),
    'POST /tasks/{id}/move' => 
    array (
      '$ref' => '#/components/schemas/Task',
    ),
    'POST /tickets' => 
    array (
      '$ref' => '#/components/schemas/Ticket',
    ),
    'POST /tickets/{id}/notes' => 
    array (
      '$ref' => '#/components/schemas/TicketNoteCreated',
    ),
    'POST /tickets/{id}/restore' => 
    array (
      '$ref' => '#/components/schemas/Ticket',
    ),
    'POST /tickets/{id}/time-entries' => 
    array (
      '$ref' => '#/components/schemas/TimeEntryCreated',
    ),
    'POST /users' => 
    array (
      '$ref' => '#/components/schemas/Requester',
    ),
    'POST /workflows' => 
    array (
      '$ref' => '#/components/schemas/Workflow',
    ),
    'POST /workflows/{id}/fire' => 
    array (
      '$ref' => '#/components/schemas/WorkflowFireResult',
    ),
  ),
  'requestBodies' => 
  array (
  ),
);
