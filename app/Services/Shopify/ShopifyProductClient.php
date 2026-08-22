<?php

namespace App\Services\Shopify;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ShopifyProductClient
{
    public function productByHandle(string $handle): ?array
    {
        $handle = trim($handle);

        if ($handle === '') {
            throw new RuntimeException('Cannot look up Shopify product without handle.');
        }

        $result = $this->graphql(
            <<<'GRAPHQL'
            query ProductByHandle($handle: String!) {
                productByHandle(handle: $handle) {
                    id
                    title
                    handle
                    variants(first: 250) {
                        nodes {
                            id
                            sku
                            barcode
                            selectedOptions {
                                name
                                value
                            }
                        }
                    }
                }
            }
            GRAPHQL,
            [
                'handle' => $handle,
            ]
        );

        $product = $result['data']['productByHandle'] ?? null;

        return is_array($product) ? $product : null;
    }

    public function createProductWithVariants(array $productInput, array $variants): array
    {
        $handle = trim((string)($productInput['handle'] ?? ''));

        if ($handle === '') {
            throw new RuntimeException('Cannot create Shopify product without handle.');
        }

        if ($variants === []) {
            throw new RuntimeException('Cannot create Shopify product without variants.');
        }

        $existingProduct = $this->productByHandle($handle);

        if ($existingProduct !== null) {
            throw new RuntimeException('Shopify product already exists for handle: ' . $handle);
        }

        $createdProduct = $this->createProduct($productInput);
        $productId      = trim((string)($createdProduct['product']['id'] ?? ''));

        if ($productId === '') {
            throw new RuntimeException('Shopify productCreate response is missing product ID.');
        }

        $createdVariants = $this->createVariants($productId, $variants);

        return [
            'product'  => $createdProduct['product'],
            'variants' => $createdVariants['productVariants'],
            'raw'      => [
                'productCreate'             => $createdProduct['raw'],
                'productVariantsBulkCreate' => $createdVariants['raw'],
            ],
        ];
    }

    private function createProduct(array $productInput): array
    {
        $result = $this->graphql(
            <<<'GRAPHQL'
            mutation CreateProductFromAfas($product: ProductCreateInput!) {
                productCreate(product: $product) {
                    product {
                        id
                        title
                        handle
                        status
                        options {
                            id
                            name
                            position
                            optionValues {
                                id
                                name
                                hasVariants
                            }
                        }
                        variants(first: 10) {
                            nodes {
                                id
                                title
                                sku
                                barcode
                                selectedOptions {
                                    name
                                    value
                                }
                            }
                        }
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
            GRAPHQL,
            [
                'product' => $productInput,
            ]
        );

        $userErrors = $result['data']['productCreate']['userErrors'] ?? [];

        if (!empty($userErrors)) {
            throw new RuntimeException(json_encode([
                'message'     => 'Shopify productCreate returned user errors.',
                'user_errors' => $userErrors,
                'input'       => $productInput,
            ], JSON_PRETTY_PRINT));
        }

        $product = $result['data']['productCreate']['product'] ?? null;

        if (!is_array($product)) {
            throw new RuntimeException(json_encode([
                'message' => 'Shopify productCreate did not return a product.',
                'result'  => $result,
                'input'   => $productInput,
            ], JSON_PRETTY_PRINT));
        }

        return [
            'product' => $product,
            'raw'     => $result,
        ];
    }

    private function createVariants(string $productId, array $variants): array
    {
        $result = $this->graphql(
            <<<'GRAPHQL'
            mutation CreateProductVariantsFromAfas(
                $productId: ID!
                $variants: [ProductVariantsBulkInput!]!
                $strategy: ProductVariantsBulkCreateStrategy
            ) {
                productVariantsBulkCreate(
                    productId: $productId
                    variants: $variants
                    strategy: $strategy
                ) {
                    product {
                        id
                        title
                        handle
                        options {
                            id
                            name
                            position
                            optionValues {
                                id
                                name
                                hasVariants
                            }
                        }
                    }
                    productVariants {
                        id
                        title
                        sku
                        barcode
                        selectedOptions {
                            name
                            value
                        }
                        inventoryItem {
                            id
                            sku
                            tracked
                            requiresShipping
                        }
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
            GRAPHQL,
            [
                'productId' => $productId,
                'variants'  => $variants,
                'strategy'  => 'REMOVE_STANDALONE_VARIANT',
            ]
        );

        $userErrors = $result['data']['productVariantsBulkCreate']['userErrors'] ?? [];

        if (!empty($userErrors)) {
            throw new RuntimeException(json_encode([
                'message'     => 'Shopify productVariantsBulkCreate returned user errors.',
                'user_errors' => $userErrors,
                'product_id'  => $productId,
                'variants'    => $variants,
            ], JSON_PRETTY_PRINT));
        }

        $productVariants = $result['data']['productVariantsBulkCreate']['productVariants'] ?? null;

        if (!is_array($productVariants)) {
            throw new RuntimeException(json_encode([
                'message'    => 'Shopify productVariantsBulkCreate did not return product variants.',
                'result'     => $result,
                'product_id' => $productId,
                'variants'   => $variants,
            ], JSON_PRETTY_PRINT));
        }

        return [
            'productVariants' => $productVariants,
            'raw'             => $result,
        ];
    }

    private function graphql(string $query, array $variables): array
    {
        $response = Http::connectTimeout(10)
            ->timeout(120)
            ->asJson()
            ->acceptJson()
            ->withHeader('X-Shopify-Access-Token', $this->accessToken())
            ->post($this->graphqlEndpoint(), [
                'query'     => $query,
                'variables' => $variables,
            ]);

        $body = $response->json();

        if (!$response->successful()) {
            throw new RuntimeException(json_encode([
                'message' => 'Shopify GraphQL request failed.',
                'status'  => $response->status(),
                'reason'  => $response->reason(),
                'body'    => $body ?? $response->body(),
            ], JSON_PRETTY_PRINT));
        }

        if (!is_array($body)) {
            throw new RuntimeException('Shopify returned invalid JSON.');
        }

        if (!empty($body['errors'])) {
            throw new RuntimeException(json_encode([
                'message' => 'Shopify GraphQL returned errors.',
                'errors'  => $body['errors'],
            ], JSON_PRETTY_PRINT));
        }

        return $body;
    }

    private function accessToken(): string
    {
        $staticToken = trim((string)config('api.shopify.admin_api_access_token'));

        if ($staticToken !== '') {
            return $staticToken;
        }

        return $this->clientCredentialsAccessToken();
    }

    private function clientCredentialsAccessToken(): string
    {
        $cacheKey = 'shopify_admin_access_token:' . $this->shopDomain();

        $cachedToken = Cache::get($cacheKey);

        if (is_string($cachedToken) && trim($cachedToken) !== '') {
            return $cachedToken;
        }

        $clientId     = trim((string)config('api.shopify.client_id'));
        $clientSecret = trim((string)config('api.shopify.client_secret'));

        if ($clientId === '') {
            throw new RuntimeException('Missing config: api.shopify.client_id');
        }

        if ($clientSecret === '') {
            throw new RuntimeException('Missing config: api.shopify.client_secret');
        }

        $response = Http::connectTimeout(10)
            ->timeout(60)
            ->asForm()
            ->acceptJson()
            ->post($this->tokenEndpoint(), [
                'grant_type'    => 'client_credentials',
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
            ]);

        $body = $response->json();

        if (!$response->successful()) {
            throw new RuntimeException(json_encode([
                'message' => 'Shopify token request failed.',
                'status'  => $response->status(),
                'reason'  => $response->reason(),
                'body'    => $body ?? $response->body(),
            ], JSON_PRETTY_PRINT));
        }

        if (!is_array($body)) {
            throw new RuntimeException('Shopify token endpoint returned invalid JSON.');
        }

        $accessToken = trim((string)($body['access_token'] ?? ''));
        $expiresIn   = (int)($body['expires_in'] ?? 0);

        if ($accessToken === '') {
            throw new RuntimeException(json_encode([
                'message' => 'Shopify token response is missing access_token.',
                'body'    => $body,
            ], JSON_PRETTY_PRINT));
        }

        $cacheSeconds = $expiresIn > 600 ? $expiresIn - 300 : 300;

        Cache::put($cacheKey, $accessToken, now()->addSeconds($cacheSeconds));

        return $accessToken;
    }

    private function graphqlEndpoint(): string
    {
        $apiVersion = trim((string)config('api.shopify.api_version', '2026-07'));

        if ($apiVersion === '') {
            throw new RuntimeException('Missing config: api.shopify.api_version');
        }

        return 'https://' . $this->shopDomain() . '/admin/api/' . $apiVersion . '/graphql.json';
    }

    private function tokenEndpoint(): string
    {
        return 'https://' . $this->shopDomain() . '/admin/oauth/access_token';
    }

    private function shopDomain(): string
    {
        $shopDomain = trim((string)config('api.shopify.shop_domain'));

        if ($shopDomain === '') {
            throw new RuntimeException('Missing config: api.shopify.shop_domain');
        }

        $shopDomain = Str::of($shopDomain)
            ->replace('https://', '')
            ->replace('http://', '')
            ->trim('/')
            ->toString();

        if (!str_contains($shopDomain, '.myshopify.com')) {
            $shopDomain .= '.myshopify.com';
        }

        return $shopDomain;
    }
}
