<?php
class ModelExtensionPaymentSquad extends Model {
    private function core() { require_once DIR_SYSTEM . 'library/squad/core.php'; }
    public function repository() { $this->core(); return new \Squad\Repository($this->db, DB_PREFIX); }
    public function vault() { $this->core(); return new \Squad\Vault(DIR_SYSTEM . 'storage/squad/encryption-key.php'); }
    public function getMethod($address, $total) {
        try { return $this->availableMethod($address, $total); }
        catch (\Squad\PaymentException $e) {
            $codes = ['credentials_missing_or_invalid','encryption_key_missing','invalid_encryption_key',
                'key_storage_unavailable','credential_decryption_failed','openssl_required','invalid_encrypted_credential','invalid_environment'];
            return $this->unavailable(in_array($e->getMessage(), $codes, true) ? $e->getMessage() : 'credential_unavailable');
        } catch (\Throwable $e) { return $this->unavailable('runtime_error'); }
    }
    private function unavailable($reason) {
        // Fixed internal codes only: no exception text, keys, addresses or session tokens.
        $last = $this->session->data['squad_availability_log'] ?? [];
        if (($last['reason'] ?? '') !== $reason || (int)($last['time'] ?? 0) < time() - 60) {
            $this->log->write('SQUAD AVAILABILITY: ' . $reason);
            $this->session->data['squad_availability_log'] = ['reason'=>$reason,'time'=>time()];
        }
        return [];
    }
    private function availableMethod($address, $total) {
        $this->core();
        $this->load->language('extension/payment/squad');
        $currency = $this->session->data['currency'] ?? $this->config->get('config_currency');
        $currencies = $this->config->get('squad_currencies') ?: ['NGN'];
        if (!$this->config->get('squad_status')) return $this->unavailable('disabled');
        if (!is_array($currencies)) return $this->unavailable('invalid_currency_configuration');
        if (!in_array($currency, ['NGN','USD'], true)) return $this->unavailable('unsupported_checkout_currency');
        if (!in_array($currency, $currencies, true)) return $this->unavailable('checkout_currency_disabled');
        if ((float)$total < (float)$this->config->get('squad_total')) return $this->unavailable('below_minimum_total');
        $this->vault()->configuredSecret($this->config, $this->config->get('squad_environment') ?: 'sandbox');
        if ($this->config->get('squad_geo_zone_id')) {
            $q = $this->db->query('SELECT zone_to_geo_zone_id FROM ' . DB_PREFIX . 'zone_to_geo_zone WHERE geo_zone_id=' . (int)$this->config->get('squad_geo_zone_id')
                . ' AND country_id=' . (int)($address['country_id'] ?? 0) . ' AND (zone_id=' . (int)($address['zone_id'] ?? 0) . ' OR zone_id=0)');
            if (!$q->num_rows) return $this->unavailable('address_outside_geo_zone');
        }
        return ['code'=>'squad','title'=>$this->language->get('text_title'),'terms'=>'','sort_order'=>(int)$this->config->get('squad_sort_order')];
    }
    public function service() {
        $this->core();
        $this->load->model('checkout/order');
        return new \Squad\Service($this->repository(), function ($environment) {
            return new \Squad\Client($environment, $this->vault()->configuredSecret($this->config, $environment));
        }, function ($id) {
            $order = $this->model_checkout_order->getOrder($id);
            if ($order) $order['squad_payable'] = in_array((int)$order['order_status_id'], [0, (int)$this->config->get('squad_pending_status_id')], true);
            return $order;
        }, function ($id, $comment) {
            $status = (int)$this->config->get('squad_paid_status_id');
            $processing = array_merge((array)$this->config->get('config_processing_status'), (array)$this->config->get('config_complete_status'));
            if (!$status || !in_array($status, array_map('intval', $processing), true)) throw new \Squad\PaymentException('invalid_paid_status');
            $this->model_checkout_order->addOrderHistory($id, $status, $comment, true);
        }, function ($id) {
            $order = $this->model_checkout_order->getOrder($id);
            if (!$order['order_status_id']) {
                $status = (int)$this->config->get('squad_pending_status_id');
                $processing = array_map('intval', array_merge((array)$this->config->get('config_processing_status'), (array)$this->config->get('config_complete_status')));
                if (!$status || in_array($status, $processing, true)) throw new \Squad\PaymentException('invalid_pending_status');
                $this->model_checkout_order->addOrderHistory($id, $status, 'Awaiting Squad payment', false);
            }
        });
    }
}
