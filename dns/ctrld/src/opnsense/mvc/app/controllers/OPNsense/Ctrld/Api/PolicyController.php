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

/**
 * Class PolicyController
 * @package OPNsense\Ctrld\Api
 */
class PolicyController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\Ctrld\Policy';
    protected static $internalModelName = 'policy';

    /**
     * Deletion of a Listener/Upstream still in use is already safely
     * guarded ($internalModelUseSafeDelete on those models), but
     * *disabling* one isn't -- an enabled Policy left pointing at a
     * disabled Listener or Upstream silently stops doing what the grid
     * appears to say, with no error anywhere. A real
     * performValidation()-based check can't express "warn, don't block"
     * here: OPNsense core's own ApiMutableModelControllerBase::validate()
     * treats every message performValidation() returns as a hard save
     * failure (confirmed against the real, current core source), so this
     * is surfaced here instead, in the grid's own Description column,
     * where it's visible without blocking Save or Apply.
     */
    public function searchItemAction()
    {
        $result = $this->searchBase('policies.policy');
        if (empty($result['rows'])) {
            return $result;
        }

        $disabledListeners = [];
        $listenerModel = new \OPNsense\Ctrld\Listener();
        foreach ($listenerModel->listeners->listener->iterateItems() as $uuid => $node) {
            if ((string)$node->enabled !== '1') {
                $disabledListeners[$uuid] = true;
            }
        }

        $disabledUpstreams = [];
        $upstreamModel = new \OPNsense\Ctrld\Upstream();
        foreach ($upstreamModel->upstreams->upstream->iterateItems() as $uuid => $node) {
            if ((string)$node->enabled !== '1') {
                $disabledUpstreams[$uuid] = true;
            }
        }

        foreach ($result['rows'] as &$row) {
            // Only an enabled Policy is actually trying to route live
            // traffic right now -- a disabled Policy referencing a
            // disabled Listener/Upstream isn't a real, current problem.
            if ((string)($row['enabled'] ?? '') !== '1') {
                continue;
            }
            $problems = [];
            if (isset($disabledListeners[$row['listener'] ?? ''])) {
                $problems[] = gettext('listener disabled');
            }
            if (isset($disabledUpstreams[$row['upstream'] ?? ''])) {
                $problems[] = gettext('upstream disabled');
            }
            if (!empty($row['fallbackUpstream']) && isset($disabledUpstreams[$row['fallbackUpstream']])) {
                $problems[] = gettext('fallback upstream disabled');
            }
            if (!empty($problems)) {
                // \u{26A0}/\u{2014} (warning sign, em dash) only expand inside
                // double-quoted PHP strings -- single-quoted, these would be
                // literal backslash sequences, not the intended characters.
                $warning = "\u{26A0} " . implode(', ', $problems);
                $row['description'] = ($row['description'] ?? '') !== ''
                    ? $warning . " \u{2014} " . $row['description']
                    : $warning;
            }
        }
        unset($row);

        return $result;
    }

    public function getItemAction($uuid = null)
    {
        return $this->getBase('policy', 'policies.policy', $uuid);
    }

    public function addItemAction()
    {
        return $this->addBase('policy', 'policies.policy');
    }

    public function setItemAction($uuid)
    {
        return $this->setBase('policy', 'policies.policy', $uuid);
    }

    public function delItemAction($uuid)
    {
        return $this->delBase('policies.policy', $uuid);
    }

    public function toggleItemAction($uuid, $enabled = null)
    {
        return $this->toggleBase('policies.policy', $uuid, $enabled);
    }
}
