<script>
    $( document ).ready(function() {
        var data_get_map = {'frm_GeneralSettings':"/api/ctrld/general/get"};
        mapDataToFormUI(data_get_map).done(function(data){
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
        });

        $("#grid-listeners").UIBootgrid({
            search:'/api/ctrld/listener/searchItem',
            get:'/api/ctrld/listener/getItem/',
            set:'/api/ctrld/listener/setItem/',
            add:'/api/ctrld/listener/addItem/',
            del:'/api/ctrld/listener/delItem/',
            toggle:'/api/ctrld/listener/toggleItem/'
        });

        $("#grid-upstreams").UIBootgrid({
            search:'/api/ctrld/upstream/searchItem',
            get:'/api/ctrld/upstream/getItem/',
            set:'/api/ctrld/upstream/setItem/',
            add:'/api/ctrld/upstream/addItem/',
            del:'/api/ctrld/upstream/delItem/',
            toggle:'/api/ctrld/upstream/toggleItem/'
        });

        $("#grid-policies").UIBootgrid({
            search:'/api/ctrld/policy/searchItem',
            get:'/api/ctrld/policy/getItem/',
            set:'/api/ctrld/policy/setItem/',
            add:'/api/ctrld/policy/addItem/',
            del:'/api/ctrld/policy/delItem/',
            toggle:'/api/ctrld/policy/toggleItem/'
        });

        $("#grid-clients").UIBootgrid({
            search:'/api/ctrld/clients/search'
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

        $("#saveAct").click(function(){
            saveFormToEndpoint("/api/ctrld/general/set", 'frm_GeneralSettings', function(){
                ajaxCall("/api/ctrld/service/reconfigure", {}, function(){
                    updateServiceControlUI('ctrld');
                });
            });
        });

        // Listener/Upstream/Policy grid edits save immediately via their
        // own addItem/setItem/delItem/toggleItem endpoints, but (unlike
        // the General tab's Save button) never regenerate ctrld.toml or
        // restart the service on their own -- these Apply buttons do that.
        $(".btn-apply-ctrld").click(function(){
            ajaxCall("/api/ctrld/service/reconfigure", {}, function(){
                updateServiceControlUI('ctrld');
            });
        });

        // Local-zone delegation helper: reuses (rather than duplicates) a
        // "Local resolver" upstream pointing at the configured
        // localZoneResolverHost/Port, then creates one pair of domain-match
        // policy rows per existing enabled listener (a Policy row requires
        // a listener -- this can't create a listener-less rule). The
        // 168.192.in-addr.arpa zone covers the common 192.168.0.0/16 home
        // range specifically; add further reverse-zone rows by hand on the
        // Policies tab for other private ranges (10.0.0.0/8, etc.).
        $("#createLocalZoneDelegation").click(function(){
            var host = $('[id="general.localZoneResolverHost"]').val();
            var port = $('[id="general.localZoneResolverPort"]').val();

            function createDelegationRules(upstreamUuid) {
                ajaxCall("/api/ctrld/listener/searchItem", {}, function(listenerResponse){
                    var listeners = (listenerResponse.rows || []).filter(function(row){
                        return row.enabled === '1';
                    });
                    if (listeners.length === 0) {
                        alert("Add at least one listener first -- local-zone delegation rules are created per listener.");
                        return;
                    }
                    listeners.forEach(function(listener){
                        ['168.192.in-addr.arpa', 'internal'].forEach(function(zone){
                            ajaxCall("/api/ctrld/policy/addItem", {
                                policy: {
                                    enabled: '1',
                                    description: 'Local-zone delegation: ' + zone,
                                    listener: listener.uuid,
                                    matchType: 'domain',
                                    matchValue: zone,
                                    upstream: upstreamUuid
                                }
                            }, function(policyResponse){
                                if (policyResponse.result === 'failed') {
                                    alert("Failed to create the " + zone + " delegation rule for listener " + listener.description + ".");
                                    return;
                                }
                                $("#grid-policies").bootgrid('reload');
                            });
                        });
                    });
                    $("#grid-upstreams").bootgrid('reload');
                });
            }

            ajaxCall("/api/ctrld/upstream/searchItem", {}, function(searchResponse){
                var existing = (searchResponse.rows || []).filter(function(row){
                    return row.name === 'Local resolver' || row.name === 'Local resolver (Unbound)';
                })[0];

                if (existing) {
                    createDelegationRules(existing.uuid);
                    return;
                }

                ajaxCall("/api/ctrld/upstream/addItem", {
                    upstream: {
                        enabled: '1',
                        name: 'Local resolver',
                        type: 'legacy',
                        endpoint: host + ':' + port,
                        timeout: '5000'
                    }
                }, function(addResponse){
                    if (addResponse.result === 'failed' || !addResponse.uuid) {
                        alert("Failed to create the Local resolver upstream -- check the Local-zone resolver host/port on the General tab.");
                        return;
                    }
                    createDelegationRules(addResponse.uuid);
                });
            });
        });

        updateServiceControlUI('ctrld');
    });
</script>

<ul class="nav nav-tabs" data-tabs="tabs" id="maintabs">
    <li class="active"><a data-toggle="tab" href="#general">{{ lang._('General') }}</a></li>
    <li><a data-toggle="tab" href="#listeners">{{ lang._('Listeners') }}</a></li>
    <li><a data-toggle="tab" href="#upstreams">{{ lang._('Upstreams') }}</a></li>
    <li><a data-toggle="tab" href="#policies">{{ lang._('Policies') }}</a></li>
    <li><a data-toggle="tab" href="#localzone">{{ lang._('Local-Zone Delegation') }}</a></li>
    <li><a data-toggle="tab" href="#clients">{{ lang._('Discovered Clients') }}</a></li>
</ul>

<div class="tab-content content-box">
    <div id="general" class="tab-pane fade in active">
        {{ partial("layout_partials/base_form",['fields':generalForm,'id':'frm_GeneralSettings'])}}
        <div class="col-md-12">
            <button class="btn btn-primary" id="saveAct" type="button">
                <b>{{ lang._('Save') }}</b>
                <i id="saveAct_progress" class=""></i>
            </button>
        </div>
    </div>

    <div id="listeners" class="tab-pane fade">
        <table id="grid-listeners" class="table table-condensed table-hover table-striped" data-editDialog="DialogEditListener">
            <thead>
                <tr>
                    <th data-column-id="enabled" data-type="string" data-formatter="rowtoggle">{{ lang._('Enabled') }}</th>
                    <th data-column-id="description" data-type="string">{{ lang._('Description') }}</th>
                    <th data-column-id="interface" data-type="string">{{ lang._('Interface') }}</th>
                    <th data-column-id="port" data-type="string">{{ lang._('Port') }}</th>
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
        {{ partial("layout_partials/base_dialog",['fields':listenerForm,'id':'DialogEditListener','label':lang._('Edit listener')])}}
        <div class="col-md-12">
            <button class="btn btn-primary btn-apply-ctrld" type="button">
                <b>{{ lang._('Apply') }}</b>
            </button>
        </div>
    </div>

    <div id="upstreams" class="tab-pane fade">
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
        <div class="col-md-12">
            <button class="btn btn-primary btn-apply-ctrld" type="button">
                <b>{{ lang._('Apply') }}</b>
            </button>
        </div>
    </div>

    <div id="policies" class="tab-pane fade">
        <table id="grid-policies" class="table table-condensed table-hover table-striped" data-editDialog="DialogEditPolicy">
            <thead>
                <tr>
                    <th data-column-id="enabled" data-type="string" data-formatter="rowtoggle">{{ lang._('Enabled') }}</th>
                    <th data-column-id="description" data-type="string">{{ lang._('Description') }}</th>
                    <th data-column-id="matchType" data-type="string">{{ lang._('Match type') }}</th>
                    <th data-column-id="matchValue" data-type="string">{{ lang._('Match value') }}</th>
                    <th data-column-id="upstream" data-type="string">{{ lang._('Upstream') }}</th>
                    <th data-column-id="uuid" data-type="string" data-formatter="commands" data-identifier="true">{{ lang._('Commands') }}</th>
                </tr>
            </thead>
            <tbody></tbody>
            <tfoot>
                <tr>
                    <td></td><td></td><td></td><td></td><td></td>
                    <td>
                        <button data-action="add" type="button" class="btn btn-xs btn-primary"><span class="fa fa-plus"></span></button>
                    </td>
                </tr>
            </tfoot>
        </table>
        {{ partial("layout_partials/base_dialog",['fields':policyForm,'id':'DialogEditPolicy','label':lang._('Edit policy rule')])}}
        <div class="col-md-12">
            <button class="btn btn-primary btn-apply-ctrld" type="button">
                <b>{{ lang._('Apply') }}</b>
            </button>
        </div>
    </div>

    <div id="localzone" class="tab-pane fade">
        <button id="createLocalZoneDelegation" type="button" class="btn btn-primary">
            {{ lang._('Create local-zone delegation rules') }}
        </button>
    </div>

    <div id="clients" class="tab-pane fade">
        <table id="grid-clients" class="table table-condensed table-hover table-striped">
            <thead>
                <tr>
                    <th data-column-id="ip" data-type="string">{{ lang._('IP') }}</th>
                    <th data-column-id="hostname" data-type="string">{{ lang._('Hostname') }}</th>
                    <th data-column-id="mac" data-type="string">{{ lang._('MAC') }}</th>
                    <th data-column-id="source" data-type="string">{{ lang._('Discovery source') }}</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
