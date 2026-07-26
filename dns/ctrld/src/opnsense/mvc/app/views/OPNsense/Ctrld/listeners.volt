<script>
    $( document ).ready(function() {
        $("#grid-listeners").UIBootgrid({
            search:'/api/ctrld/listener/searchItem',
            get:'/api/ctrld/listener/getItem/',
            set:'/api/ctrld/listener/setItem/',
            add:'/api/ctrld/listener/addItem/',
            del:'/api/ctrld/listener/delItem/',
            toggle:'/api/ctrld/listener/toggleItem/'
        });

        // Grid edits save immediately via their own addItem/setItem/
        // delItem/toggleItem endpoints, but never regenerate ctrld.toml or
        // restart the service on their own -- this Apply button does that.
        $("#applyListenersAct").SimpleActionButton({});

        updateServiceControlUI('ctrld');
    });
</script>

<div class="content-box">
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
    {{ partial('layout_partials/base_apply_button', {'data_endpoint': '/api/ctrld/service/reconfigure', 'data_service_widget': 'ctrld', 'button_id': 'applyListenersAct'}) }}
</div>
