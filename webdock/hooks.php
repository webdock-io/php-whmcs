<?php

use WHMCS\Database\Capsule;

add_hook('AdminAreaPage', 1, function ($vars) {
    if ($_POST['ajaxpage'] == 'createconfig') {
        $data = Capsule::table('tblproducts')
            ->where('id', '=', $_POST['productid'])
            ->where('servertype', '=', 'webdock')->first();
        require_once 'php-sdk-master/vendor/autoload.php';
        $appName = $data->configoption1;
        $token = $data->configoption2;
        $client = new \Webdock\Client($token, $appName);
        $jsonData = $client->location->list()->getResponse()->toArray();
        $images = $client->image->list()->getResponse()->toArray();
        foreach ($jsonData as $key => $value) {
            $products[$value['id']] = $value['city'] . ',' . $value['name'];
        }
        webdock_generateconfigoption('Location', $_POST['productid'], $products);
        $pro = [];
        foreach ($products as $key => $vals) {
            $profiles = $client->profile->list($key)->getResponse()->toArray();
            foreach ($profiles as $val) {
                $pro[$val['slug']] = $val['name'].'_'.$key;
            }
        }
        webdock_generateconfigoption('Profile', $_POST['productid'], $pro);
        foreach ($images as $key => $img) {
            $imag[$img['slug']] = $img['name'];
        }
        // $client->profile->list('fi');
        webdock_generateconfigoption('Image', $_POST['productid'], $imag);
        echo "success";
        exit();
    }
});

function webdock_generateconfigoption($name, $id, $data)
{
    // echo $id;
    # Configurable Option
    $addconfigrablegroupname = "WebDock-" . $name . " " . $id . ":";
    $addconfigurabledescription = $name;
    $addconfigurableoptionname = $name;
    $configurableoptionresult = Capsule::table('tblproductconfiglinks')->where('pid', $id)->get();
    $configurableoptionlinkresult = Capsule::table('tblproductconfiggroups')->where('name', $addconfigrablegroupname)->count();
    // print_r($configurableoptionlinkresult);
    if ($configurableoptionlinkresult == 0) {

        try {
            $configurablegroupid = Capsule::table('tblproductconfiggroups')
                ->insertGetId(
                    [
                        "name" => $addconfigrablegroupname,
                        "description" => $addconfigurabledescription
                    ]
                );
            // print_r($configurablegroupid);
            Capsule::table('tblproductconfiglinks')
                ->insertGetId(
                    [
                        "gid" => $configurablegroupid,
                        "pid" => $id
                    ]
                );
            $configid = Capsule::table('tblproductconfigoptions')
                ->insertGetId(
                    [
                        "gid" => $configurablegroupid,
                        "optionname" => $addconfigurableoptionname,
                        "optiontype" => "1",
                        "qtyminimum" => '',
                        "qtymaximum" => '',
                        "order" => "",
                        "hidden" => ""
                    ]
                );
            foreach ($data as $key => $n) {
                $tblpricing_rel_id[] = Capsule::table('tblproductconfigoptionssub')
                    ->insertGetId(
                        [
                            "configid" => $configid,
                            "optionname" => $key . "|" . $n,
                            "sortorder" => "",
                            "hidden" => ""
                        ]
                    );
            }
            $datas = Capsule::table('tblcurrencies')->orderBy('code', 'DESC')->get();
            foreach ($datas as $data) {
                $curr_id = $data->id;
                $curr_code = $data->code;
                $currenciesarray[$curr_id] = $curr_code;
            }
            foreach ($tblpricing_rel_id as $tdval) {
                foreach ($currenciesarray as $curr_id => $currency) {
                    Capsule::table('tblpricing')->insert(
                        [
                            'type' => 'configoptions',
                            'currency' => $curr_id,
                            'relid' => $tdval,
                            'msetupfee' => '',
                            'qsetupfee' => '',
                            'annually' => '',
                            'biennially' => '',
                            'triennially' => ''
                        ]
                    );
                }
            }
        } catch (Exception $e) {
            print_r($e->getMessage());
        }
    }
    // exit();
}
add_hook('ClientAreaFooterOutput', 1, function($vars) {
    if($_GET["a"] == "confproduct"){
        return '<script>
        jQuery(document).ready(function(){
            var selectprofile = "";
            var htmloption = "";
            var htmloption1 = "";
            jQuery("[name^=configoption]").each(function(index, element) { 
                var configoption = jQuery(this).prev().html();
                if(configoption == "Profile"){
                    var configop = jQuery(this).attr("id");
                    var suffix = configop.match(/\d+/);
                    selectprofile = suffix[0];
                    $("#inputConfigOption"+selectprofile+" option").each(function(ind,val)
                    {
                        var xyz = jQuery(this).text().split("_");
                        if(xyz[1].trim() == "fi"){
                            htmloption += "<option value="+jQuery(val).val()+">"+xyz[0]+"</option>";
                        }
                        if(xyz[1].trim() == "us"){
                            htmloption1 += "<option value="+jQuery(val).val()+">"+xyz[0]+"</option>";
                        }
                    });
                    jQuery("#inputConfigOption"+selectprofile).html(htmloption);
                }
            });
            jQuery("select").on("change",function(){
                if(jQuery(this).prev().html() == "Location"){
                    console.log(selectprofile);
                    var selectval = jQuery(this).attr("id");
                    if(this.selectedIndex == 0){
                        jQuery("#inputConfigOption"+selectprofile).html(htmloption);
                    }
                    if(this.selectedIndex == 1){
                        jQuery("#inputConfigOption"+selectprofile).html(htmloption1);
                    }
                    
                }
            });
        });
        </script>';
    }
});
