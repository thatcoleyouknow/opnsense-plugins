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
 */
class ClientsController extends ApiControllerBase
{
    public function searchAction()
    {
        $backend = new Backend();
        $output = trim((string)$backend->configdRun('ctrld clients'));

        $rows = [];
        $lines = $output === '' ? [] : preg_split('/\r?\n/', $output);
        $header = null;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $fields = preg_split('/\s{2,}/', trim($line));
            if ($header === null) {
                $header = array_map(function ($col) {
                    return strtolower(trim($col));
                }, $fields);
                continue;
            }
            $row = array_combine(
                array_slice($header, 0, count($fields)),
                array_slice($fields, 0, count($header))
            );
            $rows[] = [
                'ip' => $row['ip'] ?? '',
                'hostname' => $row['hostname'] ?? '',
                'mac' => $row['mac'] ?? '',
                'source' => $row['source'] ?? ($row['discovery source'] ?? ''),
            ];
        }

        return [
            'rows' => $rows,
            'rowCount' => count($rows),
            'total' => count($rows),
            'current' => 1,
        ];
    }
}
