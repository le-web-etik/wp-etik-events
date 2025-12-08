<?php

add_action('rest_api_init', function() {
    register_rest_route('lwe/v1', '/stripe-webhook', [
        'methods' => 'POST',
        'callback' => 'lwe_stripe_webhook_handler',
        'permission_callback' => '__return_true'
    ]);
});

function lwe_stripe_webhook_handler(\WP_REST_Request $request) {
    $payload = $request->get_body();
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $keys = lwe_get_stripe_keys();
    $endpoint_secret = $keys['webhook'];

    try {
        $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
    } catch (\Exception $e) {
        return new WP_REST_Response('Invalid webhook', 400);
    }

    global $wpdb;
    $ins_table = $wpdb->prefix . 'etik_inscriptions';

    if ($event->type === 'checkout.session.completed') {
        $session = $event->data->object;
        $ins_id = intval($session->metadata->inscription_id ?? 0);
        if ($ins_id) {
            $wpdb->update($ins_table, [
                'status' => 'confirmed',
                'payment_session_id' => $session->id
            ], ['id' => $ins_id], ['%s','%s'], ['%d']);
        }
    } elseif ($event->type === 'checkout.session.expired' || $event->type === 'checkout.session.async_payment_failed') {
        $session = $event->data->object;
        $ins_id = intval($session->metadata->inscription_id ?? 0);
        if ($ins_id) {
            $wpdb->update($ins_table, ['status'=>'cancelled'], ['id'=>$ins_id], ['%s'], ['%d']);
        }
    }

    return new WP_REST_Response('ok', 200);
}
