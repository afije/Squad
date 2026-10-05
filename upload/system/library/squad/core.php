<?php
/** Squad for OpenCart 2.3. Author: Stephen A (codechip), https://knackm.com, https://github.com/afije/ */
namespace Squad;

class PaymentException extends \RuntimeException {}

final class Money {
    public static function minor($total, $rate = '1') {
        foreach ([$total, $rate] as $number) {
            if (!is_scalar($number) || !preg_match('/^\d{1,12}(?:\.\d{1,12})?$/D', (string)$number)) {
                throw new PaymentException('invalid_amount');
            }
        }
        if (!function_exists('bcmul')) {
            throw new PaymentException('bcmath_required');
        }
        $amount = bcadd(bcmul(bcmul((string)$total, (string)$rate, 12), '100', 8), '0.5', 0);
        if (bccomp($amount, '0') <= 0 || bccomp($amount, '500000000') > 0) {
            throw new PaymentException('invalid_amount');
        }
        return $amount;
    }
    public static function integer($value) {
        if (is_float($value) && is_finite($value) && floor($value) === $value && $value <= 500000000) {
            $value = sprintf('%.0f', $value);
        }
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^\d{1,12}$/D', (string)$value)) {
            throw new PaymentException('invalid_amount');
        }
        return ltrim((string)$value, '0') ?: '0';
    }
}

final class Security {
    public static function https(array $server, $baseUrl) {
        // Trust the server's TLS state, never client-supplied forwarding headers.
        $tls = $server['HTTPS'] ?? '';
        return is_string($baseUrl) && parse_url($baseUrl, PHP_URL_SCHEME) === 'https'
            && (is_scalar($tls) && in_array(strtolower((string)$tls), ['on','1'], true));
    }
    public static function webhookComplete($state) {
        // A signed success event is unresolved until verified or durably held for review.
        return in_array($state, ['paid','review'], true);
    }
    public static function reference($reference) {
        return is_string($reference) && preg_match('/^SQOC[A-F0-9]{48}$/D', $reference) === 1;
    }
    public static function signature($raw, $signature, $secret) {
        return is_string($signature) && preg_match('/^[a-fA-F0-9]{128}$/D', $signature) === 1
            && $secret !== '' && hash_equals(strtoupper(hash_hmac('sha512', $raw, $secret)), strtoupper($signature));
    }
    public static function checkoutUrl($url, $environment) {
        if (!is_string($url) || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) return false;
        $parts = parse_url($url);
        $hosts = $environment === 'sandbox' ? ['sandbox-pay.squadco.com'] : ['pay.squadco.com', 'checkout.squadco.com'];
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https'
            && in_array(strtolower($parts['host'] ?? ''), $hosts, true)
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port']);
    }
    public static function secret($environment) {
        if (!in_array($environment, ['sandbox', 'live'], true)) throw new PaymentException('invalid_environment');
        $secret = trim((string)getenv($environment === 'sandbox' ? 'SQUAD_SANDBOX_SECRET_KEY' : 'SQUAD_LIVE_SECRET_KEY'));
        return self::validateSecret($secret, $environment);
    }
    public static function validateSecret($secret, $environment) {
        if (!in_array($environment, ['sandbox', 'live'], true)) throw new PaymentException('invalid_environment');
        if (!is_string($secret) || !preg_match('/^[A-Za-z0-9_-]{20,512}$/D', $secret) || strpos($secret, 'sk_') === false
            || ($environment === 'sandbox' && strpos($secret, 'sandbox_sk_') !== 0)
            || ($environment === 'live' && strpos($secret, 'sandbox_') === 0)) {
            throw new PaymentException('credentials_missing_or_invalid');
        }
        return $secret;
    }
    public static function validatePublic($key, $environment) {
        if (!in_array($environment, ['sandbox', 'live'], true)) throw new PaymentException('invalid_environment');
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{20,512}$/D', $key) || strpos($key, 'pk_') === false
            || ($environment === 'sandbox' && strpos($key, 'sandbox_pk_') !== 0)
            || ($environment === 'live' && strpos($key, 'sandbox_') === 0)) throw new PaymentException('public_key_invalid');
        return $key;
    }
}

final class Vault {
    private $path;
    public function __construct($path) { $this->path = $path; }
    private function key($create) {
        if (!function_exists('openssl_encrypt')) throw new PaymentException('openssl_required');
        $override = (string)getenv('SQUAD_ENCRYPTION_KEY');
        if ($override !== '') {
            $decoded = base64_decode($override, true);
            if ($decoded === false || strlen($decoded) !== 32) throw new PaymentException('invalid_encryption_key');
            return $decoded;
        }
        if (!$create && !is_file($this->path)) throw new PaymentException('encryption_key_missing');
        $mask = umask(0077);
        try {
            if ($create && !is_dir(dirname($this->path)) && !mkdir(dirname($this->path), 0700, true)) throw new PaymentException('key_storage_unavailable');
            $file = @fopen($this->path, $create ? 'c+b' : 'rb');
            if (!$file) throw new PaymentException('key_storage_unavailable');
            try {
                if (!flock($file, $create ? LOCK_EX : LOCK_SH)) throw new PaymentException('key_storage_unavailable');
                $contents = stream_get_contents($file, 1024);
                if ($contents === '' && $create) {
                    $contents = "<?php return '" . bin2hex(random_bytes(32)) . "';\n";
                    rewind($file);
                    if (fwrite($file, $contents) !== strlen($contents) || !fflush($file)) throw new PaymentException('key_storage_unavailable');
                    @chmod($this->path, 0600);
                }
                if (!preg_match("/\\A<\\?php return '([a-f0-9]{64})';\\n\\z/", $contents, $match)) throw new PaymentException('invalid_encryption_key');
                return hex2bin($match[1]);
            } finally { fclose($file); }
        } finally { umask($mask); }
    }
    private function context($environment, $kind) {
        if (!in_array($environment, ['sandbox','live'], true) || !in_array($kind, ['secret','public'], true)) throw new PaymentException('invalid_environment');
        // Keep existing Secret Key ciphertext compatible with earlier releases.
        return 'squad:' . $environment . ($kind === 'public' ? ':public' : '');
    }
    public function encrypt($plaintext, $environment, $kind = 'secret') {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key(true), OPENSSL_RAW_DATA, $nonce, $tag, $this->context($environment, $kind), 16);
        if ($ciphertext === false) throw new PaymentException('encryption_failed');
        return 'v1:' . base64_encode($nonce . $tag . $ciphertext);
    }
    public function decrypt($envelope, $environment, $kind = 'secret') {
        if (!is_string($envelope) || substr($envelope, 0, 3) !== 'v1:') throw new PaymentException('credentials_missing_or_invalid');
        $data = base64_decode(substr($envelope, 3), true);
        if ($data === false || strlen($data) < 29 || strlen($data) > 1024) throw new PaymentException('invalid_encrypted_credential');
        $secret = openssl_decrypt(substr($data, 28), 'aes-256-gcm', $this->key(false), OPENSSL_RAW_DATA,
            substr($data, 0, 12), substr($data, 12, 16), $this->context($environment, $kind));
        if ($secret === false) throw new PaymentException('credential_decryption_failed');
        return $kind === 'public' ? Security::validatePublic($secret, $environment) : Security::validateSecret($secret, $environment);
    }
    public function configuredSecret($config, $environment) {
        $name = $environment === 'sandbox' ? 'SQUAD_SANDBOX_SECRET_KEY' : 'SQUAD_LIVE_SECRET_KEY';
        if ((string)getenv($name) !== '') return Security::secret($environment);
        return $this->decrypt($config->get('squad_' . $environment . '_secret'), $environment);
    }
}

class Client {
    private $environment;
    private $secret;
    private $transport;
    public function __construct($environment, $secret, callable $transport = null) {
        if (!in_array($environment, ['sandbox', 'live'], true) || $secret === '' || preg_match('/[\r\n]/', $secret)) {
            throw new PaymentException('invalid_credentials');
        }
        $this->environment = $environment;
        $this->secret = $secret;
        $this->transport = $transport;
    }
    public function fingerprint() { return hash('sha256', $this->secret); }
    public function initiate(array $payload) { return $this->request('POST', '/transaction/initiate', $payload); }
    public function verify($reference) {
        if (!Security::reference($reference)) throw new PaymentException('invalid_reference');
        return $this->request('GET', '/transaction/verify/' . rawurlencode($reference));
    }
    private function request($method, $path, array $payload = null) {
        $base = $this->environment === 'sandbox' ? 'https://sandbox-api-d.squadco.com' : 'https://api-d.squadco.com';
        $headers = ['Authorization: Bearer ' . $this->secret, 'Content-Type: application/json', 'Accept: application/json'];
        $encoded = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) throw new PaymentException('invalid_request');
        if ($this->transport) {
            $response = call_user_func($this->transport, $method, $base . $path, $headers, $encoded);
        } else {
            if (!function_exists('curl_init')) throw new PaymentException('curl_required');
            $curl = curl_init($base . $path);
            $body = '';
            curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => 'Codechip-Squad-OpenCart/1.0.10',
                CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$body) {
                    if (strlen($body) + strlen($chunk) > 131072) return 0;
                    $body .= $chunk;
                    return strlen($chunk);
                }]);
            if ($encoded !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $encoded);
            $ok = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            if ($ok === false) throw new PaymentException('api_unavailable');
            $response = [$status, $body];
        }
        if (!is_array($response) || count($response) !== 2 || !is_string($response[1]) || strlen($response[1]) > 131072) {
            throw new PaymentException('invalid_api_response');
        }
        if ((int)$response[0] !== 200) throw new PaymentException('api_http_' . (int)$response[0]);
        $data = json_decode($response[1], true, 32);
        $validSuccess = $method === 'POST' && $path === '/transaction/initiate'
            ? (!array_key_exists('success', (array)$data) || $data['success'] === true)
            : ($data['success'] ?? null) === true;
        if (!is_array($data) || !$validSuccess || (int)($data['status'] ?? 0) !== 200
            || !isset($data['data']) || !is_array($data['data'])) throw new PaymentException('invalid_api_response');
        return $data['data'];
    }
}

class Repository {
    private $db;
    private $table;
    private $prefix;
    public function __construct($db, $prefix) {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $prefix)) throw new PaymentException('invalid_prefix');
        $this->db = $db;
        $this->prefix = $prefix;
        $this->table = $prefix . 'squad_attempt';
    }
    public function install() {
        $this->db->query("CREATE TABLE IF NOT EXISTS `{$this->table}` (
            attempt_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            reference VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            order_id INT UNSIGNED NOT NULL, store_id INT UNSIGNED NOT NULL,
            amount BIGINT UNSIGNED NOT NULL, currency CHAR(3) CHARACTER SET ascii NOT NULL,
            environment VARCHAR(8) CHARACTER SET ascii NOT NULL, key_fingerprint CHAR(64) CHARACTER SET ascii NOT NULL,
            state VARCHAR(24) CHARACTER SET ascii NOT NULL DEFAULT 'initializing',
            checkout_url VARCHAR(2048) NOT NULL DEFAULT '', last_error VARCHAR(80) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
            PRIMARY KEY(attempt_id), UNIQUE KEY(reference), KEY(order_id), KEY(state,updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    public function get($reference) {
        if (!Security::reference($reference)) return null;
        $r = $this->db->query("SELECT * FROM `{$this->table}` WHERE reference='" . $this->db->escape($reference) . "'");
        return $r->num_rows ? $r->row : null;
    }
    public function latest($orderId) {
        $r = $this->db->query("SELECT * FROM `{$this->table}` WHERE order_id=" . (int)$orderId . ' ORDER BY attempt_id DESC LIMIT 1');
        return $r->num_rows ? $r->row : null;
    }
    public function paid($orderId) {
        $r = $this->db->query("SELECT * FROM `{$this->table}` WHERE order_id=" . (int)$orderId . " AND state='paid' LIMIT 1");
        return $r->num_rows ? $r->row : null;
    }
    public function add(array $attempt) {
        $values = [];
        foreach (['reference','order_id','store_id','amount','currency','environment','key_fingerprint'] as $key) {
            $values[] = '`' . $key . "`='" . $this->db->escape((string)$attempt[$key]) . "'";
        }
        $this->db->query("INSERT INTO `{$this->table}` SET " . implode(',', $values) . ",created_at=NOW(),updated_at=NOW()");
        return $this->get($attempt['reference']);
    }
    public function set($reference, $state, $error = '', $url = null) {
        $states = ['initializing','pending','init_unknown','init_failed','failed','abandoned','applying','paid','review','mismatch'];
        if (!in_array($state, $states, true) || !preg_match('/^[a-z0-9_]{0,80}$/D', $error)) throw new PaymentException('invalid_state');
        $sql = "UPDATE `{$this->table}` SET state='" . $state . "',last_error='" . $error . "',updated_at=NOW()";
        if ($url !== null) $sql .= ",checkout_url='" . $this->db->escape($url) . "'";
        $this->db->query($sql . " WHERE reference='" . $this->db->escape($reference) . "'");
    }
    public function lock($orderId) {
        $name = 'squad_' . substr(hash('sha256', $this->prefix . ':' . (int)$orderId), 0, 48);
        $r = $this->db->query("SELECT GET_LOCK('" . $name . "', 3) AS acquired");
        if ((int)$r->row['acquired'] !== 1) throw new PaymentException('order_busy');
        return $name;
    }
    public function unlock($name) { $this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($name) . "')"); }
    public function historyExists($attempt) {
        $marker = 'Squad payment verified: ' . $attempt['reference'];
        $r = $this->db->query("SELECT order_history_id FROM `{$this->prefix}order_history` WHERE order_id=" . (int)$attempt['order_id']
            . " AND comment='" . $this->db->escape($marker) . "' LIMIT 1");
        return $r->num_rows > 0;
    }
    public function listRecent() {
        return $this->db->query("SELECT reference,order_id,amount,currency,environment,state,last_error,updated_at FROM `{$this->table}` ORDER BY attempt_id DESC LIMIT 50")->rows;
    }
}

class Service {
    private $repo;
    private $clients;
    private $orders;
    private $apply;
    private $pending;
    public function __construct($repository, callable $clients, callable $orders, callable $apply, callable $pending) {
        $this->repo = $repository;
        $this->clients = $clients;
        $this->orders = $orders;
        $this->apply = $apply;
        $this->pending = $pending;
    }
    public function start($orderId, $environment, $callback, array $currencies, $storeId) {
        $lock = $this->repo->lock($orderId);
        try {
            $order = call_user_func($this->orders, $orderId);
            if (!$order || $order['payment_code'] !== 'squad' || (int)$order['store_id'] !== (int)$storeId) throw new PaymentException('invalid_order');
            if (isset($order['squad_payable']) && !$order['squad_payable']) throw new PaymentException('order_not_payable');
            $currency = strtoupper($order['currency_code']);
            if (!in_array($currency, $currencies, true) || !in_array($currency, ['NGN','USD'], true)) throw new PaymentException('unsupported_currency');
            $amount = Money::minor($order['total'], $order['currency_value']);
            if (($currency === 'NGN' && bccomp($amount, '10000') < 0)
                || ($currency === 'USD' && (bccomp($amount, '100') < 0 || bccomp($amount, '1000000') > 0))) throw new PaymentException('amount_outside_limits');
            if (!filter_var($order['email'], FILTER_VALIDATE_EMAIL)) throw new PaymentException('invalid_email');
            if ($this->repo->paid($orderId)) throw new PaymentException('order_already_paid');
            $client = call_user_func($this->clients, $environment);
            $latest = $this->repo->latest($orderId);
            if ($latest && !in_array($latest['state'], ['init_failed','failed','abandoned'], true)) {
                if ($latest['state'] === 'pending' && $latest['amount'] === $amount && $latest['currency'] === $currency
                    && $latest['environment'] === $environment && hash_equals($latest['key_fingerprint'], $client->fingerprint())
                    && Security::checkoutUrl($latest['checkout_url'], $environment)) return $latest['checkout_url'];
                throw new PaymentException('payment_needs_reconciliation');
            }
            $reference = 'SQOC' . strtoupper(bin2hex(random_bytes(24)));
            $attempt = $this->repo->add(['reference'=>$reference,'order_id'=>$orderId,'store_id'=>$storeId,'amount'=>$amount,
                'currency'=>$currency,'environment'=>$environment,'key_fingerprint'=>$client->fingerprint()]);
            try { call_user_func($this->pending, $orderId); }
            catch (\Throwable $e) {
                $this->repo->set($reference, 'review', 'pending_order_update_failed');
                throw new PaymentException('manual_review_required');
            }
            $payload = ['email'=>$order['email'],'amount'=>(int)$amount,'currency'=>$currency,'initiate_type'=>'inline',
                'transaction_ref'=>$reference,'customer_name'=>$order['firstname'] . ' ' . $order['lastname'],
                'callback_url'=>$callback . '&reference=' . rawurlencode($reference),
                'metadata'=>['order_id'=>(string)$orderId,'integration'=>'codechip_opencart_23'], 'pass_charge'=>false];
            if ($currency === 'USD') $payload['payment_channels'] = ['card'];
            try {
                $result = $client->initiate($payload);
                if (($result['transaction_ref'] ?? null) !== $reference || !Security::checkoutUrl($result['checkout_url'] ?? null, $environment)) {
                    throw new PaymentException('invalid_checkout_response');
                }
                $this->repo->set($reference, 'pending', '', $result['checkout_url']);
                return $result['checkout_url'];
            } catch (PaymentException $e) {
                $state = in_array($e->getMessage(), ['api_http_400','api_http_401','api_http_403'], true) ? 'init_failed' : 'init_unknown';
                $this->repo->set($reference, $state, $e->getMessage());
                throw $e;
            }
        } finally { $this->repo->unlock($lock); }
    }
    public function reconcile($reference) {
        $attempt = $this->repo->get($reference);
        if (!$attempt) throw new PaymentException('unknown_reference');
        $lock = $this->repo->lock($attempt['order_id']);
        try {
            $attempt = $this->repo->get($reference);
            if ($attempt['state'] === 'paid') return 'paid';
            if (in_array($attempt['state'], ['applying','review'], true)) {
                if ($this->repo->historyExists($attempt)) {
                    $this->repo->set($reference, 'paid');
                    return 'paid';
                }
                $this->repo->set($reference, 'review', 'interrupted_order_update');
                return 'review';
            }
            $client = call_user_func($this->clients, $attempt['environment']);
            if (!hash_equals($attempt['key_fingerprint'], $client->fingerprint())) throw new PaymentException('credential_changed');
            $result = $client->verify($reference);
            if (($result['transaction_ref'] ?? null) !== $reference
                || Money::integer($result['transaction_amount'] ?? null) !== (string)$attempt['amount']
                || ($result['transaction_currency_id'] ?? null) !== $attempt['currency']) {
                $this->repo->set($reference, 'mismatch', 'verification_mismatch');
                throw new PaymentException('verification_mismatch');
            }
            $status = is_string($result['transaction_status'] ?? null) ? strtolower($result['transaction_status']) : '';
            if ($status !== 'success') {
                $state = ['pending'=>'pending','failed'=>'failed','abandoned'=>'abandoned'][$status] ?? null;
                if ($state === null) throw new PaymentException('unknown_payment_status');
                $this->repo->set($reference, $state);
                return $state;
            }
            $order = call_user_func($this->orders, $attempt['order_id']);
            if (!$order || $order['payment_code'] !== 'squad' || (int)$order['store_id'] !== (int)$attempt['store_id']
                || (isset($order['squad_payable']) && !$order['squad_payable'])
                || strtoupper($order['currency_code']) !== $attempt['currency']
                || Money::minor($order['total'], $order['currency_value']) !== (string)$attempt['amount']) {
                $this->repo->set($reference, 'review', 'order_changed');
                return 'review';
            }
            if ($this->repo->paid($attempt['order_id'])) {
                $this->repo->set($reference, 'review', 'additional_payment');
                return 'review';
            }
            // Durable gate BEFORE touching legacy MyISAM tables. Interrupted updates require review.
            $this->repo->set($reference, 'applying');
            try {
                call_user_func($this->apply, $attempt['order_id'], 'Squad payment verified: ' . $reference);
                if (!$this->repo->historyExists($attempt)) throw new PaymentException('order_history_missing');
                $this->repo->set($reference, 'paid');
                return 'paid';
            } catch (\Throwable $e) {
                $this->repo->set($reference, 'review', 'interrupted_order_update');
                throw new PaymentException('manual_review_required');
            }
        } finally { $this->repo->unlock($lock); }
    }
}
