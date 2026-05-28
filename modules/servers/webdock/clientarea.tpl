{* Webdock VPS - Client Area Template *}

<div class="webdock-panel mt-3">

  {* --- BLOCKED STATES --- *}
  {if $type != 'active'}

    <div class="alert alert-{$badge} d-flex align-items-start" role="alert">
      <div class="flex-grow-1">
        <h5 class="alert-heading mb-1">{$title}</h5>
        <p class="mb-0">{$message}</p>
        {if $type eq 'suspended'}
          <div class="mt-3">
            <a href="clientarea.php?action=invoices" class="btn btn-sm btn-warning">View Invoices</a>
          </div>
        {/if}
      </div>
    </div>

  {* --- ACTIVE STATE --- *}
  {else}

    {if $fetchError}
      <div class="alert alert-warning" role="alert">
        <strong>Could not reach server:</strong> {$fetchError}
      </div>
    {/if}

    {if $actionMessage}
      <div class="alert {if $actionSuccess}alert-success{else}alert-danger{/if}" role="alert">
        {$actionMessage}
      </div>
    {/if}

    {* Tab navigation *}
    <ul class="nav nav-tabs mb-0" id="webdock-tabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="tab-overview-btn"
                data-bs-toggle="tab" data-bs-target="#tab-overview"
                data-toggle="tab" href="#tab-overview"
                type="button" role="tab">
          <i class="fas fa-server me-1"></i> Overview
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-snapshots-btn"
                data-bs-toggle="tab" data-bs-target="#tab-snapshots"
                data-toggle="tab" href="#tab-snapshots"
                type="button" role="tab">
          <i class="fas fa-camera me-1"></i> Snapshots
          {if $snapshotCount > 0}
            <span class="badge bg-secondary ms-1">{$snapshotCount}</span>
          {/if}
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-shellusers-btn"
                data-bs-toggle="tab" data-bs-target="#tab-shellusers"
                data-toggle="tab" href="#tab-shellusers"
                type="button" role="tab">
          <i class="fas fa-users me-1"></i> Shell Users
          {if $shellUserCount > 0}
            <span class="badge bg-secondary ms-1">{$shellUserCount}</span>
          {/if}
        </button>
      </li>
    </ul>

    <div class="tab-content border border-top-0 rounded-bottom p-3 bg-white">

      {* TAB 1 - OVERVIEW *}
      <div class="tab-pane fade show active" id="tab-overview" role="tabpanel">

        <div class="row align-items-center mb-3">
          <div class="col">
            <h5 class="mb-0">{if $serverName}{$serverName}{else}VPS Server{/if}</h5>
            <small class="text-muted">{$slug}</small>
          </div>
          <div class="col-auto">
            <span class="badge bg-{$badge} fs-6 px-3 py-2">{$statusLabel}</span>
          </div>
        </div>

        {if $ipv4 or $ipv6}
          <div class="row mb-3 text-muted small">
            {if $ipv4}<div class="col-sm-6"><strong>IPv4:</strong> <code>{$ipv4}</code></div>{/if}
            {if $ipv6}<div class="col-sm-6"><strong>IPv6:</strong> <code>{$ipv6}</code></div>{/if}
          </div>
        {/if}

        <hr>

        {if $canStart or $canStop or $canReboot}
          <form method="post" action="clientarea.php">
            <input type="hidden" name="action" value="productdetails">
            <input type="hidden" name="token" value="{$token}">
            <input type="hidden" name="id" value="{$serviceid}">
            <div class="d-flex flex-wrap gap-2">
              {if $canStart}
                <button type="submit" name="customAction" value="Start Server" class="btn btn-success"
                        onclick="return confirm('Start this server?')">
                  <i class="fas fa-play me-1"></i> Start
                </button>
              {/if}
              {if $canStop}
                <button type="submit" name="customAction" value="Stop Server" class="btn btn-warning"
                        onclick="return confirm('Stop this server? It will be powered off.')">
                  <i class="fas fa-stop me-1"></i> Stop
                </button>
              {/if}
              {if $canReboot}
                <button type="submit" name="customAction" value="Reboot Server" class="btn btn-secondary"
                        onclick="return confirm('Reboot this server?')">
                  <i class="fas fa-redo me-1"></i> Reboot
                </button>
              {/if}
            </div>
          </form>
        {elseif $serverStatus eq 'provisioning'}
          <div class="alert alert-info mb-0">
            <i class="fas fa-spinner fa-spin me-2"></i>
            <strong>Provisioning in progress.</strong> Power actions will be available once the server is running.
          </div>
        {else}
          <div class="alert alert-secondary mb-0">
            No power actions available for the current server state (<strong>{$statusLabel}</strong>).
          </div>
        {/if}

        {* --- Danger Zone: Reinstall --- *}
        {if $canReinstall}
          <div class="card border-danger mt-4">
            <div class="card-header bg-danger text-white py-2">
              <i class="fas fa-exclamation-triangle me-1"></i> <strong>Danger Zone</strong>
            </div>
            <div class="card-body">
              <p class="text-muted small mb-3">
                <strong>Reinstall</strong> will <span class="text-danger fw-semibold">wipe all data</span> on this
                server and rebuild it from scratch with the selected image. This cannot be undone.
              </p>
              <form method="post" action="clientarea.php" onsubmit="return webdockConfirmReinstall(this)">
                <input type="hidden" name="action" value="productdetails">
                <input type="hidden" name="token"  value="{$token}">
                <input type="hidden" name="id"     value="{$serviceid}">
                <div class="row g-2 align-items-end">
                  <div class="col-sm-8">
                    <label class="form-label fw-semibold small mb-1">Select OS Image</label>
                    <select name="reinstallImage" class="form-select form-select-sm" required>
                      <option value="" disabled selected>— Choose an OS image —</option>
                      {foreach from=$reinstallImages item=img}
                        <option value="{$img.slug}">{$img.label}</option>
                      {/foreach}
                    </select>
                  </div>
                  <div class="col-sm-4">
                    <button type="submit" name="customAction" value="Reinstall Server"
                            class="btn btn-danger w-100 btn-sm">
                      <i class="fas fa-exclamation-triangle me-1"></i> Reinstall
                    </button>
                  </div>
                </div>
              </form>
            </div>
          </div>

          <script>
          function webdockConfirmReinstall(form) {
            var sel = form.reinstallImage;
            if (!sel.value) { alert('Please select an OS image first.'); return false; }
            var label = sel.options[sel.selectedIndex].text;
            var first = confirm(
              'WARNING\u2014This will permanently wipe ALL data on this server and reinstall ' + label + '.\n\n' +
              'This CANNOT be undone. Continue?'
            );
            if (!first) return false;
            return confirm('Are you absolutely sure? Type OK to confirm.\n\nSelected image: ' + label);
          }
          </script>
        {/if}

      </div>{* end tab-overview *}

      {* TAB 2 - SNAPSHOTS *}
      <div class="tab-pane fade" id="tab-snapshots" role="tabpanel">

        {if $snapshotError}
          <div class="alert alert-warning" role="alert">
            <strong>Could not load snapshots</strong> (slug: <code>{$debugSlug}</code>)<br>
            {$snapshotError}
          </div>
        {/if}

        {if $canCreateSnapshot}
          <div class="mb-3 d-flex align-items-center gap-3">
            <form method="post" action="clientarea.php" class="d-inline">
              <input type="hidden" name="action" value="productdetails">
              <input type="hidden" name="token" value="{$token}">
              <input type="hidden" name="id"     value="{$serviceid}">
              <button type="submit" name="customAction" value="Create Snapshot"
                      class="btn btn-primary"
                      onclick="return confirm('Create a snapshot of this server now?')">
                <i class="fas fa-plus me-1"></i> Create New Snapshot
              </button>
            </form>
            <small class="text-muted">Max 3 manual snapshots per server.</small>
          </div>
          <hr>
        {/if}

        {if $snapshotCount > 0}
          <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle mb-2">
              <thead class="table-light">
                <tr>
                  <th>Name</th>
                  <th>Date Taken</th>
                  <th>Type</th>
                  <th>Status</th>
                  <th class="text-center">Restore</th>
                  <th class="text-center">Delete</th>
                </tr>
              </thead>
              <tbody>
                {foreach from=$snapshots item=snap}
                  <tr>
                    <td>
                      <strong>{$snap.name|escape:'html'}</strong>
                      <div class="text-muted small">#{$snap.id}</div>
                    </td>
                    <td class="text-nowrap small">{$snap.date|escape:'html'}</td>
                    <td>
                      {if $snap.type eq 'daily'}
                        <span class="badge bg-info text-dark">Daily</span>
                      {elseif $snap.type eq 'weekly'}
                        <span class="badge bg-info text-dark">Weekly</span>
                      {else}
                        <span class="badge bg-primary">Manual</span>
                      {/if}
                    </td>
                    <td>
                      {if $snap.completed eq 'true'}
                        <span class="badge bg-success">Ready</span>
                      {else}
                        <span class="badge bg-warning text-dark">In Progress</span>
                      {/if}
                    </td>
                    <td class="text-center">
                      {if $snap.completed eq 'true' and $canRestoreSnapshot}
                        <form method="post" action="clientarea.php" class="d-inline">
                          <input type="hidden" name="action"     value="productdetails">
                          <input type="hidden" name="token"      value="{$token}">
                          <input type="hidden" name="id"         value="{$serviceid}">
                          <input type="hidden" name="snapshotId" value="{$snap.id}">
                          <button type="submit" name="customAction" value="Restore Snapshot"
                                  class="btn btn-warning btn-sm"
                                  onclick="return confirm('Restore server to this snapshot? Current data will be overwritten.')">
                            <i class="fas fa-undo me-1"></i> Restore
                          </button>
                        </form>
                      {else}
                        <span class="text-muted">-</span>
                      {/if}
                    </td>
                    <td class="text-center">
                      {if $snap.deletable eq 'true'}
                        <form method="post" action="clientarea.php" class="d-inline">
                          <input type="hidden" name="action"     value="productdetails">
                          <input type="hidden" name="token"      value="{$token}">
                          <input type="hidden" name="id"         value="{$serviceid}">
                          <input type="hidden" name="snapshotId" value="{$snap.id}">
                          <button type="submit" name="customAction" value="Delete Snapshot"
                                  class="btn btn-outline-danger btn-sm"
                                  onclick="return confirm('Permanently delete this snapshot? This cannot be undone.')">
                            <i class="fas fa-trash me-1"></i> Delete
                          </button>
                        </form>
                      {else}
                        <span class="text-muted small">-</span>
                      {/if}
                    </td>
                  </tr>
                {/foreach}
              </tbody>
            </table>
          </div>
          <p class="text-muted small mb-0">
            <i class="fas fa-info-circle me-1"></i>
            Only manual snapshots can be deleted. Daily/weekly snapshots are managed by Webdock.
          </p>
        {else}
          {if not $snapshotError}
            <div class="text-center text-muted py-5">
              <i class="fas fa-camera fa-3x mb-3 d-block opacity-50"></i>
              <strong>No snapshots yet.</strong>
              {if $canCreateSnapshot}
                <p class="mt-2 mb-0">Use <strong>Create New Snapshot</strong> above to take your first snapshot.</p>
              {/if}
            </div>
          {/if}
        {/if}

      </div>{* end tab-snapshots *}

      {* TAB 3 - SHELL USERS *}
      <div class="tab-pane fade" id="tab-shellusers" role="tabpanel">

        {if $shellUserError}
          <div class="alert alert-warning" role="alert">
            <strong>Could not load shell users:</strong> {$shellUserError}
          </div>
        {/if}

        {* Existing shell users table *}
        {if $shellUserCount > 0}
          <div class="table-responsive mb-4">
            <table class="table table-bordered table-hover align-middle mb-2">
              <thead class="table-light">
                <tr>
                  <th>Username</th>
                  <th>Group</th>
                  <th>Shell</th>
                  <th class="text-center">Delete</th>
                </tr>
              </thead>
              <tbody>
                {foreach from=$shellUsers item=su}
                  <tr>
                    <td><strong>{$su.username|escape:'html'}</strong></td>
                    <td><span class="badge bg-secondary">{$su.group|escape:'html'}</span></td>
                    <td><code>{$su.shell|escape:'html'}</code></td>
                    <td class="text-center">
                      <form method="post" action="clientarea.php" class="d-inline">
                        <input type="hidden" name="action"        value="productdetails">
                        <input type="hidden" name="token"         value="{$token}">
                        <input type="hidden" name="id"            value="{$serviceid}">
                        <input type="hidden" name="shellUsername" value="{$su.username|escape:'html'}">
                        <button type="submit" name="customAction" value="Delete Shell User"
                                class="btn btn-outline-danger btn-sm"
                                onclick="return confirm('Delete shell user {$su.username|escape:'html'}? This cannot be undone.')">
                          <i class="fas fa-trash me-1"></i> Delete
                        </button>
                      </form>
                    </td>
                  </tr>
                {/foreach}
              </tbody>
            </table>
          </div>
        {else}
          {if not $shellUserError}
            <div class="text-center text-muted py-4">
              <i class="fas fa-user-slash fa-3x mb-3 d-block opacity-50"></i>
              <strong>No shell users yet.</strong>
              <p class="mt-2 mb-0">Use the form below to add your first shell user.</p>
            </div>
          {/if}
        {/if}

        {* Create shell user form *}
        <hr>
        <h6 class="mb-3"><i class="fas fa-user-plus me-2"></i>Add Shell User</h6>
        <div class="alert alert-info py-2 small mb-3">
          <i class="fas fa-key me-1"></i>
          <strong>Note:</strong> The password is shown <em>once only</em> after creation and cannot be retrieved afterwards.
          Username <code>root</code> is reserved for administrators.
        </div>
        <form method="post" action="clientarea.php" class="row g-3"
              id="webdock-create-shell-user-form"
              onsubmit="return webdockValidateShellUser(this)">
          <input type="hidden" name="action" value="productdetails">
          <input type="hidden" name="token"  value="{$token}">
          <input type="hidden" name="id"     value="{$serviceid}">
          <div class="col-sm-4">
            <label class="form-label fw-semibold">Username</label>
            <input type="text" name="newShellUsername" id="webdock-shell-username"
                   class="form-control" placeholder="admin" value="admin"
                   pattern="[a-zA-Z0-9_]+" maxlength="32" required
                   autocomplete="off">
            <div class="form-text">Letters, numbers, underscore only.</div>
          </div>
          <div class="col-sm-4">
            <label class="form-label fw-semibold">Password</label>
            <input type="password" name="newShellPassword" id="webdock-shell-password"
                   class="form-control" placeholder="Strong password"
                   maxlength="64" required autocomplete="new-password">
            <div class="form-text text-warning">
              <i class="fas fa-exclamation-triangle me-1"></i>Saved once — write it down before submitting.
            </div>
          </div>
          <div class="col-sm-4 d-flex align-items-end">
            <button type="submit" name="customAction" value="Create Shell User"
                    class="btn btn-primary w-100">
              <i class="fas fa-plus me-1"></i> Create Shell User
            </button>
          </div>
        </form>

        <script>
        function webdockValidateShellUser(form) {
          var u = form.newShellUsername.value.trim();
          var p = form.newShellPassword.value;
          if (u.toLowerCase() === 'root') {
            alert('The username "root" is reserved for administrators and cannot be created here.');
            return false;
          }
          if (!/^[a-zA-Z0-9_]+$/.test(u)) {
            alert('Username may only contain letters, numbers and underscore.');
            return false;
          }
          if (!/^[a-zA-Z0-9_\-]+$/.test(p)) {
            alert('Password may only contain letters, numbers, underscore and dash.');
            return false;
          }
          if (p.length < 6) {
            alert('Password must be at least 6 characters.');
            return false;
          }
          return confirm('Create shell user "' + u + '"?\n\nThe password will only be shown once in the success message. Save it immediately.');
        }
        </script>

      </div>{* end tab-shellusers *}

    </div>{* end tab-content *}

    {* Tab switching shim - works with BS3, BS4 and BS5 *}
    <script>
    (function() {
      var btns = document.querySelectorAll('#webdock-tabs .nav-link');
      btns.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
          e.preventDefault();
          btns.forEach(function(b) { b.classList.remove('active'); });
          document.querySelectorAll('.webdock-panel .tab-pane').forEach(function(p) {
            p.classList.remove('show', 'active');
          });
          this.classList.add('active');
          var target = this.getAttribute('data-bs-target') || this.getAttribute('href');
          var pane = document.querySelector(target);
          if (pane) { pane.classList.add('show', 'active'); }
        });
      });
    })();
    </script>

  {/if}{* end active *}

</div>{* end webdock-panel *}

