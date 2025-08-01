<?php

namespace App\Http\Controllers\MercadoLibre\Reportes;

use App\Models\MercadoLibreCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class getRefundsByCategoryController
{
    public function getRefundsByCategory($clientId)
    {
        // Cachear credenciales por 10 minutos
        $cacheKey = 'ml_credentials_' . $clientId;
        $credentials = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($clientId) {
            Log::info("Consultando credenciales Mercado Libre en MySQL para client_id: $clientId");
            return MercadoLibreCredential::where('client_id', $clientId)->first();
        });

        // Check if credentials exist
        if (!$credentials) {
            return response()->json([
                'status' => 'error',
                'message' => 'No valid credentials found for the provided client_id.',
            ], 404);
        }

        // Check if token is expired
        if ($credentials->isTokenExpired()) {
        $refreshResponse = Http::asForm()->post('https://api.mercadolibre.com/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $credentials->client_id,
            'client_secret' => $credentials->client_secret,
            'refresh_token' => $credentials->refresh_token,
        ]);

        if ($refreshResponse->failed()) {
            return response()->json(['error' => 'No se pudo refrescar el token'], 401);
        }

        $data = $refreshResponse->json();
        $credentials->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_at' => now()->addSeconds($data['expires_in']),
        ]);
        }

        // Get user id from token
        $response = Http::withToken($credentials->access_token)
            ->get('https://api.mercadolibre.com/users/me');

        if ($response->failed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Could not get user ID. Please validate your token.',
                'error' => $response->json(),
            ], 500);
        }

        $userId = $response->json()['id'];

        // Get query parameters for date range and category
        $dateFrom = request()->query('date_from', date('Y-m-01')); // Default to first day of current month
        $dateTo = request()->query('date_to', date('Y-m-t')); // Default to last day of current month
        $category = request()->query('category', ''); // Default to empty (no category filter)

        // API request to get refunds or returns by category
        $response = Http::withToken($credentials->access_token)
            ->get("https://api.mercadolibre.com/orders/search", [
                'seller' => $userId,
                'order.status' => 'cancelled',
                'order.date_created.from' => "{$dateFrom}T00:00:00.000-00:00",
                'order.date_created.to' => "{$dateTo}T23:59:59.999-00:00",
                'category' => $category,
            ]);

        // Validate response
        if ($response->failed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error connecting to MercadoLibre API.',
                'error' => $response->json(),
            ], $response->status());
        }

        // Process refunds data
        $orders = $response->json()['results'];
        $refundsByCategory = [];

        foreach ($orders as $order) {
            // Get shipping details for each order
            $shippingResponse = Http::withToken($credentials->access_token)
                ->get("https://api.mercadolibre.com/shipments/{$order['shipping']['id']}");
            
            $shippingDetails = $shippingResponse->successful() ? $shippingResponse->json() : null;

            // Get buyer details
            $buyerResponse = Http::withToken($credentials->access_token)
                ->get("https://api.mercadolibre.com/users/{$order['buyer']['id']}");
            
            $buyerDetails = $buyerResponse->successful() ? $buyerResponse->json() : null;

            // Get billing information for the order
            $billingInfoResponse = Http::withToken($credentials->access_token)
                ->withHeaders(['x-version' => '2'])
                ->get("https://api.mercadolibre.com/orders/{$order['id']}/billing_info");

            $billingInfo = $billingInfoResponse->successful() ? $billingInfoResponse->json() : null;

            foreach ($order['order_items'] as $item) {
                $categoryId = $item['item']['category_id'];
                if (!isset($refundsByCategory[$categoryId])) {
                    $refundsByCategory[$categoryId] = [
                        'category_id' => $categoryId,
                        'total_refunds' => 0,
                        'orders' => []
                    ];
                }
                $refundsByCategory[$categoryId]['total_refunds'] += $order['total_amount'];

                // Prepare shipping address information
                $shippingAddress = $shippingDetails ? [
                    'address' => $shippingDetails['receiver_address']['street_name'] ?? '',
                    'number' => $shippingDetails['receiver_address']['street_number'] ?? '',
                    'city' => $shippingDetails['receiver_address']['city']['name'] ?? '',
                    'state' => $shippingDetails['receiver_address']['state']['name'] ?? '',
                    'country' => $shippingDetails['receiver_address']['country']['name'] ?? '',
                    'comments' => $shippingDetails['receiver_address']['comment'] ?? '',
                ] : null;

                $refundsByCategory[$categoryId]['orders'][] = [
                    'id' => $order['id'],
                    'created_date' => $order['date_created'],
                    'total_amount' => $order['total_amount'],
                    'status' => $order['status'],
                    'product' => [
                        'title' => $item['item']['title'],
                        'quantity' => $item['quantity'],
                        'price' => $item['unit_price'],
                    ]
                ];
            }
        }

        // Return refunds by category data
        return response()->json([
            'status' => 'success',
            'message' => 'Refunds by category retrieved successfully.',
            'data' => $refundsByCategory,
        ]);
    }
}