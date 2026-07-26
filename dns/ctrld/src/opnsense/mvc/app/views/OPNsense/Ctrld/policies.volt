<script>
    $( document ).ready(function() {
        $("#grid-policies").UIBootgrid({
            search:'/api/ctrld/policy/searchItem',
            get:'/api/ctrld/policy/getItem/',
            set:'/api/ctrld/policy/setItem/',
            add:'/api/ctrld/policy/addItem/',
            del:'/api/ctrld/policy/delItem/',
            toggle:'/api/ctrld/policy/toggleItem/'
        });

        // Grid edits save immediately via their own addItem/setItem/
        // delItem/toggleItem endpoints, but never regenerate ctrld.toml or
        // restart the service on their own -- this Apply button does that.
        $("#applyPoliciesAct").SimpleActionButton({});

        // Suggest a CIDR from the selected listener's own interface, for
        // cidr-type rules -- purely a convenience default. The field itself
        // stays a normal editable text input either way.
        //
        // Changing the Listener always refreshes the suggestion (force):
        // since the Listener field is required, the dropdown has no blank
        // option and defaults to the first listener in the list, so the
        // very act of picking the listener you actually want is itself a
        // "change" event -- treating that like a value the user typed on
        // purpose and refusing to overwrite it would mean the suggestion
        // never updates after that first default selection.
        //
        // Changing Match type only fills when empty (not force): that event
        // is more often exploratory (flipping through match types while
        // deciding), so a value already typed for a previous match type is
        // left alone rather than silently replaced.
        function suggestPolicyCidr(force) {
            var $matchValue = $('[id="policy.matchValue"]');
            var matchType = $('[id="policy.matchType"]').val();
            var listenerUuid = $('[id="policy.listener"]').val();
            if (matchType !== 'cidr' || !listenerUuid) {
                return;
            }
            if (!force && $matchValue.val().trim() !== '') {
                return;
            }
            ajaxCall("/api/ctrld/listener/cidr/" + listenerUuid, {}, function (response) {
                if (response && response.cidr && (force || $matchValue.val().trim() === '')) {
                    $matchValue.val(response.cidr);
                }
            });
        }
        $(document).on('change', '[id="policy.listener"]', function () {
            suggestPolicyCidr(true);
        });
        $(document).on('change', '[id="policy.matchType"]', function () {
            suggestPolicyCidr(false);
        });

        // Local-zone delegation helper: reuses (rather than duplicates) a
        // "Local resolver" upstream pointing at the configured
        // localZoneResolverHost/Port, then creates one pair of domain-match
        // policy rows per existing enabled listener (a Policy row requires
        // a listener -- this can't create a listener-less rule). The
        // 168.192.in-addr.arpa zone covers the common 192.168.0.0/16 home
        // range specifically; add further reverse-zone rows by hand for
        // other private ranges (10.0.0.0/8, etc.).
        //
        // Lives on this page (not its own blade) since it only ever
        // mutates rows shown in the grid above -- reloads the grid once
        // it's done so new/skipped rows show up without a manual refresh.
        //
        // Checks existing Policy rows (not just the Upstream row) before
        // creating anything, and disables the button while running with a
        // completion dialog at the end.
        //
        // Fetches localZoneResolverHost/Port from /api/ctrld/general/get
        // via ajaxGet(), not ajaxCall(): ApiMutableModelControllerBase's
        // inherited getAction() only populates its response when
        // $this->request->isGet() -- ajaxCall() always POSTs (confirmed
        // against OPNsense core's opnsense.js), which made this silently
        // come back as an empty [], read below as "host/port not set".
        $("#createLocalZoneDelegation").click(function(){
            var $btn = $(this);
            if ($btn.prop('disabled')) {
                return;
            }
            var zones = ['168.192.in-addr.arpa', 'internal'];
            var originalHtml = $btn.html();

            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-pulse"></i> ' + originalHtml);

            function finish(message, isError) {
                $btn.prop('disabled', false).html(originalHtml);
                BootstrapDialog.show({
                    type: isError ? BootstrapDialog.TYPE_WARNING : BootstrapDialog.TYPE_SUCCESS,
                    title: 'Local-zone delegation',
                    message: message
                });
            }

            function withUpstream(upstreamUuid) {
                ajaxCall("/api/ctrld/listener/searchItem", {}, function(listenerResponse){
                    var listeners = (listenerResponse.rows || []).filter(function(row){
                        return row.enabled === '1';
                    });
                    if (listeners.length === 0) {
                        finish("Add at least one listener first -- local-zone delegation rules are created per listener.", true);
                        return;
                    }
                    ajaxCall("/api/ctrld/policy/searchItem", {}, function(policyResponse){
                        var existingPolicies = policyResponse.rows || [];
                        function alreadyExists(listenerUuid, zone) {
                            return existingPolicies.some(function(row){
                                return row.listener === listenerUuid && row.matchType === 'domain' && row.matchValue === zone;
                            });
                        }
                        var toCreate = [];
                        listeners.forEach(function(listener){
                            zones.forEach(function(zone){
                                if (!alreadyExists(listener.uuid, zone)) {
                                    toCreate.push({listener: listener, zone: zone});
                                }
                            });
                        });
                        if (toCreate.length === 0) {
                            finish("Nothing to do -- local-zone delegation rules already exist for every enabled listener.");
                            return;
                        }
                        var deferreds = toCreate.map(function(item){
                            return ajaxCall("/api/ctrld/policy/addItem", {
                                policy: {
                                    enabled: '1',
                                    description: 'Local-zone delegation: ' + item.zone,
                                    listener: item.listener.uuid,
                                    matchType: 'domain',
                                    matchValue: item.zone,
                                    upstream: upstreamUuid
                                }
                            });
                        });
                        $.when.apply($, deferreds).always(function(){
                            $("#grid-policies").bootgrid('reload');
                            finish("Created " + toCreate.length + " local-zone delegation rule(s).");
                        });
                    });
                });
            }

            function withGeneralSettings(host, port) {
                ajaxCall("/api/ctrld/upstream/searchItem", {}, function(searchResponse){
                    var existing = (searchResponse.rows || []).filter(function(row){
                        return row.name === 'Local resolver' || row.name === 'Local resolver (Unbound)';
                    })[0];

                    if (existing) {
                        withUpstream(existing.uuid);
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
                            finish("Failed to create the Local resolver upstream -- check the Local-zone resolver host/port on the General page.", true);
                            return;
                        }
                        withUpstream(addResponse.uuid);
                    });
                });
            }

            ajaxGet("/api/ctrld/general/get", {}, function(generalResponse){
                var general = generalResponse.general || {};
                if (!general.localZoneResolverHost || !general.localZoneResolverPort) {
                    finish("Set the Local-zone resolver host/port on the General page first.", true);
                    return;
                }
                withGeneralSettings(general.localZoneResolverHost, general.localZoneResolverPort);
            });
        });

        updateServiceControlUI('ctrld');
    });
</script>

<div class="content-box">
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
    {{ partial('layout_partials/base_apply_button', {'data_endpoint': '/api/ctrld/service/reconfigure', 'data_service_widget': 'ctrld', 'button_id': 'applyPoliciesAct'}) }}
    <hr/>
    <p>{{ lang._('Guided shortcut: creates a "Local resolver" upstream plus one pair of domain-match policy rows (168.192.in-addr.arpa, internal) per enabled listener, routed to it -- skips any that already exist.') }}</p>
    <button id="createLocalZoneDelegation" type="button" class="btn btn-primary">
        {{ lang._('Create local-zone delegation policies') }}
    </button>
</div>
