<script>
    $( document ).ready(function() {
        var data_get_map = {'frm_GeneralSettings':"/api/ctrld/general/get"};
        mapDataToFormUI(data_get_map).done(function(data){
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
        });

        // SimpleActionButton (spinner, error dialog on failure, and
        // refreshing the named service-status widget afterward) must be
        // bound to one button at a time -- it reads endpoint/label from
        // data-* attributes on `this`, which only resolves correctly for a
        // single-element jQuery object, not a shared class selector.
        $("#saveAct").SimpleActionButton({
            onPreAction: function () {
                const dfObj = new $.Deferred();
                saveFormToEndpoint(
                    "/api/ctrld/general/set",
                    'frm_GeneralSettings',
                    function () { dfObj.resolve(); },
                    true,
                    function () { dfObj.reject(); }
                );
                return dfObj;
            }
        });

        updateServiceControlUI('ctrld');
    });
</script>

<div class="content-box">
    {{ partial("layout_partials/base_form",['fields':generalForm,'id':'frm_GeneralSettings'])}}
    {{ partial('layout_partials/base_apply_button', {'data_endpoint': '/api/ctrld/service/reconfigure', 'data_service_widget': 'ctrld', 'data_label': 'Save', 'button_id': 'saveAct'}) }}
</div>
