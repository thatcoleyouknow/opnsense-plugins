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

use OPNsense\Base\ApiControllerBase;
use OPNsense\Core\Backend;

/**
 * Class LogController
 * @package OPNsense\Ctrld\Api
 *
 * Grid-backed view over ctrld's own log file (/var/log/ctrld.log, tailed
 * by the 'ctrld log' configd action -- see actions.d/actions_ctrld.conf),
 * one row per line, newest first. Search/row-count/pagination/refresh all
 * come from UIBootgrid's own standard toolbar on the frontend; this just
 * has to answer the same searchPhrase/rowCount/current parameters every
 * other grid in this plugin already answers (see ClientsController for
 * the same shape applied to `ctrld clients list` instead).
 */
class LogController extends ApiControllerBase
{
    public function searchAction()
    {
        $backend = new Backend();
        $output = trim((string)$backend->configdRun('ctrld log'));
        $searchPhrase = strtolower((string)$this->request->get('searchPhrase', null, ''));
        $itemsPerPage = (int)$this->request->get('rowCount', 'int', 50);
        if ($itemsPerPage <= 0) {
            $itemsPerPage = 50;
        }
        $currentPage = max(1, (int)$this->request->get('current', 'int', 1));

        $lines = $output === '' ? [] : preg_split('/\r?\n/', $output);
        $lines = array_reverse($lines);

        if ($searchPhrase !== '') {
            $lines = array_values(array_filter($lines, function ($line) use ($searchPhrase) {
                return strpos(strtolower($line), $searchPhrase) !== false;
            }));
        }

        $total = count($lines);
        $pageLines = array_slice($lines, ($currentPage - 1) * $itemsPerPage, $itemsPerPage);

        $rows = [];
        foreach ($pageLines as $line) {
            $rows[] = ['line' => htmlspecialchars($line, ENT_QUOTES, 'UTF-8')];
        }

        return [
            'rows' => $rows,
            'rowCount' => count($rows),
            'total' => $total,
            'current' => $currentPage,
        ];
    }
}
