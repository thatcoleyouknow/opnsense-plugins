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

        // Domain delegation helper: reuses (rather than duplicates) a
        // "Local resolver" upstream pointing at the configured
        // localZoneResolverHost/Port, then creates one domain-match policy
        // row per zone, per existing enabled listener (a Policy row
        // requires a listener -- this can't create a listener-less rule).
        // Shared by both buttons below: the fixed local-zone shortcut
        // (168.192.in-addr.arpa/internal) and the arbitrary-domain
        // quick-add -- same multi-step workflow either way, just a
        // different zone list and dialog title.
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
        // Correction (a later review re-verified this against current core
        // source): searchItem rows do NOT carry the {key: {value, selected}}
        // object shape -- that's a getBase()/getNodes() thing
        // (Api/ListenerController.php's selectedOption() genuinely needs it
        // for that reason). UIModelGrid::fetch(), the real code behind
        // searchItem, returns the plain stored value per field. This
        // function was written on the theory it needed the same unwrapping
        // searchItem rows never actually need -- kept anyway as a harmless,
        // defensive pass-through (a plain string flows through unchanged),
        // rather than removed and risking whatever the original dedup bug
        // this was meant to fix actually was.
        function selectedKey(value) {
            if (typeof value !== 'object' || value === null) {
                return value;
            }
            var key = Object.keys(value).find(function (k) {
                return value[k] && value[k].selected;
            });
            return key !== undefined ? key : '';
        }

        function runDomainDelegation($btn, zones, dialogTitle, descriptionPrefix) {
            if ($btn.prop('disabled')) {
                return;
            }
            var originalHtml = $btn.html();

            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-pulse"></i> ' + originalHtml);

            function finish(message, isError) {
                $btn.prop('disabled', false).html(originalHtml);
                BootstrapDialog.show({
                    type: isError ? BootstrapDialog.TYPE_WARNING : BootstrapDialog.TYPE_SUCCESS,
                    title: dialogTitle,
                    message: message
                });
            }

            function withUpstream(upstreamUuid) {
                ajaxCall("/api/ctrld/listener/searchItem", {}, function(listenerResponse){
                    var listeners = (listenerResponse.rows || []).filter(function(row){
                        return row.enabled === '1';
                    });
                    if (listeners.length === 0) {
                        finish("Add at least one listener first -- delegation rules are created per listener.", true);
                        return;
                    }
                    ajaxCall("/api/ctrld/policy/searchItem", {}, function(policyResponse){
                        var existingPolicies = policyResponse.rows || [];
                        function alreadyExists(listenerUuid, zone) {
                            return existingPolicies.some(function(row){
                                return selectedKey(row.listener) === listenerUuid && selectedKey(row.matchType) === 'domain' && row.matchValue === zone;
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
                            finish("Nothing to do -- matching delegation rules already exist for every enabled listener.");
                            return;
                        }
                        // Tracked manually rather than via $.when.apply(), for
                        // two reasons: addItem returns HTTP 200 with
                        // {result: 'failed', ...} on a validation failure (not
                        // a rejected/non-2xx response), so the jqXHR promise
                        // itself resolves either way and can't distinguish
                        // success from failure on its own; and $.when's
                        // .always() callback shape differs between exactly
                        // one and more than one deferred, which is easy to
                        // get wrong. Inspecting each response directly avoids
                        // both problems.
                        var created = 0;
                        var failed = 0;
                        var remaining = toCreate.length;
                        toCreate.forEach(function(item){
                            ajaxCall("/api/ctrld/policy/addItem", {
                                policy: {
                                    enabled: '1',
                                    description: descriptionPrefix + item.zone,
                                    listener: item.listener.uuid,
                                    matchType: 'domain',
                                    matchValue: item.zone,
                                    upstream: upstreamUuid
                                }
                            }, function(response){
                                if (response && response.result === 'saved') {
                                    created++;
                                } else {
                                    failed++;
                                }
                                remaining--;
                                if (remaining === 0) {
                                    $("#grid-policies").bootgrid('reload');
                                    if (failed === 0) {
                                        finish("Created " + created + " delegation rule(s).");
                                    } else {
                                        finish("Created " + created + " delegation rule(s); " + failed + " failed -- check the Policies grid.", true);
                                    }
                                }
                            });
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
                        var desiredEndpoint = host + ':' + port;
                        if (existing.endpoint === desiredEndpoint) {
                            withUpstream(existing.uuid);
                            return;
                        }
                        // Found by name, but its endpoint no longer matches
                        // the General page's current host/port -- reusing it
                        // as-is would silently keep routing local-zone
                        // delegation to a stale, possibly dead endpoint.
                        // Bring it in line with the current setting first.
                        ajaxCall("/api/ctrld/upstream/setItem/" + existing.uuid, {
                            upstream: {
                                enabled: '1',
                                name: existing.name,
                                type: 'legacy',
                                endpoint: desiredEndpoint,
                                timeout: '5000'
                            }
                        }, function(setResponse){
                            if (setResponse && setResponse.result === 'saved') {
                                withUpstream(existing.uuid);
                            } else {
                                finish("Failed to update the existing Local resolver upstream's endpoint -- check the Upstreams page.", true);
                            }
                        });
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
        }

        $("#createLocalZoneDelegation").click(function(){
            runDomainDelegation($(this), ['168.192.in-addr.arpa', 'internal'], 'Local-zone delegation', 'Local-zone delegation: ');
        });

        // Arbitrary-domain quick-add: ctrld's own domain rules are an
        // *exact* match only (confirmed against ctrld's real matching
        // code, cmd/cli/dns_proxy.go -- a plain "example.com" rule never
        // falls back to a suffix/wildcard check). A "*.example.com" rule
        // does a suffix match instead, covering subdomains at any depth,
        // but it won't match the bare apex domain itself. So covering
        // "this domain and everything under it" needs both rules --
        // that's what the checkbox below adds when checked.
        $("#createDomainDelegation").click(function(){
            var domain = $("#quickAddDomain").val().trim().toLowerCase().replace(/^\*\.|^\.|\.$/g, '');
            if (domain === '') {
                BootstrapDialog.show({
                    type: BootstrapDialog.TYPE_WARNING,
                    title: 'Domain delegation',
                    message: 'Enter a domain first.'
                });
                return;
            }
            var zones = [domain];
            if ($("#quickAddIncludeSubdomains").prop('checked')) {
                zones.push('*.' + domain);
            }
            runDomainDelegation($(this), zones, 'Domain delegation', 'Domain delegation: ');
        });

        updateServiceControlUI('ctrld');
    });
</script>

<div class="content-box">
    {{ partial('layout_partials/base_bootgrid_table', policyGrid) }}
    <hr/>
    <p>{{ lang._('Guided shortcut: creates a "Local resolver" upstream plus one pair of domain-match policy rows (168.192.in-addr.arpa, internal) per enabled listener, routed to it -- skips any that already exist.') }}</p>
    <button id="createLocalZoneDelegation" type="button" class="btn btn-primary">
        {{ lang._('Create local-zone delegation policies') }}
    </button>
    <hr/>
    <p>{{ lang._('Quick-add: delegate a specific internal domain to the same local resolver, one policy row per enabled listener -- skips any that already exist.') }}</p>
    <div class="form-inline">
        <input type="text" id="quickAddDomain" class="form-control" placeholder="{{ lang._('e.g. example.com') }}"/>
        <label style="margin-left: 15px;" title="{{ lang._('ctrld only matches domains exactly -- check this to also add a *.<domain> rule covering subdomains at any depth (foo.example.com, foo.bar.example.com, etc.).') }}">
            <input type="checkbox" id="quickAddIncludeSubdomains" checked="checked"/>
            {{ lang._('Include subdomains') }}
        </label>
        <button id="createDomainDelegation" type="button" class="btn btn-primary" style="margin-left: 15px;">
            {{ lang._('Add domain delegation policy') }}
        </button>
    </div>
</div>
{{ partial('layout_partials/base_apply_button', {'data_endpoint': '/api/ctrld/service/reconfigure', 'data_service_widget': 'ctrld', 'button_id': 'applyPoliciesAct'}) }}
{{ partial("layout_partials/base_dialog",['fields':policyForm,'id':policyGrid['edit_dialog_id'],'label':lang._('Edit policy rule')])}}
