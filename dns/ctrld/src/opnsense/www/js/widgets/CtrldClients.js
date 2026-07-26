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
 *
 * Values rendered here (hostname, mac, ip, source) come from
 * Api/ClientsController::searchAction(), which already HTML-escapes and
 * format-validates everything server-side -- this widget does not need to
 * (and must not rely on client-side escaping instead of that).
 */

export default class CtrldClients extends BaseTableWidget {
    constructor(config) {
        super(config);
        this.configurable = false;
        this.previousResponseJSON = null;
    }

    getGridOptions() {
        return { sizeToContent: 650 };
    }

    getMarkup() {
        return this.createTable('ctrldClientsTable', { headerPosition: 'left' });
    }

    async onWidgetTick() {
        let generalResponse = await this.ajaxCall('/api/ctrld/general/get');
        if (generalResponse?.general?.enabled !== '1') {
            this.displayError(
                'ctrld ' + this.translations.service_not_running +
                ' <a href="/ui/ctrld/general">' + this.translations.click_here_to_configure + '</a>.'
            );
            return;
        }

        let clientsResponse = await this.ajaxCall('/api/ctrld/clients/search');
        if (JSON.stringify(clientsResponse) === JSON.stringify(this.previousResponseJSON)) {
            return;
        }
        this.previousResponseJSON = clientsResponse;

        // headerPosition: 'left' rows are [leftCell, rightCellOrArray] pairs
        // (see BaseTableWidget.populateRow()) -- not the bootgrid-style
        // {columnId, formatters} shape used elsewhere in this plugin.
        let rows = (clientsResponse?.rows || []).map((client) => {
            let hostname = client.hostname && client.hostname !== '*' ? client.hostname : this.translations.not_available;
            let mac = client.mac && client.mac !== '*' ? client.mac : this.translations.not_available;
            let identity = `<i class="fa fa-laptop"></i> <b data-toggle="tooltip" title="${mac}">${hostname}</b>`;
            let ipLink = `<a href="/ui/ctrld/clients">${client.ip}</a>`;
            let source = client.source || this.translations.not_available;
            return [identity, [ipLink, source]];
        });

        this.updateTable('ctrldClientsTable', rows);
    }
}
