<?php

use WHMCS\Database\Capsule;

/**
 * WHMCS Webdock Provisioning Module
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}
error_reporting(E_ALL);
ini_set('display_errors',true);
/**
 * Define module related meta data.
 *
 * @see https://developers.whmcs.com/provisioning-modules/meta-data-params/
 *
 * @return array
 */
function webdock_MetaData()
{
    return array(
        'DisplayName' => 'Webdock Module',
        'APIVersion' => '1.0.0'
    );
}

/**
 * Define product configuration options.
 * @see https://developers.whmcs.com/provisioning-modules/config-options/
 *
 * @return array
 */
function webdock_ConfigOptions()
{
    $id = $_REQUEST['id'];
    return array(
        // a text field type allows for single line text input
        'AppName' => array(
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Enter AppName',
        ),
        'Token' => array(
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Enter Token here',
        ), "create_opt" => array(
            "FriendlyName" => "",
            "Description" => "<a href='#' id='createoption'>Create Config Option</a><script>
			$(document).ready(function(){
			 
				$( '#createoption' ).click(function() {
				
						$.ajax({
						   url: 'index.php',
						   method: 'POST',
						   data: 'ajaxpage=createconfig&productid=" . $id . "',
						   success: function(data){
                                console.log(data);
                                window.location.href='configproducts.php?action=edit&id=" . $id . "&tab=3#tab=3';
                            }
						});
				});
				  
			
			});
	
		</script>"
        )
    );
}

/**
 * Provision a new instance of a product/service.
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/provisioning-modules/module-parameters/
 *
 * @return string "success" or an error message
 */
function webdock_CreateAccount(array $params)
{
    try {
        require_once 'php-sdk-master/vendor/autoload.php';
        $appName = $params['configoption1'];
        $token = $params['configoption2'];
        $client = new \Webdock\Client($token, $appName);
        $postdata = [
            'name' => 'whmcs-vps-' . $params['serviceid'],
            'slug' => 'whmcsvps' . $params['serviceid'],
            'locationId' => $params["configoptions"]["Location"], # get available locations: $client->location->list();
            'profileSlug' => $params["configoptions"]["Profile"],
            'imageSlug' => $params["configoptions"]["Image"],
        ];
        $server = $client->server->create($postdata);
        $resp = $server->getResponse()->toArray();
        if (isset($resp['slug'])) {
            $params['model']->serviceProperties->save(['VPSslug' => 'whmcs-vps-' . $params['serviceid']]);
            return 'success';
        } else {
            return 'Something wrong.';
        }
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'webdock',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );

        return $e->getMessage();
    }

    return 'success';
}

/**
 * Suspend an instance of a product/service.
 *
 * Called when a suspension is requested. This is invoked automatically by WHMCS
 * when a product becomes overdue on payment or can be called manually by admin
 * user.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/provisioning-modules/module-parameters/
 *
 * @return string "success" or an error message
 */
function webdock_SuspendAccount(array $params)
{
    try {
        require_once 'php-sdk-master/vendor/autoload.php';
        $appName = $params['configoption1'];
        $token = $params['configoption2'];
        $client = new \Webdock\Client($token, $appName);
        $slug = $params['customfields']['VPSslug'];
        $client->serverAction->suspend($slug);
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'webdock',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );

        return $e->getMessage();
    }

    return 'success';
}

/**
 * Un-suspend instance of a product/service.
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/provisioning-modules/module-parameters/
 *
 * @return string "success" or an error message
 */
function webdock_UnsuspendAccount(array $params)
{
    try {
        require_once 'php-sdk-master/vendor/autoload.php';
        $appName = $params['configoption1'];
        $token = $params['configoption2'];
        $client = new \Webdock\Client($token, $appName);
        $slug = $params['customfields']['VPSslug'];
        $stopServer = $client->serverAction->reboot($slug);
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'webdock',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );

        return $e->getMessage();
    }

    return 'success';
}

/**
 * Terminate instance of a product/service.
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/provisioning-modules/module-parameters/
 *
 * @return string "success" or an error message
 */
function webdock_TerminateAccount(array $params)
{
    try {
        require_once 'php-sdk-master/vendor/autoload.php';
        $appName = $params['configoption1'];
        $token = $params['configoption2'];
        $client = new \Webdock\Client($token, $appName);
        $slug = $params['customfields']['VPSslug'];
        $deleteServer = $client->delete($slug);
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'webdock',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );

        return $e->getMessage();
    }

    return 'success';
}
function webdock_AdminCustomButtonArray()
{
    $buttonarray = array(
        "Reboot Server" => "reboot",
        "Start Server" => "start",
        "Stop Server" => "shutdown",
        "Reinstall Server" => "reinstall",
    );
    return $buttonarray;
}
function webdock_ClientAreaCustomButtonArray()
{
    $buttonarray = array(
        "Reboot Server" => "reboot",
        "Start Server" => "start",
        "Stop Server" => "shutdown",
        "Reinstall Server" => "reinstall",
    );
    return $buttonarray;
}
function webdock_reinstall($params) {
    try {
        require_once 'php-sdk-master/vendor/autoload.php';
        $appName = $params['configoption1'];
        $token = $params['configoption2'];
        $client = new \Webdock\Client($token, $appName);
        $slug = $params['customfields']['VPSslug'];
        $image = $params['configoptions']['Image'];
        $stopServer = $client->serverAction->reinstall($slug, $image);
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'webdock',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );
        return $e->getMessage();
    }
    $result = "success";
    return $result;

}
function webdock_start($params)
{
    try {
        require_once 'php-sdk-master/vendor/autoload.php';
        $appName = $params['configoption1'];
        $token = $params['configoption2'];
        $client = new \Webdock\Client($token, $appName);
        $slug = $params['customfields']['VPSslug'];
        $startServer = $client->serverAction->start($slug);
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'webdock',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );

        return $e->getMessage();
    }
    $result = "success";
    return $result;
}
function webdock_reboot($params)
{

    try {
        require_once 'php-sdk-master/vendor/autoload.php';
        $appName = $params['configoption1'];
        $token = $params['configoption2'];
        $client = new \Webdock\Client($token, $appName);
        $slug = $params['customfields']['VPSslug'];
        $startServer = $client->serverAction->reboot($slug);
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'webdock',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );

        return $e->getMessage();
    }
    $result = "success";
    return $result;
}

function webdock_shutdown($params)
{

    try {
        require_once 'php-sdk-master/vendor/autoload.php';
        $appName = $params['configoption1'];
        $token = $params['configoption2'];
        $client = new \Webdock\Client($token, $appName);
        $slug = $params['customfields']['VPSslug'];
        $startServer = $client->serverAction->stop($slug);
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'webdock',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );

        return $e->getMessage();
    }
    $result = "success";
    return $result;
}

/**
 * Client area output logic handling.
 * The template file you return can be one of two types:
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/provisioning-modules/module-parameters/
 *
 * @return array
 */
function webdock_ClientArea(array $params)
{
    require_once 'php-sdk-master/vendor/autoload.php';
    try {
        $appName = $params['configoption1'];
        $token = $params['configoption2'];
        $client = new \Webdock\Client($token, $appName);
        $slug = $params['customfields']['VPSslug'];
        $server = $client->server->get($slug);
        $response = $server->getResponse()->toArray();
    } catch (Exception $e) {
        // print_r($e->getMessage()); exit();
    }
    // print_r($client->profile->list('fi')); exit();
    return array(
        'tabOverviewReplacementTemplate' => 'clientarea',
        'templateVariables' => array(
            'vps' => $response
        ),
    );
}

function webdock_getLocation($id)
{
    try {
        $product = Capsule::table('tblproducts')->where('servertype', 'webdock')->where('id', $id)->first();
        if (!empty($product->configoption1)) {
            require_once 'php-sdk-master/vendor/autoload.php';
            $appName = $product->configoption1;
            $token = $product->configoption2;
            $client = new \Webdock\Client($token, $appName);
            $jsonData = $client->location->list()->getResponse()->toArray();
            // print_r($jsonData); exit();
            foreach ($jsonData as $key => $value) {
                $products[$value['id']] = $value['city'] . ',' . $value['name'];
            }
            return $products;
        }
    } catch (Exception $e) {
    }
}
