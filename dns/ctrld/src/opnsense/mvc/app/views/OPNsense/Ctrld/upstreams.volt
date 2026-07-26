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
    <table id="grid-upstreams" class="table table-condensed table-hover table-striped" data-editDialog="DialogEditUpstream">
        <thead>
            <tr>
                <th data-column-id="enabled" data-type="string" data-formatter="rowtoggle">{{ lang._('Enabled') }}</th>
                <th data-column-id="name" data-type="string">{{ lang._('Name') }}</th>
                <th data-column-id="type" data-type="string">{{ lang._('Type') }}</th>
                <th data-column-id="endpoint" data-type="string">{{ lang._('Endpoint') }}</th>
                <th data-column-id="uuid" data-type="string" data-formatter="commands" data-identifier="true">{{ lang._('Commands') }}</th>
            </tr>
        </thead>
        <tbody></tbody>
        <tfoot>
            <tr>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td>
                    <button data-action="add" type="button" class="btn btn-xs btn-primary"><span class="fa fa-plus"></span></button>
                </td>
            </tr>
        </tfoot>
    </table>
    {{ partial("layout_partials/base_dialog",['fields':upstreamForm,'id':'DialogEditUpstream','label':lang._('Edit upstream profile')])}}
    {{ partial('layout_partials/base_apply_button', {'data_endpoint': '/api/ctrld/service/reconfigure', 'data_service_widget': 'ctrld', 'button_id': 'applyUpstreamsAct'}) }}
</div>
