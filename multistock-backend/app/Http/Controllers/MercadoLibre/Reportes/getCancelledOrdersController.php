<?php
namespace App\Http\Controllers\MercadoLibre\Reportes;

use App\Http\Controllers\Controller;
use App\Models\MercadoLibreCredential;
use App\Models\Company;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use Illuminate\Support\Facades\Cache;

class getCancelledOrdersController extends Controller
{
    public function getCancelledOrders(Request $request)
    {
        // Obtén todos los client_id de la tabla companies
        $clientIds = Company::whereNotNull('client_id')->pluck('client_id')->toArray();

        $year = (int) $request->query('year', date('Y'));
        $dateFrom = "{$year}-01-01T00:00:00.000-00:00";
        $dateTo = "{$year}-12-31T23:59:59.999-00:00";

        $allOrders = [];
        $totalCancelled = 0;
        $client = new Client(['timeout' => 20]);
        $promises = [];

        foreach ($clientIds as $clientId) {
            Log::info("Procesando empresa", ['client_id' => $clientId]);

            // Cachear credenciales por 10 minutos
            $cacheKey = 'ml_credentials_' . $clientId;
            $credentials = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($clientId) {
                Log::info("Consultando credenciales Mercado Libre en MySQL para client_id: $clientId");
                return MercadoLibreCredential::where('client_id', $clientId)->first();
            });

            if (!$credentials) {
                Log::warning("No credentials found for client_id: $clientId");
                continue;
            }

            // Refresh token
            if ($credentials->isTokenExpired()) {
                Log::info("Token expirado, refrescando para client_id: $clientId");
                $refreshResponse = Http::asForm()->post('https://api.mercadolibre.com/oauth/token', [
                    'grant_type' => 'refresh_token',
                    'client_id' => env('MELI_CLIENT_ID'),
                    'client_secret' => env('MELI_CLIENT_SECRET'),
                    'refresh_token' => $credentials->refresh_token,
                ]);
                if ($refreshResponse->failed()) {
                    Log::error("Token refresh failed for client_id: $clientId", ['response' => $refreshResponse->json()]);
                    continue;
                }
                $newTokenData = $refreshResponse->json();
                $credentials->access_token = $newTokenData['access_token'];
                $credentials->refresh_token = $newTokenData['refresh_token'] ?? $credentials->refresh_token;
                $credentials->expires_in = $newTokenData['expires_in'];
                $credentials->updated_at = now();
                $credentials->save();
                Log::info("Token refrescado correctamente para client_id: $clientId");
            }

            $userResponse = Http::withToken($credentials->access_token)->get('https://api.mercadolibre.com/users/me');
            if ($userResponse->failed()) {
                Log::error("Failed to get user ID for client_id: $clientId", ['response' => $userResponse->json()]);
                continue;
            }
            $userId = $userResponse->json()['id'];
            Log::info("Obtenido user_id para client_id: $clientId", ['user_id' => $userId]);

            $params = [
                'seller' => $userId,
                'order.status' => 'cancelled',
                'order.date_created.from' => $dateFrom,
                'order.date_created.to' => $dateTo,
                'limit' => 20,
                'offset' => 0
            ];

            $promises[$clientId] = $client->getAsync('https://api.mercadolibre.com/orders/search', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $credentials->access_token,
                ],
                'query' => $params
            ]);
        }

        $results = Promise\Utils::settle($promises)->wait();

        foreach ($results as $clientId => $result) {
            $ordersData = [];
            if ($result['state'] === 'fulfilled' && $result['value']->getStatusCode() === 200) {
                $data = json_decode($result['value']->getBody()->getContents(), true);
                if (isset($data['results']) && is_array($data['results'])) {
                    foreach (array_slice($data['results'], 0, 20) as $order) {
                        if (!isset($order['order_items']) || !is_array($order['order_items'])) continue;
                        if (isset($order['total_amount'])) $totalCancelled += $order['total_amount'];
                        foreach ($order['order_items'] as $item) {
                            $ordersData[] = [
                                'id' => $order['id'],
                                'created_date' => $order['date_created'] ?? null,
                                'total_amount' => $order['total_amount'] ?? null,
                                'status' => $order['status'] ?? null,
                                'product' => [
                                    'title' => $item['item']['title'] ?? null,
                                    'quantity' => $item['quantity'] ?? null,
                                    'price' => $item['unit_price'] ?? null
                                ]
                            ];
                        }
                    }
                }
            }
            $allOrders[$clientId] = $ordersData;
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Órdenes canceladas de todas las compañías obtenidas con éxito.',
            'orders_by_company' => $allOrders,
            'total_cancelled' => $totalCancelled
        ]);
    }
}