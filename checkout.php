<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$config_file = __DIR__ . '/config.php';
if (file_exists($config_file)) {
    require_once $config_file;
}
$STRIPE_SK = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : getenv('STRIPE_SECRET_KEY');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['amount'])) {
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$amount = intval($input['amount']);
$description = $input['description'] ?? 'Avatar Video';
$metadata = $input['metadata'] ?? [];

$domain = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$base_path = dirname($_SERVER['REQUEST_URI']);
if ($base_path === '/') $base_path = '';

$checkout_data = [
    'payment_method_types' => ['card'],
    'mode' => 'payment',
    'success_url' => $domain . $base_path . '/success.html?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url' => $domain . $base_path . '/#order',
    'line_items' => [[
        'price_data' => [
            'currency' => 'gbp',
            'unit_amount' => $amount,
            'product_data' => [
                'name' => $description,
                'description' => 'Waves Avatar Influencers - AI Video Production'
            ]
        ],
        'quantity' => 1
    ]],
    'customer_email' => $metadata['customer_email'] ?? null
];

$flat_meta = [];
foreach ($metadata as $k => $v) {
    if (is_string($v) && strlen($v) <= 500) {
        $flat_meta[$k] = $v;
    } elseif (is_string($v)) {
        $flat_meta[$k] = substr($v, 0, 497) . '...';
    }
}
if (!empty($flat_meta)) {
    $checkout_data['metadata'] = $flat_meta;
    $checkout_data['payment_intent_data'] = ['metadata' => $flat_meta];
}

$post_fields = http_build_query_nested($checkout_data);

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
curl_setopt($ch, CURLOPT_USERPWD, $STRIPE_SK . ':');
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($response, true);

if ($http_code === 200 && isset($result['url'])) {
    // Save order to file for tracking
    $order = [
        'timestamp' => date('Y-m-d H:i:s'),
        'session_id' => $result['id'],
        'amount' => $amount,
        'description' => $description,
        'metadata' => $metadata,
        'status' => 'pending'
    ];
    $orders_file = __DIR__ . '/orders.json';
    $orders = file_exists($orders_file) ? json_decode(file_get_contents($orders_file), true) : [];
    if (!is_array($orders)) $orders = [];
    $orders[] = $order;
    file_put_contents($orders_file, json_encode($orders, JSON_PRETTY_PRINT));

    echo json_encode(['url' => $result['url']]);
} else {
    echo json_encode(['error' => 'Failed to create checkout session', 'details' => $result['error']['message'] ?? 'Unknown error']);
}

function http_build_query_nested($data, $prefix = '') {
    $result = [];
    foreach ($data as $key => $value) {
        $full_key = $prefix ? $prefix . '[' . $key . ']' : $key;
        if (is_array($value)) {
            $result[] = http_build_query_nested($value, $full_key);
        } elseif ($value !== null) {
            $result[] = urlencode($full_key) . '=' . urlencode($value);
        }
    }
    return implode('&', $result);
}
