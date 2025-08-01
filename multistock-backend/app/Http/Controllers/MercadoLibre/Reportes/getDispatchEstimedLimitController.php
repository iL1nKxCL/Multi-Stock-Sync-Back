<?php

namespace App\Http\Controllers\MercadoLibre\Reportes;

use App\Models\MercadoLibreCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\Company;

class getDispatchEstimedLimitController
{
    public function getDispatchEstimedLimit($companyId)
    {
        set_time_limit(300);

        // 1. Buscar la compañía y obtener el client_id
        $company = Company::find($companyId);
        if (!$company || !$company->client_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se encontró la compañía o no tiene client_id asociado.',
            ], 404);
        }
        $clientId = $company->client_id;

        // 2. Cachear credenciales por 10 minutos
        $cacheKey = 'ml_credentials_' . $clientId;
        $credentials = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($clientId) {
            Log::info("Consultando credenciales Mercado Libre en MySQL para client_id: $clientId");
            return MercadoLibreCredential::where('client_id', $clientId)->first();
        });

        if (!$credentials) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se encontraron credenciales válidas para el client_id proporcionado.',
            ], 404);
        }

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

        // Obtener user ID
        $userResponse = Http::withToken($credentials->access_token)
            ->get('https://api.mercadolibre.com/users/me');

        if ($userResponse->failed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo obtener el ID del usuario. Valide su token.',
                'error' => $userResponse->json(),
            ], 500);
        }

        $userId = $userResponse->json()['id'];
        $to = Carbon::now()->toIso8601String();
        $from = Carbon::now()->subDays(6)->toIso8601String();
        $offset = 0;
        $limit = 50;
        $processedShipments = [];
        $shippingDetails = [];

        do {
            $response = Http::withToken($credentials->access_token)
                ->get("https://api.mercadolibre.com/orders/search", [
                    'seller' => $userId,
                    'order.status' => 'paid',
                    'order.date_created.from' => $from,
                    'order.date_created.to' => $to,
                    'sort' => 'date_desc',
                    'limit' => $limit,
                    'offset' => $offset,
                ]);

            if ($response->failed()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Error al conectar con la API de MercadoLibre.',
                    'error' => $response->json(),
                ], $response->status());
            }

            $orders = $response->json()['results'] ?? [];

            foreach ($orders as $order) {
                $shippingId = $order['shipping']['id'] ?? null;

                if (!$shippingId || isset($processedShipments[$shippingId])) continue;

                $processedShipments[$shippingId] = true;

                $shipmentResponse = Http::withToken($credentials->access_token)
                    ->get("https://api.mercadolibre.com/shipments/{$shippingId}");

                if ($shipmentResponse->successful()) {
                    $shipmentData = $shipmentResponse->json();

                    $handlingLimitRaw = $shipmentData['shipping_option']['estimated_handling_limit']['date'] ?? null;

                    if ($handlingLimitRaw && $shipmentData['status_history']['date_shipped'] === null) {
                        $handlingDate = Carbon::parse($handlingLimitRaw)->toDateString();
                        $today = Carbon::now()->toDateString();

                        if ($handlingDate === $today) {

                            $shippingDetails[] = [
                                'id' => $shipmentData['id'],
                                'estimated_handling_limit' => $handlingLimitRaw,
                                'shipping_date' => $shipmentData['status_history']['date_shipped'] ?? 'Aun no despachado',
                                'direction' =>
                                        ($shipmentData['receiver_address']['state']['name'] ?? '') . ' - ' .
                                        ($shipmentData['receiver_address']['city']['name'] ?? '') . ' - ' .
                                        ($shipmentData['receiver_address']['address_line'] ?? ''),

                                'receiver_name' => $shipmentData['receiver_address']['receiver_name'],
                                'order_id' => $order['id'],
                                'product' => $shipmentData['shipping_items'][0]['description'],
                                'quantity' => $shipmentData['shipping_items'][0]['quantity'],

                            ];
                        }
                    }
                }
            }

            $offset += $limit;
        } while (count($orders) === $limit);

        if (empty($shippingDetails)) {
            return response()->json([
                'status' => 'success',
                'message' => 'No se encontraron envíos con fecha límite de despacho para hoy.',
                'data'=>[]
            ], 200);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Productos con fecha límite de despacho para hoy.',
            'total_envios' => count($shippingDetails),
            'data' => $shippingDetails,
        ]);
    }
}
