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
 * Class ClientsController
 * @package OPNsense\Ctrld\Api
 *
 * ctrld has no JSON/API mode for client discovery (confirmed against its
 * README/wiki -- `ctrld clients list` only prints a human-readable table),
 * so this controller shells out via configd and parses that table. The
 * exact column layout should be re-verified against a live `ctrld clients
 * list` run at deployment time; this parser assumes a whitespace-aligned
 * header row of IP / Hostname / MAC / Source, which is ctrld's documented
 * column order.
 *
 * Every value in that table ultimately originates from unauthenticated
 * network input (DHCP hostname options, mDNS, etc. -- anything on any
 * client-facing VLAN can set its own hostname), so nothing from it is
 * trusted here: IP/MAC are format-validated and dropped if malformed,
 * everything is length-capped and HTML-escaped before it ever reaches the
 * grid or the dashboard widget.
 */
class ClientsController extends ApiControllerBase
{
    public function searchAction()
    {
        $backend = new Backend();
        $output = trim((string)$backend->configdRun('ctrld clients'));
        $searchPhrase = strtolower((string)$this->request->get('searchPhrase', null, ''));

        $rows = [];
        $lines = $output === '' ? [] : preg_split('/\r?\n/', $output);
        $header = null;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $fields = preg_split('/\s{2,}/', trim($line));
            if ($header === null) {
                $candidate = array_map(function ($col) {
                    return strtolower(trim($col));
                }, $fields);
                if (!in_array('ip', $candidate, true)) {
                    // Doesn't look like ctrld's real header row -- most
                    // likely configd returned an error message instead of
                    // client data. Stop rather than parsing garbage as rows.
                    break;
                }
                $header = $candidate;
                continue;
            }
            $row = array_combine(
                array_slice($header, 0, count($fields)),
                array_slice($fields, 0, count($header))
            );
            $entry = [
                'ip' => $this->sanitizeIp($row['ip'] ?? ''),
                'hostname' => $this->sanitizeText($row['hostname'] ?? ''),
                'mac' => $this->sanitizeMac($row['mac'] ?? ''),
                'source' => $this->sanitizeText($row['source'] ?? ($row['discovery source'] ?? '')),
            ];
            if (
                $searchPhrase !== '' &&
                strpos(strtolower(implode(' ', $entry)), $searchPhrase) === false
            ) {
                continue;
            }
            $rows[] = $entry;
        }

        return [
            'rows' => $rows,
            'rowCount' => count($rows),
            'total' => count($rows),
            'current' => 1,
        ];
    }

    /**
     * HTML-escape and length-cap free-text ctrld output (hostname,
     * discovery source) before it reaches a grid or widget.
     */
    private function sanitizeText($value, $maxLength = 253)
    {
        $value = substr((string)$value, 0, $maxLength);
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Only pass through a value that's actually an IP address (or ctrld's
     * "*"/unknown sentinel) -- anything else is dropped rather than
     * escaped-and-shown, since a malformed "IP" is not useful information.
     */
    private function sanitizeIp($value)
    {
        $value = trim((string)$value);
        if ($value === '*' || filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }
        return '';
    }

    /**
     * Only pass through a value that's actually a MAC address (or ctrld's
     * "*"/unknown sentinel, confirmed to appear in real lease data).
     */
    private function sanitizeMac($value)
    {
        $value = trim((string)$value);
        if ($value === '*' || preg_match('/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/', $value)) {
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }
        return '';
    }
}
