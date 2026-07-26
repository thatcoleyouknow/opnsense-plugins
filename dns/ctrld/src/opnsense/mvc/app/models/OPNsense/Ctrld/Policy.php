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
 * Class Policy
 * @package OPNsense\Ctrld
 */
class Policy extends BaseModel
{
    public function performValidation($validateFullModel = false)
    {
        $messages = parent::performValidation($validateFullModel);

        // A domain label is 1-63 chars of alnum/hyphen (not starting/ending
        // with a hyphen), optionally preceded by a "*." wildcard (e.g.
        // *.in-addr.arpa, shown as a valid example in the GUI's own help
        // text), with a length cap matching RFC 1035's 253-char limit.
        $domainPattern = '/^(\*\.)?' .
            '([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.){0,10}' .
            '[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/';

        foreach ($this->policies->policy->iterateItems() as $uuid => $node) {
            if (!$validateFullModel && !$node->isFieldChanged()) {
                continue;
            }
            $matchType = (string)$node->matchType;
            $matchValue = (string)$node->matchValue;

            if (strlen($matchValue) > 253) {
                $messages->appendMessage(new Message(
                    gettext("Match value is too long (253 characters max)."),
                    "policies.policy.{$uuid}.matchValue"
                ));
            } elseif ($matchType === 'cidr' && !Util::isSubnet($matchValue)) {
                $messages->appendMessage(new Message(
                    gettext("Enter a valid CIDR, e.g. 192.168.3.0/24."),
                    "policies.policy.{$uuid}.matchValue"
                ));
            } elseif ($matchType === 'domain' && !preg_match($domainPattern, $matchValue)) {
                $messages->appendMessage(new Message(
                    gettext("Enter a valid domain name, e.g. internal or *.in-addr.arpa."),
                    "policies.policy.{$uuid}.matchValue"
                ));
            } elseif ($matchType === 'mac' && !preg_match('/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/', $matchValue)) {
                $messages->appendMessage(new Message(
                    gettext("Enter a valid MAC address, e.g. aa:bb:cc:dd:ee:ff."),
                    "policies.policy.{$uuid}.matchValue"
                ));
            }

            $fallbackUpstream = (string)$node->fallbackUpstream;
            if ($fallbackUpstream !== '' && $fallbackUpstream === (string)$node->upstream) {
                $messages->appendMessage(new Message(
                    gettext("Fallback upstream must be different from the primary upstream above."),
                    "policies.policy.{$uuid}.fallbackUpstream"
                ));
            }
        }

        return $messages;
    }
}
