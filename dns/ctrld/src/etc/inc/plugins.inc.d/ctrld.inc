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

function ctrld_enabled()
{
    $model = new \OPNsense\Ctrld\General();
    return (string)$model->enabled == '1';
}

function ctrld_services()
{
    $services = [];

    if (!ctrld_enabled()) {
        return $services;
    }

    $services[] = [
        'description' => gettext('ctrld DNS forwarding proxy'),
        'configd' => [
            'restart' => ['ctrld restart'],
            'start' => ['ctrld start'],
            'stop' => ['ctrld stop'],
        ],
        'name' => 'ctrld',
    ];

    return $services;
}

/**
 * Registers ctrld_configure_do() with OPNsense's plugin hook system so it
 * actually runs on bootup/config-reload, not just when the General tab's
 * Save button is clicked. 'dns' matches the hook group dns/dnscrypt-proxy
 * registers under for the same purpose.
 */
function ctrld_configure()
{
    return [
        'dns' => ['ctrld_configure_do'],
    ];
}

function ctrld_configure_do($verbose = false)
{
    service_log('Configuring ctrld...', $verbose);

    ctrld_resolve_listener_ips();

    $backend = new \OPNsense\Core\Backend();
    $backend->configdRun('template reload OPNsense/Ctrld');

    if (ctrld_enabled()) {
        $backend->configdRun('ctrld restart');
    } else {
        $backend->configdRun('ctrld stop');
    }

    service_log('done.' . "\n", $verbose);
}

/**
 * Resolve each listener's bound IP from its selected interface and persist
 * it into the model, using OPNsense's own get_interface_ip()/
 * get_interface_ipv6() -- the same functions every core service (Unbound,
 * DHCP, etc.) relies on to correctly handle interfaces whose address isn't
 * a static config.xml field: DHCP, PPPoE, WireGuard, OpenVPN, etc. all
 * store nothing under interfaces.<if>.ipaddr, so a plain config.xml lookup
 * returns nothing for them.
 *
 * This has to happen here, in PHP, before the config template renders --
 * not inside the ctrld.toml Jinja2 template itself. Confirmed against
 * OPNsense core's own template_helpers.py: every helper available there
 * (getNodeByTag, physical_interface, etc.) only reads static config.xml
 * data, with no live interface-state query at all, and template.py's
 * Template class takes its data exclusively from set_config() -- there's
 * no hook for a plugin to inject additional computed data into that
 * pipeline. Persisting the resolved value into the model is the only way
 * to make it available where the template can read it.
 *
 * Skips 'lo0': that's a pseudo-interface ListenerInterfaceField adds with
 * no real assigned-interface entry to resolve -- the template already
 * special-cases it to the literal loopback address.
 */
function ctrld_resolve_listener_ips()
{
    require_once 'interfaces.inc';

    $model = new \OPNsense\Ctrld\Listener();
    $changed = false;

    foreach ($model->listeners->listener->iterateItems() as $listener) {
        $interface = (string)$listener->interface;
        if ($interface === '' || $interface === 'lo0') {
            continue;
        }

        $resolved = (string)$listener->ipVersion === 'ipv6'
            ? get_interface_ipv6($interface)
            : get_interface_ip($interface);
        $resolved = (string)($resolved ?? '');

        if ((string)$listener->resolvedIp !== $resolved) {
            $listener->resolvedIp = $resolved;
            $changed = true;
        }
    }

    if ($changed) {
        $model->serializeToConfig();
        \OPNsense\Core\Config::getInstance()->save();
    }
}
