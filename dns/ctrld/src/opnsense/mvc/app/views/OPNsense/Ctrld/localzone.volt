<script>
    $( document ).ready(function() {
        // Local-zone delegation helper: reuses (rather than duplicates) a
        // "Local resolver" upstream pointing at the configured
        // localZoneResolverHost/Port, then creates one pair of domain-match
        // policy rows per existing enabled listener (a Policy row requires
        // a listener -- this can't create a listener-less rule). The
        // 168.192.in-addr.arpa zone covers the common 192.168.0.0/16 home
        // range specifically; add further reverse-zone rows by hand on the
        // Policies page for other private ranges (10.0.0.0/8, etc.).
        //
        // Checks existing Policy rows (not just the Upstream row) before
        // creating anything, and disables the button while running with a
        // completion dialog at the end.
        //
        // Fetches localZoneResolverHost/Port from /api/ctrld/general/get
        // rather than reading it out of a form on this page -- since the
        // General tab became its own separate blade, its form doesn't
        // exist in this page's DOM to read from directly.
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

            ajaxCall("/api/ctrld/general/get", {}, function(generalResponse){
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
    <button id="createLocalZoneDelegation" type="button" class="btn btn-primary">
        {{ lang._('Create local-zone delegation rules') }}
    </button>
</div>
