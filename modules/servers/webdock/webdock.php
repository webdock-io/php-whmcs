<?php

/**
 * Webdock VPS Provisioning Module for WHMCS
 *
 * Translates WHMCS billing lifecycle events (create / suspend / unsuspend /
 * terminate) into Webdock REST API calls. Nothing else. No customer UI.
 *
 * ============================================================
 * WHMCS ADMIN SETUP CHECKLIST (required before activating)
 * ============================================================
 *
 * 1. Product custom field
 *    Products/Services → your product → Custom Fields → Add New
 *    Field Name : VPS Slug
 *    Type       : Text
 *    Client can view: Yes   |   Client can edit: No
 *
 * 2. Welcome email template
 *    Setup → Email Templates → Product/Service → Create New
 *    Name: Webdock VPS Welcome
 *    Merge fields: {$customvars.vps_slug}  {$customvars.vps_ip}  {$customvars.vps_name}
 *
 * 3. Automation timing
 *    Setup → Automation Settings → set grace period & termination days.
 *    WHMCS fires SuspendAccount / TerminateAccount automatically — this
 *    module just responds to those hooks.
 *
 * 4. Webdock delete privileges
 *    Contact Webdock support to enable server deletion for your reseller
 *    API token. Until enabled TerminateAccount returns 401 — requires
 *    manual deletion from the Webdock dashboard.
 *
 * ============================================================
 * Webdock API reference: https://api.webdock.io/v1
 * Base URL: https://api.webdock.io/v1
 * ============================================================
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

// ---------------------------------------------------------------------------
// Metadata
// ---------------------------------------------------------------------------

/**
 * Module metadata consumed by WHMCS.
 *
 * @return array
 */
function webdock_MetaData()
{
    return [
        'DisplayName'              => 'Webdock VPS',
        'APIVersion'               => '1.1',
        'RequiresServer'           => true,
        'DefaultNonSSLPort'        => '',
        'DefaultSSLPort'           => '',
        'ServiceSingleSignOnLabel' => false,
        'AdminSingleSignOnLabel'   => false,
    ];
}

// ---------------------------------------------------------------------------
// Config options (admin-set per product — not customer-facing)
// ---------------------------------------------------------------------------

/**
 * Admin-configurable fields shown on the product's Module Settings tab.
 *
 * Customers choose plans on the Webdock Next.js frontend. These fields are
 * set once by the admin when creating a WHMCS product, not by customers.
 *
 * @return array
 */
function webdock_ConfigOptions()
{
    return [
        // configoption1
        'webdock_api_token' => [
            'FriendlyName' => 'Webdock API Token',
            'Type'         => 'password',
            'Size'         => 100,
            'Default'      => '',
            'Description'  => 'From webdock.io → Account → API Tokens',
        ],
        // configoption2
        'location_id' => [
            'FriendlyName' => 'Location ID (default)',
            'Type'         => 'text',
            'Size'         => 20,
            'Default'      => '',
            'Description'  => 'Default location ID e.g. dk, fi, us — from GET /locations. Overridden by configurable option "Location ID".',
        ],
        // configoption3
        'profile_slug' => [
            'FriendlyName' => 'Profile Slug (default)',
            'Type'         => 'text',
            'Size'         => 50,
            'Default'      => '',
            'Description'  => 'Default hardware profile slug e.g. cloud.2 — from GET /profiles. Overridden by configurable option "Profile Slug".',
        ],
        // configoption4
        'image_slug' => [
            'FriendlyName' => 'Images (default)',
            'Type'         => 'text',
            'Size'         => 100,
            'Default'      => '',
            'Description'  => 'Default OS image name or slug e.g. AlmaLinux 10 or webdock-almalinux-10-cloud — overridden by configurable options like "Image Slug" or "Images".',
        ],
        // configoption5
        'abuse_notify_email' => [
            'FriendlyName' => 'Abuse Notify Email',
            'Type'         => 'text',
            'Size'         => 100,
            'Default'      => '',
            'Description'  => 'Internal email address for abuse suspension alerts (optional)',
        ],
    ];
}

// ---------------------------------------------------------------------------
// Lifecycle functions
// ---------------------------------------------------------------------------

/**
 * Provision a new VPS when a customer's order is paid.
 *
 * @param array $params WHMCS module parameters
 *
 * @return string 'success' on success, otherwise an error message string
 */
function webdock_CreateAccount(array $params)
{

    // TEMP DEBUG — remove after diagnosis
    logModuleCall(
        'webdock',
        'CreateAccount:PARAMS-DEBUG',
        [
            'customfields'  => $params['customfields'] ?? 'NOT SET',
            'configoptions' => $params['configoptions'] ?? 'NOT SET',
            'domain'        => $params['domain'] ?? '',
            'hostname'      => $params['hostname'] ?? '',
            'configoption1' => '(hidden)',
            'configoption2' => $params['configoption2'] ?? '',
            'configoption3' => $params['configoption3'] ?? '',
            'configoption4' => $params['configoption4'] ?? '',
        ],
        'Raw params dump for field resolution diagnosis',
        'debug',
        [$params['configoption1'] ?? '']
    );

    $apiToken = $params['configoption1'];

    // Webdock: resolution priority — service custom fields (set by the frontend/admin
    // before the order) take precedence, configurable options (checkout selections)
    // override them, and module settings (configoption2-4) serve as last-resort defaults.
    //
    // Configurable option group names recognised (case-insensitive aliases supported):
    //   Location ID, Profile Slug, Image Slug / Images / Operating System
    //   Custom Platform / Platform, CPU Threads / CPU / vCPU, RAM (GB) / RAM,
    //   Disk Space (GB) / Disk, Network Bandwidth (Gbit/s) / Bandwidth
    $locationId  = webdock_get_customfield_value($params, ['Location ID']);
    $profileSlug = webdock_get_customfield_value($params, ['Profile Slug']);
    $imageSlug   = webdock_get_customfield_value($params, ['Image Slug', 'Images', 'Image', 'Operating System', 'OS Image']);

    // Configurable options (checkout) override custom-field values when present.
    $coLocationId  = webdock_get_configoption_value($params, ['Location ID']);
    $coProfileSlug = webdock_get_configoption_value($params, ['Profile Slug']);
    $coImageSlug   = webdock_get_configoption_value($params, ['Image Slug', 'Images', 'Image', 'Operating System', 'OS Image']);
    if ($coLocationId !== '') {
        $locationId  = $coLocationId;
    }
    if ($coProfileSlug !== '') {
        $profileSlug = $coProfileSlug;
    }
    if ($coImageSlug !== '') {
        $imageSlug   = $coImageSlug;
    }

    // Module settings are the last-resort fallback.
    if ($locationId === '') {
        $locationId = (string) ($params['configoption2'] ?? '');
    }
    if ($profileSlug === '') {
        $profileSlug = (string) ($params['configoption3'] ?? '');
    }
    if ($imageSlug === '') {
        $imageSlug = (string) ($params['configoption4'] ?? '');
    }
    $profileSlugSource = 'configured/default';

    // Default location fallback when not provided in either configurable
    // options or module settings.
    $locationId = trim((string) $locationId);
    if ($locationId === '') {
        $locationId = 'dk';
    }

    // Default profile/image fallbacks when not provided in either configurable
    // options or module settings.
    $profileSlug = trim((string) $profileSlug);
    if ($profileSlug === '') {
        $profileSlug = 'cloud.2';
    }

    $imageSlug = trim((string) $imageSlug);
    if ($imageSlug === '') {
        $imageSlug = 'webdock-ubuntu-jammy-cloud';
    }

    $rawImageSelection = $imageSlug;

    // Webdock: Resolve image slug — admins and customers may specify a human-
    // readable image name (e.g. "Noble LEMP", "Ubuntu Jammy 22.04") instead
    // of the raw Webdock slug. Matching is case-insensitive and trims whitespace.
    // If no name matches the value is assumed to already be a valid slug and
    // passed through unchanged.
    $imageSlug = webdock_resolve_image_slug($imageSlug);

    // Optional: create a custom profile on-the-fly when all hardware fields are
    // provided. Custom fields are read first; configurable options override them.
    // Canonical field names (aliases supported — matching is case-insensitive):
    //   Platform : "Custom Platform", "Platform"
    //   CPU      : "CPU Threads", "CPU", "vCPU", "Threads"
    //   RAM      : "RAM (GB)", "RAM", "Memory", "Memory (GB)"
    //   Disk     : "Disk Space (GB)", "Disk (GB)", "Disk", "Storage", "Storage (GB)"
    //   Network  : "Network Bandwidth (Gbit/s)", "Network (Gbit)", "Network", "Bandwidth"

    // Alias key lists used for custom field + configurable option resolution.
    $platformKeys = ['Custom Platform', 'Platform'];
    $cpuKeys      = ['CPU Threads', 'CPU', 'vCPU', 'Threads'];
    $ramKeys      = ['RAM (GB)', 'RAM', 'Memory (GB)', 'Memory'];
    $diskKeys     = ['Disk Space (GB)', 'Disk (GB)', 'Disk', 'Storage (GB)', 'Storage'];
    $networkKeys  = ['Network Bandwidth (Gbit/s)', 'Network (Gbit)', 'Network', 'Bandwidth (Gbit)', 'Bandwidth'];

    // Custom fields are the primary source (set by the frontend/admin before ordering).
    $customPlatform = trim(webdock_get_customfield_value($params, $platformKeys));
    $cpuThreads     = webdock_parse_int_value(webdock_get_customfield_value($params, $cpuKeys));
    $ramGb          = webdock_parse_int_value(webdock_get_customfield_value($params, $ramKeys));
    $diskGb         = webdock_parse_int_value(webdock_get_customfield_value($params, $diskKeys));
    $networkGbit    = webdock_parse_int_value(webdock_get_customfield_value($params, $networkKeys));

    // Configurable options override custom fields if explicitly selected at checkout.
    $coCustomPlatform = trim(webdock_get_configoption_value($params, $platformKeys));
    $coCpuThreads     = webdock_parse_int_value(webdock_get_configoption_value($params, $cpuKeys));
    $coRamGb          = webdock_parse_int_value(webdock_get_configoption_value($params, $ramKeys));
    $coDiskGb         = webdock_parse_int_value(webdock_get_configoption_value($params, $diskKeys));
    $coNetworkGbit    = webdock_parse_int_value(webdock_get_configoption_value($params, $networkKeys));
    if ($coCustomPlatform !== '') {
        $customPlatform = $coCustomPlatform;
    }
    if ($coCpuThreads > 0) {
        $cpuThreads = $coCpuThreads;
    }
    if ($coRamGb > 0) {
        $ramGb = $coRamGb;
    }
    if ($coDiskGb > 0) {
        $diskGb = $coDiskGb;
    }
    if ($coNetworkGbit > 0) {
        $networkGbit = $coNetworkGbit;
    }

    // Normalise platform value — accept human-friendly labels in addition to
    // the exact API identifiers. Matching is case-insensitive.
    //   epyc_vps  : "epyc_vps", "epyc", "amd", "amd epyc"
    //   intel_vps : "intel_vps", "intel", "intel vps"
    $platformNorm = strtolower($customPlatform);
    if (in_array($platformNorm, ['epyc_vps', 'epyc', 'amd', 'amd epyc', 'amd_epyc'], true)) {
        $customPlatform = 'epyc_vps';
    } elseif (in_array($platformNorm, ['intel_vps', 'intel', 'intel vps'], true)) {
        $customPlatform = 'intel_vps';
    }

    // If all four hardware specs are provided but Custom Platform was left blank,
    // default to intel_vps so the custom profile is still created.
    // This is the normal frontend flow where the user fills CPU/RAM/Disk/Network
    // without explicitly choosing a platform.
    if ($customPlatform === '' && $cpuThreads > 0 && $ramGb > 0 && $diskGb > 0 && $networkGbit > 0) {
        $customPlatform = 'intel_vps';

        logModuleCall(
            'webdock',
            'CreateAccount:platform-defaulted',
            [
                'serviceid'    => $params['serviceid'] ?? null,
                'cpuThreads'   => $cpuThreads,
                'ramGb'        => $ramGb,
                'diskGb'       => $diskGb,
                'networkGbit'  => $networkGbit,
            ],
            'Custom Platform was empty — defaulted to intel_vps because all hardware specs are present',
            'platform default',
            [$apiToken]
        );
    }

    // Trigger custom profile creation when platform is set (explicitly or defaulted above).
    $hasCustomProfileSelection = ($customPlatform !== '');

    if ($hasCustomProfileSelection) {
        $isCompleteCustomProfileInput =
            in_array($customPlatform, ['epyc_vps', 'intel_vps'], true)
            && ($cpuThreads > 0)
            && ($ramGb > 0)
            && ($diskGb > 0)
            && ($networkGbit > 0);

        if (!$isCompleteCustomProfileInput) {
            // Hard-fail: the admin/frontend clearly intended a custom profile
            // (Custom Platform is set) but one or more required fields are
            // missing. Silently falling back to the Profile Slug would
            // provision the wrong server — abort instead.
            $missing = [];
            if (!in_array($customPlatform, ['epyc_vps', 'intel_vps'], true)) {
                $missing[] = 'Custom Platform (must be intel_vps or epyc_vps, got: "' . $customPlatform . '")';
            }
            if ($cpuThreads <= 0) {
                $missing[] = 'CPU Threads';
            }
            if ($ramGb <= 0) {
                $missing[] = 'RAM (GB)';
            }
            if ($diskGb <= 0) {
                $missing[] = 'Disk Space (GB)';
            }
            if ($networkGbit <= 0) {
                $missing[] = 'Network Bandwidth (Gbit/s)';
            }

            logModuleCall(
                'webdock',
                'CreateProfile:incomplete',
                [
                    'serviceid'      => $params['serviceid'] ?? null,
                    'customPlatform' => $customPlatform,
                    'cpuThreads'     => $cpuThreads,
                    'ramGb'          => $ramGb,
                    'diskGb'         => $diskGb,
                    'networkGbit'    => $networkGbit,
                    'missing'        => $missing,
                ],
                'Custom platform set but profile inputs incomplete — aborting provisioning',
                'custom profile aborted',
                [$apiToken]
            );

            return 'Provisioning aborted: "Custom Platform" is set to "' . $customPlatform . '" '
                . 'but the following required custom profile fields are missing or zero: '
                . implode(', ', $missing) . '. '
                . 'Set all five fields (Custom Platform, CPU Threads, RAM (GB), Disk Space (GB), Network Bandwidth (Gbit/s)) '
                . 'or clear "Custom Platform" to use a standard Profile Slug instead.';
        } else {

            $customProfilePayload = [
                'platform'          => $customPlatform,
                'cpu_threads'       => $cpuThreads,
                'ram'               => $ramGb,
                'disk_space'        => $diskGb,
                'network_bandwidth' => $networkGbit,
            ];

            $profileCreateResult = webdock_webdock_request('POST', '/profiles', $apiToken, $customProfilePayload);

            logModuleCall(
                'webdock',
                'CreateProfile',
                $customProfilePayload,
                ['status' => $profileCreateResult['status'], 'body' => $profileCreateResult['body']],
                $profileCreateResult['ok'] ? 'custom profile created' : 'custom profile creation failed',
                [$apiToken]
            );

            if (!$profileCreateResult['ok']) {
                return 'Failed to create custom profile: '
                    . webdock_stringify_error_detail($profileCreateResult['message'] ?? ('Webdock profile creation error ' . $profileCreateResult['status']));
            }

            $createdProfileSlug = $profileCreateResult['body']['slug'] ?? '';
            if (empty($createdProfileSlug)) {
                return 'Webdock created a custom profile but did not return a profile slug.';
            }

            $profileSlug = $createdProfileSlug;
            $profileSlugSource = 'custom-created';
        }
    }

    // Resolve profile slug against profiles available for the selected location.
    // This prevents Webdock 400 errors like "Selected profile is not valid"
    // when a configured default profile is not available in that location.
    if ($profileSlugSource !== 'custom-created') {
        $resolvedProfile = webdock_resolve_profile_slug_for_location($apiToken, $locationId, $profileSlug);
        if (!empty($resolvedProfile['slug']) && $resolvedProfile['slug'] !== $profileSlug) {
            logModuleCall(
                'webdock',
                'CreateAccount:profile-resolved',
                [
                    'serviceid'        => $params['serviceid'] ?? null,
                    'locationId'       => $locationId,
                    'requestedProfile' => $profileSlug,
                    'resolvedProfile'  => $resolvedProfile['slug'],
                    'reason'           => $resolvedProfile['reason'] ?? 'location profile resolution',
                ],
                'Adjusted profile slug to a location-valid profile before provisioning',
                'profile resolved',
                [$apiToken]
            );
            $profileSlug = $resolvedProfile['slug'];
            $profileSlugSource = 'location-resolved';
        }
    }

    if (empty($locationId) || empty($profileSlug) || empty($imageSlug)) {
        return 'Provisioning requires Location ID, Profile Slug (or complete custom profile inputs), and Image Slug.';
    }

    // Webdock: double-provision guard — if the VPS Slug custom field is already
    // populated this service was already provisioned (e.g. admin triggered twice,
    // or WHMCS retried). Abort immediately rather than duplicating the server.
    $existingSlug = webdock_get_customfield_value($params, ['VPS Slug']);
    if (!empty($existingSlug)) {
        logModuleCall(
            'webdock',
            'CreateAccount:skipped',
            ['serviceid' => $params['serviceid'], 'reason' => 'VPS Slug already set'],
            'Provisioning skipped — slug already exists: ' . $existingSlug,
            'double-provision guard triggered',
            [$apiToken]
        );
        return 'success';
    }

    // Webdock: server name — use "Server Name" custom field if set, then fall
    // back to the service hostname/domain, then a generated name for traceability.
    $serverName = trim(webdock_get_customfield_value($params, ['Server Name', 'VPS Name', 'Name']));
    if ($serverName === '') {
        $serverName = trim((string) ($params['domain'] ?? ''));
    }
    if ($serverName === '') {
        $serverName = trim((string) ($params['hostname'] ?? ''));
    }
    if ($serverName === '') {
        $serverName = 'webdock-' . $params['clientsdetails']['userid'] . '-' . $params['serviceid'];
    }

    $requestBody = [
        'name'        => $serverName,
        'locationId'  => $locationId,
        'profileSlug' => $profileSlug,
        'imageSlug'   => $imageSlug,
    ];

    logModuleCall(
        'webdock',
        'CreateAccount:resolved-inputs',
        [
            'serviceid'         => $params['serviceid'] ?? null,
            'locationId'        => $locationId,
            'profileSlug'       => $profileSlug,
            'profileSlugSource' => $profileSlugSource,
            'imageSlug'         => $imageSlug,
            'customPlatform'    => $customPlatform,
            'cpuThreads'        => $cpuThreads,
            'ramGb'             => $ramGb,
            'diskGb'            => $diskGb,
            'networkGbit'       => $networkGbit,
            'hasCustomProfile'  => $hasCustomProfileSelection,
        ],
        'Resolved provisioning inputs ready for /servers request',
        'input resolution',
        [$apiToken]
    );

    $result = webdock_webdock_request('POST', '/servers', $apiToken, $requestBody);

    logModuleCall(
        'webdock',
        'CreateAccount',
        $requestBody,
        ['status' => $result['status'], 'body' => $result['body']],
        $result['ok'] ? 'provisioning accepted' : 'provisioning failed',
        [$apiToken]
    );

    if (!$result['ok']) {
        return webdock_stringify_error_detail($result['message'] ?? ('Webdock API error ' . $result['status']));
    }

    $slug       = $result['body']['slug']  ?? '';
    $ipv4       = $result['body']['ipv4']  ?? '';
    $ipv6       = $result['body']['ipv6']  ?? '';
    $callbackId = $result['headers']['x-callback-id'] ?? '';

    if (empty($slug)) {
        return 'Webdock returned success but no slug in response body. Check Webdock dashboard.';
    }

    if (!empty($serverName)) {
        localAPI('UpdateClientProduct', [
            'serviceid' => $params['serviceid'],
            'hostname'  => $serverName,
        ]);
    }

    // Webdock: Critical Data Contract — the Next.js frontend's verifyOwnership()
    // reads the WHMCS service Domain field and compares it to the requested slug.
    // If this is empty every customer gets a 404 on their VPS dashboard page.
    localAPI('UpdateClientProduct', [
        'serviceid' => $params['serviceid'],
        'domain'    => $slug,
    ]);

    // Webdock: Persist key provisioning values for later lifecycle actions.
    // The helper resolves field names to integer IDs (required by UpdateClientProduct).
    webdock_update_service_customfields($params, [
        'VPS Slug'                   => $slug,
        'Provisioned Server Name'    => $serverName,
        'Location ID'                => $locationId,
        'Profile Slug'               => $profileSlug,
        'Images'                     => $rawImageSelection,
        'Image Slug'                 => $imageSlug,
        'CPU Threads'                => ($cpuThreads > 0 ? (string) $cpuThreads : ''),
        'RAM (GB)'                   => ($ramGb > 0 ? (string) $ramGb : ''),
        'Disk Space (GB)'            => ($diskGb > 0 ? (string) $diskGb : ''),
        'Network Bandwidth (Gbit/s)' => ($networkGbit > 0 ? (string) $networkGbit : ''),
        'Custom Platform'            => $customPlatform,
        'Provisioned Profile Slug'   => $profileSlug,
        'Provisioned Image Slug'     => $imageSlug,
        'Provisioned Location ID'    => $locationId,
    ]);

    // Create is async and response may not include current network values.
    // Pull the latest server DTO and update WHMCS fields from it.
    $syncResult = webdock_sync_service_server_data($params, $slug, $apiToken, 'post-create');
    if (!empty($syncResult['ipv4'])) {
        $ipv4 = $syncResult['ipv4'];
    }
    if (!empty($syncResult['ipv6'])) {
        $ipv6 = $syncResult['ipv6'];
    }

    // Store IP in the Dedicated IP field for visibility in the admin panel.
    if (!empty($ipv4)) {
        localAPI('UpdateClientProduct', [
            'serviceid'   => $params['serviceid'],
            'dedicatedip' => $ipv4,
        ]);
    }

    // Webdock: log the async callback ID so the admin can track provisioning
    // progress in Webdock if needed. Create is async — we return success now.
    if (!empty($callbackId)) {
        logModuleCall(
            'webdock',
            'CreateAccount:callback',
            ['slug' => $slug, 'service_id' => $params['serviceid']],
            'x-callback-id: ' . $callbackId,
            'async provisioning in progress on Webdock',
            [$apiToken]
        );
    }

    // Webdock: Send welcome email. Admin must create "Webdock VPS Welcome" in
    // WHMCS → Setup → Email Templates → Product/Service with merge fields:
    //   {$customvars.vps_slug}   {$customvars.vps_ip}   {$customvars.vps_name}
    localAPI('SendEmail', [
        'messagename' => 'Webdock VPS Welcome',
        'id'          => $params['serviceid'],
        'customvars'  => base64_encode(serialize([
            'vps_slug' => $slug,
            'vps_ip'   => $ipv4,
            'vps_ipv6' => $ipv6,
            'vps_name' => $serverName,
        ])),
    ]);

    return 'success';
}

/**
 * Suspend a VPS when an invoice becomes overdue.
 *
 * Webdock: Uses /actions/stop so WHMCS suspend means power off.
 *
 * @param array $params WHMCS module parameters
 *
 * @return string 'success' on success, otherwise an error message string
 */
function webdock_SuspendAccount(array $params)
{
    $apiToken = $params['configoption1'];

    // Webdock: WHMCS stores the slug in the service Domain field (set by CreateAccount).
    $slug = $params['domain'] ?? '';
    if (empty($slug)) {
        return 'Cannot suspend — VPS slug missing from service domain field.';
    }

    $result = webdock_webdock_request('POST', '/servers/' . rawurlencode($slug) . '/actions/stop', $apiToken);

    logModuleCall(
        'webdock',
        'SuspendAccount',
        ['slug' => $slug, 'service_id' => $params['serviceid']],
        ['status' => $result['status'], 'body' => $result['body']],
        $result['ok'] ? 'stopped' : 'stop failed',
        [$apiToken]
    );

    if (!$result['ok']) {
        return webdock_stringify_error_detail($result['message'] ?? ('Webdock stop error ' . $result['status']));
    }

    return 'success';
}

/**
 * Unsuspend (restore) a VPS when an overdue invoice is paid.
 *
 * Webdock: Uses /actions/start to power on after a billing suspension.
 *
 * @param array $params WHMCS module parameters
 *
 * @return string 'success' on success, otherwise an error message string
 */
function webdock_UnsuspendAccount(array $params)
{
    $apiToken = $params['configoption1'];

    $slug = $params['domain'] ?? '';
    if (empty($slug)) {
        return 'Cannot unsuspend — VPS slug missing from service domain field.';
    }

    $result = webdock_webdock_request('POST', '/servers/' . rawurlencode($slug) . '/actions/start', $apiToken);

    logModuleCall(
        'webdock',
        'UnsuspendAccount',
        ['slug' => $slug, 'service_id' => $params['serviceid']],
        ['status' => $result['status'], 'body' => $result['body']],
        $result['ok'] ? 'started' : 'start failed',
        [$apiToken]
    );

    if (!$result['ok']) {
        return webdock_stringify_error_detail($result['message'] ?? ('Webdock start error ' . $result['status']));
    }

    return 'success';
}

/**
 * Terminate (permanently delete) a VPS.
 *
 * Webdock: DELETE requires special Webdock privileges on the reseller token.
 * Contact Webdock support to enable. Until that is done this will return 401
 * and the admin must delete manually from the Webdock dashboard.
 *
 * @param array $params WHMCS module parameters
 *
 * @return string 'success' on success, otherwise an error message string
 */
function webdock_TerminateAccount(array $params)
{
    $apiToken = $params['configoption1'];

    $slug = $params['domain'] ?? '';
    if (empty($slug)) {
        return 'Cannot terminate — VPS slug missing from service domain field.';
    }

    $result = webdock_webdock_request('DELETE', '/servers/' . rawurlencode($slug), $apiToken);

    // Webdock: log the async callback ID — DELETE is async, the server is
    // queued for deletion on Webdock. We return success once request is accepted.
    $callbackId = $result['headers']['x-callback-id'] ?? '';

    logModuleCall(
        'webdock',
        'TerminateAccount',
        ['slug' => $slug, 'service_id' => $params['serviceid']],
        [
            'status'       => $result['status'],
            'body'         => $result['body'],
            'x-callback-id' => $callbackId,
        ],
        $result['ok'] ? 'delete accepted' : 'delete failed',
        [$apiToken]
    );

    if ($result['ok']) {
        return 'success';
    }

    // Webdock: 401/403 means the reseller token lacks delete privileges.
    // Surface a clear, actionable message — never silently fail a termination.
    if (in_array($result['status'], [401, 403], true)) {
        return 'Termination failed: your Webdock API token does not have server deletion '
            . 'privileges. Delete manually from the Webdock dashboard (slug: ' . $slug . '). '
            . 'Contact Webdock support to enable deletion for your reseller account.';
    }

    return webdock_stringify_error_detail($result['message'] ?? ('Webdock delete error ' . $result['status']));
}

// ---------------------------------------------------------------------------
// ---------------------------------------------------------------------------
// Client area
// ---------------------------------------------------------------------------

/**
 * Define which client-triggered button labels map to which action functions.
 * Only buttons whose labels appear in the rendered template will be callable.
 *
 * @return array
 */
function webdock_ClientAreaCustomButtonArray()
{
    return [
        'Start Server'      => 'StartServer',
        'Stop Server'       => 'StopServer',
        'Reboot Server'     => 'RebootServer',
        'Reinstall Server'  => 'ReinstallServer',
        'Create Snapshot'   => 'CreateSnapshot',
        'Restore Snapshot'  => 'RestoreSnapshot',
        'Delete Snapshot'   => 'DeleteSnapshot',
    ];
}

/**
 * Client area output — fetch live server status from Webdock and pass
 * state-aware variables to clientarea.tpl.
 *
 * @param array $params WHMCS module parameters
 *
 * @return array
 */
function webdock_ClientArea(array $params)
{
    $apiToken      = $params['configoption1'] ?? '';
    $whmcsStatus   = strtolower(trim((string) ($params['status'] ?? 'active')));

    // Resolve slug: domain field first, then custom fields, then hostname.
    $slug = trim((string) ($params['domain'] ?? ''));
    if ($slug === '') {
        $slug = webdock_get_customfield_value($params, ['VPS Slug', 'Provisioned Server Name', 'Server Slug']);
    }
    if ($slug === '') {
        $slug = trim((string) ($params['hostname'] ?? ''));
    }
    // Make sure the snapshot helper also uses the resolved slug.
    $params['domain'] = $slug;

    // Handle client area button actions directly — WHMCS's ClientAreaCustomButtonArray
    // dispatch requires a server group (RequiresServer=true) which we don't use, so we
    // intercept POST actions here instead.
    $actionMessage = '';
    $actionSuccess = null;
    if (
        isset($_POST['customAction'])
        && $whmcsStatus === 'active'
        && $slug !== ''
    ) {
        $customAction = (string) $_POST['customAction'];
        $allowedActions = [
            'Start Server'      => 'webdock_StartServer',
            'Stop Server'       => 'webdock_StopServer',
            'Reboot Server'     => 'webdock_RebootServer',
            'Reinstall Server'  => 'webdock_ReinstallServer',
            'Create Snapshot'   => 'webdock_CreateSnapshot',
            'Restore Snapshot'  => 'webdock_RestoreSnapshot',
            'Delete Snapshot'   => 'webdock_DeleteSnapshot',
            'Create Shell User' => 'webdock_CreateShellUser',
            'Delete Shell User' => 'webdock_DeleteShellUser',
        ];
        if (isset($allowedActions[$customAction])) {
            $fn = $allowedActions[$customAction];
            $result = $fn($params);
            $actionSuccess = ($result === 'success');
            if ($actionSuccess && $customAction === 'Create Shell User') {
                // Show password once — it cannot be retrieved after this page load.
                $shownUser = htmlspecialchars(
                    trim((string) (filter_input(INPUT_POST, 'newShellUsername') ?? 'admin')),
                    ENT_QUOTES,
                    'UTF-8'
                );
                $shownPass = htmlspecialchars(
                    trim((string) (filter_input(INPUT_POST, 'newShellPassword') ?? '')),
                    ENT_QUOTES,
                    'UTF-8'
                );
                $actionMessage = 'Shell user <strong>' . $shownUser . '</strong> created successfully. '
                    . 'Password: <code>' . $shownPass . '</code> &mdash; '
                    . '<strong>Save this now.</strong> It will not be shown again.';
            } elseif ($actionSuccess) {
                $actionMessage = ucwords(strtolower($customAction)) . ' completed successfully.';
            } else {
                $actionMessage = 'Action failed: ' . $result;
            }
        }
    }

    // Curated image list for the client-area reinstall dropdown.
    // Canonical name → slug (no aliases). Kept in sync with webdock_resolve_image_slug().
    $reinstallImages = [
        ['label' => 'Ubuntu Noble 24.04',            'slug' => 'webdock-ubuntu-noble-cloud'],
        ['label' => 'Ubuntu Jammy 22.04',            'slug' => 'webdock-ubuntu-jammy-cloud'],
        ['label' => 'AlmaLinux 10',                  'slug' => 'webdock-almalinux-10-cloud'],
        ['label' => 'AlmaLinux 9',                   'slug' => 'webdock-almalinux-9-cloud'],
        ['label' => 'CentOS 10',                     'slug' => 'webdock-centos-10-cloud'],
        ['label' => 'CentOS 9',                      'slug' => 'webdock-centos-9-cloud'],
        ['label' => 'Debian 13 Trixie',              'slug' => 'webdock-debian-trixie-cloud'],
        ['label' => 'Debian 12 Bookworm',            'slug' => 'webdock-debian-bookworm-cloud'],
        ['label' => 'Ubuntu GNOME Desktop',          'slug' => 'webdock-ubuntu-noble-gnome-desktop'],
        ['label' => 'Ubuntu KDE Plasma Desktop',     'slug' => 'webdock-ubuntu-noble-kdeplasma-desktop'],
        ['label' => 'Noble LEMP Stack',              'slug' => 'krellide:webdock-noble-lemp'],
        ['label' => 'Noble LAMP Stack',              'slug' => 'krellide:webdock-noble-lamp'],
    ];

    // Blocked states — no live API call needed, show a static banner.
    $blockedStates = [
        'suspended'  => [
            'type'    => 'suspended',
            'title'   => 'Service Suspended',
            'message' => 'Your service is suspended due to a past-due invoice. Please settle the outstanding balance to restore access.',
            'badge'   => 'warning',
        ],
        'cancelled'  => [
            'type'    => 'cancelled',
            'title'   => 'Service Cancelled',
            'message' => 'This service has been cancelled. No further actions are available.',
            'badge'   => 'danger',
        ],
        'terminated' => [
            'type'    => 'terminated',
            'title'   => 'Service Terminated',
            'message' => 'This service has been terminated and the server has been deleted.',
            'badge'   => 'danger',
        ],
        'pending'    => [
            'type'    => 'pending',
            'title'   => 'Provisioning in Progress',
            'message' => 'Your server is being set up. This usually takes a few minutes. This page will reflect the live status once provisioning completes.',
            'badge'   => 'info',
        ],
        'fraud'      => [
            'type'    => 'fraud',
            'title'   => 'Account Action Required',
            'message' => 'This service has been flagged. Please contact support.',
            'badge'   => 'danger',
        ],
    ];

    if (isset($blockedStates[$whmcsStatus])) {
        return [
            'templatefile' => 'clientarea',
            'vars'         => array_merge($blockedStates[$whmcsStatus], [
                'serverStatus'       => $whmcsStatus,
                'canStart'           => false,
                'canStop'            => false,
                'canReboot'          => false,
                'canReinstall'       => false,
                'canCreateSnapshot'  => false,
                'canRestoreSnapshot' => false,
                'canDeleteSnapshot'  => false,
                'snapshots'          => [],
                'snapshotCount'      => 0,
                'snapshotError'      => '',
                'debugSlug'          => $slug,
                'ipv4'               => '',
                'ipv6'               => '',
                'slug'               => $slug,
                'serverName'         => '',
                'fetchError'         => '',
                'serviceid'          => $params['serviceid'] ?? '',
                'shellUsers'         => [],
                'shellUserCount'     => 0,
                'shellUserError'     => '',
                'reinstallImages'    => [],
                'actionMessage'      => $actionMessage,
                'actionSuccess'      => $actionSuccess,
            ]),
        ];
    }

    // Active service — fetch live status from Webdock.
    $serverStatus  = 'unknown';
    $serverName    = '';
    $ipv4          = '';
    $ipv6          = '';
    $fetchError    = '';

    if ($slug !== '' && $apiToken !== '') {
        $result = webdock_webdock_request('GET', '/servers/' . rawurlencode($slug), $apiToken);
        if ($result['ok'] && is_array($result['body'])) {
            $body         = $result['body'];
            $serverStatus = strtolower(trim((string) ($body['status'] ?? 'unknown')));
            $serverName   = (string) ($body['name'] ?? '');
            $ipv4         = (string) ($body['ipv4'] ?? '');
            $ipv6         = (string) ($body['ipv6'] ?? '');
        } else {
            $fetchError = webdock_stringify_error_detail($result['message'] ?? 'Could not fetch server status.');
        }
    } elseif ($slug === '') {
        $fetchError = 'Server is being provisioned — slug not yet assigned.';
    }

    // Map Webdock server status to allowed power actions.
    // Webdock statuses: provisioning, running, stopped, suspended, archived
    $canStart    = in_array($serverStatus, ['stopped', 'suspended', 'archived'], true);
    $canStop     = $serverStatus === 'running';
    $canReboot   = $serverStatus === 'running';
    $canReinstall = in_array($serverStatus, ['running', 'stopped'], true);

    // Status badge style
    $badgeMap = [
        'running'      => 'success',
        'stopped'      => 'secondary',
        'provisioning' => 'info',
        'suspended'    => 'warning',
        'archived'     => 'warning',
    ];
    $badge = $badgeMap[$serverStatus] ?? 'secondary';

    // Human-readable status label
    $labelMap = [
        'running'      => 'Running',
        'stopped'      => 'Stopped',
        'provisioning' => 'Provisioning',
        'suspended'    => 'Suspended',
        'archived'     => 'Archived',
    ];
    $statusLabel = $labelMap[$serverStatus] ?? ucfirst($serverStatus);

    // Fetch snapshots for the active server
    $snapshots     = [];
    $snapshotError = '';
    if ($slug !== '' && $apiToken !== '' && $serverStatus !== 'provisioning') {
        $snapResult = webdock_fetch_server_snapshots($params);

        if ($snapResult['ok']) {
            // Normalize snapshot fields for Smarty: cast booleans to strings,
            // and map API type values to template-friendly labels.
            $snapshots = array_map(static function (array $snap): array {
                $snap['completed'] = ($snap['completed'] === true || $snap['completed'] === 'true') ? 'true' : 'false';
                $snap['deletable'] = ($snap['deletable'] === true || $snap['deletable'] === 'true') ? 'true' : 'false';
                // API returns 'user' for manual snapshots; map to template labels.
                $typeMap = ['user' => 'manual', 'daily' => 'daily', 'weekly' => 'weekly'];
                $snap['type'] = $typeMap[strtolower((string) ($snap['type'] ?? ''))] ?? 'manual';
                return $snap;
            }, $snapResult['snapshots']);
        } else {
            $snapshotError = $snapResult['message'];
        }
    }

    // Allow snapshot creation whenever status is not definitively blocking it
    $canCreateSnapshot  = in_array($serverStatus, ['running', 'stopped', 'unknown'], true);
    $canRestoreSnapshot = in_array($serverStatus, ['running', 'stopped', 'unknown'], true) && !empty($snapshots);
    // Delete eligibility is per-snapshot (API `deletable` field); pass a general flag for template logic
    $canDeleteSnapshot  = !empty($snapshots);
    $snapshotCount      = count($snapshots);

    // Fetch shell users for the active server
    $shellUsers      = [];
    $shellUserError  = '';
    if ($slug !== '' && $apiToken !== '' && $serverStatus !== 'provisioning') {
        $shellLookup = webdock_fetch_shell_users($params);
        if ($shellLookup['ok']) {
            $shellUsers = $shellLookup['users'];
        } else {
            $shellUserError = $shellLookup['message'];
        }
    }
    $shellUserCount = count($shellUsers);

    return [
        'templatefile' => 'clientarea',
        'vars' => [
            'type'                => 'active',
            'serverStatus'        => $serverStatus,
            'statusLabel'         => $statusLabel,
            'badge'               => $badge,
            'serverName'          => $serverName,
            'ipv4'                => $ipv4,
            'ipv6'                => $ipv6,
            'slug'                => $slug,
            'canStart'            => $canStart,
            'canStop'             => $canStop,
            'canReboot'           => $canReboot,
            'canReinstall'        => $canReinstall,
            'fetchError'          => $fetchError,
            'title'               => '',
            'message'             => '',
            'serviceid'           => $params['serviceid'] ?? '',
            'snapshots'           => $snapshots,
            'snapshotError'       => $snapshotError,
            'canCreateSnapshot'   => $canCreateSnapshot,
            'canRestoreSnapshot'  => $canRestoreSnapshot,
            'canDeleteSnapshot'   => $canDeleteSnapshot,
            'snapshotCount'       => $snapshotCount,
            'debugSlug'           => $slug,
            'shellUsers'          => $shellUsers,
            'shellUserCount'      => $shellUserCount,
            'shellUserError'      => $shellUserError,
            'reinstallImages'     => $reinstallImages,
            'actionMessage'       => $actionMessage,
            'actionSuccess'       => $actionSuccess,
        ],
    ];
}

// ---------------------------------------------------------------------------
// Admin custom buttons
// ---------------------------------------------------------------------------

/**
 * @return string 'success' on success, otherwise an error message string
 */
function webdock_AdminCustomButtonArray()
{
    return [
        'Reboot Server'               => 'RebootServer',
        'Archive Server'              => 'ArchiveServer',
        'Refresh Server Data'         => 'RefreshServerData',
        'Reinstall Server'            => 'ReinstallServer',
        'Create Snapshot'             => 'CreateSnapshot',
        'List Snapshots'              => 'ListSnapshots',
        'Restore Snapshot'            => 'RestoreSnapshot',
        'Delete Snapshot'             => 'DeleteSnapshot',
        'Create Shell User'           => 'CreateShellUser',
        'Delete Shell User'           => 'DeleteShellUser',
        'List Shell Users'            => 'ListShellUsers',
        'Run Certbot'                 => 'RunCertbot',
        'Set SSH Settings'            => 'SetSshSettings',
        'Set Server Settings'         => 'SetServerSettings',
        'Dry Run Profile Change'      => 'ProfileChangeDryRun',
        'Change Server Profile'       => 'ChangeServerProfile',
        'Emergency Suspend (Abuse)' => 'EmergencyAbuseSuspend',
    ];
}

/**
 * Start server action.
 */
function webdock_StartServer(array $params)
{
    return webdock_run_server_action($params, '/actions/start', null, 'StartServer');
}

/**
 * Stop server action.
 */
function webdock_StopServer(array $params)
{
    return webdock_run_server_action($params, '/actions/stop', null, 'StopServer');
}

/**
 * Reboot server action.
 */
function webdock_RebootServer(array $params)
{
    return webdock_run_server_action($params, '/actions/reboot', null, 'RebootServer');
}

/**
 * Archive server action.
 */
function webdock_ArchiveServer(array $params)
{
    return webdock_run_server_action($params, '/actions/suspend', null, 'ArchiveServer');
}

/**
 * Refresh server data from Webdock and update WHMCS fields.
 */
function webdock_RefreshServerData(array $params)
{
    $apiToken = $params['configoption1'] ?? '';
    $slug = trim((string) ($params['domain'] ?? ''));
    if ($slug === '') {
        return 'Cannot refresh server data — VPS slug missing from service domain field.';
    }

    $sync = webdock_sync_service_server_data($params, $slug, $apiToken, 'manual-refresh');
    if (!$sync['ok']) {
        return 'Refresh failed: ' . webdock_stringify_error_detail($sync['message'] ?? 'Unknown error');
    }

    $metricsNow = webdock_webdock_request('GET', '/servers/' . rawurlencode($slug) . '/metrics/now', $apiToken);
    logModuleCall(
        'webdock',
        'RefreshServerData:metrics-now',
        ['slug' => $slug, 'service_id' => $params['serviceid'] ?? null],
        ['status' => $metricsNow['status'], 'body' => $metricsNow['body']],
        $metricsNow['ok'] ? 'metrics fetched' : 'metrics fetch failed',
        [$apiToken]
    );

    return 'success';
}

/**
 * Reinstall server action.
 */
function webdock_ReinstallServer(array $params)
{
    // Highest priority: client area passes reinstallImage via POST with an explicit slug.
    $postImage = trim((string) (filter_input(INPUT_POST, 'reinstallImage') ?? ''));
    if ($postImage !== '') {
        $imageSlug = webdock_resolve_image_slug($postImage);
        logModuleCall(
            'webdock',
            'ReinstallServer:image-selection',
            [
                'service_id'        => $params['serviceid'] ?? null,
                'imageSource'       => 'client-area-post',
                'rawImageSelection' => $postImage,
                'resolvedImageSlug' => $imageSlug,
            ],
            'Image selected by client via dropdown',
            'image selection',
            [$params['configoption1'] ?? '']
        );
        return webdock_run_server_action($params, '/actions/reinstall', ['imageSlug' => $imageSlug], 'ReinstallServer');
    }

    // Prefer explicit reinstall image selection from custom fields/configurable
    // options, then fall back to module default image.
    $imageSlug = trim(webdock_get_customfield_value($params, [
        'Reinstall Image Slug',
        'Reinstall Image',
        'Image Slug',
        'Image',
        'Operating System',
        'OS Image',
        'Template',
    ]));

    $imageSource = 'customfield';

    if ($imageSlug === '') {
        $imageSlug = trim(webdock_get_configoption_value($params, [
            'Reinstall Image Slug',
            'Reinstall Image',
            'Image Slug',
            'Image',
            'Operating System',
            'OS Image',
            'Template',
        ]));
        if ($imageSlug !== '') {
            $imageSource = 'configoptions-key';
        }
    }

    if ($imageSlug === '') {
        // Fallback: scan all configurable-option values and pick the first one
        // that resolves to a known image slug.
        $configOptions = $params['configoptions'] ?? [];
        if (is_array($configOptions)) {
            foreach ($configOptions as $value) {
                $candidate = trim((string) $value);
                if ($candidate === '') {
                    continue;
                }
                $resolvedCandidate = webdock_resolve_image_slug($candidate);
                if ($resolvedCandidate !== $candidate || strpos($candidate, 'webdock-') === 0 || strpos($candidate, 'krellide:') === 0) {
                    $imageSlug = $candidate;
                    $imageSource = 'configoptions-scan';
                    break;
                }
            }
        }
    }

    if ($imageSlug === '') {
        $imageSlug = trim((string) ($params['configoption4'] ?? ''));
        if ($imageSlug !== '') {
            $imageSource = 'module-default';
        }
    }

    if ($imageSlug === '') {
        $imageSlug = 'webdock-ubuntu-jammy-cloud';
        $imageSource = 'hard-default';
    }

    $rawImageSelection = $imageSlug;
    $imageSlug = webdock_resolve_image_slug($imageSlug);

    logModuleCall(
        'webdock',
        'ReinstallServer:image-selection',
        [
            'service_id'         => $params['serviceid'] ?? null,
            'imageSource'        => $imageSource,
            'rawImageSelection'  => $rawImageSelection,
            'resolvedImageSlug'  => $imageSlug,
            'configoptions'      => $params['configoptions'] ?? [],
        ],
        'Resolved reinstall image selection',
        'image selection',
        [$params['configoption1'] ?? '']
    );

    $payload = [
        'imageSlug' => $imageSlug,
    ];

    return webdock_run_server_action($params, '/actions/reinstall', $payload, 'ReinstallServer');
}

/**
 * Create a snapshot for server action.
 */
function webdock_CreateSnapshot(array $params)
{
    $slug = trim((string) ($params['domain'] ?? 'server'));
    $payload = [
        'name' => 'whmcs-' . $slug . '-' . date('Ymd-His'),
    ];

    return webdock_run_server_action($params, '/actions/snapshot', $payload, 'CreateSnapshot');
}

/**
 * List snapshots for the current server and persist a readable summary.
 */
function webdock_ListSnapshots(array $params)
{
    $snapshotLookup = webdock_fetch_server_snapshots($params);
    if (!$snapshotLookup['ok']) {
        return 'List Snapshots failed: ' . webdock_stringify_error_detail($snapshotLookup['message'] ?? 'Unknown error');
    }

    $snapshots = $snapshotLookup['snapshots'];
    $updates = [
        'Available Snapshots' => webdock_format_snapshot_list_for_storage($snapshots),
    ];

    $latestCompleted = webdock_pick_latest_completed_snapshot($snapshots);
    if (!empty($latestCompleted['id'])) {
        $updates['Latest Snapshot ID'] = (string) webdock_parse_int_value($latestCompleted['id']);
    }

    webdock_update_service_customfields($params, $updates);

    return 'success';
}

/**
 * Restore server to snapshot action.
 */
function webdock_RestoreSnapshot(array $params)
{
    // Client area passes snapshotId via POST; admin area resolves from custom fields.
    $postId = filter_input(INPUT_POST, 'snapshotId', FILTER_VALIDATE_INT);
    if ($postId !== null && $postId !== false && $postId > 0) {
        return webdock_run_server_action(
            $params,
            '/actions/restore',
            ['snapshotId' => $postId],
            'RestoreSnapshot'
        );
    }

    $snapshotIdRaw = webdock_get_customfield_value($params, ['Restore Snapshot ID', 'Snapshot ID', 'Latest Snapshot ID']);
    $snapshotId = webdock_parse_int_value($snapshotIdRaw);

    if ($snapshotId <= 0) {
        $snapshotName = trim(webdock_get_customfield_value($params, ['Restore Snapshot Name', 'Snapshot Name']));
        $snapshotLookup = webdock_fetch_server_snapshots($params);
        if (!$snapshotLookup['ok']) {
            return 'Restore Snapshot could not fetch available snapshots. '
                . webdock_stringify_error_detail($snapshotLookup['message'] ?? 'Unknown error');
        }

        $snapshots = $snapshotLookup['snapshots'];
        webdock_update_service_customfields($params, [
            'Available Snapshots' => webdock_format_snapshot_list_for_storage($snapshots),
        ]);

        if ($snapshotName !== '') {
            foreach ($snapshots as $snapshot) {
                if (strcasecmp(trim((string) ($snapshot['name'] ?? '')), $snapshotName) === 0) {
                    $snapshotId = webdock_parse_int_value($snapshot['id'] ?? 0);
                    break;
                }
            }
        }

        if ($snapshotId <= 0 && $snapshotName === '') {
            $latestCompleted = webdock_pick_latest_completed_snapshot($snapshots);
            if (!empty($latestCompleted['id'])) {
                $snapshotId = webdock_parse_int_value($latestCompleted['id']);
                webdock_update_service_customfields($params, [
                    'Latest Snapshot ID' => (string) $snapshotId,
                ]);
            }
        }
    }

    if ($snapshotId <= 0) {
        return 'Restore Snapshot requires a snapshot selection. Run List Snapshots first, then set "Restore Snapshot ID" or "Restore Snapshot Name".';
    }

    return webdock_run_server_action(
        $params,
        '/actions/restore',
        ['snapshotId' => $snapshotId],
        'RestoreSnapshot'
    );
}

/**
 * Delete a snapshot. Reads snapshotId from POST (client area) or
 * "Restore Snapshot ID" / "Snapshot ID" custom fields (admin area).
 */
function webdock_DeleteSnapshot(array $params)
{
    $apiToken = $params['configoption1'] ?? '';
    $slug     = trim((string) ($params['domain'] ?? ''));

    if ($slug === '') {
        return 'Delete Snapshot failed: server slug not set.';
    }

    // Client area passes snapshotId via POST; admin area resolves from custom fields.
    $snapshotId = filter_input(INPUT_POST, 'snapshotId', FILTER_VALIDATE_INT);
    if ($snapshotId === null || $snapshotId === false || $snapshotId <= 0) {
        $raw = webdock_get_customfield_value($params, ['Delete Snapshot ID', 'Restore Snapshot ID', 'Snapshot ID', 'Latest Snapshot ID']);
        $snapshotId = webdock_parse_int_value($raw);
    }

    if ($snapshotId <= 0) {
        return 'Delete Snapshot requires a snapshot ID. Set "Delete Snapshot ID" custom field or use the client area snapshot list.';
    }

    $result = webdock_webdock_request(
        'DELETE',
        '/servers/' . rawurlencode($slug) . '/snapshots/' . $snapshotId,
        $apiToken
    );

    logModuleCall(
        'webdock',
        'DeleteSnapshot',
        ['slug' => $slug, 'snapshotId' => $snapshotId, 'service_id' => $params['serviceid'] ?? null],
        ['status' => $result['status'], 'body' => $result['body']],
        $result['ok'] ? 'snapshot deleted' : 'snapshot delete failed',
        [$apiToken]
    );

    if (!$result['ok']) {
        return 'Delete Snapshot failed: ' . webdock_stringify_error_detail($result['message'] ?? ('HTTP ' . $result['status']));
    }

    return 'success';
}

/**
 * Create a shell user on the current server.
 */
function webdock_CreateShellUser(array $params)
{
    // Client area sends credentials via POST; admin area resolves from custom fields.
    $postUsername = trim((string) (filter_input(INPUT_POST, 'newShellUsername') ?? ''));
    $postPassword = trim((string) (filter_input(INPUT_POST, 'newShellPassword') ?? ''));

    $username = $postUsername !== ''
        ? $postUsername
        : trim(webdock_get_customfield_value($params, ['Shell Username', 'SSH Username', 'Username']));
    $password = $postPassword !== ''
        ? $postPassword
        : trim(webdock_get_customfield_value($params, ['Shell Password', 'SSH Password']));

    // Block root creation from client area (POST path).
    if ($postUsername !== '' && strtolower($username) === 'root') {
        return 'Username "root" cannot be created from the client area. Contact an administrator.';
    }

    $group = trim(webdock_get_customfield_value($params, ['Shell Group', 'SSH Group']));
    $shell = trim(webdock_get_customfield_value($params, ['Shell Path', 'Shell']));
    $passwordSshAuthEnabled = webdock_parse_bool_value(
        webdock_get_customfield_value($params, ['SSH Password Auth Enabled', 'Password SSH Auth Enabled', 'passwordSshAuthEnabled']),
        true
    );
    $passwordlessSudoEnabled = webdock_parse_bool_value(
        webdock_get_customfield_value($params, ['Passwordless Sudo Enabled', 'passwordlessSudoEnabled']),
        false
    );
    $sshPort = webdock_parse_int_value(webdock_get_customfield_value($params, ['SSH Port', 'sshPort']));

    if ($username === '') {
        $username = 'admin';
    }
    if ($password === '') {
        $password = trim((string) ($params['password'] ?? ''));
    }
    if ($password === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $password)) {
        // No usable password found — generate a secure random one.
        $charset  = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789_-';
        $generated = '';
        $len = strlen($charset);
        for ($i = 0; $i < 16; $i++) {
            $generated .= $charset[random_int(0, $len - 1)];
        }
        $password = $generated;
    }
    if ($group === '') {
        $group = 'sudo';
    }
    if ($shell === '') {
        $shell = '/bin/bash';
    }

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        return 'Create Shell User requires a valid username using only letters, numbers, and underscore.';
    }
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $password)) {
        return 'Create Shell User requires a valid password using only letters, numbers, underscore, and dash.';
    }

    $slug = trim((string) ($params['domain'] ?? ''));
    if ($slug === '') {
        return 'Cannot run Create Shell User — VPS slug missing from service domain field.';
    }

    $apiToken = $params['configoption1'] ?? '';
    $endpoint = '/servers/' . rawurlencode($slug) . '/shellUsers';
    $payload = [
        'username' => $username,
        'password' => $password,
        'group'    => $group,
        'shell'    => $shell,
    ];
    $result = webdock_webdock_request('POST', $endpoint, $apiToken, $payload);

    logModuleCall(
        'webdock',
        'CreateShellUser',
        ['slug' => $slug, 'service_id' => $params['serviceid'] ?? null, 'endpoint' => $endpoint, 'payload' => $payload],
        ['status' => $result['status'], 'body' => $result['body'], 'headers' => $result['headers']],
        $result['ok'] ? 'shell user create accepted' : 'shell user create failed',
        [$apiToken]
    );

    if (!$result['ok']) {
        return webdock_stringify_error_detail($result['message'] ?? ('Webdock shell user error ' . $result['status']));
    }

    webdock_update_service_customfields($params, [
        'Shell Username' => $username,
        'Shell Password' => $password,
    ]);

    $sshSettingsPayload = [
        'passwordSshAuthEnabled' => $passwordSshAuthEnabled,
        'passwordlessSudoEnabled' => $passwordlessSudoEnabled,
        'sshPort' => ($sshPort > 0 ? $sshPort : 22),
    ];

    $sshSettingsResult = webdock_run_server_post_action($params, '/sshSettings', $sshSettingsPayload, 'SetSshSettings');
    if ($sshSettingsResult !== 'success') {
        return 'Shell user created, but SSH settings update failed: ' . $sshSettingsResult;
    }

    return 'success';
}

/**
 * List shell users for the current server and persist a readable summary.
 */
function webdock_ListShellUsers(array $params)
{
    $lookup = webdock_fetch_shell_users($params);
    if (!$lookup['ok']) {
        return 'List Shell Users failed: ' . webdock_stringify_error_detail($lookup['message'] ?? 'Unknown error');
    }

    webdock_update_service_customfields($params, [
        'Available Shell Users' => webdock_format_shell_user_list_for_storage($lookup['users']),
    ]);

    return 'success';
}

/**
 * Delete a shell user from the server.
 *
 * Username is read from POST (client area) or from custom fields (admin area).
 */
function webdock_DeleteShellUser(array $params): string
{
    // Client area passes shellUsername via POST.
    $username = trim((string) (filter_input(INPUT_POST, 'shellUsername') ?? ''));
    if ($username === '') {
        $username = trim(webdock_get_customfield_value($params, ['Delete Shell Username', 'Shell Username', 'SSH Username']));
    }

    if ($username === '') {
        return 'Delete Shell User requires a username. Pass shellUsername via POST or set the "Delete Shell Username" custom field.';
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        return 'Delete Shell User: invalid username format.';
    }

    $slug = trim((string) ($params['domain'] ?? ''));
    if ($slug === '') {
        return 'Cannot delete shell user — VPS slug missing from service domain field.';
    }

    $apiToken = $params['configoption1'] ?? '';
    $endpoint = '/servers/' . rawurlencode($slug) . '/shellUsers/' . rawurlencode($username);
    $result   = webdock_webdock_request('DELETE', $endpoint, $apiToken);

    logModuleCall(
        'webdock',
        'DeleteShellUser',
        ['slug' => $slug, 'username' => $username, 'service_id' => $params['serviceid'] ?? null],
        ['status' => $result['status'], 'body' => $result['body']],
        $result['ok'] ? 'shell user deleted' : 'shell user delete failed',
        [$apiToken]
    );

    // 404 means already gone — treat as success.
    if (!$result['ok'] && (int) $result['status'] !== 404) {
        return webdock_stringify_error_detail($result['message'] ?? ('Delete shell user failed (HTTP ' . $result['status'] . ')'));
    }

    return 'success';
}

/**
 * Run Certbot action.
 */
function webdock_RunCertbot(array $params)
{
    return webdock_run_server_action($params, '/actions/run-certbot', null, 'RunCertbot');
}

/**
 * Set SSH settings action.
 */
function webdock_SetSshSettings(array $params)
{
    $passwordSshAuthEnabled = webdock_parse_bool_value(
        webdock_get_customfield_value($params, ['SSH Password Auth Enabled', 'Password SSH Auth Enabled', 'passwordSshAuthEnabled']),
        true
    );
    $passwordlessSudoEnabled = webdock_parse_bool_value(
        webdock_get_customfield_value($params, ['Passwordless Sudo Enabled', 'passwordlessSudoEnabled']),
        false
    );
    $sshPort = webdock_parse_int_value(webdock_get_customfield_value($params, ['SSH Port', 'sshPort']));

    $payload = [
        'passwordSshAuthEnabled' => $passwordSshAuthEnabled,
        'passwordlessSudoEnabled' => $passwordlessSudoEnabled,
        'sshPort' => ($sshPort > 0 ? $sshPort : 22),
    ];

    return webdock_run_server_post_action($params, '/sshSettings', $payload, 'SetSshSettings');
}

/**
 * Set server settings action.
 */
function webdock_SetServerSettings(array $params)
{
    $webroot = webdock_get_customfield_value($params, ['Web Root', 'Webroot']);
    if ($webroot === '') {
        return 'Set Server Settings requires product custom field "Web Root".';
    }

    $payload = [
        'webroot'          => $webroot,
        'updateWebserver'  => true,
        'updateLetsencrypt' => true,
    ];

    return webdock_run_server_action($params, '/actions/settings', $payload, 'SetServerSettings');
}

/**
 * Dry run profile change action.
 *
 * Resolution order:
 *   1. Create/resolve a new custom profile from hardware custom fields or
 *      configurable options (CPU Threads, RAM, Disk, Network, Custom Platform).
 *   2. Explicit "Target Profile Slug" / "Resize Profile Slug" custom field.
 *   3. "Profile Slug" configurable option or module default (configoption3).
 *   4. Previously provisioned profile slug stored in custom fields.
 */
function webdock_ProfileChangeDryRun(array $params)
{
    // Step 1: attempt to create/resolve a custom profile from hardware specs.
    $resolved = webdock_resolve_or_create_custom_profile_from_selection($params);
    if ($resolved['ok'] && !empty($resolved['slug'])) {
        $targetProfile = $resolved['slug'];
    } else {
        // Step 2: explicit target profile slug custom field.
        $targetProfile = trim(webdock_get_customfield_value($params, ['Target Profile Slug', 'Resize Profile Slug']));
    }

    // Step 3: configurable option or module default.
    if ($targetProfile === '') {
        $targetProfile = trim(webdock_get_configoption_value($params, ['Profile Slug']));
    }
    if ($targetProfile === '') {
        $targetProfile = trim((string) ($params['configoption3'] ?? ''));
    }

    if ($targetProfile === '') {
        return 'Dry Run Profile Change: no target profile could be resolved. '
            . 'To auto-create a custom profile set all five custom fields: '
            . '"Custom Platform" (intel_vps or epyc_vps), "CPU Threads", "RAM (GB)", "Disk Space (GB)", "Network Bandwidth (Gbit/s)". '
            . 'Alternatively set a "Target Profile Slug" custom field with the desired profile slug.';
    }

    return webdock_run_server_action(
        $params,
        '/actions/resize/dryrun',
        ['profileSlug' => $targetProfile],
        'ProfileChangeDryRun'
    );
}

/**
 * Change server profile action.
 *
 * Resolution order:
 *   1. Create/resolve a new custom profile from hardware custom fields or
 *      configurable options (CPU Threads, RAM, Disk, Network, Custom Platform).
 *   2. Explicit "Target Profile Slug" / "Resize Profile Slug" custom field.
 *   3. "Profile Slug" configurable option or module default (configoption3).
 *   4. Previously provisioned profile slug stored in custom fields.
 */
function webdock_ChangeServerProfile(array $params)
{
    // Step 1: attempt to create/resolve a custom profile from hardware specs.
    $resolved = webdock_resolve_or_create_custom_profile_from_selection($params);
    if ($resolved['ok'] && !empty($resolved['slug'])) {
        $targetProfile = $resolved['slug'];
    } else {
        // Step 2: explicit target profile slug custom field.
        $targetProfile = trim(webdock_get_customfield_value($params, ['Target Profile Slug', 'Resize Profile Slug']));
    }

    // Step 3: configurable option or module default.
    if ($targetProfile === '') {
        $targetProfile = trim(webdock_get_configoption_value($params, ['Profile Slug']));
    }
    if ($targetProfile === '') {
        $targetProfile = trim((string) ($params['configoption3'] ?? ''));
    }

    if ($targetProfile === '') {
        return 'Change Server Profile: no target profile could be resolved. '
            . 'To auto-create a custom profile set all five custom fields: '
            . '"Custom Platform" (intel_vps or epyc_vps), "CPU Threads", "RAM (GB)", "Disk Space (GB)", "Network Bandwidth (Gbit/s)". '
            . 'Alternatively set a "Target Profile Slug" custom field with the desired profile slug.';
    }

    // After a successful profile change, update service custom fields to
    // reflect the new profile slug.
    $result = webdock_run_server_action(
        $params,
        '/actions/resize',
        ['profileSlug' => $targetProfile],
        'ChangeServerProfile'
    );

    if ($result === 'success') {
        webdock_update_service_customfields($params, [
            'Profile Slug'          => $targetProfile,
            'Provisioned Profile Slug' => $targetProfile,
        ]);
    }

    return $result;
}

/**
 * Immediately suspend a VPS for abuse, open a support ticket, and email the
 * internal abuse address. Used when a server is actively causing harm.
 *
 * @param array $params WHMCS module parameters
 *
 * @return array
 */
function webdock_EmergencyAbuseSuspend(array $params)
{
    $apiToken   = $params['configoption1'];
    $abuseEmail = trim($params['configoption5'] ?? '');

    $slug      = $params['domain'] ?? '';
    $serviceId = $params['serviceid'];
    $clientId  = $params['userid'];

    if (empty($slug)) {
        return 'Cannot emergency suspend — VPS slug missing from service domain field.';
    }

    // Step 1: suspend the server on Webdock (billing suspension signal)
    $result = webdock_webdock_request('POST', '/servers/' . rawurlencode($slug) . '/actions/suspend', $apiToken);

    logModuleCall(
        'webdock',
        'EmergencyAbuseSuspend',
        ['slug' => $slug, 'service_id' => $serviceId, 'client_id' => $clientId],
        ['status' => $result['status'], 'body' => $result['body']],
        $result['ok'] ? 'suspended for abuse' : 'webdock suspend failed',
        [$apiToken]
    );

    if (!$result['ok']) {
        return 'Webdock suspend failed: ' . webdock_stringify_error_detail($result['message'] ?? $result['status']);
    }

    // Step 2: mark the WHMCS service as Suspended
    localAPI('SuspendClient', [
        'serviceid'     => $serviceId,
        'suspendreason' => 'Emergency abuse suspension by Webdock admin',
    ]);

    // Step 3: open a high-priority support ticket for the client
    localAPI('OpenTicket', [
        'clientid' => $clientId,
        'deptid'   => 1,
        'subject'  => 'Your VPS has been suspended — urgent action required',
        'message'  => 'Your VPS (' . $slug . ') has been suspended by Webdock due to a violation '
            . 'of our Acceptable Use Policy. Please contact support immediately.',
        'priority' => 'High',
    ]);

    // Step 4: send internal abuse alert email if configured
    if (!empty($abuseEmail)) {
        $subject = 'Webdock abuse suspension — ' . $slug;
        $body    = 'Slug: '       . $slug
            . "\nService ID: "    . $serviceId
            . "\nClient ID: "     . $clientId
            . "\nEmail: "         . ($params['clientsdetails']['email'] ?? 'unknown')
            . "\nTime: "          . date('Y-m-d H:i:s T');

        // Webdock: use PHP mail() directly — no WHMCS dependency for abuse alerts
        @mail(
            $abuseEmail,
            $subject,
            $body,
            'From: noreply@webdock.io' . "\r\n" . 'Content-Type: text/plain; charset=UTF-8'
        );
    }

    return 'success';
}

/**
 * Helper to run a server action endpoint and normalise WHMCS return values.
 *
 * @param array      $params
 * @param string     $actionPath
 * @param array|null $payload
 * @param string     $actionName
 *
 * @return string
 */
function webdock_run_server_action(array $params, string $actionPath, ?array $payload, string $actionName): string
{
    return webdock_run_server_post_action($params, $actionPath, $payload, $actionName);
}

/**
 * Helper to run a POST endpoint below /servers/{slug} and normalise WHMCS return values.
 */
function webdock_run_server_post_action(array $params, string $actionPath, ?array $payload, string $actionName): string
{
    $apiToken = $params['configoption1'] ?? '';
    $slug = trim((string) ($params['domain'] ?? ''));

    if ($slug === '') {
        return 'Cannot run ' . $actionName . ' — VPS slug missing from service domain field.';
    }

    $endpoint = '/servers/' . rawurlencode($slug) . $actionPath;
    $result = webdock_webdock_request('POST', $endpoint, $apiToken, $payload);

    logModuleCall(
        'webdock',
        $actionName,
        ['slug' => $slug, 'service_id' => $params['serviceid'] ?? null, 'endpoint' => $endpoint, 'payload' => $payload],
        ['status' => $result['status'], 'body' => $result['body'], 'headers' => $result['headers']],
        $result['ok'] ? 'action accepted' : 'action failed',
        [$apiToken]
    );

    if (!$result['ok']) {
        return webdock_stringify_error_detail($result['message'] ?? ('Webdock action error ' . $result['status']));
    }

    return 'success';
}

/**
 * Pull latest server DTO from Webdock and sync key fields into WHMCS.
 *
 * @param array  $params
 * @param string $slug
 * @param string $apiToken
 * @param string $sourceTag
 *
 * @return array{ok:bool,ipv4:string,ipv6:string,status:string,message:string}
 */
function webdock_sync_service_server_data(array $params, string $slug, string $apiToken, string $sourceTag): array
{
    $result = webdock_webdock_request('GET', '/servers/' . rawurlencode($slug), $apiToken);

    logModuleCall(
        'webdock',
        'SyncServerData:' . $sourceTag,
        ['slug' => $slug, 'service_id' => $params['serviceid'] ?? null],
        ['status' => $result['status'], 'body' => $result['body']],
        $result['ok'] ? 'sync fetched' : 'sync fetch failed',
        [$apiToken]
    );

    if (!$result['ok'] || !is_array($result['body'])) {
        return [
            'ok'      => false,
            'ipv4'    => '',
            'ipv6'    => '',
            'status'  => '',
            'message' => webdock_stringify_error_detail($result['message'] ?? ('Webdock sync error ' . $result['status'])),
        ];
    }

    $body = $result['body'];
    $serverName = trim((string) ($body['name'] ?? ''));
    $ipv4 = trim((string) ($body['ipv4'] ?? ''));
    $ipv6 = trim((string) ($body['ipv6'] ?? ''));
    $status = trim((string) ($body['status'] ?? ''));

    if ($serverName !== '') {
        localAPI('UpdateClientProduct', [
            'serviceid' => $params['serviceid'],
            'hostname'  => $serverName,
        ]);
    }

    if ($ipv4 !== '') {
        localAPI('UpdateClientProduct', [
            'serviceid'   => $params['serviceid'],
            'dedicatedip' => $ipv4,
        ]);
    }

    $secondaryIps = [];
    if (!empty($body['secondaryIps']) && is_array($body['secondaryIps'])) {
        foreach ($body['secondaryIps'] as $ip) {
            $ip = trim((string) $ip);
            if ($ip !== '') {
                $secondaryIps[] = $ip;
            }
        }
    }

    $assignedIps = [];
    if ($ipv6 !== '') {
        $assignedIps[] = $ipv6;
    }
    if (!empty($secondaryIps)) {
        $assignedIps = array_merge($assignedIps, $secondaryIps);
    }

    if (!empty($assignedIps)) {
        localAPI('UpdateClientProduct', [
            'serviceid'   => $params['serviceid'],
            'assignedips' => implode("\n", array_unique($assignedIps)),
        ]);
    }

    // Update known optional custom fields when present on product.
    $currentCustomFields = $params['customfields'] ?? [];
    if (is_array($currentCustomFields) && !empty($currentCustomFields)) {
        $updates = [];
        if (array_key_exists('VPS Slug', $currentCustomFields)) {
            $updates['VPS Slug'] = $slug;
        }
        if ($serverName !== '' && array_key_exists('Provisioned Server Name', $currentCustomFields)) {
            $updates['Provisioned Server Name'] = $serverName;
        }
        if ($serverName !== '' && array_key_exists('VPS Name', $currentCustomFields)) {
            $updates['VPS Name'] = $serverName;
        }
        if ($serverName !== '' && array_key_exists('Server Name', $currentCustomFields)) {
            $updates['Server Name'] = $serverName;
        }
        if ($ipv4 !== '' && array_key_exists('VPS IPv4', $currentCustomFields)) {
            $updates['VPS IPv4'] = $ipv4;
        }
        if ($ipv6 !== '' && array_key_exists('VPS IPv6', $currentCustomFields)) {
            $updates['VPS IPv6'] = $ipv6;
        }
        if ($status !== '' && array_key_exists('VPS Status', $currentCustomFields)) {
            $updates['VPS Status'] = $status;
        }
        if (!empty($updates)) {
            webdock_update_service_customfields($params, $updates);
        }
    }

    return [
        'ok'      => true,
        'ipv4'    => $ipv4,
        'ipv6'    => $ipv6,
        'status'  => $status,
        'message' => '',
    ];
}

/**
 * Fetch first non-empty custom field value by key from WHMCS module params.
 *
 * @param array $params
 * @param array $keys
 *
 * @return string
 */
function webdock_get_customfield_value(array $params, array $keys): string
{
    $customFields = $params['customfields'] ?? [];
    if (!is_array($customFields)) {
        return '';
    }

    // Build a lowercase-keyed lookup so matching is case-insensitive.
    // WHMCS may pass field names with differing capitalisation between
    // auto-provisioning (payment hook) and manual Module Commands runs.
    $normalised = [];
    foreach ($customFields as $name => $value) {
        $normalised[strtolower(trim((string) $name))] = $value;
    }

    foreach ($keys as $key) {
        $lookup = strtolower(trim((string) $key));
        if (array_key_exists($lookup, $normalised)) {
            $value = trim((string) $normalised[$lookup]);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

/**
 * Fetch first non-empty configurable option value by key (case-insensitive).
 *
 * @param array $params
 * @param array $keys
 *
 * @return string
 */
function webdock_get_configoption_value(array $params, array $keys): string
{
    $configOptions = $params['configoptions'] ?? [];
    if (!is_array($configOptions) || empty($configOptions)) {
        return '';
    }

    $optionsLower = [];
    foreach ($configOptions as $key => $value) {
        $optionsLower[strtolower(trim((string) $key))] = $value;
    }

    foreach ($keys as $key) {
        $lookup = strtolower(trim((string) $key));
        if (array_key_exists($lookup, $optionsLower)) {
            $value = trim((string) $optionsLower[$lookup]);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

/**
 * Update service custom fields while preserving current values.
 */
function webdock_update_service_customfields(array $params, array $updates): void
{
    if (empty($updates)) {
        return;
    }

    // WHMCS UpdateClientProduct requires integer field IDs as keys — NOT field names.
    // Resolve name → ID map by fetching the service product record first.
    $product = localAPI('GetClientsProducts', [
        'serviceid' => $params['serviceid'],
        'getsingle' => true,
    ]);

    $fieldIdMap = [];
    $customFieldList = $product['customfields']['customfield'] ?? [];
    foreach ($customFieldList as $cf) {
        $fieldIdMap[(string) $cf['name']] = (int) $cf['id'];
    }

    $writeData = [];
    foreach ($updates as $name => $value) {
        if (isset($fieldIdMap[$name])) {
            $writeData[$fieldIdMap[$name]] = $value;
        }
    }

    if (!empty($writeData)) {
        localAPI('UpdateClientProduct', [
            'serviceid'    => $params['serviceid'],
            'customfields' => base64_encode(serialize($writeData)),
        ]);
    }
}

/**
 * Fetch snapshots for the current server.
 *
 * @return array{ok:bool,snapshots:array<int,array>,message:string}
 */
function webdock_fetch_server_snapshots(array $params): array
{
    $apiToken = $params['configoption1'] ?? '';
    $slug = trim((string) ($params['domain'] ?? ''));

    if ($slug === '') {
        return ['ok' => false, 'snapshots' => [], 'message' => 'VPS slug missing from service domain field.'];
    }

    $result = webdock_webdock_request('GET', '/servers/' . rawurlencode($slug) . '/snapshots', $apiToken);

    logModuleCall(
        'webdock',
        'ListSnapshots',
        ['slug' => $slug, 'service_id' => $params['serviceid'] ?? null],
        ['status' => $result['status'], 'body' => $result['body']],
        $result['ok'] ? 'snapshots fetched' : 'snapshot fetch failed',
        [$apiToken]
    );

    if (!$result['ok'] || !is_array($result['body'])) {
        return [
            'ok'        => false,
            'snapshots' => [],
            'message'   => webdock_stringify_error_detail($result['message'] ?? ('Webdock snapshot list error ' . $result['status'])),
        ];
    }

    return ['ok' => true, 'snapshots' => array_values($result['body']), 'message' => ''];
}

/**
 * Pick the latest completed snapshot by date, falling back to the first snapshot.
 */
function webdock_pick_latest_completed_snapshot(array $snapshots): array
{
    $completed = [];
    foreach ($snapshots as $snapshot) {
        $isCompleted = filter_var($snapshot['completed'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($isCompleted === null) {
            $isCompleted = ((string) ($snapshot['completed'] ?? '')) === 'true';
        }
        if ($isCompleted) {
            $completed[] = $snapshot;
        }
    }

    $pool = !empty($completed) ? $completed : $snapshots;
    usort($pool, static function (array $a, array $b): int {
        return strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
    });

    return $pool[0] ?? [];
}

/**
 * Render snapshots into a short text block suitable for a custom field.
 */
function webdock_format_snapshot_list_for_storage(array $snapshots): string
{
    if (empty($snapshots)) {
        return 'No snapshots found.';
    }

    $lines = [];
    foreach ($snapshots as $snapshot) {
        $lines[] = '#'
            . webdock_parse_int_value($snapshot['id'] ?? 0)
            . ' | '
            . trim((string) ($snapshot['name'] ?? 'unnamed'))
            . ' | '
            . trim((string) ($snapshot['date'] ?? ''))
            . ' | completed=' . trim((string) ($snapshot['completed'] ?? 'false'));
    }

    return implode("\n", $lines);
}

/**
 * Fetch shell users for the current server.
 *
 * @return array{ok:bool,users:array<int,array>,message:string}
 */
function webdock_fetch_shell_users(array $params): array
{
    $apiToken = $params['configoption1'] ?? '';
    $slug = trim((string) ($params['domain'] ?? ''));

    if ($slug === '') {
        return ['ok' => false, 'users' => [], 'message' => 'VPS slug missing from service domain field.'];
    }

    $result = webdock_webdock_request('GET', '/servers/' . rawurlencode($slug) . '/shellUsers', $apiToken);

    logModuleCall(
        'webdock',
        'ListShellUsers',
        ['slug' => $slug, 'service_id' => $params['serviceid'] ?? null],
        ['status' => $result['status'], 'body' => $result['body']],
        $result['ok'] ? 'shell users fetched' : 'shell users fetch failed',
        [$apiToken]
    );

    if (!$result['ok'] || !is_array($result['body'])) {
        return [
            'ok'      => false,
            'users'   => [],
            'message' => webdock_stringify_error_detail($result['message'] ?? ('Webdock shell users error ' . $result['status'])),
        ];
    }

    return ['ok' => true, 'users' => array_values($result['body']), 'message' => ''];
}

/**
 * Render shell users into a short text block suitable for a custom field.
 */
function webdock_format_shell_user_list_for_storage(array $users): string
{
    if (empty($users)) {
        return 'No shell users found.';
    }

    $lines = [];
    foreach ($users as $user) {
        $lines[] = '#'
            . webdock_parse_int_value($user['id'] ?? 0)
            . ' | '
            . trim((string) ($user['username'] ?? 'unknown'))
            . ' | '
            . trim((string) ($user['group'] ?? ''))
            . ' | '
            . trim((string) ($user['shell'] ?? ''));
    }

    return implode("\n", $lines);
}

/**
 * Resolve a custom profile slug from selected checkout values.
 * If needed and complete values are available, creates a custom profile.
 *
 * @param array $params
 *
 * @return array{ok:bool,slug:string,message:string}
 */
function webdock_resolve_or_create_custom_profile_from_selection(array $params): array
{
    $apiToken = (string) ($params['configoption1'] ?? '');
    if ($apiToken === '') {
        return ['ok' => false, 'slug' => '', 'message' => 'Missing API token.'];
    }

    $platformKeys = ['Custom Platform', 'Platform'];
    $cpuKeys      = ['CPU Threads', 'CPU', 'vCPU', 'Threads'];
    $ramKeys      = ['RAM (GB)', 'RAM', 'Memory (GB)', 'Memory'];
    $diskKeys     = ['Disk Space (GB)', 'Disk (GB)', 'Disk', 'Storage (GB)', 'Storage'];
    $networkKeys  = ['Network Bandwidth (Gbit/s)', 'Network (Gbit)', 'Network', 'Bandwidth (Gbit)', 'Bandwidth'];

    // Custom fields are the primary source (set by the frontend/admin before ordering).
    $customPlatform = trim(webdock_get_customfield_value($params, $platformKeys));
    $cpuThreads     = webdock_parse_int_value(webdock_get_customfield_value($params, $cpuKeys));
    $ramGb          = webdock_parse_int_value(webdock_get_customfield_value($params, $ramKeys));
    $diskGb         = webdock_parse_int_value(webdock_get_customfield_value($params, $diskKeys));
    $networkGbit    = webdock_parse_int_value(webdock_get_customfield_value($params, $networkKeys));

    // Configurable options override custom fields if explicitly selected at checkout.
    $coCustomPlatform = trim(webdock_get_configoption_value($params, $platformKeys));
    $coCpuThreads     = webdock_parse_int_value(webdock_get_configoption_value($params, $cpuKeys));
    $coRamGb          = webdock_parse_int_value(webdock_get_configoption_value($params, $ramKeys));
    $coDiskGb         = webdock_parse_int_value(webdock_get_configoption_value($params, $diskKeys));
    $coNetworkGbit    = webdock_parse_int_value(webdock_get_configoption_value($params, $networkKeys));
    if ($coCustomPlatform !== '') {
        $customPlatform = $coCustomPlatform;
    }
    if ($coCpuThreads > 0) {
        $cpuThreads = $coCpuThreads;
    }
    if ($coRamGb > 0) {
        $ramGb = $coRamGb;
    }
    if ($coDiskGb > 0) {
        $diskGb = $coDiskGb;
    }
    if ($coNetworkGbit > 0) {
        $networkGbit = $coNetworkGbit;
    }

    $platformNorm = strtolower($customPlatform);
    if (in_array($platformNorm, ['epyc_vps', 'epyc', 'amd', 'amd epyc', 'amd_epyc'], true)) {
        $customPlatform = 'epyc_vps';
    } elseif (in_array($platformNorm, ['intel_vps', 'intel', 'intel vps'], true)) {
        $customPlatform = 'intel_vps';
    }

    if ($customPlatform === '' && $cpuThreads <= 0 && $ramGb <= 0 && $diskGb <= 0 && $networkGbit <= 0) {
        return ['ok' => false, 'slug' => '', 'message' => 'No target profile and no custom profile selection found.'];
    }

    $isCompleteCustomProfileInput =
        in_array($customPlatform, ['epyc_vps', 'intel_vps'], true)
        && ($cpuThreads > 0)
        && ($ramGb > 0)
        && ($diskGb > 0)
        && ($networkGbit > 0);

    if (!$isCompleteCustomProfileInput) {
        logModuleCall(
            'webdock',
            'ResolveOrCreateProfile:incomplete',
            [
                'configoptions' => $params['configoptions'] ?? [],
                'customfields'  => $params['customfields'] ?? [],
            ],
            [
                'platform' => $customPlatform,
                'cpu'      => $cpuThreads,
                'ram'      => $ramGb,
                'disk'     => $diskGb,
                'network'  => $networkGbit,
            ],
            'profile selection incomplete',
            [$apiToken]
        );

        return [
            'ok'      => false,
            'slug'    => '',
            'message' => 'Custom profile selection is incomplete. Set all of: Custom Platform, CPU Threads, RAM (GB), Disk Space (GB), Network Bandwidth (Gbit/s).',
        ];
    }

    $payload = [
        'platform'          => $customPlatform,
        'cpu_threads'       => $cpuThreads,
        'ram'               => $ramGb,
        'disk_space'        => $diskGb,
        'network_bandwidth' => $networkGbit,
    ];

    $create = webdock_webdock_request('POST', '/profiles', $apiToken, $payload);

    logModuleCall(
        'webdock',
        'ResolveOrCreateProfile',
        $payload,
        ['status' => $create['status'], 'body' => $create['body']],
        $create['ok'] ? 'profile created for profile-change action' : 'profile create failed for profile-change action',
        [$apiToken]
    );

    if (!$create['ok']) {
        return [
            'ok'      => false,
            'slug'    => '',
            'message' => webdock_stringify_error_detail($create['message'] ?? ('Webdock profile create error ' . $create['status'])),
        ];
    }

    $slug = trim((string) ($create['body']['slug'] ?? ''));
    if ($slug === '') {
        return ['ok' => false, 'slug' => '', 'message' => 'Webdock did not return profile slug after profile creation.'];
    }

    return ['ok' => true, 'slug' => $slug, 'message' => ''];
}

/**
 * Parse an integer from mixed WHMCS values like "1", "1 Gbit/s", "2 x Price per unit...".
 */
function webdock_parse_int_value($value): int
{
    if (is_int($value)) {
        return $value;
    }

    if (is_float($value)) {
        return (int) round($value);
    }

    $text = trim((string) $value);
    if ($text === '') {
        return 0;
    }

    if (preg_match('/-?\d+(?:\.\d+)?/', $text, $matches)) {
        return (int) round((float) $matches[0]);
    }

    return 0;
}

/**
 * Parse a boolean from common WHMCS/admin values.
 */
function webdock_parse_bool_value($value, bool $default = false): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $text = strtolower(trim((string) $value));
    if ($text === '') {
        return $default;
    }

    if (in_array($text, ['1', 'true', 'yes', 'on', 'enabled'], true)) {
        return true;
    }

    if (in_array($text, ['0', 'false', 'no', 'off', 'disabled'], true)) {
        return false;
    }

    return $default;
}

// ---------------------------------------------------------------------------
// Image slug resolver
// ---------------------------------------------------------------------------

/**
 * Resolve a human-readable image name to a Webdock image slug.
 * Admins and customers may enter a friendly name (e.g. "Noble LEMP",
 * "Ubuntu Jammy 22.04") in the "Image Slug" configurable option or module
 * setting. This function maps those names — case-insensitively — to the
 * canonical Webdock slug. If the input already looks like a slug (contains
 * a colon or hyphen and doesn't match any name) it is returned unchanged.
 *
 * @param string $input  Raw value from the configurable option or module setting
 *
 * @return string  Resolved slug, or the original input if no name matched
 */
function webdock_resolve_image_slug(string $input): string
{
    // Keyed by lowercase display name → Webdock slug.
    // Sources: GET /images response (slug + name fields).
    static $nameToSlug = [
        // Stack images
        'noble lemp'                  => 'krellide:webdock-noble-lemp',
        'noble lamp'                  => 'krellide:webdock-noble-lamp',
        // Ubuntu
        'ubuntu noble 24.04'          => 'webdock-ubuntu-noble-cloud',
        'ubuntu noble 22.04'          => 'webdock-ubuntu-noble-cloud',
        'noble 22.04'                 => 'webdock-ubuntu-noble-cloud',
        'ubuntu noble'                => 'webdock-ubuntu-noble-cloud',
        'ubuntu 24.04'                => 'webdock-ubuntu-noble-cloud',
        'ubuntu jammy 22.04'          => 'webdock-ubuntu-jammy-cloud',
        'ubuntu jammy'                => 'webdock-ubuntu-jammy-cloud',
        'ubuntu 22.04'                => 'webdock-ubuntu-jammy-cloud',
        // AlmaLinux
        'almalinux 9'                 => 'webdock-almalinux-9-cloud',
        'alma 9'                      => 'webdock-almalinux-9-cloud',
        'almalinux 10'                => 'webdock-almalinux-10-cloud',
        'alma 10'                     => 'webdock-almalinux-10-cloud',
        // CentOS
        'centos 9'                    => 'webdock-centos-9-cloud',
        'centos 10'                   => 'webdock-centos-10-cloud',
        // Debian
        'debian 13 trixie'            => 'webdock-debian-trixie-cloud',
        'debian trixie'               => 'webdock-debian-trixie-cloud',
        'debian 13'                   => 'webdock-debian-trixie-cloud',
        'debian 12 bookworm'          => 'webdock-debian-bookworm-cloud',
        'debian bookworm'             => 'webdock-debian-bookworm-cloud',
        'debian 12'                   => 'webdock-debian-bookworm-cloud',
        // Desktop images
        'ubuntu gnome desktop'        => 'webdock-ubuntu-noble-gnome-desktop',
        'gnome desktop'               => 'webdock-ubuntu-noble-gnome-desktop',
        'ubuntu kde plasma desktop'   => 'webdock-ubuntu-noble-kdeplasma-desktop',
        'kde plasma desktop'          => 'webdock-ubuntu-noble-kdeplasma-desktop',
        'kdeplasma desktop'           => 'webdock-ubuntu-noble-kdeplasma-desktop',
    ];

    $key = strtolower(trim($input));

    return $nameToSlug[$key] ?? $input;
}

/**
 * Ensure a profile slug is valid for a given location.
 *
 * If the requested slug is unavailable in the selected location, falls back
 * to the first available profile slug returned by Webdock for that location.
 *
 * @param string $apiToken
 * @param string $locationId
 * @param string $requestedSlug
 *
 * @return array{slug:string,reason:string}
 */
function webdock_resolve_profile_slug_for_location(string $apiToken, string $locationId, string $requestedSlug): array
{
    $requestedSlug = trim($requestedSlug);
    if ($requestedSlug === '') {
        return ['slug' => '', 'reason' => 'requested slug empty'];
    }

    $profilesResult = webdock_webdock_request('GET', '/profiles', $apiToken, null, ['locationId' => $locationId]);
    if (!$profilesResult['ok'] || !is_array($profilesResult['body'])) {
        return ['slug' => $requestedSlug, 'reason' => 'profiles lookup failed'];
    }

    $body = $profilesResult['body'];
    if (isset($body['profiles']) && is_array($body['profiles'])) {
        $profiles = $body['profiles'];
    } elseif (isset($body['data']) && is_array($body['data'])) {
        $profiles = $body['data'];
    } else {
        $profiles = $body;
    }

    if (!is_array($profiles)) {
        return ['slug' => $requestedSlug, 'reason' => 'unexpected profiles response'];
    }

    $availableSlugs = [];
    foreach ($profiles as $profile) {
        if (is_array($profile) && !empty($profile['slug'])) {
            $availableSlugs[] = (string) $profile['slug'];
        }
    }

    if (in_array($requestedSlug, $availableSlugs, true)) {
        return ['slug' => $requestedSlug, 'reason' => 'requested slug valid for location'];
    }

    if (!empty($availableSlugs)) {
        return ['slug' => $availableSlugs[0], 'reason' => 'requested slug unavailable in location'];
    }

    return ['slug' => $requestedSlug, 'reason' => 'no profiles returned for location'];
}

/**
 * Convert any error detail (string/array/object/scalar) to a safe string.
 *
 * WHMCS expects module error messages to be plain strings. Returning arrays
 * causes the generic and unhelpful "Error: Array" output in activity logs.
 *
 * @param mixed $value
 *
 * @return string
 */
function webdock_stringify_error_detail($value): string
{
    if (is_string($value)) {
        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : 'Unknown error';
    }

    if (is_array($value) || is_object($value)) {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return ($encoded !== false && $encoded !== '') ? $encoded : 'Unknown error';
    }

    if ($value === null) {
        return 'Unknown error';
    }

    return (string) $value;
}

// ---------------------------------------------------------------------------
// HTTP helper
// ---------------------------------------------------------------------------

/**
 * Make an authenticated request to the Webdock REST API.
 *
 * Returns a normalised array so all callers handle the same shape regardless
 * of whether the request succeeded or threw an exception.
 *
 * @param string     $method    HTTP method: GET, POST, DELETE, PATCH
 * @param string     $endpoint  Path starting with / e.g. /servers
 * @param string     $apiToken  Webdock API token (never logged raw)
 * @param array|null $body      Optional JSON body (passed via Guzzle 'json' option)
 *
 * @return array {
 *   ok      : bool,
 *   status  : int,
 *   headers : array<string,string>,  // lowercase header names
 *   body    : array|null,
 *   message : string                 // only set on error
 * }
 */
function webdock_webdock_request(string $method, string $endpoint, string $apiToken, ?array $body = null, ?array $query = null): array
{
    // Webdock: Guzzle is bundled with WHMCS — no extra composer deps needed.
    $client = new GuzzleHttp\Client([
        'base_uri' => 'https://api.webdock.io/v1/',
        'timeout'  => 30,
        'headers'  => [
            'Authorization' => 'Bearer ' . $apiToken,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ],
        // Webdock: http_errors = false so 4xx/5xx come back as responses, not
        // ClientExceptions. We handle status codes explicitly in each caller.
        'http_errors' => false,
    ]);

    try {
        $options = [];
        if ($body !== null) {
            $options['json'] = $body;
        }
        if (!empty($query)) {
            $options['query'] = $query;
        }

        $response   = $client->request($method, ltrim($endpoint, '/'), $options);
        $statusCode = $response->getStatusCode();
        $bodyStr    = (string) $response->getBody();

        // Normalise headers to lowercase keys for consistent access
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        $ok      = ($statusCode >= 200 && $statusCode < 300);
        $decoded = json_decode($bodyStr, true);

        $result = [
            'ok'      => $ok,
            'status'  => $statusCode,
            'headers' => $headers,
            'body'    => $decoded,
        ];

        if (!$ok) {
            // Build a human-readable error string — never let a raw array bubble up
            // to WHMCS, which would display the useless word "Array".
            // Webdock error body: {"id": 0, "message": "...", "logId": "UUID"}
            if (is_array($decoded)) {
                $errorDetail = webdock_stringify_error_detail($decoded['message'] ?? $decoded);
                if (!empty($decoded['logId'])) {
                    $errorDetail .= ' (logId: ' . $decoded['logId'] . ')';
                }
            } elseif (!empty($bodyStr)) {
                $errorDetail = $bodyStr;
            } else {
                // Empty body — provide a meaningful fallback per status code
                $errorDetail = match ($statusCode) {
                    401     => 'Unauthorized — check that your Webdock API token is correct and has the required scopes (write:servers, delete:servers).',
                    403     => 'Forbidden — your API token does not have permission for this action.',
                    404     => 'Not found — the server slug may be wrong or the server no longer exists.',
                    429     => 'Rate limit exceeded — too many requests to the Webdock API.',
                    default => 'No response body returned by Webdock API.',
                };
            }
            $result['message'] = 'HTTP ' . $statusCode . ': ' . $errorDetail;
        }

        return $result;
    } catch (\Exception $e) {
        return [
            'ok'      => false,
            'status'  => 0,
            'headers' => [],
            'body'    => null,
            'message' => $e->getMessage(),
        ];
    }
}
