<?php
use core\TonPay;
class Main{
  private $TonPay;
  function __construct(){
    $config = [
      'TON_NETWORK' => 'testnet',                  //testnet OR mainnet
      'RECIPIENT_WALLET_ADDRESS_MAINNET' => '..',  //your mainnet address
      'RECIPIENT_WALLET_ADDRESS_TESTNET' => '..',  //your testnet address
      'TONAPI_API_KEY' => '..',                    //your tonapi key -> tonapi.io
    ];
    $this->TonPay = new TonPay($config);
  }

  function test(){
    //VARS FOR TEST!
    $amount  = 0.5;               //amount TON`s to pay
    $orderID = 1;                 //order id
    $userID  = 1;                 //user id
    $item    = "donate-for-item"; //anything else

    //create new order
    $new_order = $this->TonPay->createOrder($amount, $userID, $item ?? null);
    //maybe you return order: print_r($new_order);
    //$new_order = [id, memo, amount_ton, recipient_address, network, urltopay, status]

    //get order ALL  (WITHOUT CHECK PAY on tonapi.io)
    $get_order = $this->TonPay->get($orderID, $userID, $item);
    print_r($get_order);
    //$get_order = [id, memo, amount_nanoton, recipient_address, urltopay, status]

    //get pay status of order
    $payed = $this->TonPay->checkPayment($orderID, $userID);
    echo $payed['status'];
    //$payed = [status]
  }
}