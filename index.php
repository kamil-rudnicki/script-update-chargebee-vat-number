<?php

// curl https://????.chargebee.com/api/v1/customers/.../update_billing_info \
// -u live_fwef...: \
// -d 'billing_address[line1]=...' \
// -d 'billing_address[city]=...' \
// -d 'billing_address[zip]=...' \
// -d 'billing_address[country]=...' \
// -d 'vat_number=...'

class ChargebeeVatUpdater {
    private $api_key;
    private $site_name;
    private $log_file;
    
    public function __construct($api_key, $site_name, $log_file = 'vat_update_log.txt') {
        $this->api_key = $api_key;
        $this->site_name = $site_name;
        $this->log_file = $log_file;
    }
    
    public function updateVatNumbers($customers) {
        foreach ($customers as $email => $customerData) {
            try {
                // First get customer ID and current billing info by email
                $customer_info = $this->getCustomerByEmail($email);
                
                if ($customer_info) {
                    $customer_id = $customer_info['customer']['id'];
                    
                    // Get current billing address if it exists
                    $current_billing_address = isset($customer_info['customer']['billing_address']) 
                        ? $customer_info['customer']['billing_address'] 
                        : [];

                    $vat_number = $customerData['vat_number'];
                    if (strlen($vat_number) >= 2 && 
                        ctype_alpha(substr($vat_number, 0, 2))) {
                        $vat_number = substr($vat_number, 2);
                        $this->logMessage("INFO: Removed country code from VAT number for {$email}");
                    }

                    // Merge new VAT number with existing billing address
                    $billing_info = array_merge($current_billing_address, [
                        'vat_number' => $vat_number,
                        'billing_address[first_name]' => $customerData['first_name'] ?? $current_billing_address['first_name'] ?? '',
                        'billing_address[last_name]' => $customerData['last_name'] ?? $current_billing_address['last_name'] ?? '',
                        'billing_address[line1]' => $customerData['line1'] ?? $current_billing_address['line1'] ?? '',
                        'billing_address[city]' => $customerData['city'] ?? $current_billing_address['city'] ?? '',
                        'billing_address[country]' => $customerData['country'] ?? $current_billing_address['country'] ?? '',
                        'billing_address[zip]' => $customerData['zip'] ?? $current_billing_address['zip'] ?? '',
                        'billing_address[company]' => $customerData['company'] ?? $current_billing_address['company'] ?? '',
                        'billing_address[state]' => $customerData['state'] ?? $current_billing_address['state'] ?? '',
                        'billing_address[state_code]' => $customerData['state_code'] ?? $current_billing_address['state_code'] ?? ''
                    ]);

                    //unset empty values
                    $billing_info = array_filter($billing_info);
                    unset($billing_info['object']);

                    $this->updateCustomerVat($customer_id, $billing_info);
                    $this->logMessage("SUCCESS: Updated VAT number and billing info for customer {$email}");
                } else {
                    $this->logMessage("ERROR: Customer not found with email {$email}");
                }
            } catch (Exception $e) {
                $this->logMessage("ERROR: Failed to update VAT for {$email}: " . $e->getMessage());
            }
        }
    }
    
    private function getCustomerByEmail($email) {
        $url = "https://{$this->site_name}.chargebee.com/api/v2/customers";
        $params = http_build_query(['email[is]' => $email]);
        
        $response = $this->makeRequest('GET', $url . '?' . $params);
        
        if (!empty($response['list'])) {
            return $response['list'][0];
        }
        
        return null;
    }
    
    private function updateCustomerVat($customer_id, $billing_info) {
        $url = "https://{$this->site_name}.chargebee.com/api/v2/customers/{$customer_id}/update_billing_info";
        
        return $this->makeRequest('POST', $url, $billing_info);
    }
    
    private function makeRequest($method, $url, $data = null) {
        $ch = curl_init();
        
        $headers = [
            'Authorization: Basic ' . base64_encode($this->api_key . ':'),
            'Content-Type: application/x-www-form-urlencoded'
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        if ($data && $method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            throw new Exception('Curl error: ' . curl_error($ch));
        }
        
        curl_close($ch);
        
        if ($http_code >= 400) {
            throw new Exception("API request failed with status {$http_code}: {$response}");
        }
        
        return json_decode($response, true);
    }
    
    private function logMessage($message) {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($this->log_file, "[{$timestamp}] {$message}\n", FILE_APPEND);
    }
}

// Example usage:
$api_key = 'live_...';
$site_name = '...';

$updater = new ChargebeeVatUpdater($api_key, $site_name);

// Array of email => customer data pairs
$customers = [
    'email' => ['vat_number' => '...'],
    'email2' => ['vat_number' => '...'],
];

$updater->updateVatNumbers($customers);
