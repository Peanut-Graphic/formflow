<?php
namespace ISF\Connectors\DominionPtr;
use ISF\Api\ApiClient;
if (!defined('ABSPATH')) { exit; }
require_once dirname(__DIR__,2).'/includes/api/class-api-client.php';
/** Server-only adapter. Never expose its return value or site key to a browser. */
final class HubProspectClient {
 private string $url;
 private string $api_key;
 public function __construct(string $hub_url,string $api_key) {
    if (strtolower((string)parse_url($hub_url,PHP_URL_SCHEME))!=='https' || empty($api_key) || parse_url($hub_url,PHP_URL_USER)!==null || parse_url($hub_url,PHP_URL_QUERY)!==null || parse_url($hub_url,PHP_URL_FRAGMENT)!==null) throw new \InvalidArgumentException('A trusted HTTPS HUB connection is required.');
    $this->url=rtrim($hub_url,'/');$this->api_key=$api_key;
 }
 public static function from_connect(): self {
    if (!class_exists('Peanut_Connect_Auth')) throw new \RuntimeException('Peanut Connect is required.');
    return new self((string)get_option('peanut_connect_hub_url'),(string)\Peanut_Connect_Auth::get_hub_api_key());
 }
 public function lookup(string $form_slug,string $zip,?string $click_id=null,?string $email=null): array {
    $url=$this->url.'/api/v1/forms/'.rawurlencode($form_slug).'/prospect-lookup';
    if (!ApiClient::is_safe_outbound_url($url)) throw new \RuntimeException('Unsafe HUB endpoint.');
    if (!preg_match('/^\d{5}$/D',$zip)) throw new \InvalidArgumentException('Service ZIP must be five digits.');
    $response=wp_remote_request($url,['method'=>'POST','timeout'=>15,'sslverify'=>true,'reject_unsafe_urls'=>true,'redirection'=>0,'headers'=>['Authorization'=>'Bearer '.$this->api_key,'Content-Type'=>'application/json','Accept'=>'application/json'],'body'=>wp_json_encode(['zip'=>$zip,'click_id'=>$click_id,'email'=>$email])]);
    if (is_wp_error($response) || (int)wp_remote_retrieve_response_code($response)!==200) return ['matched'=>false];
    $data=json_decode(wp_remote_retrieve_body($response),true);
    if (!is_array($data) || ($data['matched'] ?? null)!==true) return ['matched'=>false];
    if (!is_string($data['account_number'] ?? null) || !is_array($data['prefill'] ?? null) || !is_string($data['expires_at'] ?? null) || strtotime($data['expires_at'])<=time()) return ['matched'=>false];
    return $data;
 }
}
