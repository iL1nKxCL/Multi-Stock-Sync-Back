<?php

namespace App\Http\Controllers\Woocommerce;

use App\Models\WooStore;
use Automattic\WooCommerce\Client;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;

class WooProductController extends Controller
{
    public function __construct()
    {
        set_time_limit(300);
        ini_set('max_execution_time', 300);
        $this->middleware('auth:sanctum');
    }

    private function connect($storeId)
    {
        $store = WooStore::findOrFail($storeId);

        if (!$store->active) {
            throw new \Exception('Tienda desactivada');
        }

        return new Client(
            $store->store_url,
            $store->consumer_key,
            $store->consumer_secret,
            [
                'version' => 'wc/v3',
                'verify_ssl' => false
            ]
        );
    }

    public function createProduct(Request $request, $storeId)
    {
        try {
            $woocommerce = $this->connect($storeId);

            // Validación de datos de entrada
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'type' => 'sometimes|in:simple,grouped,external,variable',
                'regular_price' => 'sometimes|numeric|min:0',
                'sale_price' => 'sometimes|numeric|min:0',
                'description' => 'sometimes|string',
                'short_description' => 'sometimes|string',
                'sku' => 'sometimes|string|max:100',
                'manage_stock' => 'sometimes|boolean',
                'stock_quantity' => 'sometimes|integer|min:0',
                'stock_status' => 'sometimes|in:instock,outofstock,onbackorder',
                'weight' => 'sometimes|numeric|min:0',
                'dimensions.length' => 'sometimes|numeric|min:0',
                'dimensions.width' => 'sometimes|numeric|min:0',
                'dimensions.height' => 'sometimes|numeric|min:0',
                'categories' => 'sometimes|array',
                'categories.*.id' => 'required_with:categories|integer',
                'tags' => 'sometimes|array',
                'tags.*.id' => 'required_with:tags|integer',
                'images' => 'sometimes|array',
                'images.*.src' => 'required_with:images|url',
                'attributes' => 'sometimes|array',
                'status' => 'sometimes|in:draft,pending,private,publish',
                'featured' => 'sometimes|boolean',
                'catalog_visibility' => 'sometimes|in:visible,catalog,search,hidden',
                'virtual' => 'sometimes|boolean',
                'downloadable' => 'sometimes|boolean',
                'external_url' => 'sometimes|url',
                'button_text' => 'sometimes|string|max:255',
                'tax_status' => 'sometimes|in:taxable,shipping,none',
                'tax_class' => 'sometimes|string',
                'reviews_allowed' => 'sometimes|boolean',
                'purchase_note' => 'sometimes|string',
                'menu_order' => 'sometimes|integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Error de validación.',
                    'errors' => $validator->errors(),
                    'status' => 'error'
                ], 422);
            }

            $data = $validator->validated();

            // Campos por defecto para un nuevo producto
            $productData = array_merge([
                'type' => 'simple',
                'status' => 'publish',
                'featured' => false,
                'catalog_visibility' => 'visible',
                'manage_stock' => false,
                'stock_status' => 'instock',
                'virtual' => false,
                'downloadable' => false,
                'reviews_allowed' => true,
                'tax_status' => 'taxable',
            ], $data);

            // Validaciones adicionales basadas en el tipo de producto
            $validationErrors = $this->validateProductType($productData);
            if (!empty($validationErrors)) {
                return response()->json([
                    'message' => 'Error de validación específica del tipo de producto.',
                    'errors' => $validationErrors,
                    'status' => 'error'
                ], 422);
            }

            // Crear el producto en WooCommerce
            $created = $woocommerce->post("products", $productData);

            // Respuesta filtrada con información relevante
            $filtered = $this->filterProductResponse($created, $woocommerce);

            return response()->json([
                'message' => 'Producto creado correctamente.',
                'created_product' => $filtered,
                'status' => 'success'
            ], 201);
        } catch (\Automattic\WooCommerce\HttpClient\HttpClientException $e) {
            return response()->json([
                'message' => 'Error de WooCommerce API.',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al crear el producto.',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    public function updateProduct(Request $request, $storeId, $productId)
    {
        try {
            $woocommerce = $this->connect($storeId);

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'regular_price' => 'sometimes|numeric|min:0',
                'sale_price' => 'sometimes|numeric|min:0',
                'description' => 'sometimes|string',
                'short_description' => 'sometimes|string',
                'sku' => 'sometimes|string|max:100',
                'manage_stock' => 'sometimes|boolean',
                'stock_quantity' => 'sometimes|integer|min:0',
                'stock_status' => 'sometimes|in:instock,outofstock,onbackorder',
                'weight' => 'sometimes|numeric|min:0',
                'dimensions.length' => 'sometimes|numeric|min:0',
                'dimensions.width' => 'sometimes|numeric|min:0',
                'dimensions.height' => 'sometimes|numeric|min:0',
                'categories' => 'sometimes|array',
                'categories.*.id' => 'required_with:categories|integer',
                'tags' => 'sometimes|array',
                'tags.*.id' => 'required_with:tags|integer',
                'images' => 'sometimes|array',
                'images.*.src' => 'required_with:images|url',
                'status' => 'sometimes|in:draft,pending,private,publish',
                'featured' => 'sometimes|boolean',
                'catalog_visibility' => 'sometimes|in:visible,catalog,search,hidden',
                'virtual' => 'sometimes|boolean',
                'downloadable' => 'sometimes|boolean',
                'external_url' => 'sometimes|url',
                'button_text' => 'sometimes|string|max:255',
                'tax_status' => 'sometimes|in:taxable,shipping,none',
                'reviews_allowed' => 'sometimes|boolean',
                'purchase_note' => 'sometimes|string',
                'menu_order' => 'sometimes|integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Error de validación.',
                    'errors' => $validator->errors(),
                    'status' => 'error'
                ], 422);
            }

            $data = $validator->validated();

            if (empty($data)) {
                return response()->json([
                    'message' => 'Debes enviar al menos un campo a modificar.',
                    'status' => 'error'
                ], 422);
            }

            $updated = $woocommerce->put("products/{$productId}", $data);

            $filtered = $this->filterProductResponse($updated, $woocommerce);

            return response()->json([
                'message' => 'Producto actualizado correctamente.',
                'updated_product' => $filtered,
                'status' => 'success'
            ]);
        } catch (\Automattic\WooCommerce\HttpClient\HttpClientException $e) {
            return response()->json([
                'message' => 'Error de WooCommerce API.',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al actualizar el producto.',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    public function getProduct($storeId, $productId)
    {
        try {
            $woocommerce = $this->connect($storeId);

            $product = $woocommerce->get("products/{$productId}");

            return response()->json([
                'product' => $this->filterProductResponse($product, $woocommerce),
                'status' => 'success'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener el producto.',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    public function listProducts($storeId, Request $request)
    {
        try {
            $woocommerce = $this->connect($storeId);

            // Parámetros de consulta
            $params = [
                'per_page' => $request->get('per_page', 10),
                'page' => $request->get('page', 1),
                'search' => $request->get('search'),
                'status' => $request->get('status'),
                'featured' => $request->get('featured'),
                'category' => $request->get('category'),
                'tag' => $request->get('tag'),
                'sku' => $request->get('sku'),
                'orderby' => $request->get('orderby', 'date'),
                'order' => $request->get('order', 'desc'),
            ];

            // Filtrar parámetros vacíos
            $params = array_filter($params, function ($value) {
                return $value !== null && $value !== '';
            });

            $products = $woocommerce->get('products', $params);


            if (!is_array($products)) {
                $products = [$products];
            }

            $filtered = array_map(function ($product) use ($woocommerce) {
                return $this->filterProductResponse($product, $woocommerce);
            }, $products);

            return response()->json([
                'products' => $filtered,
                'status' => 'success'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al listar productos.',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    public function deleteProduct($storeId, $productId)
    {
        try {
            $woocommerce = $this->connect($storeId);

            $deleted = $woocommerce->delete("products/{$productId}", ['force' => true]);

            return response()->json([
                'message' => 'Producto eliminado correctamente.',
                'deleted_product' => $this->filterProductResponse($deleted, $woocommerce),
                'status' => 'success'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al eliminar el producto.',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }
    public function listVariations($storeId, $productId)
    {
        try {
            $woocommerce = $this->connect($storeId);

            $variations = $woocommerce->get("products/{$productId}/variations");

            return response()->json([
                'variations' => $variations,
                'status' => 'success'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener las variaciones.',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    public function createVariation(Request $request, $storeId, $productId)
    {
        try {
            $woocommerce = $this->connect($storeId);

            $validator = Validator::make($request->all(), [
                'regular_price' => 'required|numeric|min:0',
                'sale_price' => 'sometimes|numeric|min:0',
                'attributes' => 'required|array',
                'attributes.*.name' => 'required|string',
                'attributes.*.option' => 'required|string',
                'sku' => 'sometimes|string|max:100',
                'stock_quantity' => 'sometimes|integer|min:0',
                'weight' => 'sometimes|numeric|min:0',
                'dimensions.length' => 'sometimes|numeric|min:0',
                'dimensions.width' => 'sometimes|numeric|min:0',
                'dimensions.height' => 'sometimes|numeric|min:0',
            ]);

            if ($validator->fails()) {
                Log::warning('Validación fallida al crear variación', [
                    'errors' => $validator->errors(),
                    'data_enviada' => $request->all(),
                    'storeId' => $storeId,
                    'productId' => $productId
                ]);
                return response()->json([
                    'message' => 'Error de validación.',
                    'errors' => $validator->errors(),
                    'status' => 'error'
                ], 422);
            }

            $data = $validator->validated();

            $variation = $woocommerce->post("products/{$productId}/variations", $data);

            return response()->json([
                'message' => 'Variación creada correctamente.',
                'variation' => $variation,
                'status' => 'success'
            ]);
        } catch (\Automattic\WooCommerce\HttpClient\HttpClientException $e) {
            Log::error('Error al crear la variación', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'response_body' => method_exists($e, 'getResponse') ? $e->getResponse() : null,
                'woo_body' => method_exists($e, 'getResponse') ? $e->getResponse() : null,
                'data_enviada' => $request->all(),
                'storeId' => $storeId,
                'productId' => $productId,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Error al crear la variación.',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        } catch (\Exception $e) {
            Log::error('Error general al crear variación', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'data_enviada' => $request->all(),
                'storeId' => $storeId,
                'productId' => $productId,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Error al crear la variación.',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    public function deleteVariation($storeId, $productId, $variationId)
    {
        try {
            $woocommerce = $this->connect($storeId);

            $deleted = $woocommerce->delete("products/{$productId}/variations/{$variationId}", ['force' => true]);

            return response()->json([
                'message' => 'Variación eliminada correctamente.',
                'deleted_variation' => $deleted,
                'status' => 'success'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al eliminar la variación.',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    /**
     * Validaciones específicas según el tipo de producto
     */
    private function validateProductType($productData)
    {
        $errors = [];

        switch ($productData['type']) {
            case 'external':
                if (empty($productData['external_url'])) {
                    $errors['external_url'] = ['La URL externa es requerida para productos externos.'];
                }
                break;

            case 'variable':
                if (empty($productData['attributes'])) {
                    $errors['attributes'] = ['Los atributos son requeridos para productos variables.'];
                }
                break;
        }

        // Validar que sale_price no sea mayor que regular_price
        if (!empty($productData['sale_price']) && !empty($productData['regular_price'])) {
            if ($productData['sale_price'] > $productData['regular_price']) {
                $errors['sale_price'] = ['El precio de oferta no puede ser mayor al precio regular.'];
            }
        }

        return $errors;
    }

    /**
     * Filtrar la respuesta del producto para mostrar solo campos relevantes
     */
    /**
     * Mapear atributos del producto WooCommerce a campos de stock_warehouses
     */
    private function mapWooCommerceAttributesToStockWarehouse($wooProduct, $requestData)
    {
        $mappedData = [];
        
        // Mapear atributos específicos del producto WooCommerce
        if (isset($wooProduct->attributes) && is_array($wooProduct->attributes)) {
            foreach ($wooProduct->attributes as $attribute) {
                $attributeName = strtolower($attribute->name ?? '');
                $attributeValue = $attribute->options[0] ?? null;
                
                // Mapear atributos conocidos
                switch ($attributeName) {
                    case 'condition':
                    case 'condicion':
                    case 'estado':
                        if (!isset($requestData['condicion'])) {
                            $mappedData['condicion'] = $this->mapCondition($attributeValue);
                        }
                        break;
                        
                    case 'currency':
                    case 'moneda':
                        if (!isset($requestData['currency_id'])) {
                            $mappedData['currency_id'] = $this->mapCurrency($attributeValue);
                        }
                        break;
                        
                    case 'listing_type':
                    case 'tipo_listado':
                        if (!isset($requestData['listing_type_id'])) {
                            $mappedData['listing_type_id'] = $this->mapListingType($attributeValue);
                        }
                        break;
                        
                    case 'category':
                    case 'categoria':
                        if (!isset($requestData['category_id'])) {
                            $mappedData['category_id'] = $attributeValue;
                        }
                        break;
                        
                    case 'sale_terms':
                    case 'terminos_venta':
                        if (!isset($requestData['sale_terms'])) {
                            $mappedData['sale_terms'] = json_encode([$attribute]);
                        }
                        break;
                        
                    case 'shipping':
                    case 'envio':
                        if (!isset($requestData['shipping'])) {
                            $mappedData['shipping'] = json_encode([$attribute]);
                        }
                        break;
                }
            }
        }
        
        // Mapear campos directos del producto WooCommerce
        if (!isset($requestData['description']) && !empty($wooProduct->description)) {
            $mappedData['description'] = $wooProduct->description;
        }
        
        // Mapear imagen del producto WooCommerce - campo pictures es longtext
        if (!isset($requestData['pictures']) && !empty($wooProduct->images)) {
            $mappedData['pictures'] = $wooProduct->images[0]->src ?? null;
        }
        
        // Mapear categorías si no se especificó category_id
        if (!isset($requestData['category_id']) && !empty($wooProduct->categories)) {
            $mappedData['category_id'] = $wooProduct->categories[0]->id ?? null;
        }
        
        return $mappedData;
    }
    
    /**
     * Mapear condición del producto
     */
    private function mapCondition($condition)
    {
        $condition = strtolower($condition ?? '');
        
        switch ($condition) {
            case 'new':
            case 'nuevo':
            case 'new_item':
                return 'new';
            case 'used':
            case 'usado':
            case 'second_hand':
                return 'used';
            default:
                return 'new';
        }
    }
    
    /**
     * Mapear moneda
     */
    private function mapCurrency($currency)
    {
        $currency = strtoupper($currency ?? '');
        
        switch ($currency) {
            case 'CLP':
            case 'PESO':
            case 'PESOS':
                return 'CLP';
            case 'USD':
            case 'DOLAR':
            case 'DOLARES':
                return 'USD';
            default:
                return 'CLP';
        }
    }
    
    /**
     * Mapear tipo de listado
     */
    private function mapListingType($listingType)
    {
        $listingType = strtolower($listingType ?? '');
        
        switch ($listingType) {
            case 'gold_special':
            case 'gold special':
            case 'gold':
                return 'gold_special';
            case 'gold_pro':
            case 'gold pro':
                return 'gold_pro';
            case 'gold':
                return 'gold';
            default:
                return 'gold_special';
        }
    }

    private function filterProductResponse($product, $woocommerce)
    {
        if ($product->type === 'variable') {
            $minPrice = null;
            foreach ($product->variations as $variationId) {
                $variation = $woocommerce->get("products/{$product->id}/variations/{$variationId}");
                $variationPrice = floatval($variation->sale_price ?: $variation->regular_price);
                if ($variationPrice > 0 && ($minPrice === null || $variationPrice < $minPrice)) {
                    $minPrice = $variationPrice;
                }
            }
            $product->price = $minPrice !== null ? (string)$minPrice : "";
        }

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'type' => $product->type,
            'status' => $product->status,
            'featured' => $product->featured,
            'catalog_visibility' => $product->catalog_visibility,
            'description' => $product->description,
            'short_description' => $product->short_description,
            'sku' => $product->sku,
            'price' => $product->price,
            'regular_price' => $product->regular_price,
            'sale_price' => $product->sale_price,
            'price_html' => $product->price_html,
            'on_sale' => $product->on_sale,
            'purchasable' => $product->purchasable,
            'total_sales' => $product->total_sales,
            'virtual' => $product->virtual,
            'downloadable' => $product->downloadable,
            'external_url' => $product->external_url,
            'button_text' => $product->button_text,
            'tax_status' => $product->tax_status,
            'tax_class' => $product->tax_class,
            'manage_stock' => $product->manage_stock,
            'stock_quantity' => $product->stock_quantity,
            'stock_status' => $product->stock_status,
            'backorders' => $product->backorders,
            'backorders_allowed' => $product->backorders_allowed,
            'backordered' => $product->backordered,
            'weight' => $product->weight,
            'dimensions' => $product->dimensions,
            'shipping_required' => $product->shipping_required,
            'shipping_taxable' => $product->shipping_taxable,
            'shipping_class' => $product->shipping_class,
            'shipping_class_id' => $product->shipping_class_id,
            'reviews_allowed' => $product->reviews_allowed,
            'average_rating' => $product->average_rating,
            'rating_count' => $product->rating_count,
            'related_ids' => $product->related_ids,
            'upsell_ids' => $product->upsell_ids,
            'cross_sell_ids' => $product->cross_sell_ids,
            'parent_id' => $product->parent_id,
            'purchase_note' => $product->purchase_note,
            'categories' => $product->categories,
            'tags' => $product->tags,
            'images' => $product->images,
            'attributes' => $product->attributes,
            'variations' => $product->variations,
            'grouped_products' => $product->grouped_products,
            'menu_order' => $product->menu_order,
            'permalink' => $product->permalink,
            'date_created' => $product->date_created,
            'date_modified' => $product->date_modified,
        ];
    }

    /**
     * Crear un producto variable con sus variaciones
     */
    public function createVariableProduct(Request $request, $storeId)
    {
        Log::info('Iniciando creación de producto variable', [
            'storeId' => $storeId,
            'request_data' => $request->all(),
            'user_id' => optional(Auth::user())->id
        ]);

        try {
            $woocommerce = $this->connect($storeId);
            Log::info('Conexión a WooCommerce establecida correctamente', ['storeId' => $storeId]);

            // Validación de datos de entrada para producto variable
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'description' => 'sometimes|string',
                'short_description' => 'sometimes|string',
                'sku' => 'sometimes|string|max:100',
                'status' => 'sometimes|in:draft,pending,private,publish',
                'featured' => 'sometimes|boolean',
                'catalog_visibility' => 'sometimes|in:visible,catalog,search,hidden',
                'categories' => 'sometimes|array',
                'categories.*.id' => 'required_with:categories|integer',
                'tags' => 'sometimes|array',
                'tags.*.id' => 'required_with:tags|integer',
                'images' => 'sometimes|array',
                'images.*.src' => 'required_with:images|url',
                'attributes' => 'required|array',
                'attributes.*.name' => 'required|string',
                'attributes.*.position' => 'sometimes|integer',
                'attributes.*.visible' => 'sometimes|boolean',
                'attributes.*.variation' => 'sometimes|boolean',
                'attributes.*.options' => 'required|array',
                'attributes.*.options.*' => 'required|string',
                'variations' => 'required|array',
                'variations.*.regular_price' => 'required|numeric|min:0',
                'variations.*.sale_price' => 'sometimes|numeric|min:0',
                'variations.*.attributes' => 'required|array',
                'variations.*.attributes.*.name' => 'required|string',
                'variations.*.attributes.*.option' => 'required|string',
                'variations.*.sku' => 'sometimes|string|max:100',
                'variations.*.stock_quantity' => 'sometimes|integer|min:0',
                'variations.*.manage_stock' => 'sometimes|boolean',
                'variations.*.stock_status' => 'sometimes|in:instock,outofstock,onbackorder',
                'variations.*.weight' => 'sometimes|numeric|min:0',
                'variations.*.dimensions.length' => 'sometimes|numeric|min:0',
                'variations.*.dimensions.width' => 'sometimes|numeric|min:0',
                'variations.*.dimensions.height' => 'sometimes|numeric|min:0',
                'variations.*.images' => 'sometimes|array',
                'variations.*.images.*.src' => 'required_with:variations.*.images|url',
            ]);

            if ($validator->fails()) {
                Log::warning('Validación fallida al crear producto variable', [
                    'errors' => $validator->errors(),
                    'request_data' => $request->all(),
                    'storeId' => $storeId
                ]);
                return response()->json([
                    'message' => 'Error de validación.',
                    'errors' => $validator->errors(),
                    'status' => 'error'
                ], 422);
            }

            $data = $validator->validated();
            $variations = $data['variations'];
            unset($data['variations']); // Remover variaciones del array principal

            // Campos por defecto para un producto variable
            $productData = array_merge([
                'type' => 'variable',
                'status' => 'publish',
                'featured' => false,
                'catalog_visibility' => 'visible',
                'manage_stock' => false,
                'stock_status' => 'instock',
                'virtual' => false,
                'downloadable' => false,
                'reviews_allowed' => true,
                'tax_status' => 'taxable',
            ], $data);

            // Configurar atributos para variaciones
            foreach ($productData['attributes'] as &$attribute) {
                $attribute['variation'] = true; // Habilitar variaciones para todos los atributos
                $attribute['visible'] = true;
            }

            Log::info('Enviando datos a WooCommerce para crear producto variable', [
                'productData' => $productData,
                'variations_count' => count($variations),
                'storeId' => $storeId
            ]);

            // Crear el producto variable en WooCommerce
            $created = $woocommerce->post("products", $productData);

            Log::info('Producto variable creado exitosamente', [
                'product_id' => $created->id,
                'storeId' => $storeId
            ]);

            // Crear las variaciones
            $createdVariations = [];
            foreach ($variations as $variationData) {
                try {
                    Log::info('Creando variación', [
                        'product_id' => $created->id,
                        'variation_data' => $variationData,
                        'storeId' => $storeId
                    ]);

                    $variation = $woocommerce->post("products/{$created->id}/variations", $variationData);
                    $createdVariations[] = $variation;

                    Log::info('Variación creada exitosamente', [
                        'variation_id' => $variation->id,
                        'product_id' => $created->id,
                        'storeId' => $storeId
                    ]);
                } catch (\Exception $e) {
                    Log::error('Error al crear variación', [
                        'message' => $e->getMessage(),
                        'variation_data' => $variationData,
                        'product_id' => $created->id,
                        'storeId' => $storeId
                    ]);
                    // Continuar con las siguientes variaciones
                }
            }

            // Obtener el producto completo con todas las variaciones
            $completeProduct = $woocommerce->get("products/{$created->id}");
            $filtered = $this->filterProductResponse($completeProduct, $woocommerce);

            Log::info('Producto variable completado', [
                'product_id' => $created->id,
                'variations_created' => count($createdVariations),
                'storeId' => $storeId
            ]);

            return response()->json([
                'message' => 'Producto variable creado correctamente.',
                'created_product' => $filtered,
                'variations_created' => count($createdVariations),
                'status' => 'success'
            ], 201);
        } catch (\Automattic\WooCommerce\HttpClient\HttpClientException $e) {
            Log::error('Error de WooCommerce API al crear producto variable', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'storeId' => $storeId,
                'request_data' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Error de WooCommerce API.',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 400);
        } catch (\Exception $e) {
            Log::error('Error general al crear producto variable', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'storeId' => $storeId,
                'request_data' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Error al crear el producto variable.',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    /**
     * Crear registro de StockWarehouse de manera segura
     */
    private function createStockWarehouseSafely($data)
    {
        try {
            // Verificar si ya existe el producto en la bodega
            $existing = \App\Models\StockWarehouse::where('id_mlc', $data['id_mlc'])
                ->where('warehouse_id', $data['warehouse_id'])
                ->first();

            if ($existing) {
                Log::warning('Producto ya existe en la bodega', [
                    'id_mlc' => $data['id_mlc'],
                    'warehouse_id' => $data['warehouse_id'],
                    'existing_id' => $existing->id
                ]);
                return $existing;
            }

            // Validar datos antes de crear
            $validData = array_intersect_key($data, array_flip([
                'id_mlc', 'warehouse_id', 'title', 'price', 'available_quantity',
                'pictures', 'category_id', 'attribute', 'condicion', 'currency_id',
                'listing_type_id', 'sale_terms', 'shipping', 'description'
            ]));

            // Asegurar que los campos numéricos sean válidos
            if (isset($validData['price'])) {
                $validData['price'] = floatval($validData['price']);
            }
            if (isset($validData['available_quantity'])) {
                $validData['available_quantity'] = intval($validData['available_quantity']);
            }

            // Limpiar campos de texto
            if (isset($validData['title'])) {
                $validData['title'] = substr(trim($validData['title']), 0, 255);
            }
            if (isset($validData['description'])) {
                $validData['description'] = substr(trim($validData['description']), 0, 3500);
            }

            Log::info('Creando StockWarehouse con datos validados', [
                'validData' => $validData
            ]);

            return \App\Models\StockWarehouse::create($validData);

        } catch (\Exception $e) {
            Log::error('Error al crear StockWarehouse', [
                'data' => $data,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Asignar un producto de WooCommerce a una bodega
     */
    public function assignProductToWarehouse(Request $request, $storeId, $productId)
    {
        Log::info('Iniciando asignación de producto a bodega', [
            'storeId' => $storeId,
            'productId' => $productId,
            'request_data' => $request->all(),
            'user_id' => optional(Auth::user())->id
        ]);

        try {
            $woocommerce = $this->connect($storeId);

            // Validar que venga warehouse_id en el body
            $validator = Validator::make($request->all(), [
                'warehouse_id' => 'required|integer|exists:warehouses,id',
            ]);

            if ($validator->fails()) {
                Log::warning('Validación fallida al asignar producto a bodega', [
                    'errors' => $validator->errors(),
                    'request_data' => $request->all(),
                    'storeId' => $storeId,
                    'productId' => $productId
                ]);
                return response()->json([
                    'message' => 'Error de validación.',
                    'errors' => $validator->errors(),
                    'status' => 'error'
                ], 422);
            }

            $data = $validator->validated();

            // Obtener el producto de WooCommerce
            $wooProduct = $woocommerce->get("products/{$productId}");

            if (!$wooProduct) {
                return response()->json([
                    'message' => 'Producto de WooCommerce no encontrado.',
                    'status' => 'error'
                ], 404);
            }

            // Tomar datos del producto WooCommerce
            $price = $wooProduct->price ?? $wooProduct->regular_price ?? 0;
            $available_quantity = $wooProduct->stock_quantity ?? 0;
            $title = $wooProduct->name ?? '';
            $description = $wooProduct->description ?? '';
            
            // Extraer imágenes
            $pictures = null;
            if (!empty($wooProduct->images)) {
                $pictures = json_encode($wooProduct->images);
            }
            
            // Extraer categorías
            $category_id = null;
            if (!empty($wooProduct->categories)) {
                $category_id = $wooProduct->categories[0]->id ?? null;
            }
            
            // Extraer atributos
            $attribute = null;
            if (!empty($wooProduct->attributes)) {
                $attribute = json_encode($wooProduct->attributes);
            }
            
            // Configurar valores por defecto
            $condicion = 'new'; // Por defecto nuevo
            $currency_id = 'CLP'; // Por defecto pesos chilenos
            $listing_type_id = 'gold_special'; // Por defecto

            // Crear registro en stock_warehouses usando el método seguro
            $stockWarehouse = $this->createStockWarehouseSafely([
                'id_mlc' => $wooProduct->id,
                'warehouse_id' => $data['warehouse_id'],
                'title' => $title,
                'price' => $price,
                'available_quantity' => $available_quantity,
                'pictures' => $pictures,
                'category_id' => $category_id,
                'attribute' => $attribute,
                'condicion' => $condicion,
                'currency_id' => $currency_id,
                'listing_type_id' => $listing_type_id,
                'description' => $description,
            ]);

            Log::info('Producto asignado exitosamente a bodega', [
                'stock_warehouse_id' => $stockWarehouse->id,
                'warehouse_id' => $data['warehouse_id'],
                'woo_product_id' => $productId,
                'storeId' => $storeId
            ]);

            return response()->json([
                'message' => 'Producto asignado correctamente a la bodega.',
                'stock_warehouse' => $stockWarehouse,
                'woo_product' => $this->filterProductResponse($wooProduct, $woocommerce),
                'status' => 'success'
            ], 201);
        } catch (\Automattic\WooCommerce\HttpClient\HttpClientException $e) {
            Log::error('Error de WooCommerce API al asignar producto a bodega', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'storeId' => $storeId,
                'productId' => $productId,
                'request_data' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Error de WooCommerce API.',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 400);
        } catch (\Exception $e) {
            Log::error('Error general al asignar producto a bodega', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'storeId' => $storeId,
                'productId' => $productId,
                'request_data' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Error al asignar el producto a la bodega.',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    /**
     * Crear producto en WooCommerce y asignarlo a una bodega en una sola operación
     */
    public function createProductAndAssignToWarehouse(Request $request, $storeId)
    {
        Log::info('Iniciando creación de producto y asignación a bodega', [
            'storeId' => $storeId,
            'request_data' => $request->all(),
            'user_id' => optional(Auth::user())->id
        ]);

        try {
            $woocommerce = $this->connect($storeId);

            // Validación de datos de entrada
            $validator = Validator::make($request->all(), [
                // Datos del producto WooCommerce
                'name' => 'required|string|max:255',
                'type' => 'sometimes|in:simple,grouped,external,variable',
                'regular_price' => 'sometimes|numeric|min:0',
                'sale_price' => 'sometimes|numeric|min:0',
                'description' => 'sometimes|string',
                'short_description' => 'sometimes|string',
                'sku' => 'sometimes|string|max:100',
                'manage_stock' => 'sometimes|boolean',
                'stock_quantity' => 'sometimes|integer|min:0',
                'stock_status' => 'sometimes|in:instock,outofstock,onbackorder',
                'weight' => 'sometimes|numeric|min:0',
                'dimensions.length' => 'sometimes|numeric|min:0',
                'dimensions.width' => 'sometimes|numeric|min:0',
                'dimensions.height' => 'sometimes|numeric|min:0',
                'categories' => 'sometimes|array',
                'categories.*.id' => 'required_with:categories|integer',
                'tags' => 'sometimes|array',
                'tags.*.id' => 'required_with:tags|integer',
                'images' => 'sometimes|array',
                'images.*.src' => 'required_with:images|url',
                'attributes' => 'sometimes|array',
                'status' => 'sometimes|in:draft,pending,private,publish',
                'featured' => 'sometimes|boolean',
                'catalog_visibility' => 'sometimes|in:visible,catalog,search,hidden',
                'virtual' => 'sometimes|boolean',
                'downloadable' => 'sometimes|boolean',
                'external_url' => 'sometimes|url',
                'button_text' => 'sometimes|string|max:255',
                'tax_status' => 'sometimes|in:taxable,shipping,none',
                'tax_class' => 'sometimes|string',
                'reviews_allowed' => 'sometimes|boolean',
                'purchase_note' => 'sometimes|string',
                'menu_order' => 'sometimes|integer',

                // Datos de asignación a bodega
                'warehouse_id' => 'required|integer|exists:warehouses,id',
                'warehouse_quantity' => 'required|integer|min:0',
                'warehouse_price' => 'required|numeric|min:0',
                'condicion' => 'required|string|max:255',
                'currency_id' => 'required|string|max:255',
                'listing_type_id' => 'required|string|max:255',
                'category_id' => 'nullable|string|max:255',
                'warehouse_attribute' => 'nullable|array',
                'warehouse_pictures' => 'nullable|array',
                'warehouse_sale_terms' => 'nullable|array',
                'warehouse_shipping' => 'nullable|array',
                'warehouse_description' => 'nullable|string'
            ]);

            if ($validator->fails()) {
                Log::warning('Validación fallida al crear producto y asignar a bodega', [
                    'errors' => $validator->errors(),
                    'request_data' => $request->all(),
                    'storeId' => $storeId
                ]);
                return response()->json([
                    'message' => 'Error de validación.',
                    'errors' => $validator->errors(),
                    'status' => 'error'
                ], 422);
            }

            $data = $validator->validated();

            // Separar datos del producto WooCommerce
            $wooProductData = array_intersect_key($data, array_flip([
                'name',
                'type',
                'regular_price',
                'sale_price',
                'description',
                'short_description',
                'sku',
                'manage_stock',
                'stock_quantity',
                'stock_status',
                'weight',
                'dimensions',
                'categories',
                'tags',
                'images',
                'attributes',
                'status',
                'featured',
                'catalog_visibility',
                'virtual',
                'downloadable',
                'external_url',
                'button_text',
                'tax_status',
                'tax_class',
                'reviews_allowed',
                'purchase_note',
                'menu_order'
            ]));

            // Campos por defecto para un nuevo producto
            $productData = array_merge([
                'type' => 'simple',
                'status' => 'publish',
                'featured' => false,
                'catalog_visibility' => 'visible',
                'manage_stock' => false,
                'stock_status' => 'instock',
                'virtual' => false,
                'downloadable' => false,
                'reviews_allowed' => true,
                'tax_status' => 'taxable',
            ], $wooProductData);

            // Crear el producto en WooCommerce
            Log::info('Creando producto en WooCommerce', [
                'productData' => $productData,
                'storeId' => $storeId
            ]);

            $created = $woocommerce->post("products", $productData);

            Log::info('Producto creado exitosamente en WooCommerce', [
                'created_product' => $created,
                'storeId' => $storeId
            ]);

            // Crear registro en stock_warehouses usando el método seguro
            $pictures = null;
            if (!empty($created->images)) {
                $pictures = json_encode($created->images);
            }
            
            $category_id = null;
            if (!empty($created->categories)) {
                $category_id = $created->categories[0]->id ?? null;
            }
            
            $attribute = null;
            if (!empty($created->attributes)) {
                $attribute = json_encode($created->attributes);
            }
            
            $stockWarehouse = $this->createStockWarehouseSafely([
                'id_mlc' => $created->id, // Usar el ID de WooCommerce como id_mlc
                'warehouse_id' => $data['warehouse_id'],
                'title' => $created->name,
                'price' => $data['warehouse_price'],
                'available_quantity' => $data['warehouse_quantity'],
                'pictures' => $pictures,
                'category_id' => $category_id,
                'attribute' => $attribute,
                'condicion' => $data['condicion'] ?? 'new',
                'currency_id' => $data['currency_id'] ?? 'CLP',
                'listing_type_id' => $data['listing_type_id'] ?? 'gold_special',
                'description' => $data['warehouse_description'] ?? $created->description ?? '',
            ]);

            Log::info('Producto asignado exitosamente a bodega', [
                'stock_warehouse_id' => $stockWarehouse->id,
                'warehouse_id' => $data['warehouse_id'],
                'woo_product_id' => $created->id,
                'storeId' => $storeId
            ]);

            // Respuesta filtrada con información relevante
            $filtered = $this->filterProductResponse($created, $woocommerce);

            return response()->json([
                'message' => 'Producto creado y asignado correctamente a la bodega.',
                'created_product' => $filtered,
                'stock_warehouse' => $stockWarehouse,
                'status' => 'success'
            ], 201);
        } catch (\Automattic\WooCommerce\HttpClient\HttpClientException $e) {
            Log::error('Error de WooCommerce API al crear producto y asignar a bodega', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'storeId' => $storeId,
                'request_data' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Error de WooCommerce API.',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 400);
        } catch (\Exception $e) {
            Log::error('Error general al crear producto y asignar a bodega', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'storeId' => $storeId,
                'request_data' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Error al crear el producto y asignarlo a la bodega.',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    /**
     * Obtener productos de WooCommerce asignados a una bodega específica
     */
    public function getProductsByWarehouse($storeId, $warehouseId)
    {
        Log::info('Obteniendo productos de WooCommerce por bodega', [
            'storeId' => $storeId,
            'warehouseId' => $warehouseId,
            'user_id' => optional(Auth::user())->id
        ]);

        try {
            // Verificar que la bodega existe
            $warehouse = \App\Models\Warehouse::findOrFail($warehouseId);

            // Obtener productos de la bodega
            $stockWarehouses = \App\Models\StockWarehouse::where('warehouse_id', $warehouseId)->get();

            $woocommerce = $this->connect($storeId);
            $products = [];
            $errores = [];
            $erroresCount = 0;
            foreach ($stockWarehouses as $stockWarehouse) {
                try {
                    // Obtener producto de WooCommerce usando el id_mlc
                    $wooProduct = $woocommerce->get("products/{$stockWarehouse->id_mlc}");
                    $filteredProduct = $this->filterProductResponse($wooProduct, $woocommerce);

                    // Combinar datos del producto WooCommerce con datos de stock
                    $products[] = array_merge($filteredProduct, [
                        'stock_warehouse_id' => $stockWarehouse->id,
                        'warehouse_quantity' => $stockWarehouse->warehouse_stock ?? $stockWarehouse->available_quantity ?? null,
                        'warehouse_price' => $stockWarehouse->price_clp ?? $stockWarehouse->price ?? null,
                        'warehouse_condicion' => $stockWarehouse->condicion ?? null,
                        'warehouse_currency_id' => $stockWarehouse->currency_id ?? null,
                        'warehouse_listing_type_id' => $stockWarehouse->listing_type_id ?? null,
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Error al obtener producto de WooCommerce', [
                        'id_mlc' => $stockWarehouse->id_mlc,
                        'error' => $e->getMessage()
                    ]);
                    $errores[] = [
                        'id_mlc' => $stockWarehouse->id_mlc,
                        'error' => $e->getMessage()
                    ];
                    $erroresCount++;
                    // Continuar con el siguiente producto
                }
            }

            Log::info('Productos obtenidos exitosamente', [
                'warehouseId' => $warehouseId,
                'products_count' => count($products),
                'errores_count' => $erroresCount,
                'storeId' => $storeId
            ]);

            return response()->json([
                'warehouse' => $warehouse,
                'products' => $products,
                'errores' => $errores,
                'total_ok' => count($products),
                'total_errores' => $erroresCount,
                'total_count' => count($products) + $erroresCount,
                'status' => 'success',
                'mensaje' => "{$erroresCount} productos no se pudieron obtener de WooCommerce, " . count($products) . " productos obtenidos correctamente."
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error('Bodega no encontrada', [
                'warehouseId' => $warehouseId,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'message' => 'Bodega no encontrada.',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error al obtener productos por bodega', [
                'message' => $e->getMessage(),
                'warehouseId' => $warehouseId,
                'storeId' => $storeId,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Error al obtener productos por bodega.',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    /**
     * Descargar un Excel con los IDs de productos WooCommerce que no están en ninguna bodega
     */
    public function exportProductsNotInWarehouse($storeId)
    {
        $woocommerce = $this->connect($storeId);
        // Obtener todos los productos de WooCommerce
        $products = $woocommerce->get('products', ['per_page' => 100]);
        if (!is_array($products)) {
            $products = [$products];
        }
        // Obtener IDs de productos ya asignados a bodegas
        $idsEnBodega = \App\Models\StockWarehouse::pluck('id_mlc')->toArray();
        // Filtrar productos que no están en ninguna bodega
        $productosNoAsignados = array_filter($products, function($p) use ($idsEnBodega) {
            return !in_array($p->id, $idsEnBodega);
        });
        // Crear Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'product_id');
        $sheet->setCellValue('B1', 'name');
        $row = 2;
        foreach ($productosNoAsignados as $p) {
            $sheet->setCellValue('A'.$row, $p->id);
            $sheet->setCellValue('B'.$row, $p->name);
            $row++;
        }
        $writer = new Xlsx($spreadsheet);
        $response = new StreamedResponse(function() use ($writer) {
            $writer->save('php://output');
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment;filename="productos_no_asignados.xlsx"');
        $response->headers->set('Cache-Control','max-age=0');
        return $response;
    }

    /**
     * Asignar productos masivamente a una bodega desde un Excel
     */
    public function assignWarehouseMasive(Request $request, $storeId)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);
        $file = $request->file('file');
        $spreadsheet = IOFactory::load($file->getPathname());
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);
        // Buscar la primera fila no vacía como encabezado
        $headerRow = null;
        $headerIndex = null;
        foreach ($rows as $i => $fila) {
            if ($fila && array_filter($fila)) {
                $headerRow = $fila;
                $headerIndex = $i;
                break;
            }
        }
        Log::info('Excel header detectado', ['headerRow' => $headerRow, 'headerIndex' => $headerIndex]);
        if (!$headerRow) {
            return response()->json([
                'message' => 'El archivo Excel está vacío o no tiene encabezado.',
                'status' => 'error'
            ], 422);
        }
        $colProductId = null;
        $colWarehouseId = null;
        foreach ($headerRow as $col => $name) {
            if (strtolower(trim($name)) === 'product_id') {
                $colProductId = $col;
            }
            if (strtolower(trim($name)) === 'warehouse_id') {
                $colWarehouseId = $col;
            }
        }
        if (!$colProductId || !$colWarehouseId) {
            return response()->json([
                'message' => 'El archivo debe tener encabezados product_id y warehouse_id.',
                'status' => 'error',
                'headerRow' => $headerRow
            ], 422);
        }
        $asignados = [];
        $errores = [];
        foreach ($rows as $i => $row) {
            if ($i <= $headerIndex) continue; // Saltar encabezado y filas previas
            $productId = $row[$colProductId] ?? null;
            $warehouseId = $row[$colWarehouseId] ?? null;
            if (!$productId || !$warehouseId) {
                $errores[] = ['row' => $row, 'error' => 'Faltan product_id o warehouse_id'];
                continue;
            }
            try {
                $woocommerce = $this->connect($storeId);
                $wooProduct = $woocommerce->get("products/{$productId}");
                if (!$wooProduct) {
                    $errores[] = ['row' => $row, 'error' => 'Producto WooCommerce no encontrado'];
                    continue;
                }
                $price = $wooProduct->price ?? $wooProduct->regular_price ?? 0;
                $available_quantity = $wooProduct->stock_quantity ?? 0;
                $title = $wooProduct->name ?? '';
                $description = $wooProduct->description ?? '';
                
                // Extraer imágenes
                $pictures = null;
                if (!empty($wooProduct->images)) {
                    $pictures = json_encode($wooProduct->images);
                }
                
                // Extraer categorías
                $category_id = null;
                if (!empty($wooProduct->categories)) {
                    $category_id = $wooProduct->categories[0]->id ?? null;
                }
                
                // Extraer atributos
                $attribute = null;
                if (!empty($wooProduct->attributes)) {
                    $attribute = json_encode($wooProduct->attributes);
                }
                
                $stockWarehouse = $this->createStockWarehouseSafely([
                    'id_mlc' => $wooProduct->id,
                    'warehouse_id' => $warehouseId,
                    'title' => $title,
                    'price' => $price,
                    'available_quantity' => $available_quantity,
                    'pictures' => $pictures,
                    'category_id' => $category_id,
                    'attribute' => $attribute,
                    'condicion' => 'new',
                    'currency_id' => 'CLP',
                    'listing_type_id' => 'gold_special',
                    'description' => $description,
                ]);
                $asignados[] = [
                    'product_id' => $wooProduct->id,
                    'warehouse_id' => $warehouseId,
                    'stock_warehouse_id' => $stockWarehouse->id
                ];
            } catch (\Exception $e) {
                $errores[] = ['row' => $row, 'error' => $e->getMessage()];
            }
        }
        return response()->json([
            'asignados' => $asignados,
            'errores' => $errores,
            'total_asignados' => count($asignados),
            'total_errores' => count($errores)
        ]);
    }
}
