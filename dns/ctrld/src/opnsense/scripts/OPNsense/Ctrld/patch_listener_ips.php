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
 * So those listeners render as the literal string ip = 'None' -- Python's
 * str(None), from a config.xml lookup that found nothing -- which is
 * exactly what ctrld choked on the first time this was attempted
 * ("lookup None on 127.0.0.1:53"). A static VLAN interface's ipaddr
 * already resolves correctly in the template and is never touched here.
 *
 * Run via rc.d/ctrld's start_precmd/reload_precmd, immediately after
 * `configd template reload OPNsense/Ctrld` has produced ctrld.toml and
 * immediately before ctrld actually reads it -- deliberately the single
 * choke point every real path to (re)starting ctrld goes through (the
 * GUI's Apply button, this plugin's own boot/WAN-IP-change reconfigure
 * hook, or a bare `service ctrld restart`), rather than a PHP-side call
 * added at each caller -- which is exactly the kind of "forgot one call
 * site" bug that took every listener down, not just WireGuard's, the
 * first time this was attempted (2026-07-27).
 *
 * Deliberately does NOT write anything back to config.xml: resolving live
 * interface state doesn't belong in persisted, user-editable config, and
 * doing so from a reconfigure path risks a lost-update race against a
 * concurrent GUI save with no clean way to avoid it. This only ever
 * touches the generated /etc/controld/ctrld.toml runtime artifact.
 *
 * get_interface_ip()/get_interface_ipv6() are the same functions every
 * core OPNsense service already relies on for this exact problem.
 */

require_once 'config.inc';
require_once 'interfaces.inc';

const CTRLD_TOML_PATH = '/etc/controld/ctrld.toml';

function ctrld_patch_listener_ips()
{
    if (!file_exists(CTRLD_TOML_PATH)) {
        return;
    }

    $contents = file_get_contents(CTRLD_TOML_PATH);
    if ($contents === false || strpos($contents, '[listener]') === false) {
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

    for ($i = 1; $i < count($parts); $i++) {
        if (!preg_match('/\[listener\.(\d+)\]/', $parts[$i], $m)) {
            continue;
        }
        $idx = (int)$m[1];
        if (strpos($parts[$i], "ip = 'None'") === false) {
            // Already a real static IP (or the lo0 special case, which
            // the template resolves to a literal loopback address on its
            // own) -- never touch a block that isn't showing the
            // unresolved placeholder.
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

        if (!empty($resolved)) {
            $parts[$i] = str_replace("ip = 'None'", "ip = '{$resolved}'", $parts[$i]);
        } else {
            // Interface has no live address yet (down, or not up this
            // soon after boot) -- drop the whole block rather than leave
            // an invalid ip = 'None' in place, which crash-loops ctrld's
            // single process and takes every other listener down with
            // it. Missing from the rendered config for one cycle is a
            // far smaller failure than that; the next reconfigure (Apply,
            // or the next boot/WAN-event cycle) tries again.
            syslog(LOG_WARNING, "ctrld: no live IP yet for listener.{$idx} on interface {$interface}, omitting it this cycle");
            $parts[$i] = '';
        }
    }

    file_put_contents(CTRLD_TOML_PATH, implode('', $parts));
}

try {
    ctrld_patch_listener_ips();
} catch (\Throwable $e) {
    // Never let a bug here block ctrld from starting at all -- that's the
    // actual failure mode this script exists to prevent, just relocated
    // to a new place if this itself isn't equally defensive.
    syslog(LOG_ERR, 'ctrld: patch_listener_ips.php failed: ' . $e->getMessage());
}
