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
use Phalcon\Messages\Message as PhalconMessage;

/**
 * Class General
 * @package OPNsense\Ctrld
 */
class General extends BaseModel
{
    /**
     * Cross-check the local-zone resolver host/port against Unbound's live
     * forward-zone port, when the Unbound model is reachable, so an admin
     * isn't left pointing at a stale port after Unbound's own config
     * changes. This does not hardcode the port -- it only warns when the
     * two disagree.
     */
    public function performValidation($validateFullModel = false)
    {
        $messages = parent::performValidation($validateFullModel);

        if ((string)$this->enabled == '1') {
            $unboundPort = $this->getUnboundLoopbackPort();
            if ($unboundPort !== null && (string)$unboundPort !== (string)$this->localZoneResolverPort) {
                $messages->appendMessage(new Message(
                    sprintf(
                        gettext(
                            "Unbound is currently configured on loopback port %s, which does not " .
                            "match the local-zone resolver port configured here (%s). Local-zone " .
                            "delegation (in-addr.arpa / internal) will fail until these match."
                        ),
                        $unboundPort,
                        (string)$this->localZoneResolverPort
                    ),
                    "localZoneResolverPort"
                ));
            }
        }

        return $messages;
    }

    /**
     * Best-effort read of Unbound's own configured loopback port, so the
     * local-zone delegation helper can be checked against reality instead
     * of an assumed constant. Returns null when Unbound's model can't be
     * loaded or doesn't expose a port in the expected shape.
     */
    private function getUnboundLoopbackPort()
    {
        if (!class_exists('\OPNsense\Unbound\Unbound')) {
            return null;
        }
        try {
            $unbound = new \OPNsense\Unbound\Unbound();
            if (isset($unbound->general->port) && (string)$unbound->general->port !== '') {
                return (string)$unbound->general->port;
            }
        } catch (\Throwable $e) {
            return null;
        }
        return null;
    }
}
