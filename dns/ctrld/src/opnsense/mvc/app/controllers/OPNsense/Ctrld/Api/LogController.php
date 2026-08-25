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
 * come from UIBootgrid's own standard toolbar on the frontend;
 * searchAction() parses every line into rows, then hands them to
 * ApiControllerBase::searchRecordsetBase() (see ClientsController for the
 * same shape applied to `ctrld clients list` instead), which is the
 * framework's own documented helper for exactly this "dataset not bound
 * to a model" case.
 *
 * ctrld logs one JSON object per line (confirmed against a real running
 * instance: {"level":"info","time":"...","message":"..."}, sometimes with
 * extra fields like "bootstrap_ip") -- parsed into time/level/message
 * columns when a line parses as such, so the page reads like a normal log
 * instead of a raw JSON blob. A line that doesn't parse (unexpected format,
 * or a future ctrld version) still shows up, verbatim, in the message
 * column, rather than being dropped.
 *
 * Column values are NOT pre-escaped here: OPNsense's grid component
 * (Tabulator, wrapped by opnsense_bootgrid.js) renders plain-string
 * formatter output as text, not HTML -- confirmed empirically (escaping
 * server-side produced visibly double-escaped entities like `&quot;` in
 * the rendered page, since ctrld's JSON lines are full of literal quote
 * characters). This is NOT the same situation as ClientsController: that
 * data also feeds CtrldClients.js's dashboard widget, which builds a raw
 * `<a href="...">${client.ip}</a>` HTML string client-side, so
 * CtrldClients.js's own escapeHtml() (applied client-side, at the point
 * the HTML string is actually built) is genuinely load-bearing there, not
 * redundant with anything server-side.
 */
class LogController extends ApiControllerBase
{
    public function searchAction()
    {
        // Releases PHP's file-based session lock before the configdRun()
        // call below (same reasoning as ClientsController::searchAction())
        // -- every search keystroke or page change on this Log page shells
        // out to `ctrld log`, and without this it'd serialize every other
        // request from the same browser session behind each one.
        session_write_close();

        $backend = new Backend();
        $output = trim((string)$backend->configdRun('ctrld log'));

        $lines = $output === '' ? [] : preg_split('/\r?\n/', $output);
        $lines = array_reverse($lines);

        $rows = [];
        foreach ($lines as $line) {
            $rows[] = $this->parseLine($line);
        }

        // searchRecordsetBase() (ApiControllerBase, documented for exactly
        // this "dataset not bound to a model" case) replaces what used to
        // be a hand-rolled rowCount/current/searchPhrase implementation
        // here. No $defaultSort passed: leaving $rows in the newest-first
        // order already established above, since searchRecordsetBase()
        // only re-sorts when a sort key is actually supplied (an explicit
        // $defaultSort, or the grid's own 'sort' POST parameter).
        return $this->searchRecordsetBase($rows);
    }

    /**
     * 'time'/'level'/'message' are the three real grid columns; 'raw' is
     * not a column at all (no matching <th data-column-id="raw">, so
     * opnsense_bootgrid.js's Tabulator wrapper just ignores the extra
     * key) -- it exists purely so searchRecordsetBase()'s search, which
     * scans every key of every row when no explicit $fields list is
     * passed, can still match against fields ctrld sometimes logs beyond
     * the three parsed ones (this class's own docblock notes
     * "bootstrap_ip" as a real example). Parsing into time/level/message
     * was itself a real narrowing versus the old raw-line substring
     * search this replaced -- 'raw' closes that gap rather than silently
     * shipping it as a regression.
     */
    private function parseLine($line)
    {
        $decoded = json_decode($line, true);
        if (is_array($decoded) && isset($decoded['message'])) {
            return [
                'time' => (string)($decoded['time'] ?? ''),
                'level' => (string)($decoded['level'] ?? ''),
                'message' => (string)$decoded['message'],
                'raw' => $line,
            ];
        }
        return ['time' => '', 'level' => '', 'message' => $line, 'raw' => $line];
    }
}
