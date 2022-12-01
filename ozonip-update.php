<?php

define('CLIENT_ID', '***'); 
define('API_KEY', '***'); 
define('HOST', 'api-seller.ozon.ru');

$perc_old = 1.44;

function make_request($method, $url, $body) {
  
  $header = array(  
    "Client-Id: ". CLIENT_ID,
    "Api-Key: ". API_KEY,
    "Content-Type: application/json"
  );

  $ch = curl_init("https://" . HOST . $url);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
  curl_setopt($ch, CURLOPT_HEADER, false);

  if (strtoupper($method) == 'POST') {
    curl_setopt($ch, CURLOPT_POST, true);
  }
  
  if (!empty($body)) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
  }
  
  curl_setopt($ch, CURLOPT_TIMEOUT, 30);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

  $result = curl_exec($ch);
  $info = curl_getinfo($ch);
  curl_close($ch);
  
  echo '<br />Send: ' . $url . ' Code: ' . $info['http_code'] . '<br />';

  return json_decode($result, true);
}

function limit_request($method, $url, $body, $limit) {
  
  if(!$param) {
    foreach($body as $key => $value){
      $param = $key;
      break;
    }
  }
  
  if(count($body[$param]) > $limit) {
    $new_array = array($param => array());
    for($i = 0; $i < $limit; $i++){
      array_push($new_array[$param], array_shift($body[$param]));
    }
    make_request($method, $url, $new_array);
    
    limit_request($method, $url, $body, $limit);
  } else {
    make_request($method, $url, $body);
  }
}

$filename2 = '../import-price/prices/price-dostav.csv';
$handle2 = fopen($filename2, 'r');
$arr_prime = array();

while(!feof($handle2)) {
	$string2 = fgets($handle2);
  $string2 = str_replace(',', '.', $string2);
	$datacsv2 = explode("|", $string2);

  if(empty($datacsv2[0]))
		continue;
	
	if ($datacsv2[5] < 1) {
		continue;
	} else {
		$arr_prime[$datacsv2[0]] = $datacsv2[5];
	}
}
fclose($handle2);
 
$filename = '../import-price/prices/ozon.csv';
$handle = fopen($filename, 'r');
$arr_pricelist = array();

while(!feof($handle)) {
  $string = str_replace(',', '.', fgets($handle));
	$datacsv = explode(";", $string);
    if(empty($datacsv[0]))
		continue;
	
	if ($datacsv[3] < 1) {
		continue;
	} else {
		$arr_pricelist[$datacsv[0]] = array('price' => $datacsv[4], 'weight' => $datacsv[2], 'quantity' => $datacsv[3]);
	}
}
fclose($handle);


//получаем ID складов;

$warehous = make_request('post', '/v1/warehouse/list', '');

if($warehous['result'][0]) {
  $fbs_id = $warehous['result'][0]['warehouse_id'];
}

if($warehous['result'][1]) {
  $rfbs_id = $warehous['result'][1]['warehouse_id'];
}

//получаем массив совпадений с выгрузкой и 1с;

$array_result = array();
$id = 0;

$arr_ids = make_request('post', '/v2/product/list', array("limit" => "0"));

foreach($arr_ids['result']['items'] as $key => $value) {
  $arr_ids['result']['items'][$key]['price'] = 0;
  $arr_ids['result']['items'][$key]['old_price'] = 0;
  $arr_ids['result']['items'][$key]['quantity'] = 0;
  $arr_ids['result']['items'][$key]['wh_quantity'] = 0;
  $arr_ids['result']['items'][$key]['weight'] = "";
  $arr_ids['result']['items'][$key]['warehous_id'] = "";
  
  foreach($arr_pricelist as $article => $result){
    if($value['offer_id'] == $article) {
      
      $quantity = "0";
      
      if($result['weight'] >= 25) {
        $warhous_id = $rfbs_id;
      } else {
        $warhous_id = $fbs_id;
      }
      if($result['price'] > 0){
        if($result['quantity'] >= 5) {
          $quantity = "2";
        } elseif($result['quantity'] < 5 && $result['quantity'] >= 3) {
          $quantity = "2";
        } else {
          $quantity = "1";
        }
      }
      
      foreach($arr_prime as $pkey => $presult){
        if($value['offer_id'] == $pkey) {
           if($result['price'] > 0){
              $avail_quantity = $result['quantity'] - $presult;
              if($avail_quantity < 1) {
                $quantity = "0";
                $warhous_id = "";
              } elseif($avail_quantity >= 5) {
                $quantity = "2";
              } elseif($avail_quantity < 5 && $avail_quantity >= 3) {
                $quantity = "2";
              } else {
                $quantity = "1";
              }
              continue;
           }
        } 
      } 
      
      $array_result[$id] = array(
        'product_id'   => $value['product_id'], 
        'offer_id'     => $value['offer_id'],
        'price'        => $result['price'],
        'old_price'    => round($result['price'] * $perc_old, -1),
        'weight'       => $result['weight'],
        'wh_quantity'  => $quantity,
        'quantity'     => $result['quantity'],
        'warehous_id'  => $warhous_id,
      );
      
      $arr_ids['result']['items'][$key]['price'] = $result['price'];
      $arr_ids['result']['items'][$key]['old_price'] = round($result['price'] * $perc_old, -1);
      $arr_ids['result']['items'][$key]['quantity'] = $result['quantity'];
      $arr_ids['result']['items'][$key]['wh_quantity'] = $quantity;
      $arr_ids['result']['items'][$key]['weight'] = $result['weight'];
      $arr_ids['result']['items'][$key]['warehous_id'] = strval($warhous_id);
      
      
      $id++;
      continue;
    }
  }
}

$arr_prices = array("prices" => array());
$arr_quantity = array("stocks" => array());
$clean_quantity = array("stocks" => array());
$clean_quantity_rfbs = array("stocks" => array());

foreach($arr_ids['result']['items'] as $product){
  
  if($product['warehous_id'] == 0) {
    array_push($arr_quantity["stocks"], array(
        "offer_id"    => $product['offer_id'],
        "product_id"  => strval($product['product_id']),
        "stock"       => "0",
        "warehouse_id"=> $fbs_id
      )
    );
  } else {
    array_push($arr_prices["prices"], array(
      "min_price"   => "",
      "offer_id"    => $product['offer_id'],
      "old_price"   => strval($product['old_price']),
      "price"       => $product['price'],
      "product_id"  => strval($product['product_id'])
      )
    );
    array_push($arr_quantity["stocks"], array(
        "offer_id"    => $product['offer_id'],
        "product_id"  => strval($product['product_id']),
        "stock"       => strval($product['wh_quantity']),
        "warehouse_id"=> strval($product['warehous_id'])
      )
    );
  }

}

limit_request('post', '/v1/product/import/prices', $arr_prices, 1000);
sleep(5);
limit_request('post', '/v2/products/stocks', $arr_quantity, 100);

echo 'ok'; 




