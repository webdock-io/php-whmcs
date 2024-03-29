<?php
/**
 * @Author: Rakesh Kumar(rakeshthakurpro0306@gmail.com)
 * @copyright Copyright (c) whmcsninja.com
 * @contact https://whmcsninja.com/
 * @license https://whmcsninja.com/license/
 */
/**
 * WHMCS Webdock Module
 */
use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Define addon module configuration parameters.
 * @return array
 */
function webdockio_config()
{
    return [
        // Display name for your module
        'name' => 'Webdock.io',
        // Description displayed within the admin interface
        'description' => 'This module lets you connect with Webdock.io, synch existing servers to WHMCS as well as resell Webdock servers.',
        // Module author name
        'author' => 'Webdock.io',
        // Default language
        'language' => 'english',
        // Version number
        'version' => '1.0',
        'fields' => [
            // a text field type allows for single line text input
            'AccessToken' => [
                'FriendlyName' => 'API Token',
                'Type' => 'text',
                'Size' => '25',
                'Default' => '',
                'Description' => 'Webdock API Token goes here',
            ],
            // a password field type allows for masked text input
            'AppName' => [
                'FriendlyName' => 'Application Name',
                'Type' => 'text',
                'Size' => '25',
                'Default' => '',
                'Description' => 'Enter optional Application Name here',
            ],
        ]
    ];
}

/**
 * Activate.
 * @return array Optional success/failure message
 */
function webdockio_activate()
{

    return [
        // Supported values here include: success, error or info
        'status' => 'success',
        'description' => 'Activated Successfully.',
    ];
}

/**
 * Deactivate.
 * @return array Optional success/failure message
 */
function webdockio_deactivate()
{
    // Undo any database and schema modifications made by your module here
    return [
        // Supported values here include: success, error or info
        'status' => 'success',
        'description' => 'Deactivated Successfully.',
    ];
}

/**
 * Admin Area Output.
 *
 * @see AddonModule\Admin\Controller::index()
 *
 * @return string
 */
function webdockio_output($vars)
{
    require_once(__DIR__ . '/../../servers/webdock/vendor/autoload.php');
    $appName = $vars['AppName'];
    $token = $vars['AccessToken'];
    $client = new \Webdock\Client($token, $appName);
    $status = 'all'; // [all, suspended, active]
    $cfid = Capsule::table('tblcustomfields')->where('type', 'product')->where('fieldname', 'VPSslug')->first();
    $data = Capsule::table('tblcustomfieldsvalues')->where('fieldid', $cfid->id)->where('value', $_GET['slug'])->first();
    if ($_GET['ajax'] == 1) {
        if ($data) {
            echo '<h2>Already Assigned</h2><br> Click to view <a href="clientsservices.php?id=' . $data->relid . '">Detail</a>';
            exit();
        }
        if (isset($_GET['userid'])) {
            $serverdata = $client->server->get($_GET['slug']);
            $serverarray = $serverdata->getResponse()->toArray();
            $cgidl = Capsule::table('tblproductconfiggroups')->where('name', 'WebDock-Location ' . $_GET['pid'] . ':')->first();
            $cfidl = Capsule::table('tblproductconfigoptions')->where('gid', $cgidl->id)->where('optionname', 'Location')->first();

            $cgidp = Capsule::table('tblproductconfiggroups')->where('name', 'WebDock-Profile ' . $_GET['pid'] . ':')->first();
            $cfidp = Capsule::table('tblproductconfigoptions')->where('gid', $cgidp->id)->where('optionname', 'Profile')->first();

            $cgidi = Capsule::table('tblproductconfiggroups')->where('name', 'WebDock-Image ' . $_GET['pid'] . ':')->first();
            $cfidi = Capsule::table('tblproductconfigoptions')->where('gid', $cgidi->id)->where('optionname', 'Image')->first();
            $command = 'AddOrder';
            $postData = array(
                'clientid' => $_GET['userid'],
                'pid' => array($_GET['pid']),
                'billingcycle' => array('monthly'),
                'configoptions' => array(base64_encode(serialize(array($cfidl->id => $serverarray['location']))), base64_encode(serialize(array($cfidp->id => $serverarray['profile']))), base64_encode(serialize(array($cfidi->id => $serverarray['image'])))),
                'paymentmethod' => 'paypal',
            );
            $adminUsername = ''; // Optional for WHMCS 7.2 and later
            $results = localAPI($command, $postData, $adminUsername);
            if ($results['result'] == 'success') {
                $command = 'AcceptOrder';
                $postData = array(
                    'orderid' => $results['orderid'],
                    'autosetup' => false,
                    'sendemail' => false,
                );
                $adminUsername = ''; // Optional for WHMCS 7.2 and later
                $res = localAPI($command, $postData, $adminUsername);

                Capsule::table('tblhosting')->where('id', $res['serviceids'])->update([
                    'domainstatus' => 'Active'
                ]);
                Capsule::table('tblcustomfieldsvalues')->where('fieldid', $cfid->id)->update(['value' => $_GET['slug']]);
                echo "Successfully Assigned";
            }
        } else {
            $p = '';
            $pdata = Capsule::table('tblproducts')->where('servertype', 'webdock')->get();
            foreach ($pdata as $key => $val) {
                $p .= '<option value="' . $val->id . '">' . $val->name . '</option>';
            }
            $options = '<h4>Select User</h4><h4><p style="font-weight: bold" id="responseid"></p></h4><select id="userid" name="userid" class="form-control select-inline">';
            $cfid = Capsule::table('tblcustomfields')->where('type', 'product')->where('fieldname', 'VPSslug')->first();
            $data = Capsule::table('tblcustomfieldsvalues')->where('fieldid', $cfid->id)->where('value', $_GET['slug'])->first();
            if (!$data) {
                $clients = Capsule::table('tblclients')->where('status', 'Active')->get();
                foreach ($clients as $key => $value) {
                    $options .= '<option value="' . $value->id . '">' . $value->firstname . '-' . $value->email . '</option>';
                }
                echo $options . '</select><br>
                <input type="hidden" name="vpsslug" id="vpsslug" value="' . $_GET['slug'] . '"/>
                <p><br>Product <br> <select id="pid" name="pid" class="form-control select-inline">' . $p . '</select></p>
                <br><br><a href="#" class="btn btn-primary" onclick="assignVps()">Assign</a>';
            } else {
                echo '<h2>Already Assigned</h2><br> Click to view <a href="clientsservices.php?id=' . $data->relid . '">Detail</a>';
            }
        }
        exit();
    }
    $serverList = $client->server->list($status);
    require_once 'home.php';
}
