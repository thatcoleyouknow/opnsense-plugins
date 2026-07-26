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

namespace OPNsense\Ctrld\Api;

use OPNsense\Base\ApiMutableServiceControllerBase;
use OPNsense\Core\Backend;

/**
 * Class ServiceController
 * @package OPNsense\Ctrld\Api
 *
 * All start/stop/restart/status/reconfigure behavior is inherited from
 * ApiMutableServiceControllerBase, which drives configd via the four static
 * properties below -- no raw exec() calls. reconfigureAction() (inherited)
 * regenerates ctrld.toml via `configd template reload OPNsense/Ctrld` and
 * then restarts/starts through actions_ctrld.conf, matching the pattern
 * used by dns/dnscrypt-proxy and dns/ddclient's ServiceController classes.
 */
class ServiceController extends ApiMutableServiceControllerBase
{
    protected static $internalServiceClass = '\OPNsense\Ctrld\General';
    protected static $internalServiceTemplate = 'OPNsense/Ctrld';
    protected static $internalServiceEnabled = 'enabled';
    protected static $internalServiceName = 'ctrld';

    /**
     * Recent lines from ctrld's own log file (/var/log/ctrld.log, set via
     * the log_path in the rendered ctrld.toml), for the Log tab. Read-only,
     * no parsing -- unlike ClientsController, this is just raw text for a
     * human to read, not data driving a grid.
     */
    public function logAction()
    {
        $backend = new Backend();
        $response = trim((string)$backend->configdRun(escapeshellarg(static::$internalServiceName) . ' log'));
        return ['log' => $response];
    }
}
