<script>
    $( document ).ready(function() {
        $("#grid-log").UIBootgrid({
            search:'/api/ctrld/log/search'
        });

        updateServiceControlUI('ctrld');
    });
</script>

<div class="content-box">
    <table id="grid-log" class="table table-condensed table-hover table-striped">
        <thead>
            <tr>
                <th data-column-id="time" data-type="string" data-width="12em">{{ lang._('Time') }}</th>
                <th data-column-id="level" data-type="string" data-width="6em">{{ lang._('Level') }}</th>
                <th data-column-id="message" data-type="string">{{ lang._('Message') }}</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>
