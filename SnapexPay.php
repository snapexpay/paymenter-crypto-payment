<?php

namespace Paymenter\Extensions\Gateways\SnapexPay;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

#[ExtensionMeta(
    name: 'SnapEX Pay',
    description: 'Accept USDT (TRC20, ERC20, BEP20) and other coins. Customer pays on SnapEX Pay; the invoice is marked paid from a signed webhook.',
    version: '1.1.0',
    author: 'SnapEX',
    url: 'https://pay.snapex.pro/integrations/paymenter',
    icon: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA2NCA2NCI+PHJlY3Qgd2lkdGg9IjY0IiBoZWlnaHQ9IjY0IiByeD0iMTQiIGZpbGw9IiMwQjEyMjAiLz48dGV4dCB4PSIzMiIgeT0iNDIiIHRleHQtYW5jaG9yPSJtaWRkbGUiIGZvbnQtc2l6ZT0iMzIiIGZvbnQtZmFtaWx5PSJBcmlhbCxzYW5zLXNlcmlmIiBmb250LXdlaWdodD0iNzAwIiBmaWxsPSIjM0REQzk3Ij5TPC90ZXh0Pjwvc3ZnPg=='
)]
class SnapexPay extends Gateway
{
    public const VERSION = '1.1.0';

    private const DEFAULT_API_URL = 'https://snapex.pro/api/v1/merchant';

    private const WEBHOOK_TOLERANCE = 300;

    /** @var callable|null fn(string $method, string $url, array $headers, ?string $body): array{0:int,1:string} */
    public static $transport;

    public function boot()
    {
        require __DIR__.'/routes.php';
    }

    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'api_key',
                'label' => 'API key',
                'type' => 'password',
                'required' => true,
                'encrypted' => true,
                'description' => 'SnapEX Pay cabinet → API keys. Scopes: invoices.read, invoices.write.',
            ],
            [
                'name' => 'webhook_secret',
                'label' => 'Webhook secret',
                'type' => 'password',
                'required' => true,
                'encrypted' => true,
                'description' => 'whsec_… from the cabinet. Point the cabinet webhook URL at /extensions/snapexpay/webhook',
            ],
            [
                'name' => 'fee_on',
                'label' => 'Network fee paid by',
                'type' => 'select',
                'required' => false,
                'default' => 'merchant',
                'options' => [
                    'merchant' => 'Merchant (client pays the invoice amount)',
                    'customer' => 'Customer (fee added on top)',
                ],
            ],
            [
                'name' => 'expires_minutes',
                'label' => 'Payment window, minutes',
                'type' => 'number',
                'required' => false,
                'min_value' => 1,
                'max_value' => 1440,
                'default' => '60',
                'description' => '1–1440. Default 60.',
            ],
            [
                'name' => 'usd_rate',
                'label' => 'USD per 1 invoice currency',
                'type' => 'text',
                'required' => false,
                'default' => '1',
                'description' => 'Use 1 if Paymenter amounts are already USD. Example: 1.08 if the invoice is EUR.',
            ],
            [
                'name' => 'environment',
                'label' => 'Environment',
                'type' => 'select',
                'required' => false,
                'default' => 'live',
                'options' => [
                    'live' => 'Live',
                    'test' => 'Test (confirms without real money)',
                ],
            ],
            [
                'name' => 'partner_id',
                'label' => 'Partner ID (optional)',
                'type' => 'text',
                'required' => false,
                'description' => 'The integrator\'s ID in the SnapEX Pay referral program. Leave empty if you set this up yourself.',
            ],
            [
                'name' => 'api_url',
                'label' => 'API URL',
                'type' => 'text',
                'required' => false,
                'default' => self::DEFAULT_API_URL,
            ],
        ];
    }

    public function canUseGateway($total, $currency, $type, $items = [])
    {
        return (float) $total > 0;
    }

    public function pay(Invoice $invoice, $total)
    {
        $amount = number_format(round((float) $total, 2), 2, '.', '');
        $amountUsd = $this->amountUsd((float) $total, (string) ($invoice->currency_code ?? 'USD'));
        $environment = $this->environment();
        $minutes = $this->expiresMinutes();
        $back = function_exists('route') ? (string) route('invoices.show', $invoice) : '';
        // external_id is unique per merchant on SnapEX, so every attempt gets its own one. It also
        // carries the amount in invoice currency, so the webhook credits exactly what was billed.
        // The minute bucket collapses double clicks into one SnapEX invoice (same Idempotency-Key).
        $attempt = base_convert((string) intdiv(time(), 60), 10, 36);
        $externalId = 'paymenter-'.$this->siteTag().'-'.$invoice->id.'-'.$amount.'-'.$attempt;
        $created = $this->request('POST', '/invoices', array_filter([
            'amount_usd' => $amountUsd,
            'fee_on' => $this->feeOn(),
            'environment' => $environment,
            'external_id' => $externalId,
            'description' => $this->description($invoice),
            'success_url' => $back !== '' ? $back.'?checkPayment=true' : null,
            'cancel_url' => $back !== '' ? $back : null,
            'expires_in' => $minutes * 60,
            'partner_ref' => trim((string) $this->config('partner_id')) ?: null,
        ], fn ($value) => $value !== null), ['Idempotency-Key' => $externalId]);

        $payUrl = (string) ($created['pay_url'] ?? '');
        if ($payUrl === '') {
            throw new Exception('SnapEX Pay did not return a payment URL');
        }

        return $payUrl;
    }

    public function webhook(Request $request)
    {
        $body = (string) $request->getContent();
        $secret = trim((string) $this->config('webhook_secret'));
        if ($secret === '' || ! self::verifyWebhook($body, $secret, [
            'Webhook-Timestamp' => (string) $request->header('Webhook-Timestamp', ''),
            'Webhook-Signature' => (string) $request->header('Webhook-Signature', ''),
        ])) {
            return response()->json(['error' => 'invalid signature'], 400);
        }

        $event = json_decode($body, true);
        if (! is_array($event)) {
            return response()->json(['error' => 'invalid body'], 400);
        }
        $type = (string) ($event['type'] ?? '');
        $snapexId = (string) ($event['data']['invoice_id'] ?? '');
        if ($snapexId === '' || ! str_starts_with($type, 'invoice.')) {
            return response()->json(['status' => 'ignored']);
        }
        if ($type !== 'invoice.paid' && ($event['data']['status'] ?? '') !== 'paid') {
            return response()->json(['status' => 'ignored']);
        }

        // Never trust the webhook body: the invoice is re-read from the API with our key.
        $remote = $this->request('GET', '/invoices/'.rawurlencode($snapexId));
        if ((string) ($remote['invoice_id'] ?? '') !== $snapexId) {
            return response()->json(['error' => 'invoice mismatch'], 400);
        }
        if (($remote['status'] ?? '') !== 'paid') {
            return response()->json(['status' => 'not_paid']);
        }
        if (($remote['environment'] ?? 'live') === 'test' && $this->environment() !== 'test') {
            return response()->json(['status' => 'ignored_test_payment']);
        }

        // Only the API copy of external_id counts; another site on the same SnapEX account is ignored.
        $target = $this->parseExternalId((string) ($remote['external_id'] ?? ''));
        if ($target === null) {
            return response()->json(['status' => 'ignored']);
        }

        $invoice = Invoice::find($target['invoice_id']);
        if (! $invoice) {
            return response()->json(['error' => 'invoice not found'], 404);
        }

        // A repeated webhook must not create or rewrite the payment.
        if ($invoice->transactions()->where('transaction_id', $snapexId)->exists()) {
            return response()->json(['status' => 'success']);
        }

        $amount = $target['amount'] ?? $invoice->remaining;
        if ((float) $amount <= 0) {
            return response()->json(['status' => 'nothing_to_credit']);
        }
        ExtensionHelper::addPayment($invoice->id, 'SnapexPay', $amount, null, $snapexId);

        return response()->json(['status' => 'success']);
    }

    public static function verifyWebhook(string $body, string $secret, array $headers): bool
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = (string) $value;
        }
        $timestamp = $normalized['webhook-timestamp'] ?? '';
        $signature = $normalized['webhook-signature'] ?? '';
        if ($secret === '' || $body === '' || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::WEBHOOK_TOLERANCE) {
            return false;
        }

        return hash_equals('v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret), $signature);
    }

    public function amountUsd(float $total, string $currency): string
    {
        $raw = trim((string) $this->config('usd_rate'));
        $rate = $raw === '' ? 1.0 : (float) str_replace(',', '.', $raw);
        if ($total <= 0 || $rate <= 0) {
            throw new Exception('SnapEX Pay: set usd_rate so the invoice total can be converted to USD');
        }
        unset($currency);
        $usd = round($total * $rate, 2);
        if ($usd <= 0) {
            throw new Exception('SnapEX Pay: invoice total is too small after USD conversion');
        }

        return number_format($usd, 2, '.', '');
    }

    /**
     * paymenter-{site}-{invoice}-{amount}-{attempt}; 1.0.0 used paymenter-{invoice} (credited with the remaining balance).
     *
     * @return array{invoice_id:int, amount:?string}|null
     */
    private function parseExternalId(string $external): ?array
    {
        if (preg_match('/^paymenter-([0-9a-f]{8})-(\d+)-(\d+\.\d{2})-[0-9a-z]+$/', $external, $match)) {
            if (! hash_equals($this->siteTag(), $match[1])) {
                return null;
            }

            return ['invoice_id' => (int) $match[2], 'amount' => $match[3]];
        }
        if (preg_match('/^paymenter-(\d+)$/', $external, $match)) {
            return ['invoice_id' => (int) $match[1], 'amount' => null];
        }

        return null;
    }

    /** Short stable id of this Paymenter install, so two shops on one SnapEX account never credit each other. */
    private function siteTag(): string
    {
        $url = function_exists('config') ? (string) config('app.url') : '';

        return substr(hash('sha256', rtrim(strtolower($url), '/')), 0, 8);
    }

    private function description(Invoice $invoice): string
    {
        $text = 'Paymenter invoice #'.($invoice->number ?? $invoice->id);

        return function_exists('mb_substr') ? mb_substr($text, 0, 255) : substr($text, 0, 255);
    }

    private function feeOn(): string
    {
        return $this->config('fee_on') === 'customer' ? 'customer' : 'merchant';
    }

    private function environment(): string
    {
        return $this->config('environment') === 'test' ? 'test' : 'live';
    }

    private function expiresMinutes(): int
    {
        $minutes = (int) ($this->config('expires_minutes') ?: 60);

        return max(1, min(1440, $minutes));
    }

    private function apiUrl(): string
    {
        $url = trim((string) ($this->config('api_url') ?: ''));

        return rtrim($url !== '' ? $url : self::DEFAULT_API_URL, '/');
    }

    private function request(string $method, string $path, ?array $payload = null, array $extraHeaders = []): array
    {
        $apiKey = trim((string) $this->config('api_key'));
        if ($apiKey === '') {
            throw new Exception('SnapEX Pay API key is missing');
        }
        $headers = array_merge([
            'Authorization' => 'Bearer '.$apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'SnapEX-Pay-Paymenter/'.self::VERSION,
        ], $extraHeaders);
        $json = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $url = $this->apiUrl().$path;

        if (self::$transport !== null) {
            [$status, $raw] = (self::$transport)($method, $url, $headers, $json);
        } else {
            $pending = Http::withHeaders($headers)->timeout(30)->connectTimeout(10);
            $response = $payload === null
                ? $pending->send($method, $url)
                : $pending->withBody($json, 'application/json')->send($method, $url);
            $status = $response->status();
            $raw = $response->body();
        }

        $decoded = json_decode((string) $raw, true);
        if ($status < 200 || $status >= 300 || ! is_array($decoded) || ! isset($decoded['data']) || ! is_array($decoded['data'])) {
            $message = is_string($decoded['error']['message'] ?? null) ? (string) $decoded['error']['message'] : '';
            $details = $decoded['error']['details'] ?? null;
            if (is_array($details)) {
                $first = reset($details);
                $first = is_array($first) ? reset($first) : $first;
                if (is_string($first) && $first !== '') {
                    $message = trim($message.' '.$first);
                }
            }
            throw new Exception('SnapEX Pay: '.($message !== '' ? $message : 'HTTP '.$status));
        }

        return $decoded['data'];
    }
}
