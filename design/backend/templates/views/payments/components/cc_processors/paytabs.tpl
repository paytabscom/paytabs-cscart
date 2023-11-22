<h1>PayTabs</h1>

<div class="control-group">
    <label class="control-label" for="endpoint">Endpoint Region:</label>
    <div class="controls">
        <select name="payment_data[processor_params][endpoint]" id="endpoint" value="{$processor_params.endpoint}">
            {foreach $endpoints as $k=>$v}
            <option value="{$k}">{$v}</option>
            {/foreach}
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
    <label class="control-label" for="hide_shipping">Hide Shipping:</label>
    <div class="controls">
        <select name="payment_data[processor_params][hide_shipping]" id="hide_shipping" value="{}">
            <option value="0">No</option>
            <option value="1">Yes</option>
        </select>
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="iframe_mode">{__("iframe_mode")}:</label>
    <div class="controls">
        <select name="payment_data[processor_params][iframe_mode]" id="iframe_mode">
            <option value="N">{__("disabled")}</option>
            <option value="Y">{__("enabled")}</option>
        </select>
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

<div class="control-group">
    <label class="control-label" for="config_id">Config id (Theme id):</label>
    <div class="controls">
        <input type="text" name="payment_data[processor_params][config_id]" id="config_id" value="{$processor_params.config_id}" size="12" >
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="enable_alt_currency">Enable Alt Currency:</label>
    <div class="controls">
        <select name="payment_data[processor_params][enable_alt_currency]" id="enable_alt_currency">
            <option value="yes">Yes</option>
            <option value="no">No</option>
        </select>
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="alt_currency">Alt Currency:</label>
    <div class="controls">
        <input type="text" name="payment_data[processor_params][alt_currency]" id="alt_currency" value="{$processor_params.alt_currency}" size="6">
    </div>
</div>

<script>
    document.getElementById('endpoint').value = '{$processor_params.endpoint}';
    document.getElementById('order_status_after_payment').value = '{$processor_params.order_status_after_payment}';
    document.getElementById('hide_shipping').value = '{$processor_params.hide_shipping}';
    document.getElementById('iframe_mode').value = '{$processor_params.iframe_mode}';
    document.getElementById('config_id').value = '{$processor_params.config_id}';
    document.getElementById('enable_alt_currency').value = '{$processor_params.enable_alt_currency}';
    document.getElementById('alt_currency').value = '{$processor_params.alt_currency}';
</script>