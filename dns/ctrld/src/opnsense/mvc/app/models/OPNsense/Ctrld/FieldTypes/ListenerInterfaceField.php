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

namespace OPNsense\Ctrld\FieldTypes;

use OPNsense\Base\FieldTypes\InterfaceField;

/**
 * Class ListenerInterfaceField
 * @package OPNsense\Ctrld\FieldTypes
 *
 * Same as the stock InterfaceField (real assigned interfaces only -- no
 * loopback/localhost pseudo-option there), plus one extra static entry for
 * FreeBSD's actual loopback device. Unlike Unbound, ctrld's own listener
 * bind address is resolved in the ctrld.toml template purely from the
 * selected interface's configured IP (see
 * service/templates/OPNsense/Ctrld/ctrld.toml) -- there's no hardcoded
 * "always also bind lo0" behavior the way there is in Unbound's own
 * unbound.inc, so a listener can only ever reach 127.0.0.1/::1 if loopback
 * is an explicitly selectable option here. The template special-cases the
 * 'lo0' key back to the literal address 127.0.0.1 or ::1, picked by the
 * listener's own ipVersion field.
 */
class ListenerInterfaceField extends InterfaceField
{
    protected function actionPostLoadingEvent()
    {
        parent::actionPostLoadingEvent();
        $this->internalOptionList = ['lo0' => gettext('Loopback (127.0.0.1 / ::1)')] + $this->internalOptionList;
    }
}
