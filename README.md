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

## Refund feature

### Enable Refund

Go to `Add-ons` and active `RMA Add-on`.

Docs: <https://docs.cs-cart.com/latest/user_guide/addons/rma/index.html>


#### Store admin notes

Make sure that return feature is enabled for all products *(Enabled by default)*

1. Navigate to: `CS-Cart admin panel >> Products >> Products >> open any product`
2. Open `Add-ons` tab
3. Double check **RMA** section, `Returnable` checkbox

##### Manage the Refund requests

1. Navigate to `CS-Cart admin panel >> Orders >> Return Requests`
2. Select the request
3. Open the `Actions` tab
4. Set the new status (Approved or Completed)
5. Save


#### Customer steps

To request a Refund:

1. Navigate to the order details page
2. Click on `Request a replacement or a refund`
3. Select `Refund`, selecte the products & quantities, add any comments
4. Click `Return`

***Notes:***

1. Order status should be `Completed` to allow **Return** requests

---

### Note

Clear cache from `var\cache\templates\backend` before reinstalling the plugin.

---

## Log Access

### PayTabs custom log

1. Access `debug_paytabs.log` file found at: `/var/debug_paytabs.log`

---

Done
