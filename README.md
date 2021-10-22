## Webdock Provisioning and Addon Modules for WHMCS

**Installing the Modules:**

 1.  Download and extract the source from Github or clone the repository
 2.  Upload the module folder to your WHMCS root.
 3. Grab an API token from your Webdock Dashboard. Find information on how to get your token here:
 
https://webdock.io/en/docs/webdock-api/api-quick-start-guide

**Provisioning Module Setup:**

 1. Go to system settings by clicking the wrench icon in the top right corner of the screen.
 2. Click on Products/Services in the left hand menu.
 3. Create a group if you haven't already, call it e.g. "Webdock VPS Servers" - fiill in the remaining fields as you wish.
 4. Back on the main Products/Services screen, click Create a New Product
 5. Select Server/VPS, Give it a product name e.g. "Super Fast affordable VPS servers!"
 6. Select "Webdock Module" under Module
 7. Save this and once the Product is created, go to Module Settings for the product. Here you enter your API Token and Fill in the App Name field with a name that identifies your installation. This string is just used to identify requests from your WHMCS installation in the Webdock API docs and is not required. Just choose any name here that describes your WHMCS, like "My WHMCS Provisioning Module" or similar.
 8. Now click on the "Click to (Re)Generate Config Options" - this fetches the available values for our VPS products, such as location, images and available hardware profiles. If we change our hardware lineup or add new locations, you will need to refresh this for your WHMCS installation here. 
 9. You can change the pricing for each configurable option, such as hardware profile under Configurable Options.
 10. On the Custom Fields Tab you will see a field named "**VPS slug**" - This field should not be renamed or removed or it will break functionality. This is the VPS shortname as set by either Webdock automatically or by you or your client if you choose to show this field on the order form. The slug should be no more than 12 alphanumerical characters. Webdock will automatically generate a slug based on the VPS name.
 11. If you want to be able to control the name of VPSs created with WHMCS, add a custom field called "**VPS name**". Anything entered here will become the name of the VPS and a slug will automatically be generated. If you do not provide this field, a VPS server name and slug will be generated in the format "whmcs-vps-{serviceid}"
 12. For your customer order form, you should hide the "Configure Server" fields which WHMCS adds as a default, as the Hostname, Root Password, NS1 Prefix and NS2 Prefix are not used for Webdock VPS servers. 

https://docs.whmcs.com/Order_Form_Templates#Remove_Fields_From_Order_Form

**Notes and known issues:**

- Right now no information on logins or SSH users are being created when a VPS is created. So you as a reseller will need to set up a Shell User or send the default admin shell user (and others, e.g. Database, FTP etc.) for a LAMP/LEMP stack manually to the customer.
- The Create Command should not be triggered manually if it has already been run automatically for a product, as this will just create a new VPS in Webdock with the same name. There is no functionality at the moment in this module that prevents double-provisioning.
- The Suspend and Unsuspend actions in WMCS simply stop or restart the VPS server respectively
- You will NOT be able to use the Terminate Module Command to automatically delete a VPS from Webdock unless contacting Webdock Support first. You will receive a 401 error when using this Command as it requires special privileges.
- If you want to reinstall a server as an admin, you first need to select another Image and then hit "Save Changes" before issuing the Reinstall Server command.
- You can not change location or profile for the VPS using this module. You can update the values in WHMCS but there is no way to tell Webdock about these changes.

**Todo:**

- Ability to specify a shell username and password which will get set up automatically when a VPS server is created, so the customer has access with SSH immediately.
- The ability to change hardware profile as a Custom Command
- Ensure Config Options are re-generated. It does not seem they are refreshed as-is.

**Addon Module:** 

This module is for listing existing servers in your account at Webdock.io so you can assign these to a customer and bill them via. WHMCS.

 1. Go to System settings by clicking the wrench icon in the top right corner of the screen
 2. Click on Addon modules in the left hand menu
 3. Click on Activate for the Webdockio module
 4. Next click on Configure and enter the API token into the API Token field
 5. Fill in the App Name field with a name that identifies your installation. This string is just used to identify requests from your WHMCS installation in the Webdock API docs and is not required. Just choose any name here that describes your WHMCS, like "My WHMCS Control Panel" or similar.
 6. Click Save. Now you can click on **Addons -> Webdock.io** to list all existing servers in your Webdock account and assign these to your WHMCS products or clients.
