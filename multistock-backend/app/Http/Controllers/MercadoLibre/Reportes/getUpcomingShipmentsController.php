<?php

namespace App\Http\Controllers\MercadoLibre\Reportes;

use App\Models\MercadoLibreCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class getUpcomingShipmentsController
{
    public function getUpcomingShipments(Request $request, $clientId)
    {
        // Cachear credenciales por 10 minutos
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

        $userResponse = Http::withToken($credentials->access_token)
            ->get('https://api.mercadolibre.com/users/me');

        if ($userResponse->failed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo obtener el ID del usuario.',
                'error' => $userResponse->json(),
            ], 500);
        }

        $userId = $userResponse->json()['id'];

        $dateFrom = Carbon::now()->format('Y-m-d\T00:00:00.000-00:00');
        $dateTo = Carbon::now()->addDays(2)->format('Y-m-d\T23:59:59.999-00:00');

        $response = Http::withToken($credentials->access_token)
            ->get("https://api.mercadolibre.com/orders/search", [
                'seller' => $userId,
                'order.status' => 'paid',
                'order.date_created.from' => $dateFrom,
                'order.date_created.to' => $dateTo,
                'limit' => 50
            ]);

        if ($response->failed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al conectar con la API de MercadoLibre.',
                'error' => $response->json(),
            ], $response->status());
        }

        $orders = $response->json()['results'];
        $upcomingOrders = [];

        foreach ($orders as $order) {
            $shippingId = $order['shipping']['id'] ?? null;

            if ($shippingId) {
                // 1. Obtener lead_time (fecha estimada)
                $leadTimeResponse = Http::withToken($credentials->access_token)
                    ->get("https://api.mercadolibre.com/shipments/{$shippingId}/lead_time");

                // 2. Obtener información completa del envío (incluye dirección)
                $shipmentInfoResponse = Http::withToken($credentials->access_token)
                    ->get("https://api.mercadolibre.com/shipments/{$shippingId}");

                $leadTimeData = $leadTimeResponse->successful() ? $leadTimeResponse->json() : [];
                $shipmentInfo = $shipmentInfoResponse->successful() ? $shipmentInfoResponse->json() : [];

                $dateReadyToShip = $leadTimeData['estimated_handling_limit']['date'] ?? null;
                $receiver = $shipmentInfo['receiver_address'] ?? [];

                if ($dateReadyToShip) {
                    $fechaEnvio = Carbon::parse($dateReadyToShip);

                    foreach ($order['order_items'] as $item) {
                        // Unir todos los atributos de variación
                        $tamanio = collect($item['item']['variation_attributes'] ?? [])
                            ->pluck('value_name')
                            ->implode(' / ');

                        // Construir dirección completa combinando campos
                        $direccionCompleta = implode(', ', array_filter([
                            $receiver['address_line'] ?? null,
                            $receiver['comment'] ?? null,
                            $receiver['city']['name'] ?? null,
                            $receiver['state']['name'] ?? null,
                            $receiver['zip_code'] ?? null
                        ]));

                        $upcomingOrders[] = [
                            'order_id' => $order['id'],
                            'shipping_id' => $shippingId,
                            'fecha_envio_programada' => $fechaEnvio->toDateTimeString(),
                            'shipment_status' => null,

                            // Datos adicionales
                            'id_producto' => $item['item']['id'] ?? null,
                            'nombre_producto' => $item['item']['title'] ?? null,
                            'tamaño' => $tamanio,
                            'cantidad' => $item['quantity'] ?? null,
                            'sku' => $item['item']['seller_sku'] ?? null,

                            'receptor' => [
                                'id_receiver' => $order['buyer']['id'] ?? null,
                                'name_receiver' => $order['buyer']['nickname'] ?? null,
                                'direction' => $direccionCompleta ?: null,
                            ],

                            'date_created' => $order['date_created'] ?? null,
                            'substatus' => $order['substatus'] ?? null,
                        ];
                    }
                }
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Órdenes con fecha de envío obtenidas con éxito.',
            'data' => $upcomingOrders,
        ]);
    }
}
