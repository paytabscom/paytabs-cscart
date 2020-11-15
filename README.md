# PayTabs - CS-Cart

The official PayTabs CS-Cart Plugin

- - -

## Installation

### Install using FTP method

1. Download the latest release
2. Copy the files to your root cs-cart folder.
3. Run the below SQL statement in the corresponding CS Cart database

```sql
REPLACE INTO cscart_payment_processors 
(processor_id, processor, processor_script, processor_template, admin_template, callback, type) values
('1100', 'PayTabs', 'paytabs.php', 'views/orders/components/payments/cc_outside.tpl', 'paytabs.tpl', 'N', 'P');
```

- - -

## Activating the Plugin

1. Navigate to: `"CS-Cart admin panel" >> Administration >> Payment methods`
2. Click on `Add Payment Method`
3. Enter "PayTabs" in the Name field, select PayTabs from the Processor list, use the provided 'paytabs_logo.png' as the Icon
4. Click **Create**
5. Edit the newly added Payment Method and navigate to the `Configure` tab and supply your PayTabs Credentials

- - -

### Note

Clear cache from `var\cache\templates\backend`​ before reinstalling the plugin.​

- - -

## Log Access

### PayTabs custom log

1. Access `debug_paytabs.log` file found at: `/var/debug_paytabs.log`

- - -

Done
