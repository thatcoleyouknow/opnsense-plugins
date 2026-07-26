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
</div>
