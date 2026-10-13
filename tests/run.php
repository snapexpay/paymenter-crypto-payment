<?php

// Standalone checks: php tests/run.php
// Minimal stand-ins for the Paymenter / Laravel classes the extension touches. Signatures follow
// Paymenter v1 (app/Attributes/ExtensionMeta.php, app/Classes/Extension/*, ExtensionHelper::addPayment).

namespace App\Attributes {
    #[\Attribute(\Attribute::TARGET_CLASS)]
    class ExtensionMeta
    {
        public function __construct(
            public string $name,
            public string $description,
            public string $version,
            public string $author,
            public string $url = '',
            public string $icon = '',
            public string $extensionId = 'unknown',
        ) {
        }
    }
}

namespace App\Classes\Extension {
    class Extension
    {
        public function __construct(public $config = [])
        {
        }

        public function config($key)
        {
            return $this->config[$key] ?? null;
        }

        public function getConfig($values = [])
        {
            return [];
        }

        public function boot()
        {
        }
    }

    abstract class Gateway extends Extension
    {
        abstract public function pay(\App\Models\Invoice $invoice, $total);
    }
}

namespace App\Helpers {
    class ExtensionHelper
    {
        /** @var array<string, array> keyed like Paymenter: updateOrCreate on transaction_id */
        public static array $payments = [];

        public static int $calls = 0;

        public static function addPayment($invoice, $gateway, $amount, $fee = null, $transactionId = null, $status = 'succeeded', $isCreditTransaction = false)
        {
            self::$calls++;
            $row = compact('invoice', 'gateway', 'amount', 'fee', 'transactionId');
            if ($transactionId) {
                self::$payments[$transactionId] = $row;
            } else {
                self::$payments[] = $row;
            }

            return (object) $row;
        }
    }
}

namespace App\Models {
    class Invoice
    {
        public static array $rows = [];

        public function __construct(
            public $id,
            public $currency_code = 'USD',
            public $total = 10,
            public $remaining = 10,
            public $number = null,
        ) {
        }

        public static function find($id): ?self
        {
            return self::$rows[$id] ?? null;
        }

        public function transactions()
        {
            $id = $this->id;

            return new class($id)
            {
                private array $where = [];

                public function __construct(private $invoiceId)
                {
                }

                public function where($column, $value)
                {
                    $this->where[$column] = $value;

                    return $this;
                }

                public function exists(): bool
                {
                    foreach (\App\Helpers\ExtensionHelper::$payments as $txId => $row) {
                        if ($row['invoice'] == $this->invoiceId && (! isset($this->where['transaction_id']) || $this->where['transaction_id'] === $txId)) {
                            return true;
                        }
                    }

                    return false;
                }
            };
        }
    }
}

namespace Illuminate\Http {
    class Request
    {
        public function __construct(public string $content = '', public array $headers = [])
        {
        }

        public function getContent(): string
        {
            return $this->content;
        }

        public function header(string $key, $default = '')
        {
            foreach ($this->headers as $name => $value) {
                if (strcasecmp((string) $name, $key) === 0) {
                    return $value;
                }
            }

            return $default;
        }
    }
}

namespace Illuminate\Support\Facades {
    class Http
    {
    }

    class Route
    {
        public static array $routes = [];

        public static function post($uri, $action)
        {
            $route = new class($uri, $action)
            {
                public array $without = [];

                public string $name = '';

                public function __construct(public $uri, public $action)
                {
                }

                public function withoutMiddleware($middleware)
                {
                    $this->without = (array) $middleware;

                    return $this;
                }

                public function name($name)
                {
                    $this->name = $name;

                    return $this;
                }
            };
            self::$routes[] = ['POST', $route];

            return $route;
        }
    }
}

namespace Paymenter\Extensions\Gateways\SnapexPay {
    // Unqualified time() inside the extension namespace resolves here first: lets tests move the clock.
    function time(): int
    {
        return \time() + ($GLOBALS['clock'] ?? 0);
    }
}

namespace {
    use App\Helpers\ExtensionHelper;
    use App\Models\Invoice;
    use Illuminate\Http\Request;
    use Paymenter\Extensions\Gateways\SnapexPay\SnapexPay;

    function response()
    {
        return new class
        {
            public int $status = 200;

            public $data = null;

            public function json($data, $status = 200)
            {
                $this->data = $data;
                $this->status = $status;

                return $this;
            }
        };
    }

    function route($name, $params = null)
    {
        return 'https://billing.example/invoices/'.(is_object($params) ? $params->id : $params);
    }

    function config($key, $default = null)
    {
        return $key === 'app.url' ? ($GLOBALS['app_url'] ?? 'https://billing.example') : $default;
    }

    require dirname(__DIR__).'/SnapexPay.php';

    $failures = 0;
    function check($cond, string $label): void
    {
        global $failures;
        echo ($cond ? '  ok   ' : '  FAIL ').$label."\n";
        if (! $cond) {
            $failures++;
        }
    }

    function now(): int
    {
        return time() + ($GLOBALS['clock'] ?? 0);
    }

    function signed(string $secret, array $payload, ?int $ts = null): array
    {
        $ts = (string) ($ts ?? now());
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        return [$body, [
            'Webhook-Id' => 'evt_'.bin2hex(random_bytes(4)),
            'Webhook-Timestamp' => $ts,
            'Webhook-Signature' => 'v1='.hash_hmac('sha256', $ts.'.'.$body, $secret),
        ]];
    }

    function hook(SnapexPay $gw, string $type, string $id, array $extra = [], string $secret = 'whsec_x')
    {
        [$body, $headers] = signed($secret, ['id' => 'evt', 'type' => $type, 'data' => ['invoice_id' => $id] + $extra]);

        return $gw->webhook(new Request($body, $headers));
    }

    function caught(callable $fn): string
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return '';
    }

    // ------------------------------------------------------------------ mock SnapEX merchant API
    // Mirrors MerchantInvoiceController::store / show: Bearer auth, Idempotency-Key required, replay
    // with a different body is a conflict, external_id unique per merchant, {data:{...}} envelope.
    $GLOBALS['remote'] = [];
    $GLOBALS['keys'] = [];
    $GLOBALS['requests'] = [];
    SnapexPay::$transport = function ($method, $url, $headers, $body) {
        $GLOBALS['requests'][] = compact('method', 'url', 'headers', 'body');
        $err = fn ($status, $code, $message, $details = null) => [$status, json_encode(['error' => array_filter(compact('code', 'message', 'details'))])];
        if (($headers['Authorization'] ?? '') !== 'Bearer sk_test') {
            return $err(401, 'unauthorized', 'A valid merchant API key is required.');
        }
        $base = 'https://snapex.pro/api/v1/merchant/invoices';
        if ($method === 'POST' && $url === $base) {
            $data = json_decode((string) $body, true);
            $key = (string) ($headers['Idempotency-Key'] ?? '');
            if ($key === '' || strlen($key) > 191) {
                return $err(422, 'request_error', 'A valid Idempotency-Key header is required.');
            }
            if (! preg_match('/^[0-9]+(?:\.[0-9]{1,8})?$/', (string) ($data['amount_usd'] ?? ''))) {
                return $err(422, 'validation_error', 'The request is invalid.', ['amount_usd' => ['The amount usd field format is invalid.']]);
            }
            if (bccomp($data['amount_usd'], '0.50', 8) < 0) {
                return $err(409, 'conflict', 'Amount is below the 0.50 USD minimum.');
            }
            if (isset($GLOBALS['keys'][$key])) {
                $existing = $GLOBALS['remote'][$GLOBALS['keys'][$key]];
                if ($existing['amount_usd'] !== $data['amount_usd'] || $existing['external_id'] !== ($data['external_id'] ?? null)) {
                    return $err(409, 'conflict', 'Idempotency-Key was already used with a different request.');
                }

                return [200, json_encode(['data' => $existing])];
            }
            foreach ($GLOBALS['remote'] as $row) {
                if (($data['external_id'] ?? null) !== null && $row['external_id'] === $data['external_id']) {
                    return $err(409, 'conflict', 'external_id has already been used by this merchant.');
                }
            }
            $id = 'INV-'.str_pad((string) (count($GLOBALS['remote']) + 1), 8, '0', STR_PAD_LEFT);
            $GLOBALS['remote'][$id] = [
                'invoice_id' => $id,
                'environment' => $data['environment'] ?? 'live',
                'amount_usd' => $data['amount_usd'],
                'fee_on' => $data['fee_on'] ?? 'merchant',
                'status' => 'open',
                'pay_url' => 'https://snapex.pro/pay/'.bin2hex(random_bytes(4)),
                'external_id' => $data['external_id'] ?? null,
            ];
            $GLOBALS['keys'][$key] = $id;

            return [201, json_encode(['data' => $GLOBALS['remote'][$id]])];
        }
        if ($method === 'GET' && str_starts_with($url, $base.'/')) {
            $id = rawurldecode(substr($url, strlen($base) + 1));

            return isset($GLOBALS['remote'][$id])
                ? [200, json_encode(['data' => $GLOBALS['remote'][$id]])]
                : $err(404, 'not_found', 'The requested resource was not found.');
        }

        return $err(404, 'not_found', 'The requested resource was not found.');
    };

    $config = [
        'api_key' => 'sk_test',
        'webhook_secret' => 'whsec_x',
        'fee_on' => 'merchant',
        'expires_minutes' => '45',
        'usd_rate' => '1',
        'environment' => 'live',
    ];
    $gw = new SnapexPay($config);

    // ------------------------------------------------------------------ extension contract
    echo "contract\n";
    $ref = new ReflectionClass(SnapexPay::class);
    check($ref->getNamespaceName() === 'Paymenter\Extensions\Gateways\SnapexPay', 'namespace Paymenter\Extensions\Gateways\SnapexPay');
    check(basename(dirname(__DIR__)) === 'SnapexPay' || basename($ref->getFileName()) === 'SnapexPay.php', 'class file is SnapexPay.php (folder must be extensions/Gateways/SnapexPay)');
    check($ref->isSubclassOf(\App\Classes\Extension\Gateway::class) && ! $ref->isAbstract(), 'extends App\Classes\Extension\Gateway');
    $attrs = $ref->getAttributes(\App\Attributes\ExtensionMeta::class);
    $meta = $attrs ? $attrs[0]->newInstance() : null; // throws on a wrong attribute signature
    check($meta && $meta->name === 'SnapEX Pay' && $meta->version === SnapexPay::VERSION && $meta->author !== '', '#[ExtensionMeta] matches Paymenter signature');
    check(str_starts_with((string) $meta?->icon, 'data:image/svg+xml;base64,') && base64_decode(substr($meta->icon, 26), true) !== false, 'icon is a valid data URI');
    $pay = $ref->getMethod('pay');
    check((string) $pay->getParameters()[0]->getType() === 'App\Models\Invoice' && $pay->getNumberOfParameters() === 2, 'pay(Invoice $invoice, $total)');
    $fields = $gw->getConfig();
    $types = ['select', 'tags', 'text', 'time', 'textarea', 'markdown', 'password', 'email', 'number', 'color', 'file', 'checkbox', 'placeholder'];
    $names = array_column($fields, 'name');
    check($names === ['api_key', 'webhook_secret', 'fee_on', 'expires_minutes', 'usd_rate', 'environment', 'partner_id', 'api_url'], 'getConfig field names');
    check(array_reduce($fields, fn ($ok, $f) => $ok && isset($f['name'], $f['label']) && in_array($f['type'], $types, true), true), 'every field has name/label and a Paymenter input type');
    $byName = array_column($fields, null, 'name');
    check($byName['api_key']['type'] === 'password' && $byName['webhook_secret']['type'] === 'password', 'secrets use password inputs');
    check(array_keys($byName['fee_on']['options']) === ['merchant', 'customer'] && array_keys($byName['environment']['options']) === ['live', 'test'], 'select options are value => label');

    \Illuminate\Support\Facades\Route::$routes = [];
    $gw->boot();
    $route = \Illuminate\Support\Facades\Route::$routes[0][1] ?? null;
    check($route && $route->uri === '/extensions/snapexpay/webhook' && $route->action === [SnapexPay::class, 'webhook'], 'boot() registers POST /extensions/snapexpay/webhook');
    check($route && $route->name === 'extensions.gateways.snapexpay.webhook', 'route name');
    check($route && in_array('Illuminate\Foundation\Http\Middleware\PreventRequestForgery', $route->without, true), 'webhook route skips CSRF');
    check($gw->canUseGateway(10, 'USD', 'invoice') === true && $gw->canUseGateway(0, 'USD', 'invoice') === false, 'canUseGateway');

    // ------------------------------------------------------------------ signature
    echo "signature\n";
    [$body, $h] = signed('whsec_x', ['type' => 'invoice.paid', 'data' => ['invoice_id' => 'INV-1']]);
    check(SnapexPay::verifyWebhook($body, 'whsec_x', $h), 'valid signature');
    check(SnapexPay::verifyWebhook($body, 'whsec_x', array_change_key_case($h, CASE_LOWER)), 'header names are case-insensitive');
    check(! SnapexPay::verifyWebhook($body, 'whsec_y', $h), 'wrong secret rejected');
    check(! SnapexPay::verifyWebhook($body.' ', 'whsec_x', $h), 'tampered body rejected');
    check(! SnapexPay::verifyWebhook($body, 'whsec_x', ['Webhook-Timestamp' => $h['Webhook-Timestamp']] + ['Webhook-Signature' => substr($h['Webhook-Signature'], 3)]), 'signature without v1= rejected');
    check(! SnapexPay::verifyWebhook($body, 'whsec_x', ['Webhook-Signature' => $h['Webhook-Signature']]), 'missing timestamp rejected');
    check(! SnapexPay::verifyWebhook($body, '', $h), 'empty secret rejected');
    [$oldBody, $old] = signed('whsec_x', ['type' => 'invoice.paid'], now() - 301);
    check(! SnapexPay::verifyWebhook($oldBody, 'whsec_x', $old), 'replay older than 5 minutes rejected');
    [$futBody, $fut] = signed('whsec_x', ['type' => 'invoice.paid'], now() + 301);
    check(! SnapexPay::verifyWebhook($futBody, 'whsec_x', $fut), 'timestamp too far in the future rejected');
    $badTs = ['Webhook-Timestamp' => '1e9', 'Webhook-Signature' => 'v1='.hash_hmac('sha256', '1e9.'.$body, 'whsec_x')];
    check(! SnapexPay::verifyWebhook($body, 'whsec_x', $badTs), 'non-numeric timestamp rejected');
    $res = $gw->webhook(new Request($body, ['Webhook-Timestamp' => $h['Webhook-Timestamp'], 'Webhook-Signature' => 'v1=nope']));
    check($res->status === 400 && $GLOBALS['requests'] === [], 'bad signature: 400 and no API call');

    // ------------------------------------------------------------------ pay(): request shape
    echo "pay\n";
    check($gw->amountUsd(12, 'USD') === '12.00', 'USD amount as decimal string');
    $invoice = new Invoice(44, 'USD', 12, 12, 'INV-2026-0044');
    Invoice::$rows[44] = $invoice;
    $url = $gw->pay($invoice, 12);
    $req = $GLOBALS['requests'][0];
    $sent = json_decode($req['body'], true);
    check($url === $GLOBALS['remote']['INV-00000001']['pay_url'], 'pay() returns the SnapEX pay_url');
    check($req['method'] === 'POST' && $req['url'] === 'https://snapex.pro/api/v1/merchant/invoices', 'POST {base}/invoices');
    check($req['headers']['Authorization'] === 'Bearer sk_test' && $req['headers']['Content-Type'] === 'application/json' && $req['headers']['Accept'] === 'application/json', 'auth and JSON headers');
    check(is_string($sent['amount_usd']) && $sent['amount_usd'] === '12.00' && $sent['fee_on'] === 'merchant' && $sent['environment'] === 'live', 'amount_usd / fee_on / environment');
    check($sent['expires_in'] === 2700, 'expires_in in seconds (45 min)');
    check(! array_key_exists('partner_ref', $sent), 'no Partner ID → no partner_ref');
    check((bool) preg_match('/^paymenter-[0-9a-f]{8}-44-12\.00-[0-9a-z]+$/', $sent['external_id']), 'external_id = paymenter-{site}-{invoice}-{amount}-{attempt}');
    check($req['headers']['Idempotency-Key'] === $sent['external_id'], 'Idempotency-Key sent');
    check($sent['success_url'] === 'https://billing.example/invoices/44?checkPayment=true' && $sent['cancel_url'] === 'https://billing.example/invoices/44', 'success/cancel URLs');
    check($sent['description'] === 'Paymenter invoice #INV-2026-0044', 'description uses the invoice number');
    check(array_diff(array_keys($sent), ['amount_usd', 'fee_on', 'environment', 'external_id', 'description', 'success_url', 'cancel_url', 'expires_in']) === [], 'only fields the API validates');

    $again = $gw->pay($invoice, 12);
    check($again === $url && count($GLOBALS['remote']) === 1, 'double click in the same minute reuses the SnapEX invoice');
    $GLOBALS['clock'] = 120;
    $later = $gw->pay($invoice, 12);
    check($later !== $url && count($GLOBALS['remote']) === 2, 'later retry (e.g. after expiry) creates a new invoice instead of an external_id conflict');
    $GLOBALS['clock'] = 0;

    $eur = new SnapexPay(['usd_rate' => '1,08'] + $config);
    check($eur->amountUsd(10, 'EUR') === '10.80', 'usd_rate conversion accepts a comma');
    check(str_contains(caught(fn () => (new SnapexPay(['usd_rate' => '0'] + $config))->amountUsd(10, 'EUR')), 'usd_rate'), 'zero rate refused');
    check(str_contains(caught(fn () => $gw->pay(new Invoice(50, 'USD', 0.3, 0.3), 0.3)), '0.50 USD minimum'), 'API minimum error is surfaced');
    check(str_contains(caught(fn () => (new SnapexPay(['api_key' => 'sk_wrong'] + $config))->pay($invoice, 12)), 'merchant API key'), 'auth error is surfaced');
    check(str_contains(caught(fn () => (new SnapexPay(['api_key' => ''] + $config))->pay($invoice, 12)), 'API key is missing'), 'missing key fails before any request');

    // ------------------------------------------------------------------ webhook
    $refInvoice = new Invoice(45, 'USD', 12, 12, 'INV-2026-0045');
    Invoice::$rows[45] = $refInvoice;
    (new SnapexPay(['partner_id' => ' int-42 '] + $config))->pay($refInvoice, 12);
    check((json_decode(end($GLOBALS['requests'])['body'], true)['partner_ref'] ?? null) === 'int-42', 'Partner ID → partner_ref');

    echo "webhook\n";
    $sx = 'INV-00000001';
    $res = hook($gw, 'invoice.paid', $sx, ['status' => 'paid']);
    check($res->status === 200 && $res->data['status'] === 'not_paid' && ExtensionHelper::$calls === 0, 'body says paid but API says open: nothing credited');

    $GLOBALS['remote'][$sx]['status'] = 'paid';
    $res = hook($gw, 'invoice.paid', $sx);
    check($res->status === 200 && ExtensionHelper::$calls === 1, 'paid invoice credited once');
    $p = ExtensionHelper::$payments[$sx] ?? [];
    check(($p['invoice'] ?? null) === 44 && ($p['gateway'] ?? null) === 'SnapexPay' && ($p['transactionId'] ?? null) === $sx, 'addPayment(invoice, gateway, ..., transactionId = SnapEX id)');
    check(($p['amount'] ?? null) === '12.00', 'credited amount is the billed amount in invoice currency');

    $invoice->remaining = 0; // Paymenter recomputes the balance after the payment
    $dup = hook($gw, 'invoice.paid', $sx);
    $dup2 = hook($gw, 'invoice.extra_payment', $sx, ['status' => 'paid']);
    check($dup->status === 200 && $dup2->status === 200 && ExtensionHelper::$calls === 1, 'repeated webhooks do not add or rewrite the payment');
    check((ExtensionHelper::$payments[$sx]['amount'] ?? null) === '12.00', 'stored amount unchanged after duplicates');

    $sx2 = 'INV-00000002';
    $GLOBALS['remote'][$sx2]['status'] = 'partially_paid';
    $res = hook($gw, 'invoice.partially_paid', $sx2, ['status' => 'partially_paid']);
    check($res->data['status'] === 'ignored' && ExtensionHelper::$calls === 1, 'partially_paid is not credited');
    check(hook($gw, 'payment.credited', $sx2)->data['status'] === 'ignored', 'non-invoice events ignored');
    check(hook($gw, 'invoice.paid', $sx2, [], 'whsec_wrong')->status === 400, 'webhook with a wrong secret: 400');

    // Another Paymenter on the same SnapEX account: its webhook must not credit our invoice 44.
    $GLOBALS['app_url'] = 'https://other-shop.example';
    $other = new SnapexPay($config);
    $Invoice77 = new Invoice(77, 'USD', 20, 20);
    $other->pay($Invoice77, 20);
    $otherId = array_key_last($GLOBALS['remote']);
    unset($GLOBALS['app_url']);
    $GLOBALS['remote'][$otherId]['status'] = 'paid';
    Invoice::$rows[77] = new Invoice(77, 'USD', 20, 20);
    $res = hook($gw, 'invoice.paid', $otherId);
    check($res->status === 200 && $res->data['status'] === 'ignored' && ExtensionHelper::$calls === 1, 'invoice from another site is ignored');

    // Test-mode invoice while the gateway is live.
    $test = new SnapexPay(['environment' => 'test'] + $config);
    Invoice::$rows[45] = new Invoice(45, 'EUR', 9.5, 9.5);
    $test->pay(Invoice::$rows[45], 9.5);
    $testId = array_key_last($GLOBALS['remote']);
    check($GLOBALS['remote'][$testId]['environment'] === 'test', 'test environment creates test invoices');
    $GLOBALS['remote'][$testId]['status'] = 'paid';
    check(hook($gw, 'invoice.paid', $testId)->data['status'] === 'ignored_test_payment' && ExtensionHelper::$calls === 1, 'test payment ignored while live');
    check(hook($test, 'invoice.paid', $testId)->status === 200 && (ExtensionHelper::$payments[$testId]['amount'] ?? null) === '9.50', 'test payment credited in test mode');

    // Invoices created by 1.0.0 (external_id paymenter-{id}) still settle, with the remaining balance.
    $GLOBALS['remote']['INV-LEGACY'] = ['invoice_id' => 'INV-LEGACY', 'environment' => 'live', 'amount_usd' => '5.00', 'status' => 'paid', 'pay_url' => 'x', 'external_id' => 'paymenter-46'];
    Invoice::$rows[46] = new Invoice(46, 'USD', 5, 5);
    hook($gw, 'invoice.paid', 'INV-LEGACY');
    check((ExtensionHelper::$payments['INV-LEGACY']['amount'] ?? null) === 5, 'legacy external_id credited with the remaining balance');

    // Webhook body lies about the invoice: the API is authoritative.
    $GLOBALS['remote']['INV-X'] = ['invoice_id' => 'INV-X', 'environment' => 'live', 'status' => 'paid', 'external_id' => 'someone-else-1'];
    check(hook($gw, 'invoice.paid', 'INV-X', ['external_id' => 'paymenter-44'])->data['status'] === 'ignored', 'external_id is read from the API, not the body');
    check(str_contains(caught(fn () => hook($gw, 'invoice.paid', 'INV-MISSING')), 'not found'), 'unknown SnapEX invoice throws (SnapEX retries)');
    Invoice::$rows = [];
    $GLOBALS['remote'][$sx2]['status'] = 'paid';
    check(hook($gw, 'invoice.paid', $sx2)->status === 404, 'missing Paymenter invoice: 404 (SnapEX retries)');

    echo $failures === 0 ? "all ok\n" : "$failures failed\n";
    exit($failures === 0 ? 0 : 1);
}
