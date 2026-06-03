# Webdock VPS — WHMCS Provisioning Module

**Version:** 1.1  
**Requires:** WHMCS 8.x · PHP 7.4+ · Webdock Reseller Account  
**API:** [Webdock REST API v1](https://api.webdock.io/v1)

---

## Table of Contents

1. [Overview](#overview)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Module Configuration](#module-configuration)
5. [Product Setup](#product-setup)
   - [Custom Fields](#custom-fields)
   - [Configurable Options](#configurable-options)
   - [Module Settings Tab](#module-settings-tab)
6. [Email Templates](#email-templates)
7. [Automation Settings](#automation-settings)
8. [Client Area Features](#client-area-features)
9. [Admin Panel Functions](#admin-panel-functions)
10. [Server Provisioning Logic](#server-provisioning-logic)
    - [Field Resolution Priority](#field-resolution-priority)
    - [Custom Profile Creation](#custom-profile-creation)
    - [Image Slug Resolution](#image-slug-resolution)
11. [Lifecycle Events](#lifecycle-events)
12. [Troubleshooting](#troubleshooting)
13. [Security Notes](#security-notes)
14. [Changelog](#changelog)

---

## Overview

The **Webdock VPS** module for WHMCS automates the full lifecycle of Webdock VPS servers
directly from your WHMCS billing panel.

**What it does:**

| WHMCS Event                 | Webdock Action                                  |
| ---------------------------- | ----------------------------------------------- |
| Order paid / Create Account  | `POST /servers` — Provision new VPS             |
| Invoice overdue / Suspend    | `POST /servers/{slug}/actions/stop` — Power off |
| Payment received / Unsuspend | `POST /servers/{slug}/actions/start` — Power on |
| Cancellation / Terminate     | `DELETE /servers/{slug}` — Destroy server       |

**Client area capabilities (in-panel):**

- Live server status (running / stopped / provisioning)
- Start · Stop · Reboot
- OS Reinstall with image selection dropdown
- Snapshot management (create, restore, delete)
- Shell user management (create, delete)

**Admin-only capabilities:**

- Reboot, Archive, Refresh server data
- Reinstall, Snapshot list/create/restore/delete
- Shell user list/create/delete
- Run Certbot, Set SSH settings, Set server settings
- Dry-run and live server profile change
- Emergency abuse suspension

---

## Requirements

| Requirement              | Detail                                                     |
| ------------------------ | ----------------------------------------------------------------- |
| WHMCS                    | 8.0 or later                                                      |
| PHP                      | 7.4 or later (8.x recommended)                                    |
| PHP extensions           | `curl`, `json`                                                    |
| Webdock account          | Reseller account with API token                                   |
| Webdock delete privilege | Must be enabled by Webdock support for `TerminateAccount` to work |

---

## Installation

1. **Download** the module and extract the `webdock` folder.

2. **Upload** the folder to your WHMCS server:

   ```
   /path/to/whmcs/modules/servers/webdock/
   ├── webdock.php
   └── clientarea.tpl
   ```

3. **Verify permissions** — the folder and files must be readable by the web server user (typically `www-data` or `apache`):

   ```bash
   chmod 644 modules/servers/webdock/webdock.php
   chmod 644 modules/servers/webdock/clientarea.tpl
   ```

4. Log in to WHMCS Admin and confirm the module appears under  
   **Setup → Products/Services → Servers → Add New Server**.

---

## Module Configuration

### Obtain a Webdock API Token

1. Log in to [webdock.io](https://webdock.io).
2. Navigate to **Account → API Tokens**.
3. Create a new token with reseller scope.
4. Copy the token — it will only be shown once.

### Enable Server Deletion

By default, Webdock reseller tokens **cannot delete servers**. To enable `TerminateAccount`:

1. Contact Webdock support and request deletion privileges for your reseller token.
2. Until this is enabled, termination requests return `401` and the admin must delete servers manually from the Webdock dashboard.

---

## Product Setup

### Step 1 — Create a Server Group

1. **Setup → Products/Services → Servers → Add New Server**
2. Set **Type** to `Webdock VPS` (this module).
3. Leave hostname/IP blank — the module does not use a server connection.
4. Save.

### Step 2 — Create or Edit a Product

1. **Setup → Products/Services → Products/Services → Create New Product**
2. In the **Module Settings** tab, select `Webdock VPS` as the module.
3. Fill in the fields described in the next section.

---

## Custom Fields

Custom fields are defined per product under **Products/Services → {Product} → Custom Fields**.

These fields are populated automatically by the module at provisioning time and are also used as the _primary_ input source when an order is placed.

| Field Name                   | Type | Required | Description                                                                    |
| ---------------------------- | ---- | -------- | ------------------------------------------------------------------------------ |
| `VPS Slug`                   | Text | Yes      | Webdock server slug — set automatically at creation. Do not edit.              |
| `Server Name`                | Text | No       | Human-readable server name. Falls back to service hostname then auto-generated. |
| `Location ID`                | Text | No       | Overrides the module default. See available locations: <https://api.webdock.io/v1/locations> |
| `Profile Slug`               | Text | No       | Hardware profile slug. See available profiles: <https://api.webdock.io/v1/profiles?locationId=dk> |
| `Image Slug`                 | Text | No       | OS image slug or human name. See available images: <https://api.webdock.io/v1/images> |
| `Images`                     | Text | No       | Alias for Image Slug — accepted by resolution logic.                           |
| `Operating System`           | Text | No       | Alias for Image Slug.                                                          |
| `Custom Platform`            | Text | No       | Platform slug. See available platforms: <https://api.webdock.io/v1/platforms>  |
| `CPU Threads`                | Text | No       | Required when Custom Platform is set. Integer.                                 |
| `RAM (GB)`                   | Text | No       | Required when Custom Platform is set. Integer.                                 |
| `Disk Space (GB)`            | Text | No       | Required when Custom Platform is set. Integer.                                 |
| `Network Bandwidth (Gbit/s)` | Text | No       | Required when Custom Platform is set. Integer.                                 |
| `Provisioned Server Name`    | Text | No       | Set by the module post-creation. Read-only.                                    |
| `Provisioned Profile Slug`   | Text | No       | Set by the module post-creation. Read-only.                                    |
| `Provisioned Image Slug`     | Text | No       | Set by the module post-creation. Read-only.                                    |
| `Provisioned Location ID`    | Text | No       | Set by the module post-creation. Read-only.                                    |

> **Visibility:** Set `VPS Slug` as **Client can view: Yes / Client can edit: No**. Provisioned fields should be admin-only.

---

## Configurable Options

Configurable options appear on the order form and **override** custom fields and module defaults.

Create a Configurable Options Group under  
**Setup → Products/Services → Configurable Options → Create New Group**,  
then link it to your product.

| Option Name                                  | Accepted Values                                                          | Effect                          |
| -------------------------------------------- | ------------------------------------------------------------------------ | ------------------------------- |
| `Location ID`                                | Any location ID from <https://api.webdock.io/v1/locations>               | Overrides default location      |
| `Profile Slug`                               | Any profile slug from <https://api.webdock.io/v1/profiles?locationId=dk> | Overrides default profile       |
| `Image Slug` / `Images` / `Operating System` | Any image slug or human name from <https://api.webdock.io/v1/images>     | Overrides default OS image      |
| `Custom Platform` / `Platform`               | Any platform slug from <https://api.webdock.io/v1/platforms>             | Enables custom profile creation |
| `CPU Threads` / `CPU` / `vCPU`               | Integer                                                                  | Custom profile CPU threads      |
| `RAM (GB)` / `RAM` / `Memory`                | Integer                                                                  | Custom profile RAM              |
| `Disk Space (GB)` / `Disk` / `Storage`       | Integer                                                                  | Custom profile disk             |
| `Network Bandwidth (Gbit/s)` / `Bandwidth`   | Integer                                                                  | Custom profile network          |

All option name matching is **case-insensitive** and alias-aware — the module recognises multiple common spellings for each field.

---

## Module Settings Tab

These fields appear in the WHMCS admin under  
**Products/Services → {Product} → Module Settings**.

| Setting (configoption) | Friendly Name          | Description                                                                                                                           |
| ---------------------- | ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `configoption1`        | Webdock API Token      | Your Webdock reseller API token. Stored as password field.                                                                            |
| `configoption2`        | Location ID (default)  | Fallback location if none specified in custom fields or configurable options. See <https://api.webdock.io/v1/locations> for valid IDs. |
| `configoption3`        | Profile Slug (default) | Fallback hardware profile. See <https://api.webdock.io/v1/profiles?locationId=dk> for available slugs.                                |
| `configoption4`        | Images (default)       | Fallback OS image slug or human-readable name. See <https://api.webdock.io/v1/images> for available slugs.                            |
| `configoption5`        | Abuse Notify Email     | Internal email for abuse suspension alerts (optional).                                                                                |

**Priority order for each field:**

```
Custom Fields  →  Configurable Options  →  Module Settings  →  Built-in default
```

---

## Email Templates

### Webdock VPS Welcome

Create a welcome email that is sent automatically after a server is provisioned.

1. **Setup → Email Templates → Product/Service → Create New Email Template**
2. Set the name exactly to: `Webdock VPS Welcome`
3. Use these merge fields in the body:

| Merge Field              | Value               |
| ------------------------ | ------------------- |
| `{$customvars.vps_slug}` | Webdock server slug |
| `{$customvars.vps_ip}`   | IPv4 address        |
| `{$customvars.vps_ipv6}` | IPv6 address        |
| `{$customvars.vps_name}` | Server display name |

**Example body:**

```
Your Webdock VPS has been provisioned!

Server: {$customvars.vps_name}
IPv4:   {$customvars.vps_ip}
IPv6:   {$customvars.vps_ipv6}
Slug:   {$customvars.vps_slug}

You can manage your server from the client area.
```

> If the template is not found, provisioning still completes — the email step fails silently.

---

## Automation Settings

Configure WHMCS automation to automatically suspend and terminate overdue services.

1. **Setup → Automation Settings**
2. Set **Suspension Days After Due Date** — WHMCS will call `SuspendAccount` automatically.
3. Set **Termination Days After Suspension** — WHMCS will call `TerminateAccount` automatically.

The module responds to these WHMCS lifecycle events; no cron customisation is required.

---

## Client Area Features

When a service is **Active**, the client sees a tabbed panel with:

### Overview Tab

- Server name and slug
- IPv4 and IPv6 addresses
- Current status badge (Running / Stopped / Provisioning / etc.)
- Power action buttons: **Start**, **Stop**, **Reboot**
- **Reinstall** panel (Danger Zone): client selects an OS image from a dropdown and confirms twice before triggering reinstall

The current list of available OS images for reinstall is always up to date at:  
<https://api.webdock.io/v1/images>

### Snapshots Tab

- Displays all snapshots (manual, daily, weekly) with status badge
- **Create Snapshot** — up to 3 manual snapshots per server
- **Restore** — reverts server to selected snapshot (confirmation required)
- **Delete** — removes manual snapshots (daily/weekly cannot be deleted by clients)

### Shell Users Tab

- Lists all shell users (username, group, shell)
- **Create Shell User** — username validated (letters/numbers/underscore, max 32 chars); password shown once in success message and never again
- **Delete Shell User** — confirmation required; irreversible

---

## Admin Panel Functions

Available under **Admin → Client's Product Service** via the module action buttons:

| Button                    | Action                                         | Notes                                 |
| ------------------------- | ---------------------------------------------- | ------------------------------------- |
| Reboot Server             | `POST /actions/reboot`                         |                                       |
| Archive Server            | `POST /actions/suspend`                        | Webdock suspend (archive state)       |
| Refresh Server Data       | `GET /servers/{slug}`                          | Syncs IP, status, name to WHMCS       |
| Reinstall Server          | `POST /actions/reinstall`                      | Uses configured image slug            |
| Create Snapshot           | `POST /servers/{slug}/snapshots`               |                                       |
| List Snapshots            | `GET /servers/{slug}/snapshots`                | Logged to Module Log                  |
| Restore Snapshot          | `POST /servers/{slug}/snapshots/{id}/restore`  |                                       |
| Delete Snapshot           | `DELETE /servers/{slug}/snapshots/{id}`        |                                       |
| Create Shell User         | `POST /servers/{slug}/shellusers`              |                                       |
| Delete Shell User         | `DELETE /servers/{slug}/shellusers/{username}` |                                       |
| List Shell Users          | `GET /servers/{slug}/shellusers`               | Logged to Module Log                  |
| Run Certbot               | Webdock action                                 |                                       |
| Set SSH Settings          | Webdock action                                 |                                       |
| Set Server Settings       | Webdock action                                 |                                       |
| Dry Run Profile Change    | `GET` profile diff check                       | Non-destructive                       |
| Change Server Profile     | `POST` profile change                          | Destructive — confirm before use      |
| Emergency Suspend (Abuse) | Stop + abuse email notification                | Sends alert to configured abuse email |

---

## Server Provisioning Logic

### Field Resolution Priority

At order time, the module resolves `locationId`, `profileSlug`, and `imageSlug` using this cascading priority:

```
1. Service Custom Fields   (set by admin or frontend before ordering)
2. Configurable Options    (customer selections at checkout)
3. Module Settings         (configoption2 / configoption3 / configoption4)
4. Built-in defaults       (dk / cloud.2 / webdock-ubuntu-jammy-cloud)
```

### Custom Profile Creation

If `Custom Platform` is provided (or all hardware specs are set and platform is defaulted to `intel_vps`), the module:

1. Calls `POST /profiles` with `{ platform, cpu_threads, ram, disk_space, network_bandwidth }`
2. Uses the returned profile slug for server creation
3. Aborts provisioning with a clear error if any required field is missing

**Supported platform values** (all case-insensitive) — see <https://api.webdock.io/v1/platforms> for the full and current list:

| Platform API Value | Accepted Aliases                                  |
| ------------------ | ------------------------------------------------- |
| `intel_vps`        | `intel_vps`, `intel`, `intel vps`                 |
| `epyc_vps`         | `epyc_vps`, `epyc`, `amd`, `amd epyc`, `amd_epyc` |

### Image Slug Resolution

Admins and clients may specify human-readable OS names instead of raw API slugs.  
The module resolves them automatically (case-insensitive).

For the full and up-to-date list of available image slugs and their names, check:  
<https://api.webdock.io/v1/images>

Unrecognised values are passed through unchanged (assumed to already be a valid slug).

### Double-Provision Guard

If `VPS Slug` is already populated in the custom fields when `CreateAccount` runs,  
provisioning is immediately skipped and `success` is returned.  
This prevents duplicate servers when WHMCS retries or an admin re-triggers the action.

### Profile–Location Validation

Before provisioning, the module queries `GET /profiles?locationId={locationId}` and  
verifies the selected profile is available in the chosen location. If not, it switches  
to the closest available profile and logs the change.

---

## Troubleshooting

### View Module Logs

1. **Utilities → Logs → Module Log**
2. Filter by module name `webdock`

Every API call, parameter resolution, and lifecycle event is logged with request body,  
response body, and sensitive values (API token) redacted automatically.

### Common Issues

| Symptom                                                         | Cause                                                      | Fix                                                                                                                                                                        |
| --------------------------------------------------------------- | ---------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Provisioning aborted: "Custom Platform" is set…`               | Custom Platform is set but hardware fields are incomplete. | Set all 5 fields: Custom Platform, CPU Threads, RAM (GB), Disk Space (GB), Network Bandwidth (Gbit/s). Or clear Custom Platform to use a Profile Slug instead.             |
| `Termination failed: …does not have server deletion privileges` | Webdock reseller token lacks DELETE permission.            | Contact Webdock support to enable deletion for your account. Delete the server manually in the Webdock dashboard.                                                           |
| `Cannot suspend — VPS slug missing from service domain field`   | Service domain was not written at creation time.           | Edit the service and set the Domain field to the server slug (visible in the Webdock dashboard).                                                                            |
| `Webdock returned success but no slug in response body`         | API response was unexpected.                               | Check Webdock dashboard — server may have been created. File a Webdock support ticket if not.                                                                              |
| Client area shows "Server is being provisioned"                 | Provisioning is still running (async).                     | Wait 2–5 minutes and refresh. Provisioning status updates when the page is loaded.                                                                                         |
| Selected profile is not valid (400 from Webdock)                | Chosen profile unavailable in selected location.           | The module auto-resolves this; if the error persists, check [available locations](https://api.webdock.io/v1/locations) and [profiles](https://api.webdock.io/v1/profiles?locationId=dk) and update your defaults. |

### Testing Without Billing

Use **Admin → {your test client} → Products/Services → {Service} → Module Commands**  
to manually trigger Create / Suspend / Unsuspend / Terminate and review the Module Log immediately after.

---

## Security Notes

- The Webdock API token is stored in the WHMCS database as a password field (masked in the UI).
- All sensitive parameters (API token) are stripped from Module Log entries automatically.
- Client-area custom action inputs (`reinstallImage`, `snapshotId`, `newShellUsername`, `newShellPassword`) are validated and sanitised before being passed to Webdock API calls.
- Shell passwords are shown **once only** in the success message and never stored by WHMCS.
- The `root` username is explicitly blocked in the shell user creation form and validated server-side.
- All template output uses Smarty's `escape:'html'` modifier to prevent XSS.
- Direct PHP file access is blocked via `if (!defined('WHMCS')) { die(…); }`.

---

## Changelog

### 1.1 — Current

- Added shell user management (create, list, delete) in client area and admin panel
- Added server profile change: dry-run and live change admin actions
- Added Certbot and SSH/server settings admin actions
- Added Emergency Abuse Suspend admin action with configurable notification email
- Added real-time snapshot status normalisation (boolean-to-string casting, type alias mapping)
- Added profile–location validation with auto-resolution before provisioning
- Added image slug resolver supporting human-readable OS names
- Added double-provision guard (checks `VPS Slug` field before creating)
- Improved field resolution: custom fields → configurable options → module settings → built-in defaults
- Added Custom Platform auto-default to `intel_vps` when all hardware specs are present
- Improved module log redaction (API token stripped from all log entries)
- Tab panel works with Bootstrap 3, 4, and 5 via JS shim
- Production hardening: input validation, error surface improvements, 401/403 termination guidance

### 1.0

- Initial release: CreateAccount, SuspendAccount, UnsuspendAccount, TerminateAccount
- Basic client area with power actions (Start, Stop, Reboot)
- Snapshot tab (Create, Restore, Delete)

---

## Support

For issues with this WHMCS module, open an issue in the module repository.  
For Webdock API questions, refer to <https://api.webdock.io/v1> or contact [Webdock support](https://webdock.io) or [Author](https://github.com/Colorado4Sure).
