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

namespace OPNsense\Ctrld;

use OPNsense\Base\BaseModel;
use OPNsense\Base\Messages\Message;
use OPNsense\Firewall\Util;

/**
 * Class General
 * @package OPNsense\Ctrld
 */
class General extends BaseModel
{
    /**
     * Cross-check the local-zone resolver host/port against Dnsmasq's live
     * config -- but only when the host is actually 127.0.0.1 or localhost,
     * i.e. only when it's plausible this field is meant to point at
     * Dnsmasq's own loopback DNS listener at all. An admin who deliberately
     * points local-zone delegation at something else (a different resolver
     * entirely) isn't assumed to be misconfigured and isn't blocked from
     * saving.
     */
    public function performValidation($validateFullModel = false)
    {
        $messages = parent::performValidation($validateFullModel);

        $host = (string)$this->localZoneResolverHost;
        if (!Util::isIpAddress($host) && $host !== 'localhost') {
            $messages->appendMessage(new Message(
                gettext("Enter a valid IP address of the local resolver used for zone delegation."),
                "localZoneResolverHost"
            ));
        } elseif ((string)$this->enabled == '1' && in_array($host, ['127.0.0.1', '::1', 'localhost'], true)) {
            $dnsmasq = $this->getDnsmasqStatus();
            if ($dnsmasq !== null && !$dnsmasq['enabled']) {
                $messages->appendMessage(new Message(
                    gettext(
                        "The local-zone resolver host points at loopback, which normally means " .
                        "Dnsmasq, but Dnsmasq is not enabled. Local-zone delegation (in-addr.arpa / " .
                        "internal) will fail until Dnsmasq is enabled or this is pointed elsewhere."
                    ),
                    "localZoneResolverHost"
                ));
            } elseif ($dnsmasq !== null && $dnsmasq['port'] !== (string)$this->localZoneResolverPort) {
                $messages->appendMessage(new Message(
                    sprintf(
                        gettext(
                            "Dnsmasq is currently configured on port %s, which does not match the " .
                            "local-zone resolver port configured here (%s). Local-zone delegation " .
                            "(in-addr.arpa / internal) will fail until these match."
                        ),
                        $dnsmasq['port'],
                        (string)$this->localZoneResolverPort
                    ),
                    "localZoneResolverPort"
                ));
            }
        }

        return $messages;
    }

    /**
     * Best-effort read of Dnsmasq's own enabled/port state, so the
     * local-zone delegation helper can be checked against reality instead
     * of an assumed constant. Returns null when Dnsmasq's model can't be
     * loaded. Dnsmasq's own port field is blank by default, meaning 53.
     */
    private function getDnsmasqStatus()
    {
        if (!class_exists('\OPNsense\Dnsmasq\Dnsmasq')) {
            return null;
        }
        try {
            $dnsmasq = new \OPNsense\Dnsmasq\Dnsmasq();
            $port = (string)$dnsmasq->port !== '' ? (string)$dnsmasq->port : '53';
            return [
                'enabled' => (string)$dnsmasq->enable == '1',
                'port' => $port,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }
}
