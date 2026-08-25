{#
 # Copyright (C) 2026 os-ctrld contributors
 # All rights reserved.
 #
 # Redistribution and use in source and binary forms, with or without
 # modification, are permitted provided that the following conditions are met:
 #
 # 1. Redistributions of source code must retain the above copyright notice,
 #    this list of conditions and the following disclaimer.
 #
 # 2. Redistributions in binary form must reproduce the above copyright
 #    notice, this list of conditions and the following disclaimer in the
 #    documentation and/or other materials provided with the distribution.
 #
 # THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 # AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 # IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 # ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
 # LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 # CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 # SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 # INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 # CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 # ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 # POSSIBILITY OF SUCH DAMAGE.
 #}

<script>
    $( document ).ready(function() {
        $("#grid-upstreams").UIBootgrid({
            search:'/api/ctrld/upstream/searchItem',
            get:'/api/ctrld/upstream/getItem/',
            set:'/api/ctrld/upstream/setItem/',
            add:'/api/ctrld/upstream/addItem/',
            del:'/api/ctrld/upstream/delItem/',
            toggle:'/api/ctrld/upstream/toggleItem/'
        });

        // NextDNS quick-add: derive type/endpoint from a pasted profile ID.
        // Field ids are rendered as literal id="upstream.nextdnsProfileId"
        // (a dotted id, not data-id) by OPNsense's form partials.
        $(document).on('input', '[id="upstream.nextdnsProfileId"]', function () {
            var profileId = $(this).val().trim();
            if (profileId.length > 0) {
                $('[id="upstream.type"]').val('doh3').selectpicker('refresh');
                $('[id="upstream.endpoint"]').val('https://dns.nextdns.io/' + profileId);
            }
        });

        // Grid edits save immediately via their own addItem/setItem/
        // delItem/toggleItem endpoints, but never regenerate ctrld.toml or
        // restart the service on their own -- this Apply button does that.
        $("#applyUpstreamsAct").SimpleActionButton({});

        updateServiceControlUI('ctrld');
    });
</script>

<div class="content-box">
    {{ partial('layout_partials/base_bootgrid_table', upstreamGrid) }}
</div>
{{ partial('layout_partials/base_apply_button', {'data_endpoint': '/api/ctrld/service/reconfigure', 'data_service_widget': 'ctrld', 'button_id': 'applyUpstreamsAct'}) }}
{{ partial("layout_partials/base_dialog",['fields':upstreamForm,'id':upstreamGrid['edit_dialog_id'],'label':lang._('Edit upstream profile')])}}
