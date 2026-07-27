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
 * so this controller shells out via configd and parses that table.
 *
 * Confirmed against real output from a live instance (this was previously
 * an untested assumption, and the assumption was wrong): it's a
 * box-drawn ASCII table, not a plain whitespace-aligned one --
 *
 *   +---------------+-----------------------+-------------------+----------------+
 *   |      IP       |       Hostname        |        Mac        |   Discovered   |
 *   +---------------+-----------------------+-------------------+----------------+
 *   | 70.178.43.1   |                       | 00:1c:73:00:09:99 | arp            |
 *   | 70.178.43.73  | OPNsense              | 64:62:66:22:5d:18 | arp,dhcp       |
 *
 * -- with "Mac"/"Discovered" as the real column names (not "MAC"/"Source"),
 * `+---+` border lines to skip, and cells delimited by `|` rather than
 * runs of whitespace. "Discovered" is a comma-separated list of discovery
 * methods (arp, dhcp, ptr, hosts), not a single source value; an empty
 * cell (e.g. no resolved hostname yet) is valid and shown blank.
 *
 * Every value in that table ultimately originates from unauthenticated
 * network input (DHCP hostname options, mDNS, etc. -- anything on any
 * client-facing VLAN can set its own hostname), so nothing from it is
 * trusted here: IP/MAC are format-validated and dropped if malformed,
 * and everything is length-capped -- but deliberately NOT HTML-escaped
 * here. This response feeds two different consumers with two different
 * needs: clients.volt's grid (UIBootgrid/Tabulator) renders plain-string
 * formatter output as text, not HTML, so escaping here just meant a
 * hostname like "Bob&Alice-PC" displayed as "Bob&amp;Alice-PC" and could
 * never be found by searching for a literal "&" (the search phrase was
 * compared against the pre-escaped string). The one consumer that
 * actually builds raw HTML from these values, CtrldClients.js's
 * dashboard widget, does its own escaping at the point it interpolates
 * them -- see that file's own header comment.
 */
class ClientsController extends ApiControllerBase
{
    public function searchAction()
    {
        // Releases PHP's file-based session lock before the configdRun()
        // call below, which shells out to `ctrld clients list` and can
        // take a moment -- without this, the lock serializes every other
        // request from the same browser session behind this one. Matters
        // here specifically because the Discovered Clients dashboard
        // widget polls this endpoint on a timer. Plain session_write_close()
        // rather than an OPNsense-specific wrapper: confirmed no such
        // method exists on ApiControllerBase/ControllerBase, this is
        // standard PHP for releasing the lock mid-request.
        session_write_close();

        $backend = new Backend();
        $output = trim((string)$backend->configdRun('ctrld clients'));
        $searchPhrase = strtolower((string)$this->request->get('searchPhrase', null, ''));

        $rows = [];
        $lines = $output === '' ? [] : preg_split('/\r?\n/', $output);
        $header = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if ($trimmed[0] === '+') {
                // border line (+----+----+), no data
                continue;
            }
            if ($trimmed[0] !== '|') {
                // Doesn't look like ctrld's real table output -- most
                // likely configd returned an error message instead of
                // client data. Stop rather than parsing garbage as rows.
                break;
            }

            // '| a | b | c |' split on '|' yields a leading and trailing
            // empty element from the boundary pipes -- drop both.
            $parts = explode('|', $trimmed);
            array_shift($parts);
            array_pop($parts);
            $fields = array_map('trim', $parts);

            if ($header === null) {
                $header = array_map('strtolower', $fields);
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
                'source' => $this->sanitizeText($row['discovered'] ?? ''),
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
     * Length-cap free-text ctrld output (hostname, discovery source).
     * Deliberately not HTML-escaped here -- see this class's own docblock.
     */
    private function sanitizeText($value, $maxLength = 253)
    {
        return substr((string)$value, 0, $maxLength);
    }

    /**
     * Only pass through a value that's actually an IP address (or ctrld's
     * "*"/unknown sentinel) -- anything else is dropped rather than shown,
     * since a malformed "IP" is not useful information.
     */
    private function sanitizeIp($value)
    {
        $value = trim((string)$value);
        if ($value === '*' || filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return $value;
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
            return $value;
        }
        return '';
    }
}
