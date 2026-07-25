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
 * Class UpstreamController
 * @package OPNsense\Ctrld\Api
 */
class UpstreamController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\Ctrld\Upstream';
    protected static $internalModelName = 'upstream';

    /**
     * Refuse to delete an upstream profile still referenced by a policy
     * rule's ModelRelationField, instead of silently orphaning the rule.
     */
    protected static $internalModelUseSafeDelete = true;

    public function searchItemAction()
    {
        return $this->searchBase('upstreams.upstream');
    }

    public function getItemAction($uuid = null)
    {
        return $this->getBase('upstream', 'upstreams.upstream', $uuid);
    }

    public function addItemAction()
    {
        return $this->addBase('upstream', 'upstreams.upstream');
    }

    public function setItemAction($uuid)
    {
        return $this->setBase('upstream', 'upstreams.upstream', $uuid);
    }

    public function delItemAction($uuid)
    {
        return $this->delBase('upstreams.upstream', $uuid);
    }

    public function toggleItemAction($uuid, $enabled = null)
    {
        return $this->toggleBase('upstreams.upstream', $uuid, $enabled);
    }
}
