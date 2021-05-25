<?php

namespace Wexo\Webshipper\Model;

use GuzzleHttp\Client;
use GuzzleHttp\ClientFactory;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Response;
use Magento\Framework\Api\ObjectFactory;
use Magento\Framework\Api\SimpleDataObjectConverter;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Base64Json;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Wexo\Webshipper\Api\Data\ParcelShopInterface;

class Api
{
    /**
     * @var ClientFactory
     */
    private $clientFactory;

    /**
     * @var UrlInterface
     */
    private $url;

    /**
     * @var Client
     */
    private $client = null;

    /**
     * @var Json
     */
    private $jsonSerializer;

    /**
     * @var ObjectFactory
     */
    private $objectFactory;

    /**
     * @var Config
     */
    private $config;
    /**
     * @var Base64Json
     */
    private $base64Json;

    /**
     * @param ClientFactory $clientFactory
     * @param UrlInterface $url
     * @param Json $jsonSerializer
     * @param ObjectFactory $objectFactory
     * @param null $appUid
     * @param null $apiKey
     */
    public function __construct(
        ClientFactory $clientFactory,
        UrlInterface $url,
        Json $jsonSerializer,
        ObjectFactory $objectFactory,
        \Wexo\Webshipper\Model\Config $config,
        Base64Json $base64Json
    ) {
        $this->clientFactory = $clientFactory;
        $this->url = $url;
        $this->jsonSerializer = $jsonSerializer;
        $this->objectFactory = $objectFactory;
        $this->config = $config;
        $this->base64Json = $base64Json;
    }

    /**
     * @param $countryCode
     * @param $postalCode
     * @return array|false
     */
    public function getParcelShops($countryCode, $method, $postalCode)
    {
        return $this->request(function (Client $client) use ($countryCode, $method, $postalCode) {
            $data = [
                'data' => [
                    'type' => 'drop_point_locators',
                    'attributes' => [
                        'shipping_rate_id' => $this->getShippingRateIdFromMethod($method),
                        'delivery_address' => [
                            'zip' => $postalCode,
                            'country_code' => $countryCode
                        ]
                    ]
                ]
            ];
            return $client->post(
                "/v2/drop_point_locators",
                [
                    'json' => $data,
                    'headers' => [
                        'Accept' => 'application/vnd.api+json',
                        'Content-Type' => 'application/vnd.api+json'
                    ]
                ]
            );
        }, function (Response $response, $content) {
            return isset($content['data']['attributes']['drop_points'])
                ? $this->mapParcelShops($content['data']['attributes']['drop_points'])
                : false;
        });
    }

    public function getShippingRateIdFromMethod($method)
    {
        return (int)explode('_', $method)[0] ?? 0;
    }

    /**
     * @param callable $func
     * @param callable $transformer
     * @return mixed
     */
    public function request(callable $func, callable $transformer = null)
    {
        /** @var Response $response */
        $response = $func($this->getClient());

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() <= 299) {
            $content = $this->jsonSerializer->unserialize($response->getBody()->__toString());
            return $transformer === null ? $content : $transformer($response, $content);
        }

        return false;
    }

    public function verifyRequest($hmacHeader, $content)
    {
        return true;
        // TODO: talk to webshipper to find an easier way to find this shared secret so we can start validating
//        $secret = 'SHARED SECRET';
//        $calculated_hmac = base64_encode(hash_hmac('sha256', $content, $secret, true));
//        if(!hash_equals($hmacHeader, $calculated_hmac)){
//            throw new LocalizedException(__('Request is not verified'));
//        }
    }

    /**
     * @return Client
     */
    public function getClient()
    {
        if ($this->client === null) {
            $baseUri = str_replace(
                '//',
                '//' . $this->config->getTenantName() . '.',
                $this->config->getEndpoint()
            );
            $this->client = $this->clientFactory->create([
                'config' => [
                    'base_uri' => $baseUri,
                    'time_out' => 5.0,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->config->getToken(),
                        'Accept' => 'application/vnd.api+json',
                        'Content-Type' => 'application/vnd.api+json'
                    ]
                ]
            ]);
        }

        return $this->client;
    }

    public $dayMapper = [
        0 => 'Monday',
        1 => 'Tuesday',
        2 => 'Wednesday',
        3 => 'Thursday',
        4 => 'Friday',
        5 => 'Saturday',
        6 => 'Sunday'
    ];

    /**
     * @param $parcelShops
     * @return array
     */
    protected function mapParcelShops($parcelShops)
    {
        return array_map(function ($parcelShop) {
            $parcelShopData = [];
            foreach ($parcelShop['opening_hours'] as $key => $item) {
                $item['day'] = $this->dayMapper[$item['day']];
                $parcelShop['opening_hours'][$key] = $item;
            }

            /** @var ParcelShopInterface $parcelShopObject */
            $parcelShopObject = $this->objectFactory->create(ParcelShopInterface::class, [
                'data' => $parcelShopData
            ]);
            $parcelShopObject->setNumber($parcelShop['drop_point_id']);
            $parcelShopObject->setCompanyName($parcelShop['name']);
            $parcelShopObject->setStreetName($parcelShop['address_1']);
            $parcelShopObject->setZipCode($parcelShop['zip']);
            $parcelShopObject->setCity($parcelShop['city']);
            $parcelShopObject->setCountryCode($parcelShop['country_code']);
            $parcelShopObject->setLongitude($parcelShop['longitude']);
            $parcelShopObject->setLatitude($parcelShop['latitude']);
            $parcelShopObject->setOpeningHours([$parcelShop['opening_hours']]);
            return $parcelShopObject;
        }, $parcelShops);
    }
}
