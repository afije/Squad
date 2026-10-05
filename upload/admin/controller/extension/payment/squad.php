<?php
/** Author: Stephen A (codechip). https://knackm.com | https://github.com/afije/ */
class ControllerExtensionPaymentSquad extends Controller {
    private $error = '';
    private function core() { require_once DIR_SYSTEM . 'library/squad/core.php'; }
    private function repository() { $this->core(); return new \Squad\Repository($this->db, DB_PREFIX); }
    private function vault() { $this->core(); return new \Squad\Vault(DIR_SYSTEM . 'storage/squad/encryption-key.php'); }
    private function logLedgerError(\Throwable $error) {
        $code = preg_match('/Error No: ([0-9]{1,5})\b/', $error->getMessage(), $match) ? 'db_error_' . $match[1] : 'unavailable';
        $this->log->write('SQUAD LEDGER: ' . $code);
    }
    private function visibilityDiagnostics($existing) {
        $messages = [];
        $messages[] = $this->language->get(empty($existing['squad_status']) ? 'diagnostic_disabled' : 'diagnostic_enabled');
        $installed = $this->db->query("SELECT extension_id FROM " . DB_PREFIX . "extension WHERE type='payment' AND code='squad'")->num_rows;
        if (!$installed) $messages[] = $this->language->get('diagnostic_not_registered');
        $currencies = $existing['squad_currencies'] ?? ['NGN'];
        $currencies = is_array($currencies) ? array_values(array_intersect(['NGN','USD'], $currencies)) : [];
        $messages[] = sprintf($this->language->get('diagnostic_currencies'), implode(', ', $currencies) ?: 'None');
        if (!in_array($this->config->get('config_currency'), $currencies, true)) $messages[] = $this->language->get('diagnostic_default_currency_disabled');
        if (!empty($existing['squad_geo_zone_id'])) $messages[] = $this->language->get('diagnostic_geo_zone');
        if ((float)($existing['squad_total'] ?? 0) > 0) $messages[] = $this->language->get('diagnostic_minimum');
        $mode = $existing['squad_environment'] ?? 'sandbox';
        try {
            $this->vault()->configuredSecret($this->config, $mode);
            $messages[] = $this->language->get('diagnostic_credentials_ok');
        } catch (\Throwable $e) {
            $codes = ['credentials_missing_or_invalid','encryption_key_missing','invalid_encryption_key','key_storage_unavailable',
                'credential_decryption_failed','openssl_required','invalid_encrypted_credential','invalid_environment'];
            $code = $e instanceof \Squad\PaymentException && in_array($e->getMessage(), $codes, true) ? $e->getMessage() : 'runtime_error';
            $messages[] = sprintf($this->language->get('diagnostic_credentials_failed'), $code);
        }
        foreach (['curl_init'=>'cURL','bcmul'=>'BCMath','openssl_encrypt'=>'OpenSSL','getenv'=>'getenv'] as $function=>$name) {
            if (!function_exists($function)) $messages[] = sprintf($this->language->get('diagnostic_runtime_missing'), $name);
        }
        return $messages;
    }
    private function missingCheckoutFiles() {
        $files = [
            'catalog/model/extension/payment/squad.php' => DIR_CATALOG . 'model/extension/payment/squad.php',
            'catalog/controller/extension/payment/squad.php' => DIR_CATALOG . 'controller/extension/payment/squad.php',
            'catalog/controller/payment/squad.php' => DIR_CATALOG . 'controller/payment/squad.php',
            'catalog/language/en-gb/extension/payment/squad.php' => DIR_CATALOG . 'language/en-gb/extension/payment/squad.php',
            'catalog/view/theme/default/template/extension/payment/squad.tpl' => DIR_CATALOG . 'view/theme/default/template/extension/payment/squad.tpl',
            'catalog/view/theme/default/template/extension/payment/squad_result.tpl' => DIR_CATALOG . 'view/theme/default/template/extension/payment/squad_result.tpl'
        ];
        $missing = [];
        foreach ($files as $relative => $path) {
            if (!is_file($path) || !is_readable($path)) $missing[] = $relative;
        }
        return $missing;
    }
    public function install() {
        // Core grants Squad permissions in the database during this request;
        // Cart\User still holds the permissions loaded before that grant.
        if (!$this->validToken() || (!$this->user->hasPermission('modify', 'extension/extension/payment')
            && !$this->user->hasPermission('modify', 'extension/payment/squad'))) return;
        $this->repository()->install();
        $this->load->model('setting/setting');
        if (!$this->model_setting_setting->getSetting('squad')) {
            $processing = array_map('intval', array_merge((array)$this->config->get('config_processing_status'), (array)$this->config->get('config_complete_status')));
            $this->load->model('localisation/order_status');
            $pending = 0;
            foreach ($this->model_localisation_order_status->getOrderStatuses() as $status) {
                if (!in_array((int)$status['order_status_id'], $processing, true)) { $pending = (int)$status['order_status_id']; break; }
            }
            $default = (int)$this->config->get('config_order_status_id');
            if ($default && !in_array($default, $processing, true)) $pending = $default;
            $this->model_setting_setting->editSetting('squad', ['squad_status'=>0,'squad_environment'=>'sandbox','squad_currencies'=>['NGN'],
                'squad_paid_status_id'=>$processing[0] ?? 0,'squad_pending_status_id'=>$pending,
                'squad_geo_zone_id'=>0,'squad_total'=>'0','squad_sort_order'=>0,'squad_merchant_id'=>'']);
        }
    }
    public function uninstall() {
        // Keep the payment ledger and encryption key for audit/reconciliation. Core removes only settings.
    }
    public function index() {
        $this->core();
        $this->response->addHeader('Cache-Control: no-store');
        $this->response->addHeader('Referrer-Policy: no-referrer');
        $this->load->language('extension/payment/squad');
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/setting');
        $existing = $this->model_setting_setting->getSetting('squad');
        if (($this->request->server['REQUEST_METHOD'] ?? '') === 'POST') {
            try {
                if (!$this->user->hasPermission('modify', 'extension/payment/squad') || !$this->validToken()) throw new \Squad\PaymentException('permission_denied');
                $settings = $this->validatedSettings($existing);
                // Repairs earlier registrations whose install hook skipped the ledger.
                // IF NOT EXISTS preserves existing payment attempts.
                try { $this->repository()->install(); }
                catch (\Throwable $e) { $this->logLedgerError($e); throw new \Squad\PaymentException('ledger_unavailable'); }
                $this->model_setting_setting->editSetting('squad', $settings);
                $this->session->data['success'] = $this->language->get('text_success');
                $this->response->redirect($this->url->link('extension/payment/squad', 'token=' . $this->session->data['token'], true));
                return;
            } catch (\Squad\PaymentException $e) {
                if (preg_match('/^[a-z0-9_]{1,80}$/D', $e->getMessage())) $this->log->write('SQUAD ADMIN: ' . $e->getMessage());
                $this->error = $this->language->get('error_' . $e->getMessage());
                if ($this->error === 'error_' . $e->getMessage()) $this->error = $this->language->get('error_configuration');
            }
        }
        $data = [];
        foreach (['heading_title','text_edit','text_success','text_enabled','text_disabled','text_all_zones','text_test','text_live',
            'text_credentials','text_secret_help','text_author','text_attempts','text_recheck','text_storage','text_review_help',
            'entry_status','entry_environment','entry_sandbox_secret','entry_sandbox_public','entry_live_secret','entry_live_public','entry_merchant_id','entry_currencies',
            'entry_paid_status','entry_pending_status','entry_geo_zone','entry_total','entry_sort_order','button_save','button_cancel',
            'text_key_saved','text_key_empty'] as $key) $data[$key] = $this->language->get($key);
        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $processing = array_map('intval', array_merge((array)$this->config->get('config_processing_status'), (array)$this->config->get('config_complete_status')));
        $pending = (int)$this->config->get('config_order_status_id');
        if (!$pending || in_array($pending, $processing, true)) {
            $pending = 0;
            foreach ($data['order_statuses'] as $status) {
                if (!in_array((int)$status['order_status_id'], $processing, true)) { $pending = (int)$status['order_status_id']; break; }
            }
        }
        $defaults = ['status'=>0,'environment'=>'sandbox','merchant_id'=>'','currencies'=>['NGN'],'paid_status_id'=>$processing[0] ?? 0,
            'pending_status_id'=>$pending,'geo_zone_id'=>0,'total'=>'0','sort_order'=>0];
        foreach ($defaults as $key=>$default) {
            $name = 'squad_' . $key;
            $value = $this->request->post[$name] ?? $existing[$name] ?? $default;
            $data[$name] = $key === 'currencies' ? (is_array($value) ? $value : []) : (is_scalar($value) ? $value : $default);
        }
        foreach (['sandbox','live'] as $mode) {
            foreach (['secret','public'] as $kind) {
                try {
                    if ($kind === 'secret') $this->vault()->configuredSecret($this->config, $mode);
                    else $this->vault()->decrypt($existing['squad_' . $mode . '_public'] ?? '', $mode, 'public');
                    $data[$mode . '_' . $kind . '_configured'] = true;
                } catch (\Throwable $e) { $data[$mode . '_' . $kind . '_configured'] = false; }
            }
        }
        $data['error_warning'] = $this->error;
        $missing = $this->missingCheckoutFiles();
        if ($missing) $data['error_warning'] = sprintf($this->language->get('error_installation_incomplete'), implode(', ', $missing));
        $data['text_diagnostics'] = $this->language->get('text_diagnostics');
        $data['text_diagnostics_help'] = $this->language->get('text_diagnostics_help');
        $data['diagnostics'] = $this->visibilityDiagnostics($existing);
        $data['success'] = $this->session->data['success'] ?? '';
        unset($this->session->data['success']);
        $token = 'token=' . rawurlencode($this->session->data['token']);
        // OpenCart returns HTML-encoded links. Decode here so the template escapes them once.
        $data['action'] = html_entity_decode($this->url->link('extension/payment/squad', $token, true), ENT_QUOTES, 'UTF-8');
        $data['cancel'] = html_entity_decode($this->url->link('extension/extension', $token . '&type=payment', true), ENT_QUOTES, 'UTF-8');
        $data['recheck'] = html_entity_decode($this->url->link('extension/payment/squad/recheck', $token, true), ENT_QUOTES, 'UTF-8');
        $base = HTTPS_CATALOG;
        $data['logo'] = $base . 'image/catalog/payment/squad.png';
        $data['webhook'] = $base . 'index.php?route=payment/squad/webhook';
        $data['callback'] = $base . 'index.php?route=payment/squad/callback';
        $this->load->model('localisation/geo_zone');
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();
        try { $data['attempts'] = $this->repository()->listRecent(); }
        catch (\Throwable $e) {
            $this->logLedgerError($e);
            $data['attempts'] = [];
            if (!$data['error_warning']) $data['error_warning'] = $this->language->get('error_install');
        }
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('extension/payment/squad', $data));
    }
    public function recheck() {
        $this->core();
        if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST' || !$this->validToken()
            || !$this->user->hasPermission('modify', 'extension/payment/squad')
            || !\Squad\Security::reference($this->request->post['reference'] ?? null)) {
            $this->response->addHeader('HTTP/1.1 403 Forbidden');
            $this->response->setOutput('Forbidden');
            return;
        }
        // The public callback performs authoritative verification; never expose a force-paid action.
        $this->response->redirect(HTTPS_CATALOG . 'index.php?route=payment/squad/callback&reference=' . rawurlencode($this->request->post['reference']));
    }
    private function validToken() {
        return is_string($this->request->get['token'] ?? null) && !empty($this->session->data['token'])
            && hash_equals($this->session->data['token'], $this->request->get['token']);
    }
    private function validatedSettings($existing) {
        $post = $this->request->post;
        foreach (['status','environment','paid_status_id','pending_status_id','geo_zone_id','total','sort_order','merchant_id'] as $key) {
            if (!isset($post['squad_' . $key]) || !is_scalar($post['squad_' . $key])) throw new \Squad\PaymentException('configuration');
        }
        $mode = (string)$post['squad_environment'];
        if (!in_array($mode, ['sandbox','live'], true) || !in_array((string)$post['squad_status'], ['0','1'], true)) throw new \Squad\PaymentException('configuration');
        if ((string)$post['squad_status'] === '1' && $this->missingCheckoutFiles()) throw new \Squad\PaymentException('installation_incomplete');
        $currencies = $post['squad_currencies'] ?? [];
        if (!is_array($currencies) || !$currencies || array_diff($currencies, ['NGN','USD'])) throw new \Squad\PaymentException('configuration');
        if (!preg_match('/^\d{1,10}(?:\.\d{1,4})?$/D', (string)$post['squad_total'])
            || !preg_match('/^\d{1,5}$/D', (string)$post['squad_sort_order'])
            || !preg_match('/^[A-Za-z0-9_-]{0,64}$/D', (string)$post['squad_merchant_id'])) throw new \Squad\PaymentException('configuration');
        $paid = (int)$post['squad_paid_status_id']; $pending = (int)$post['squad_pending_status_id'];
        $processing = array_map('intval', array_merge((array)$this->config->get('config_processing_status'), (array)$this->config->get('config_complete_status')));
        $this->load->model('localisation/order_status');
        $ids = array_map('intval', array_column($this->model_localisation_order_status->getOrderStatuses(), 'order_status_id'));
        if (!in_array($paid, $ids, true) || !in_array($paid, $processing, true) || !in_array($pending, $ids, true)
            || in_array($pending, $processing, true)) throw new \Squad\PaymentException('invalid_status_mapping');
        $zone = (int)$post['squad_geo_zone_id'];
        if ($zone && !$this->db->query('SELECT geo_zone_id FROM ' . DB_PREFIX . 'geo_zone WHERE geo_zone_id=' . $zone)->num_rows) throw new \Squad\PaymentException('configuration');
        $settings = ['squad_status'=>(int)$post['squad_status'],'squad_environment'=>$mode,'squad_currencies'=>array_values(array_unique($currencies)),
            'squad_paid_status_id'=>$paid,'squad_pending_status_id'=>$pending,'squad_geo_zone_id'=>$zone,
            'squad_total'=>(string)$post['squad_total'],'squad_sort_order'=>(int)$post['squad_sort_order'],'squad_merchant_id'=>(string)$post['squad_merchant_id']];
        foreach (['sandbox','live'] as $environment) {
            foreach (['secret','public'] as $kind) {
                $key = 'squad_' . $environment . '_' . $kind;
                $settings[$key] = $existing[$key] ?? '';
                // Save the selected pair; inactive inputs cannot overwrite or invalidate its saved keys.
                if ($environment !== $mode) continue;
                $input = $post[$key . '_input'] ?? '';
                if (!is_string($input)) throw new \Squad\PaymentException('invalid_' . $environment . '_' . $kind);
                if (trim($input) !== '') {
                    try {
                        $value = $kind === 'secret' ? \Squad\Security::validateSecret(trim($input), $environment) : \Squad\Security::validatePublic(trim($input), $environment);
                    } catch (\Squad\PaymentException $e) { throw new \Squad\PaymentException('invalid_' . $environment . '_' . $kind); }
                    $settings[$key] = $this->vault()->encrypt($value, $environment, $kind);
                }
            }
        }
        if ($settings['squad_status']) {
            $envName = $mode === 'sandbox' ? 'SQUAD_SANDBOX_SECRET_KEY' : 'SQUAD_LIVE_SECRET_KEY';
            if ((string)getenv($envName) !== '') \Squad\Security::secret($mode);
            else {
                if ($settings['squad_' . $mode . '_secret'] === '') throw new \Squad\PaymentException('missing_' . $mode . '_secret');
                $this->vault()->decrypt($settings['squad_' . $mode . '_secret'], $mode);
            }
        }
        return $settings;
    }
}
