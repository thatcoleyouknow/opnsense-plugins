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

/**
 * Class Listener
 * @package OPNsense\Ctrld
 *
 * Listeners are restricted to a specific interface via InterfaceField at the
 * schema level, so binding to "all interfaces" is not selectable through the
 * GUI. What still needs runtime validation is: no two listeners sharing the
 * same interface+port, and no listener claiming a port another OPNsense
 * service (Unbound or Dnsmasq) already binds on that same interface.
 */
class Listener extends BaseModel
{
    public function performValidation($validateFullModel = false)
    {
        $messages = parent::performValidation($validateFullModel);

        // $seen is built from every enabled row regardless of whether it
        // changed (needed for correct duplicate detection), but a message
        // is only appended for the row actually being edited -- otherwise
        // editing row A on a single-row setItem() would fail because some
        // unrelated, untouched row B happens to already be invalid.
        $seen = [];
        foreach ($this->listeners->listener->iterateItems() as $uuid => $node) {
            if ((string)$node->enabled != '1') {
                continue;
            }
            $shouldReport = $validateFullModel || $node->isFieldChanged();
            $key = (string)$node->interface . '/' . (string)$node->port;
            if (isset($seen[$key]) && $shouldReport) {
                $messages->appendMessage(new Message(
                    sprintf(
                        gettext("Listener on interface %s port %s is already used by another listener."),
                        (string)$node->interface,
                        (string)$node->port
                    ),
                    "listeners.listener.{$uuid}.port"
                ));
            }
            $seen[$key] = true;

            if (!$shouldReport) {
                continue;
            }

            $conflict = $this->findConflictingService((string)$node->interface, (string)$node->port);
            if ($conflict !== null) {
                $messages->appendMessage(new Message(
                    sprintf(
                        gettext(
                            "Interface %s port %s is already bound by %s. Choose a different port or " .
                            "reconfigure %s first -- ctrld will otherwise fail to start."
                        ),
                        (string)$node->interface,
                        (string)$node->port,
                        $conflict,
                        $conflict
                    ),
                    "listeners.listener.{$uuid}.port"
                ));
            }
        }

        return $messages;
    }

    /**
     * Best-effort check against Unbound/Dnsmasq's own live config for a
     * conflicting bind on the same interface+port. Returns the conflicting
     * service's display name, or null when no conflict is found or the
     * other service's model can't be loaded.
     */
    private function findConflictingService($interface, $port)
    {
        if (class_exists('\OPNsense\Unbound\Unbound')) {
            try {
                $unbound = new \OPNsense\Unbound\Unbound();
                if ((string)$unbound->general->enabled == '1' && (string)$unbound->general->port === (string)$port) {
                    // Unbound always implicitly binds lo0 (unbound.inc hardcodes
                    // array_unshift($active_interfaces, 'lo0') at render time), regardless
                    // of what's actually stored in active_interface -- so that field can
                    // never be used to rule out a conflict for our own loopback listener.
                    if (
                        $interface === 'lo0' ||
                        $this->interfaceListMatches((string)($unbound->general->active_interface ?? ''), $interface)
                    ) {
                        return 'Unbound';
                    }
                }
            } catch (\Throwable $e) {
                // model not available on this install; nothing to compare against
            }
        }
        if (class_exists('\OPNsense\Dnsmasq\Dnsmasq')) {
            try {
                $dnsmasq = new \OPNsense\Dnsmasq\Dnsmasq();
                if ((string)$dnsmasq->enable == '1' && (string)$dnsmasq->port === (string)$port) {
                    if ($this->interfaceListMatches((string)($dnsmasq->interface ?? ''), $interface)) {
                        return 'Dnsmasq';
                    }
                }
            } catch (\Throwable $e) {
                // model not available on this install; nothing to compare against
            }
        }
        return null;
    }

    /**
     * $csvInterfaces is a comma-separated interface list (as used by both
     * Unbound's active_interface and Dnsmasq's interface fields) or empty,
     * which for both of those fields means "all interfaces". Checking both
     * port AND interface here (not just port) is what lets this plugin's
     * documented setup -- Dnsmasq staying enabled on its own non-default
     * port for DHCP -- coexist with ctrld listeners on port 53 without a
     * false-positive conflict.
     */
    private function interfaceListMatches($csvInterfaces, $interface)
    {
        $interfaces = array_filter(array_map('trim', explode(',', $csvInterfaces)));
        return empty($interfaces) || in_array($interface, $interfaces, true);
    }
}
