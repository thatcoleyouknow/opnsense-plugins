#!/usr/local/bin/php
<?php

/**
 * Copyright (C) 2026 os-ctrld contributors
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

/**
 * Patches ctrld.toml's rendered listener IPs for interfaces whose address
 * isn't a static config.xml field -- WireGuard, OpenVPN, DHCP, PPPoE, etc.
 * The Jinja2 template (service/templates/OPNsense/Ctrld/ctrld.toml) has no
 * access to live interface state at all: confirmed against OPNsense core's
 * own template_helpers.py/template.py, config.xml is its only data source.
 * So those listeners render with whatever config.xml's <ipaddr> element
 * literally contains for a dynamic interface -- the string 'None' (Python's
 * str(None), from a lookup that found nothing at all, e.g. WireGuard/
 * OpenVPN) or one of interfaces.inc's own magic strings ('dhcp', 'pppoe',
 * 'track6', 'slaac', 'dhcp6', confirmed against a real interfaces.inc read)
 * for other dynamic-addressing types -- none of which are a usable IP.
 * ctrld choked on the 'None' case the first time this was attempted
 * ("lookup None on 127.0.0.1:53"); the other magic strings are equally
 * fatal and were an unfixed gap in that first attempt. Detection below
 * therefore checks "is this a valid IP at all" via is_ipaddr(), not
 * "does this match the one placeholder string that got tested." A static
 * VLAN interface's ipaddr already resolves correctly in the template and
 * is never touched here.
 *
 * Run via rc.d/os-ctrld's start_precmd/reload_precmd, immediately after
 * `configd template reload OPNsense/Ctrld` has produced ctrld.toml and
 * immediately before ctrld actually reads it -- deliberately the single
 * choke point every real path to (re)starting ctrld goes through (the
 * GUI's Apply button, this plugin's own boot/WAN-IP-change reconfigure
 * hook, or a bare `service os-ctrld restart`), rather than a PHP-side call
 * added at each caller -- which is exactly the kind of "forgot one call
 * site" bug that took every listener down, not just WireGuard's, the
 * first time this was attempted (2026-07-27).
 *
 * Deliberately does NOT write anything back to config.xml: resolving live
 * interface state doesn't belong in persisted, user-editable config, and
 * doing so from a reconfigure path risks a lost-update race against a
 * concurrent GUI save with no clean way to avoid it.
 *
 * Reads the Jinja2-rendered /etc/controld/ctrld.toml (PRISTINE_TOML_PATH)
 * but never modifies it -- writes the patched result to a separate
 * /etc/controld/ctrld_active.toml (ACTIVE_TOML_PATH), which is what
 * rc.d/os-ctrld's ctrld_config actually points `ctrld run --config` at. This
 * split exists because `service os-ctrld restart` is a real, supported path
 * that does NOT re-render the template first -- with the earlier
 * single-file design, a listener resolved (or dropped) by one patch run
 * stayed that way until the *next full template render*, so an interface
 * that only gains an address after ctrld already started (the common case
 * for an OpenVPN server/client interface waiting on a peer to connect)
 * could never get picked up by a plain restart. Keeping
 * PRISTINE_TOML_PATH untouched means every start/restart/reload re-reads
 * the real current model state and gets a fresh resolution attempt, and
 * makes it safe to run this script repeatedly without accumulating drift
 * from patching an already-patched file.
 *
 * ACTIVE_TOML_PATH deliberately lives under /etc/controld, NOT /var/run:
 * an earlier version of this file used /var/run/ctrld_active.toml, which
 * is wrong -- /var/run is cleared on every boot (tmpfs on some configs,
 * wiped by rc.d's own cleanvar on all of them), so the active config
 * would start every single boot completely absent, not stale-but-valid,
 * turning every one of this script's own bail-out/failure paths (missing
 * pristine render, count mismatch, a caught exception, a failed write)
 * into "ctrld has no config to start with at all" rather than "ctrld
 * starts with what it had before." Persistent storage means those same
 * failure paths degrade to the intended stale-but-valid fallback instead.
 *
 * get_interface_ip()/get_interface_ipv6() are the same functions every
 * core OPNsense service already relies on for this exact problem.
 */

// interfaces.inc's own functions (get_interface_ip() included) call
// into other legacy .inc files (is_ipaddrv4() etc. from util.inc, at
// minimum) without requiring them itself -- confirmed the hard way: the
// first deployed version of this script only required config.inc +
// interfaces.inc and fataled on a live box with "Call to undefined
// function is_ipaddrv4()" the moment it tried to actually resolve
// WireGuard's interface. Rather than guess at exactly which functions
// are transitively needed and risk finding a third missing one on the
// next test, this matches the real require list OPNsense core's own
// src/opnsense/scripts/shell/setaddr.php uses -- a real, confirmed CLI
// script that also calls get_interface_ip() directly, standalone.
require_once 'config.inc';
require_once 'interfaces.inc';
require_once 'util.inc';
require_once 'filter.inc';
require_once 'system.inc';

const PRISTINE_TOML_PATH = '/etc/controld/ctrld.toml';
const ACTIVE_TOML_PATH = '/etc/controld/ctrld_active.toml';

// Real ident/PID on every syslog() call below, instead of relying on
// each message's own "ctrld: " string prefix and whatever default ident
// PHP CLI's syslog() falls back to without this.
openlog('ctrld', LOG_PID, LOG_DAEMON);

/**
 * Atomic, checked write: a short write (disk full -- a real appliance
 * failure mode) must never leave a truncated, invalid config for ctrld to
 * crash-loop on with no logged explanation. Writes to a temp file in the
 * same directory first, then rename()s over the real target -- atomic on
 * the same filesystem, so ctrld (or a concurrent read of this same file)
 * never observes a partially-written file.
 */
function ctrld_write_active_toml($contents)
{
    $tmpPath = ACTIVE_TOML_PATH . '.tmp.' . getmypid();
    // file_put_contents() returns the byte count written, not false, on a
    // short write (e.g. disk full) -- checking only "=== false" misses
    // exactly the truncated-write case this function's own docblock above
    // says it guards against. Comparing against the intended length
    // catches both a hard failure (false) and a partial one.
    $written = @file_put_contents($tmpPath, $contents);
    if ($written === false || $written !== strlen($contents)) {
        syslog(
            LOG_ERR,
            "ctrld: failed to write {$tmpPath} (wrote " . var_export($written, true)
                . ' of ' . strlen($contents) . ' bytes)'
        );
        @unlink($tmpPath);
        return false;
    }
    // 0600: this file (like the pristine render it's derived from)
    // contains NextDNS profile IDs and routing policy -- no other user on
    // the box needs read access. Set on the temp file before the rename
    // so the mode is already correct the instant the real path exists,
    // rather than a brief window where it's whatever chmod()/umask left
    // it at by default. Unlike PRISTINE_TOML_PATH (whose mode comes from
    // configd's Template._generate(), which copies the containing
    // directory's own permission bits and has no per-file mode option in
    // +TARGETS -- confirmed against the real core source), this file is
    // written directly by this script, so its mode is fully ours to set.
    @chmod($tmpPath, 0600);
    if (!@rename($tmpPath, ACTIVE_TOML_PATH)) {
        syslog(LOG_ERR, 'ctrld: failed to rename ' . $tmpPath . ' to ' . ACTIVE_TOML_PATH);
        @unlink($tmpPath);
        return false;
    }
    return true;
}

function ctrld_patch_listener_ips()
{
    if (!file_exists(PRISTINE_TOML_PATH)) {
        // Nothing rendered yet (plugin installed but never applied) --
        // leave ACTIVE_TOML_PATH alone; rc.d/os-ctrld simply won't find a
        // config to start against, same as before this file existed.
        return;
    }

    $contents = file_get_contents(PRISTINE_TOML_PATH);
    if ($contents === false) {
        syslog(LOG_ERR, 'ctrld: failed to read ' . PRISTINE_TOML_PATH);
        return;
    }

    if (strpos($contents, '[listener]') === false) {
        // No listeners configured at all -- nothing to patch, just mirror
        // pristine -> active so ctrld_config always points at whatever the
        // model's current state actually is.
        ctrld_write_active_toml($contents);
        return;
    }

    // Enabled-only, in document order -- matches the Jinja2 template's own
    // ns3.l_idx counter exactly, so the Nth entry here is [listener.N].
    $listeners = [];
    $model = new \OPNsense\Ctrld\Listener();
    foreach ($model->listeners->listener->iterateItems() as $node) {
        if ((string)$node->enabled === '1') {
            $listeners[] = [
                'interface' => (string)$node->interface,
                'ipVersion' => (string)$node->ipVersion,
            ];
        }
    }

    // Split right before each "  [listener.N]" header, keeping everything
    // before the first one (the [network]/[upstream] sections and the
    // "[listener]" table declaration itself) as parts[0], untouched.
    $parts = preg_split('/(?=\n  \[listener\.\d+\]\n)/', $contents);

    // Cross-check the [listener.N] block count the template actually
    // rendered against this script's own independently-computed
    // enabled-listener count before touching anything. These normally
    // agree by construction (both ultimately derive from the same
    // config.xml), but if a save landed between render and patch, or a
    // row's <enabled> value doesn't PHP-cast the same way Jinja2's
    // Undefined-comparison did, positional index N here could silently
    // mean a *different* listener than [listener.N] in the template --
    // which would bind the wrong VLAN's gateway IP, a worse failure than
    // just skipping one listener. Bail out on the whole cycle rather than
    // guess at a mapping that might be wrong; ACTIVE_TOML_PATH is left
    // exactly as it was (a stale-but-valid config from the last good
    // cycle, or simply absent on a first-ever run).
    // Start at $parts[1], not $parts[0]: $parts[0] holds everything before
    // the first [listener.N] header, including the [network]/[upstream]
    // sections -- whose [network.N] blocks render each Policy's own
    // `name = '<description>'` verbatim. A Policy description containing
    // literal text like "[listener.0]" (Policy.xml's Mask permits
    // brackets/digits) would otherwise inflate this count by one and trip
    // the mismatch bail-out below for a reason that has nothing to do
    // with an actual listener/model disagreement.
    $blockCount = 0;
    for ($i = 1; $i < count($parts); $i++) {
        if (preg_match('/\[listener\.\d+\]/', $parts[$i])) {
            $blockCount++;
        }
    }
    if ($blockCount !== count($listeners)) {
        syslog(
            LOG_CRIT,
            "ctrld: listener count mismatch (template rendered {$blockCount}, model reports "
                . count($listeners) . ' enabled), leaving active config unpatched this cycle'
        );
        return;
    }

    for ($i = 1; $i < count($parts); $i++) {
        if (!preg_match('/\[listener\.(\d+)\]/', $parts[$i], $m)) {
            continue;
        }
        $idx = (int)$m[1];
        if (!preg_match("/ip = '([^']*)'/", $parts[$i], $ipMatch)) {
            // No ip = '...' at all in this block -- not a shape this
            // script understands, leave it alone rather than guess.
            continue;
        }
        if (is_ipaddr($ipMatch[1])) {
            // Already a real static/resolved IP (or the lo0 special case,
            // which the template resolves to a literal loopback address on
            // its own) -- never touch a block that isn't showing one of
            // the unresolved-dynamic-interface placeholders.
            continue;
        }
        if (!isset($listeners[$idx])) {
            // Shouldn't happen (would mean the template's and this
            // script's view of which listeners are enabled disagree) --
            // leave it alone rather than guess at what to do with it.
            continue;
        }

        $interface = $listeners[$idx]['interface'];
        $resolved = $listeners[$idx]['ipVersion'] === 'ipv6'
            ? get_interface_ipv6($interface)
            : get_interface_ip($interface);

        if (!empty($resolved) && is_ipaddr($resolved)) {
            $parts[$i] = preg_replace("/ip = '[^']*'/", "ip = '{$resolved}'", $parts[$i], 1);
        } else {
            // Interface has no live address yet (down, or not up this
            // soon after boot) -- drop the whole block rather than leave
            // an invalid ip = 'None' in place, which crash-loops ctrld's
            // single process and takes every other listener down with
            // it. Missing from the rendered config for one cycle is a
            // far smaller failure than that; the next reconfigure (Apply,
            // or the next boot/WAN-event cycle) tries again.
            syslog(
                LOG_WARNING,
                "ctrld: no live IP yet for listener.{$idx} on interface {$interface}, omitting it this cycle"
            );
            $parts[$i] = '';
        }
    }

    $result = implode('', $parts);

    // ctrld's own Config struct requires Listener/Network/Upstream to each
    // have at least one entry, UNCONDITIONALLY -- confirmed against real
    // ctrld source (config.go's `validate:"min=1,dive"` tag on all three),
    // and enforced on every single `ctrld run` via validateConfig(), which
    // os.Exit(1)s on failure. The [network]/[upstream]/[listener] section
    // headers above all render together whenever any listener *row* exists
    // (enabled or not), but nothing guarantees any of the three actually
    // gets a numbered [x.N] sub-entry: every listener could be disabled,
    // every listener that resolved to a dropped block above (no live IP
    // yet), or -- reachable through this plugin's own guided setup, e.g.
    // adding only local-zone-delegation domain policies and never a CIDR
    // one -- zero enabled 'cidr' Policy rows to populate [network] at all.
    // Any of those crash-loops ctrld with no live listener the moment the
    // config is actually used, while the GUI's own Apply reports success
    // (template render + reconfigure don't run ctrld's own validator).
    // Same bail-out posture as the count-mismatch check above: leave
    // ACTIVE_TOML_PATH exactly as it was rather than write a config known
    // to crash ctrld outright.
    foreach (['network', 'upstream', 'listener'] as $section) {
        if (strpos($result, "[{$section}]") !== false && strpos($result, "[{$section}.") === false) {
            syslog(
                LOG_CRIT,
                "ctrld: rendered config has a [{$section}] section with zero entries -- ctrld requires at "
                    . 'least one, leaving active config unpatched this cycle'
            );
            return;
        }
    }

    ctrld_write_active_toml($result);
}

try {
    ctrld_patch_listener_ips();
} catch (\Throwable $e) {
    // Never let a bug here block ctrld from starting at all -- that's the
    // actual failure mode this script exists to prevent, just relocated
    // to a new place if this itself isn't equally defensive.
    syslog(LOG_ERR, 'ctrld: patch_listener_ips.php failed: ' . $e->getMessage());
}
