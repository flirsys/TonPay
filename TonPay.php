<?php
# Copyright (C) 2025 FLIRSYS
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU Affero General Public License as published
# by the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU Affero General Public License for more details.
#
# You should have received a copy of the GNU Affero General Public License
# along with this program. If not, see <https://www.gnu.org/licenses/>.
use PDO;
use PDOException;
use Exception;
use Throwable;

class TonPay {

  private PDO $db;
  private array $config;
  private string $apiEndpoint;
  private string $recipientAddress;
  private string $network;

  public static $instance = null;

  public function __construct($config){
    $this->config = $config;
    $this->validateConfig();
    $this->network = $this->config['TON_NETWORK'];
    $this->recipientAddress = $this->network === 'testnet'
      ? $this->config['RECIPIENT_WALLET_ADDRESS_TESTNET']
      : $this->config['RECIPIENT_WALLET_ADDRESS_MAINNET'];
    $this->apiEndpoint = $this->network === 'testnet'
        ? 'https://testnet.tonapi.io/v2/'
        : 'https://tonapi.io/v2/';
    $dbPath = $this->config['SQLITE_DB_PATH'] ?? __DIR__.'/tonpay-'.$this->network.'.db'; //<- DB PATH
    $this->connectDatabase($dbPath);
    $this->setupDatabase();
  }
  public static function getInstance(){ return self::$instance; }

  private function validateConfig(){
    $requiredKeys = [ 'TON_NETWORK',
      'RECIPIENT_WALLET_ADDRESS_MAINNET',
      'RECIPIENT_WALLET_ADDRESS_TESTNET',
      'TONAPI_API_KEY',
    ];
    foreach ($requiredKeys as $key) {
      if (empty($this->config[$key])) { throw new Exception("Configuration error: Missing required key '$key'."); }
    }
    if (!in_array($this->config['TON_NETWORK'], ['mainnet', 'testnet'])) {
      throw new Exception("Configuration error: Invalid TON_NETWORK value. Use 'mainnet' or 'testnet'.");
    }
    if (!extension_loaded('pdo_sqlite') || !extension_loaded('bcmath')) {
      throw new Exception("Configuration error: Required PHP extensions pdo_sqlite or bcmath are not loaded.");
    }
  }

  private function connectDatabase($dbPath){
    try {
      $this->db = new PDO('sqlite:' . $dbPath);
      $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
      $this->db->exec("PRAGMA foreign_keys = ON;");
    } catch (PDOException $e) {
      throw new PDOException("Database connection failed: " . $e->getMessage(), (int)$e->getCode());
    }
  }

  private function setupDatabase(){
    $this->db->exec("
      CREATE TABLE IF NOT EXISTS orders (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER NULL,
      amount_ton TEXT NOT NULL,
      amount_nanoton TEXT NOT NULL,
      memo TEXT NOT NULL UNIQUE,
      status TEXT NOT NULL DEFAULT 'pending'
        CHECK(status IN ('pending', 'paid', 'expired', 'error')),
      item TEXT NOT NULL DEFAULT 'donate',
      created_at INTEGER NOT NULL,
      paid_at INTEGER NULL,
      tx_hash TEXT NULL)");
    $this->db->exec("CREATE INDEX IF NOT EXISTS idx_orders_memo ON orders (memo)");
    $this->db->exec("CREATE INDEX IF NOT EXISTS idx_orders_status_id ON orders (status, id)");
  }

  public function createOrder($amountTon, $userId, $item = 'donate'){
    if (!extension_loaded('bcmath')) { throw new Exception("bcmath extension is required for amount calculation."); }
    $amountTonStr = (string)$amountTon;
    $amountNano = bcmul($amountTonStr, '1000000000', 0);
    if ($amountNano === null) { throw new Exception("Failed to calculate nanoton amount."); }
    $uniqueMemo = $userId.$this->network.'_'.bin2hex(random_bytes(8));
    try {
      $stmt = $this->db->prepare(
        "INSERT INTO orders (user_id, amount_ton, amount_nanoton, memo, status, item, created_at)
        VALUES (:user_id, :amount_ton, :amount_nanoton, :memo, 'pending', :item, :created_at)"
      );
      $stmt->execute([
        ':user_id' => $userId,
        ':amount_ton' => $amountTonStr,
        ':amount_nanoton' => $amountNano,
        ':memo' => $uniqueMemo,
        ':item' => $item,
        ':created_at' => time()
      ]);
      $newOrderId = $this->db->lastInsertId();
      return [ 'id' => (int)$newOrderId,
        'memo' => $uniqueMemo,
        'amount_ton' => $amountTonStr,
        'recipient_address' => $this->recipientAddress,
        'network' => $this->network,
        'urltopay' => sprintf("ton://transfer/%s?amount=%s&text=%s",
        $this->recipientAddress, $amountNano, urlencode($uniqueMemo)),
        'status' => 'pending' ];
    } catch (PDOException $e) {
      throw new Exception("Database error creating order: " . $e->getMessage(), (int)$e->getCode());
    }
  }

  
  public function get($orderId, $userId = null, $item = null){
    $t = "SELECT id, amount_nanoton, memo, item, status FROM orders WHERE ";
    if($item != null){
      $t .= "item = :item AND user_id = :user_id";
      $params = [':item' => $item, ':user_id' => $userId];
    }else{
      $t .= "id = :order_id AND user_id = :user_id";
      $params = [':order_id' => $orderId, ':user_id' => $userId];
    }
    $stmt = $this->db->prepare($t);
    $stmt->execute($params);
    $order = $stmt->fetch();
    if (!$order)  return null;
    else{
      $order['recipient_address'] = $this->recipientAddress;
      $order['urltopay'] = sprintf("ton://transfer/%s?amount=%s&text=%s",
      $this->recipientAddress, $order['amount_nanoton'], urlencode($order['memo']));
      return $order;
    }
  }

  public function checkPayment($orderId, $userId){
    try {
      $stmt = $this->db->prepare("SELECT id, amount_nanoton, memo, item, status FROM orders WHERE id = :order_id AND user_id = :user_id");
      $stmt->execute([':order_id' => $orderId, ':user_id' => $userId]);
      $order = $stmt->fetch();
      if (!$order) { return ['status' => 'not_found']; }
      if ($order['status'] !== 'pending') { return ['status' => $order['status']]; }
      $expectedAmountNano = $order['amount_nanoton'];
      $expectedMemo = $order['memo'];
      $apiResponse = $this->callTonApi(
        'blockchain/accounts/{account_id}/transactions',
        ['limit' => 35], // Check recent transactions
        ['account_id' => $this->recipientAddress]
      );
      if ($apiResponse === null) { return ['status' => 'api_error']; }
      if (!isset($apiResponse['transactions']) || !is_array($apiResponse['transactions'])) { return ['status' => 'api_error']; }
      $transactions = $apiResponse['transactions'];
      $paymentFound = false;
      $foundTxDetails = null;
      foreach ($transactions as $tx) {
        $in_msg = $tx['in_msg'] ?? null;
        $txHash = $tx['hash'] ?? null;
        $txLt = $tx['lt'] ?? null;
        $utime = $tx['utime'] ?? null;
        $senderAddress = $in_msg['source']['address'] ?? null;
        $valueNano = $in_msg['value'] ?? null;
        if (!$in_msg || $valueNano === null || $valueNano === '0' || !$senderAddress || !$txHash || !$txLt) { continue; }
        $receivedAmountNano = (string)$valueNano;
        $receivedMemo = null;
        if (isset($in_msg['decoded_op_name']) && $in_msg['decoded_op_name'] === 'text_comment' && isset($in_msg['decoded_body']['text']) && is_string($in_msg['decoded_body']['text'])) {
          $receivedMemo = trim($in_msg['decoded_body']['text']);
        }
        elseif (isset($in_msg['message_content']['data']) && is_string($in_msg['message_content']['data'])) {
          $receivedMemo = $this->safe_base64_decode_and_clean($in_msg['message_content']['data']);
        }
        if ($receivedAmountNano >= $expectedAmountNano && $receivedMemo !== null && $receivedMemo === $expectedMemo) {
          $paymentFound = true;
          $foundTxDetails = ['tx_hash' => $txHash, 'lt' => $txLt, 'sender' => $senderAddress, 'timestamp' => $utime ?? time()];
          break; // Found it
        }
      } // end foreach
      if ($paymentFound && $foundTxDetails) {
        $this->db->beginTransaction();
        try {
          $stmtCheck = $this->db->prepare("SELECT status FROM orders WHERE id = :order_id");
          $stmtCheck->execute([':order_id' => $orderId]);
          $currentStatus = $stmtCheck->fetchColumn();
          if ($currentStatus === 'pending') {
            $stmtUpdate = $this->db->prepare(
              "UPDATE orders SET status = 'paid', paid_at = :paid_at, tx_hash = :tx_hash
              WHERE id = :order_id AND status = 'pending'"
            );
            $stmtUpdate->execute([
              ':paid_at' => $foundTxDetails['timestamp'],
              ':tx_hash' => $foundTxDetails['tx_hash'],
              ':order_id' => $orderId
            ]);
            if ($stmtUpdate->rowCount() > 0) {
              $this->db->commit();
              return ['item' => $order['item'],'status' => 'paid', 'tx_details' => $foundTxDetails];
            } else {
              $this->db->rollBack();
              $stmtReCheck = $this->db->prepare("SELECT status FROM orders WHERE id = :order_id");
              $stmtReCheck->execute([':order_id' => $orderId]);
              return ['status' => $stmtReCheck->fetchColumn() ?? 'error'];
            }
          } else {
            $this->db->rollBack();
            return ['status' => $currentStatus];
          }
        } catch (PDOException $e) {
          $this->db->rollBack();
          return ['status' => 'db_error'];
        }
      } else {
        return ['status' => 'pending'];
      }
    } catch (PDOException $e) {
      return ['status' => 'db_error'];
    } catch (Throwable $e) {
      return ['status' => 'error'];
    }
  }

  private function callTonApi(string $methodPath, array $params = [], array $pathParams = []){
    $methodPath = ltrim($methodPath, '/');
    foreach ($pathParams as $key => $value) {
      $methodPath = str_replace('{' . $key . '}', urlencode($value), $methodPath);
    }
    $url = rtrim($this->apiEndpoint, '/') . '/' . $methodPath;
    if (!empty($params)) {
      $url .= '?' . http_build_query($params);
    }
    $headers = [ "Accept: application/json",
      "Authorization: Bearer " . $this->config['TONAPI_API_KEY']
    ];
    $options = [ 'http' => [
        'header' => implode("\r\n", $headers), 'method' => 'GET',
        'ignore_errors' => true, 'timeout' => 25
      ],
      'ssl' => [ 'verify_peer' => true, 'verify_peer_name' => true, 'cafile' => ini_get('curl.cainfo') ?: (ini_get('openssl.cafile') ?: null) ]
    ];
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) { return null; }
    $httpStatusCode = 0;
    if (isset($http_response_header) && is_array($http_response_header) && count($http_response_header) > 0) {
      if (preg_match('{HTTP/\d\.\d\s+(\d+)}', $http_response_header[0], $match)) { $httpStatusCode = (int)$match[1]; }
    }
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) { return null; }
    if ($httpStatusCode >= 400) { return null; }
    return $data;
  }

  private function safe_base64_decode_and_clean(string $base64String){
    $decoded = @base64_decode($base64String, true);
    if ($decoded === false) { return null; }
      $cleaned = trim(preg_replace('/[[:cntrl:]]/', '', $decoded));
      return $cleaned;
    }
}
