<script>
    $( document ).ready(function() {
        $("#grid-log").UIBootgrid({
            search:'/api/ctrld/log/search'
        });
    });
</script>

<div class="content-box">
    <table id="grid-log" class="table table-condensed table-hover table-striped">
        <thead>
            <tr>
                <th data-column-id="line" data-type="string">{{ lang._('Line') }}</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>
