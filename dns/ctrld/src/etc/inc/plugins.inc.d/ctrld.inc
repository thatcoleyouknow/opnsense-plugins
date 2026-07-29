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

    // dns_ports is the real, established convention core's own Unbound/
    // Dnsmasq plugin hooks both publish (unbound.inc/dnsmasq.inc's own
    // *_services(), confirmed against real core source) so that *other*
    // DNS services' own conflict checks (e.g. Dnsmasq's
    // performValidation(), which walks `configd service list` and flags
    // any other service sharing dns_ports) can warn about colliding with
    // this one -- without it, ctrld was only ever the passive side of a
    // port conflict (see Listener::findConflictingService()), never the
    // one other services could detect. Distinct ports across every
    // *enabled* listener row, as plain strings to match the convention.
    $dnsPorts = [];
    $listenerModel = new \OPNsense\Ctrld\Listener();
    foreach ($listenerModel->listeners->listener->iterateItems() as $node) {
        if ((string)$node->enabled === '1') {
            $dnsPorts[(string)$node->port] = true;
        }
    }

    $services[] = [
        'description' => gettext('ctrld DNS forwarding proxy'),
        'configd' => [
            'restart' => ['ctrld restart'],
            'start' => ['ctrld start'],
            'stop' => ['ctrld stop'],
        ],
        'dns_ports' => array_keys($dnsPorts),
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

    $backend = new \OPNsense\Core\Backend();
    $result = trim($backend->configdRun('template reload OPNsense/Ctrld'));

    // core's own ApiMutableServiceControllerBase::reconfigureAction() (the
    // interactive Apply-button path) refuses to proceed past a failed
    // template render, checking for the literal 'OK' marker configd's
    // template.reload wrapper returns on success -- confirmed against the
    // real, current core source. This boot/WAN-event path used to skip
    // that check entirely and restart regardless (matching the real
    // dnscryptproxy_configure_do() plugin hook, which also doesn't check
    // it) -- but unlike a one-off interactive Apply click, this path also
    // runs unattended on every boot and WAN-IP-change event, so a broken
    // template here has more chances to matter and no user watching to
    // notice a failure. Restarting ctrld against a template render that
    // may not have produced a complete/valid ctrld.toml risks the same
    // "one bad listener takes every listener down" failure class this
    // whole plugin has already been bitten by twice.
    //
    // A single retry on an EMPTY result specifically: Backend::configdRun()
    // also returns '' on a socket timeout or if configd is momentarily
    // unreachable -- indistinguishable from "got a response and it wasn't
    // OK" by string comparison alone, but a genuinely different, often
    // transient condition (this runs at boot, when configd itself may
    // still be starting up). One immediate retry costs nothing when
    // configd is actually fine, and turns a boot-timing race into a
    // successful restart instead of a silently-skipped one.
    if ($result === '') {
        $result = trim($backend->configdRun('template reload OPNsense/Ctrld'));
    }
    if ($result !== 'OK') {
        syslog(LOG_ERR, "ctrld: template reload failed ({$result}), skipping restart this cycle");
        service_log("template reload failed, skipping restart.\n", $verbose);
        return;
    }

    if (ctrld_enabled()) {
        $backend->configdRun('ctrld restart');
    } else {
        $backend->configdRun('ctrld stop');
    }

    service_log('done.' . "\n", $verbose);
}
