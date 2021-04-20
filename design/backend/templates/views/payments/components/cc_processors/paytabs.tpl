<h1>PayTabs</h1>

<div class="control-group">
    <label class="control-label" for="endpoint">Endpoint Region:</label>
    <div class="controls">
        <select name="payment_data[processor_params][endpoint]" id="endpoint" value="{$processor_params.endpoint}">
            <option value="ARE">United Arab Emirates</option>
            <option value="SAU">Saudi Arabia</option>
            <option value="OMN">Oman</option>
            <option value="JOR">Jordan</option>
            <option value="EGY">Egypt</option>
            <option value="GLOBAL">Global</option>
            <!-- <option value="DEMO">Demo</option> -->
        </select>
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="profile_id">Profile ID:</label>
    <div class="controls">
        <input type="text" name="payment_data[processor_params][profile_id]" id="profile_id" value="{$processor_params.profile_id}" size="9">
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="server_key">Server Key:</label>
    <div class="controls">
        <input type="text" name="payment_data[processor_params][server_key]" id="server_key" value="{$processor_params.server_key}" size="60">
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="order_status_after_payment">Order Status After Payment:</label>
    <div class="controls">
        <select name="payment_data[processor_params][order_status_after_payment]" id="order_status_after_payment" value="{}">
            <option value="B">Backordered</option>
            <option value="C">Complete</option>
            <option value="D">Declined</option>
            <option value="F">Failed</option>
            <option value="I">Canceled</option>
            <option value="O">Open</option>
            <option value="P">Processed</option>
            <option value="Y">Awaiting call</option>
        </select>
    </div>
</div>

<script>
    document.getElementById('endpoint').value = '{$processor_params.endpoint}';
    document.getElementById('order_status_after_payment').value = '{$processor_params.order_status_after_payment}';
</script>