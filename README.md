# PayTabs - CS-Cart

The official PayTabs CS-Cart Plugin

---

## Installation

### Install using Add-on method

1. Download the latest release
2. Navigate to: `CS-Cart admin panel >> Add-ons >> Manage Add-ons >> Manual installation`
3. Select "Local" then select the downloaded the Add-on zip file from your local disk
4. Click on `Upload & install`

**Note:**

If the Add-on does not appear or if it is not correctly installed, repeat the Upload step.

This happens sometimes when a previous version exists.

### Install using FTP method

1. Download the latest release
2. Copy the files to your root cs-cart folder.
3. Navigate to: `CS-Cart admin panel >> Add-ons >> Manage Add-ons`
4. Locate `PayTabs` and clik on `Install` button

---

## Activating the Plugin

1. Navigate to: `"CS-Cart admin panel" >> Administration >> Payment methods`
2. Click on `Add Payment Method`
3. Enter "PayTabs" in the Name field, select PayTabs from the Processor list, use the provided 'paytabs_logo.png' as the Icon
4. Click **Create**
5. Edit the newly added Payment Method and navigate to the `Configure` tab and supply your PayTabs Credentials

---

### Note

Clear cache from `var\cache\templates\backend` before reinstalling the plugin.

---

### Note

To Active refund/returns features Go to `Add-ons` and active `RMA Add-on` 
then the user can make `return request` for an order by opening the order details and make `return request` and change the status to `replace or refund`
and the admin can approve this request  
from `CS-Cart admin panel >> Orders >> Return Requests` open any request and `change status` to `Approved or Completed` .

***Notes:***

1. Make sure that the order status is `Complated` to enable user to make return request
2. Make sure that return feature is enabled for all products *(Enabled by default ) 
from `CS-Cart admin panel >> Products >> Products >> open any product >> Add-ons  and check  RMA Returnable checkbox `



---

## Log Access

### PayTabs custom log

1. Access `debug_paytabs.log` file found at: `/var/debug_paytabs.log`

---

Done
