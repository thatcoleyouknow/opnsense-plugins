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

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Config;

/**
 * Class ListenerController
 * @package OPNsense\Ctrld\Api
 */
class ListenerController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\Ctrld\Listener';
    protected static $internalModelName = 'listener';

    /**
     * Refuse to delete a listener still referenced by a policy rule's
     * ModelRelationField, instead of silently orphaning the rule (same
     * reasoning as UpstreamController's use of this).
     */
    protected static $internalModelUseSafeDelete = true;

    /**
     * Suggest the CIDR of the interface a listener is bound to, for the
     * Policy dialog's "Match value" auto-fill -- purely a convenience
     * default; the field it feeds stays a normal editable text input, so
     * this never removes the ability to enter a different CIDR by hand
     * (a narrower range, a non-standard setup, etc.).
     *
     * Network math is done inline with plain PHP (ip2long/long2ip),
     * deliberately not via OPNsense's own gen_subnet() (src/etc/inc/
     * util.inc): that's a legacy-bootstrap global function, and its
     * availability inside the Phalcon MVC/API request lifecycle (as
     * opposed to legacy /www-style pages, which explicitly include that
     * file) was never actually confirmed -- calling an undefined function
     * would fatal the whole request, which is exactly what surfaced as a
     * generic "Unexpected error" dialog on the frontend the first time
     * this shipped. Verified against real VLAN examples (192.168.3.1/24
     * -> 192.168.3.0/24, etc.) before shipping either way, but this
     * version has no dependency on an unconfirmed include path.
     *
     * Returns null (not an error) for the Loopback pseudo-option, an
     * interface with no configured IPv4 address, or any other case where
     * there's nothing sensible to suggest.
     */
    public function cidrAction($uuid)
    {
        // Purely a convenience suggestion for a text field the user can
        // always type over -- never let this be the thing that pops an
        // error dialog over the Policy edit form. Any unexpected failure
        // here (a malformed $uuid, a config shape this doesn't expect on
        // some install, etc.) degrades to "nothing to suggest", not an
        // error surfaced to the user.
        try {
            $data = $this->getBase('listener', 'listeners.listener', $uuid);
            $interface = $this->selectedOption($data['listener']['interface'] ?? '');
            if ($interface === '' || $interface === 'lo0') {
                return ['cidr' => null];
            }

            $configObj = Config::getInstance()->object();
            if (!isset($configObj->interfaces->$interface)) {
                return ['cidr' => null];
            }

            $ifCfg = $configObj->interfaces->$interface;
            $ipaddr = (string)($ifCfg->ipaddr ?? '');
            $subnet = (string)($ifCfg->subnet ?? '');
            $network = $this->networkAddress($ipaddr, $subnet);
            if ($network === null) {
                return ['cidr' => null];
            }

            return ['cidr' => $network . '/' . $subnet];
        } catch (\Throwable $e) {
            return ['cidr' => null];
        }
    }

    /**
     * Mask $ipaddr down to its network address for the given prefix
     * length, or null if either input isn't usable (not IPv4, not a
     * valid 0-32 prefix). Verified: 192.168.3.1/24 -> 192.168.3.0,
     * 192.168.3.130/25 -> 192.168.3.128, 10.0.5.7/8 -> 10.0.0.0.
     */
    private function networkAddress($ipaddr, $subnet)
    {
        if (!ctype_digit((string)$subnet)) {
            return null;
        }
        $prefix = (int)$subnet;
        if ($prefix < 0 || $prefix > 32 || filter_var($ipaddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        $mask = $prefix === 0 ? 0 : ((~0 << (32 - $prefix)) & 0xFFFFFFFF);
        return long2ip(ip2long($ipaddr) & $mask);
    }

    /**
     * getBase()/BaseField::getNodes() returns list-type fields (anything
     * built on BaseListField, which our custom ListenerInterfaceField is)
     * as their full option array -- {optKey: {value, selected}, ...}, per
     * BaseListField::getNodeOptions() -- not the plain selected string a
     * naive (string) cast would assume. Confirmed against real core
     * source, not guessed: BaseField::getNodes() itself explicitly checks
     * is_string() before treating a field's value as one, which only
     * makes sense if it's sometimes NOT a string. Picks out the key whose
     * entry has selected == 1; falls through unchanged if $raw is already
     * a plain string (a simple TextField/IntegerField, unaffected by this
     * at all).
     */
    private function selectedOption($raw)
    {
        if (is_string($raw)) {
            return $raw;
        }
        if (is_array($raw)) {
            foreach ($raw as $key => $opt) {
                if (!empty($opt['selected'])) {
                    return (string)$key;
                }
            }
        }
        return '';
    }

    public function searchItemAction()
    {
        return $this->searchBase('listeners.listener', null, 'interface');
    }

    public function getItemAction($uuid = null)
    {
        return $this->getBase('listener', 'listeners.listener', $uuid);
    }

    public function addItemAction()
    {
        return $this->addBase('listener', 'listeners.listener');
    }

    public function setItemAction($uuid)
    {
        return $this->setBase('listener', 'listeners.listener', $uuid);
    }

    public function delItemAction($uuid)
    {
        return $this->delBase('listeners.listener', $uuid);
    }

    public function toggleItemAction($uuid, $enabled = null)
    {
        return $this->toggleBase('listeners.listener', $uuid, $enabled);
    }
}
