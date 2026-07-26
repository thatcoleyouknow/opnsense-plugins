<script>
    $( document ).ready(function() {
        $("#grid-clients").UIBootgrid({
            search:'/api/ctrld/clients/search'
        });

        updateServiceControlUI('ctrld');
    });
</script>

<div class="content-box">
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
