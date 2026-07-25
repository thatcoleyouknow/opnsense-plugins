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
                'ctrld ' + i18n.t('widget.dashboard.service_not_running') +
                ' <a href="/ui/ctrld/general">' + i18n.t('widget.dashboard.click_here_to_configure') + '</a>.'
            );
            return;
        }

        let clientsResponse = await this.ajaxCall('/api/ctrld/clients/search');
        if (JSON.stringify(clientsResponse) === JSON.stringify(this.previousResponseJSON)) {
            return;
        }
        this.previousResponseJSON = clientsResponse;

        let rows = (clientsResponse?.rows || []).map((client) => {
            return [
                {
                    columnId: 'host',
                    formatters: [
                        {
                            type: 'text_with_tooltip',
                            text: client.hostname && client.hostname !== '*' ? client.hostname : i18n.t('widget.dashboard.not_available'),
                            tooltip: client.mac && client.mac !== '*' ? client.mac : i18n.t('widget.dashboard.not_available'),
                            icon: 'fa-laptop'
                        }
                    ]
                },
                {
                    columnId: 'ip',
                    formatters: [
                        {
                            type: 'link',
                            text: client.ip,
                            href: '/ui/ctrld/general#clients'
                        }
                    ]
                },
                {
                    columnId: 'source',
                    formatters: [{ type: 'text', text: client.source || '' }]
                }
            ];
        });

        this.updateTable('ctrldClientsTable', rows);
    }
}
