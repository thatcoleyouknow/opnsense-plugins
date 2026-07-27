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

    $backend = new \OPNsense\Core\Backend();
    $backend->configdRun('template reload OPNsense/Ctrld');

    if (ctrld_enabled()) {
        $backend->configdRun('ctrld restart');
    } else {
        $backend->configdRun('ctrld stop');
    }

    service_log('done.' . "\n", $verbose);
}
