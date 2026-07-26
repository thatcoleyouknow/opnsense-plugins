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
        // cidr-type rules -- purely a convenience default. Only fills the
        // field when it's currently empty, so it never overwrites a value
        // already typed (a narrower range, a non-standard setup, etc.);
        // the field itself stays a normal editable text input either way.
        $(document).on('change', '[id="policy.listener"], [id="policy.matchType"]', function () {
            var $matchValue = $('[id="policy.matchValue"]');
            var matchType = $('[id="policy.matchType"]').val();
            var listenerUuid = $('[id="policy.listener"]').val();
            if (matchType !== 'cidr' || !listenerUuid || $matchValue.val().trim() !== '') {
                return;
            }
            ajaxCall("/api/ctrld/listener/cidr/" + listenerUuid, {}, function (response) {
                if (response && response.cidr && $matchValue.val().trim() === '') {
                    $matchValue.val(response.cidr);
                }
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
</div>
