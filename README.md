# paytabs-cscart
The official PayTabs CS-Cart Plugin


CS Cart, Installation Guide Steps for PayTabs Payment gateway Plugin:


Please follow the below steps
------------------------------
1. Copy files to your root cs-cart folder.

2. Run the below SQL statement in the corresponding CS Cart database

`REPLACE INTO cscart_payment_processors (processor_id, processor, processor_script, processor_template, admin_template, callback, type) values ('1100', 'PayTabs', 'paytabs.php', 'views/orders/components/payments/cc_outside.tpl', 'paytabs.tpl', 'N', 'P');`

3. Login to your CS Cart admin panel and add New Payment Method, put "PayTabs" in the Name field, select PayTabs from the Processor list, use the provided 'paytabs_logo.png' as the Icon and click Create

4. Edit the newly added Payment Method and navigate to the Configure tab and supply your PayTabs Secret Key

NOTE:
-----
Clear cache from var\cache\templates\backend​ before reinstalling the plugin.​
