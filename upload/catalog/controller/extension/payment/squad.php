<?php
class ControllerExtensionPaymentSquad extends Controller {
    private function setup() {
        $this->load->language('extension/payment/squad');
        $this->load->model('extension/payment/squad');
        require_once DIR_SYSTEM . 'library/squad/core.php';
    }
    public function index() {
        $this->setup();
        if (empty($this->session->data['order_id']) || ($this->session->data['payment_method']['code'] ?? '') !== 'squad') return '';
        if (empty($this->session->data['squad_csrf'])) $this->session->data['squad_csrf'] = bin2hex(random_bytes(32));
        return $this->load->view('extension/payment/squad', [
            'text_testmode'=>$this->language->get('text_testmode'), 'text_redirect'=>$this->language->get('text_redirect'),
            'button_confirm'=>$this->language->get('button_confirm'), 'text_error'=>$this->language->get('error_payment'),
            'sandbox'=>($this->config->get('squad_environment') ?: 'sandbox') === 'sandbox',
            'logo'=>HTTPS_SERVER . 'image/catalog/payment/squad.png',
            'csrf'=>$this->session->data['squad_csrf'], 'start'=>$this->url->link('extension/payment/squad/start', '', true)
        ]);
    }
    public function start() {
        $this->setup();
        $this->response->addHeader('Cache-Control: no-store');
        $this->response->addHeader('Content-Type: application/json');
        if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST'
            || !is_string($this->request->post['csrf'] ?? null)
            || !hash_equals((string)($this->session->data['squad_csrf'] ?? ''), $this->request->post['csrf'])
            || empty($this->session->data['squad_csrf']) || empty($this->session->data['order_id'])
            || ($this->session->data['payment_method']['code'] ?? '') !== 'squad' || !$this->config->get('squad_status')) {
            $this->response->addHeader('HTTP/1.1 403 Forbidden');
            $this->response->setOutput(json_encode(['error'=>$this->language->get('error_session')]));
            return;
        }
        try {
            $environment = $this->config->get('squad_environment') ?: 'sandbox';
            if ($environment === 'live' && !\Squad\Security::https($this->request->server, HTTPS_SERVER)) throw new \Squad\PaymentException('https_required');
            $url = $this->model_extension_payment_squad->service()->start((int)$this->session->data['order_id'], $environment,
                html_entity_decode($this->url->link('payment/squad/callback', '', true), ENT_QUOTES, 'UTF-8'),
                (array)($this->config->get('squad_currencies') ?: ['NGN']), (int)$this->config->get('config_store_id'));
            $this->response->setOutput(json_encode(['redirect'=>$url], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
        } catch (\Throwable $e) {
            $this->safeLog($e);
            $this->response->addHeader('HTTP/1.1 502 Bad Gateway');
            $this->response->setOutput(json_encode(['error'=>$this->language->get('error_payment')]));
        }
    }
    public function callback() {
        $this->setup();
        $this->response->addHeader('Cache-Control: no-store');
        $reference = $this->request->get['reference'] ?? $this->request->get['transaction_ref'] ?? '';
        $state = 'pending';
        try {
            if (!\Squad\Security::reference($reference)) throw new \Squad\PaymentException('invalid_reference');
            $attempt = $this->model_extension_payment_squad->repository()->get($reference);
            if (!$attempt) throw new \Squad\PaymentException('unknown_reference');
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            $state = $this->model_extension_payment_squad->service()->reconcile($reference);
            if ($state === 'paid' && (int)($this->session->data['order_id'] ?? 0) === (int)$attempt['order_id']
                && ($this->session->data['payment_method']['code'] ?? '') === 'squad') {
                // Core success clears only the matching checkout session.
                $this->response->redirect($this->url->link('checkout/success', '', true));
                return;
            }
        } catch (\Throwable $e) { $this->safeLog($e); }
        $messages = ['paid'=>'text_paid','pending'=>'text_pending','failed'=>'text_failed','abandoned'=>'text_pending','review'=>'text_review'];
        $this->response->setOutput($this->load->view('extension/payment/squad_result', [
            'heading'=>$this->language->get('text_title'), 'message'=>$this->language->get($messages[$state] ?? 'text_pending'),
            'continue'=>$this->url->link('common/home', '', true), 'button_continue'=>$this->language->get('button_continue')
        ]));
    }
    public function webhook() {
        $this->setup();
        $this->response->addHeader('Content-Type: application/json');
        $this->response->addHeader('Cache-Control: no-store');
        $reference = '';
        try {
            if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') return $this->ack(405, '', 'Method not allowed');
            if ((int)($this->request->server['CONTENT_LENGTH'] ?? 0) > 131072) return $this->ack(413, '', 'Payload too large');
            $raw = file_get_contents('php://input', false, null, 0, 131073);
            if ($raw === false || strlen($raw) > 131072) return $this->ack(413, '', 'Payload too large');
            $event = json_decode($raw, true, 32);
            if (!is_array($event) || ($event['Event'] ?? '') !== 'charge_successful' || !is_array($event['Body'] ?? null)) return $this->ack(400, '', 'Invalid event');
            $reference = $event['TransactionRef'] ?? '';
            if (!\Squad\Security::reference($reference) || ($event['Body']['transaction_ref'] ?? '') !== $reference) return $this->ack(400, '', 'Invalid reference');
            $attempt = $this->model_extension_payment_squad->repository()->get($reference);
            if (!$attempt) return $this->ack(400, '', 'Unknown reference');
            if ($attempt['environment'] === 'live' && !\Squad\Security::https($this->request->server, HTTPS_SERVER)) return $this->ack(400, '', 'HTTPS required');
            $secret = $this->model_extension_payment_squad->vault()->configuredSecret($this->config, $attempt['environment']);
            if (!\Squad\Security::signature($raw, $this->request->server['HTTP_X_SQUAD_ENCRYPTED_BODY'] ?? '', $secret)) return $this->ack(401, '', 'Invalid signature');
            if (\Squad\Money::integer($event['Body']['amount'] ?? null) !== (string)$attempt['amount']
                || ($event['Body']['currency'] ?? '') !== $attempt['currency']) return $this->ack(400, $reference, 'Payment mismatch');
            $merchant = (string)$this->config->get('squad_merchant_id');
            if ($merchant !== '' && ($event['Body']['merchant_id'] ?? '') !== $merchant) return $this->ack(400, $reference, 'Merchant mismatch');
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            $state = $this->model_extension_payment_squad->service()->reconcile($reference);
            return $this->ack(\Squad\Security::webhookComplete($state) ? 200 : 503, $reference,
                $state === 'review' ? 'Recorded for review' : ($state === 'paid' ? 'Received' : 'Verification not yet confirmed'));
        } catch (\Throwable $e) {
            $this->safeLog($e);
            return $this->ack(503, is_string($reference) ? $reference : '', 'Verification temporarily unavailable');
        }
    }
    private function ack($status, $reference, $message) {
        $phrases = [200=>'OK',400=>'Bad Request',401=>'Unauthorized',405=>'Method Not Allowed',413=>'Payload Too Large',503=>'Service Unavailable'];
        $this->response->addHeader('HTTP/1.1 ' . $status . ' ' . $phrases[$status]);
        $this->response->setOutput(json_encode(['response_code'=>$status,'transaction_reference'=>$reference,'response_description'=>$message]));
    }
    private function safeLog($error) {
        $code = $error instanceof \Squad\PaymentException ? $error->getMessage() : 'internal_error';
        if (!preg_match('/^[a-z0-9_]{1,80}$/D', $code)) $code = 'internal_error';
        $this->log->write('SQUAD: ' . $code);
    }
}
