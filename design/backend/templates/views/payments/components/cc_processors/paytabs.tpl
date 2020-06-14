<h1>PayTabs</h1>

<div class="control-group">
    <label class="control-label" for="profile_id">Profile ID:</label>
    <div class="controls">
        <input type="text" name="payment_data[processor_params][profile_id]" id="profile_id" value="{$processor_params.profile_id}" size="9">
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="merchant_email">Merchant Email:</label>
    <div class="controls">
        <input type="email" name="payment_data[processor_params][merchant_email]" id="merchant_email" value="{$processor_params.merchant_email}" size="60">
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="secret_key">Secret Key:</label>
    <div class="controls">
        <input type="text" name="payment_data[processor_params][secret_key]" id="secret_key" value="{$processor_params.secret_key}" size="60">
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="order_status_after_payment">Order Status After Payment:</label>
    <div class="controls">
        <select name="payment_data[processor_params][order_status_after_payment]" id="order_status_after_payment" value="{}">
			<option {if $processor_params.order_status_after_payment eq 'B'} selected="selected" {/if} value="B">Backordered</option>
			<option {if $processor_params.order_status_after_payment eq 'C'} selected="selected" {/if} value="C">Complete</option>
			<option {if $processor_params.order_status_after_payment eq 'D'} selected="selected" {/if} value="D">Declined</option>
			<option {if $processor_params.order_status_after_payment eq 'F'} selected="selected" {/if} value="F">Failed</option>
			<option {if $processor_params.order_status_after_payment eq 'I'} selected="selected" {/if} value="I">Canceled</option>
			<option {if $processor_params.order_status_after_payment eq 'O'} selected="selected" {/if} value="O">Open</option>
			<option {if $processor_params.order_status_after_payment eq 'P'} selected="selected" {/if} value="P">Processed</option>
			<option {if $processor_params.order_status_after_payment eq 'Y'} selected="selected" {/if} value="Y">Awaiting call</option>
		</select>
    </div>
</div>