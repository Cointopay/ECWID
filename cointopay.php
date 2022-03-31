<?php

// If this is a payment request

if (isset($_POST["data"])) {

  // Functions to decrypt the payment request from Ecwid
  function getEcwidPayload($app_secret_key, $data) {
    // Get the encryption key (16 first bytes of the app's client_secret key)
    $encryption_key = substr($app_secret_key, 0, 16);

    // Decrypt payload
    $json_data = aes_128_decrypt($encryption_key, $data);

    // Decode json
    $json_decoded = json_decode($json_data, true);
    return $json_decoded;
  }

  function aes_128_decrypt($key, $data) {
    // Ecwid sends data in url-safe base64. Convert the raw data to the original base64 first
    $base64_original = str_replace(array('-', '_'), array('+', '/'), $data);

    // Get binary data
    $decoded = base64_decode($base64_original);

    // Initialization vector is the first 16 bytes of the received data
    $iv = substr($decoded, 0, 16);

    // The payload itself is is the rest of the received data
    $payload = substr($decoded, 16);

    // Decrypt raw binary payload
    $json = openssl_decrypt($payload, "aes-128-cbc", $key, OPENSSL_RAW_DATA, $iv);
    //$json = mcrypt_decrypt(MCRYPT_RIJNDAEL_128, $key, $payload, MCRYPT_MODE_CBC, $iv); // You can use this instead of openssl_decrupt, if mcrypt is enabled in your system

    return $json;
  }

  // Function to make transaction request on Cointopay.com
  function makeTransactionRequest($perameters) {

    $url  = "https://app.cointopay.com/MerchantAPI";
    if (count($perameters) > 0) {
        $url .= '?' . http_build_query($perameters);
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $json_response = curl_exec($ch);

    if ($json_response === false) {

      // $this->last_curl_error = curl_error($ch);
      // $this->last_curl_errno = curl_errno($ch);
      $last_curl_error = curl_error($ch);
      $last_curl_errno = curl_errno($ch);

      curl_close($ch);
      return false;
    }

    $response = json_decode($json_response, true);
    curl_close($ch);

    return $response;
  }

  // Get payload from the POST and decrypt it
  $ecwid_payload = $_POST['data'];
  $client_secret = ""; // PROVIDE ECWID client_secrate

  // The resulting JSON from payment request will be in $order variable
  $order = getEcwidPayload($client_secret, $ecwid_payload);

  // Account info from merchant app settings in app interface in Ecwid CP
  $account_id = $order['merchantAppSettings']['merchantId'];
  $secret_code = $order['merchantAppSettings']['secretCode'];
  $cryptoCurrency = $order['merchantAppSettings']['cryptoCurrency'];

  // Encode access token and prepare calltack URL template
  $callbackPayload = base64_encode($order['token']);
  $callbackUrl = "https://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]"."?storeId=".$order['storeId']."&orderNumber=".$order['cart']['order']['orderNumber']."&account_id=".$account_id."&callbackPayload=".$callbackPayload;

  // Perameters to make transaction request on Cointopay.com
  $perameters = array(
    "Checkout"              => "true",
    "MerchantID"            => $account_id,
    "Amount"                => $order["cart"]["order"]["total"],
    "AltCoinID"             => $cryptoCurrency,
    "CustomerReferenceNr"   => $order['cart']['order']['orderNumber'],
    "SecurityCode"          => $secret_code,
    "output"                => "json",
    "inputCurrency"         => $order["cart"]["currency"],
    "returnurl"             => $callbackUrl,
    "transactionfailurl"    => $callbackUrl,
    "transactionconfirmurl" => $callbackUrl
  );
  
  // Make the transaction request
  $results = makeTransactionRequest($perameters);

  // If the transaction request was a success then goto Cointopay.com to make payments
  if( isset($results['shortURL']) ){
   header("Location: ".$results['shortURL']);
    exit;
  }

  echo "<center><div style='width: 50%; padding: 8%; margin-top: 10px; background: #f9f9f9; border-radius: 10px;'>";
  echo "<h2>Error: Unable to process transaction. Please make sure the SecurityCode is copied correctly. Need help? Contact support@cointopay.com.</h2>";
  if ( !empty($results) ) {
  echo "Message: <b>" . $results . "</b>";
  }
  echo "</div></center>";
  exit;

}

// If we are returning back to storefront. Callback from payment

else if (isset($_GET["callbackPayload"]) && isset($_GET["status"])) {

   //Validate response
   function makeTransactionValidation($perameters) {
	$url  = "https://app.cointopay.com/v2REAPI";
    if (count($perameters) > 0) {
        $url .= '?' . http_build_query($perameters);
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $json_response = curl_exec($ch);

 
    if ($json_response === false) {

      $this->last_curl_error = curl_error($ch);
      $this->last_curl_errno = curl_errno($ch);

      curl_close($ch);
      return false;
    }

    $response = json_decode($json_response, true);
    curl_close($ch);

    return $response;
  }
  echo "<center><div style='width: 50%; padding: 8%; margin-top: 10px; background: #f9f9f9; border-radius: 10px;'>";
  echo "<h1>Notification:</h1>";
   if (empty($_GET['account_id'])){
			echo "<center><div style='width: 50%; padding: 8%; margin-top: 10px; background: #f9f9f9; border-radius: 10px;'>";
  echo "<h2>Error: CredentialsMissing</h2>";
  echo "</div></center>";
  exit;
  } 
  $resp_data = array(
    "MerchantID"            => $_GET['account_id'],
	"Call"             => "Transactiondetail",
	"APIKey"   =>    "a",
	"output"  => "json",
	"ConfirmCode" =>        $_GET['ConfirmCode'],
  );

  // Make the transaction request
  $transactionData = makeTransactionValidation($resp_data);
  	if(200 !== $transactionData['status_code']){
		echo "<h3>".$transactionData['message']."</h3>";exit;
	}
	else{
		if($transactionData['data']['Security'] != $_GET['ConfirmCode']){
			echo "<h3>Data mismatch! ConfirmCode doesn\'t match</h3>";exit;
		}
		elseif($transactionData['data']['CustomerReferenceNr'] != $_GET['CustomerReferenceNr']){
			echo "<h3>Data mismatch! CustomerReferenceNr doesn\'t match</h3>";exit;
		}
		elseif($transactionData['data']['TransactionID'] != $_GET['TransactionID']){
			echo "<h3>Data mismatch! TransactionID doesn\'t match</h3>";exit;
		}
		elseif(isset($_GET['AltCoinID']) && $transactionData['data']['AltCoinID'] != $_GET['AltCoinID']){
			echo "<h3>Data mismatch! AltCoinID doesn\'t match</h3>";exit;
		}
		elseif(isset($_GET['MerchantID']) && $transactionData['data']['MerchantID'] != $_GET['MerchantID']){
			echo "<h3>Data mismatch! MerchantID doesn\'t match</h3>";exit;
		}
		elseif(isset($_GET['CoinAddressUsed']) && $transactionData['data']['coinAddress'] != $_GET['CoinAddressUsed']){
			echo "<h3>Data mismatch! coinAddress doesn\'t match</h3>";exit;
		}
		elseif(isset($_GET['SecurityCode']) && $transactionData['data']['SecurityCode'] != $_GET['SecurityCode']){
			echo "<h3>Data mismatch! SecurityCode doesn\'t match</h3>";exit;
		}
		elseif(isset($_GET['inputCurrency']) && $transactionData['data']['inputCurrency'] != $_GET['inputCurrency']){
			echo "<h3>Data mismatch! inputCurrency doesn\'t match</h3>";exit;
		}
		elseif(isset($_GET["cancel"]) && $_GET["cancel"]=="1" && $transactionData['data']['Status'] != $_GET['status']){
                        $_GET['status'] = "cancel";
                }
                elseif(isset($_GET["notenough"]) && $_GET["notenough"]=="1" && $transactionData['data']['Status'] != $_GET['status']){
                        //echo "<h3>Data mismatch! status doesn\'t match. Your order status is ".$transactionData['data']['Status']."</h3>";exit;
                }
                elseif($transactionData['data']['Status'] != 'paid' && 'paid' == $_GET['status']){
                        echo "<h3>Data mismatch! status doesn\'t match. Your order status is ".$transactionData['data']['Status']."</h3>";exit;
                }
                elseif($transactionData['data']['Status'] != 'expired' && $transactionData['data']['Status'] != $_GET['status']){

                }
		
	}

  $status = strtolower($_GET['status']);
  $halt = false;

  if($status == "paid"){
    $status = "PAID";
  }

  if($status == "cancel"){
    $status = "CANCELLED";
  }

  if($status == "failed"){
    $status = "FAILED";
  }

  if(isset($_GET["notenough"]) && $_GET["notenough"]=="1"){
    //echo "<center><div style='width: 50%; padding: 8%; margin-top: 10px; background: #f9f9f9; border-radius: 10px;'>";
    //echo "<h1>Notification:</h1>";
    echo "<h3>Payment has been received but it is not enough. Please pay the remaining amount or contact the site administrator for further details.</h3>";
    $status = "INCOMPLETE";
    $halt = true;
  }

  $valid_status = array( "PAID", "CANCELLED", "INCOMPLETE", "FAILED");

  if( !in_array($status, $valid_status) ){
    echo "<h3>Transaction status is unknown. Therefore the transaction is canceled. Please, contact site administrator for further details.</h3>";
    $status = "CANCELLED";
    $halt = true;
  }

  // Set variables
  $client_id = ""; // PROVIDE ECWID client_id
  $token = base64_decode(($_GET['callbackPayload']));
  $storeId = $_GET['storeId'];
  $orderNumber = $_GET['orderNumber'];
  $returnUrl = "https://app.ecwid.com/custompaymentapps/$storeId?orderId=$orderNumber&clientId=$client_id";

  // Prepare request body for updating the order
  $json = json_encode(array(
      "paymentStatus" => $status,
      "externalTransactionId" => "transaction_".$orderNumber
  ));

  // URL used to update the order via Ecwid REST API
  $url = "https://app.ecwid.com/api/v3/$storeId/orders/$orderNumber?token=$token";

  // Send request to update order
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: ' . strlen($json)));
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
  curl_setopt($ch, CURLOPT_POSTFIELDS,$json);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $response = curl_exec($ch);
  curl_close($ch);

  // return customer back to storefront
  if(!$halt) header("Location: ".$returnUrl);

  echo "<a href='https://cointopay.com/invoice/".$_GET['ConfirmCode']."'>Back</a>";
  echo "</div></center>";
  exit;

}

else { 

  header('HTTP/1.0 403 Forbidden');
  echo 'Access forbidden!';

}


?>
