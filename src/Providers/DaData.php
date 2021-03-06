<?php

declare(strict_types=1);

namespace Geocoding\Laravel\Providers;

use Geocoding\Laravel\Models\Query\BatchQuery;
use Geocoding\Laravel\Models\Query\GeocodeQuery;
use Geocoding\Laravel\Models\Query\Query;
use Geocoding\Laravel\Models\Query\ReverseQuery;
use Geocoding\Laravel\Models\Query\SuggestQuery;
use Geocoding\Laravel\Models\QueryGroup;
use Geocoding\Laravel\Resources\Address;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;
use Geocoding\Laravel\Exceptions\InvalidServerResponse;

final class DaData implements Provider
{
    /**
     * @var mixed
     */
    protected $token;

    /**
     * @var mixed
     */
    protected $proxy;

    /**
     * @var mixed
     */
    protected $proxy_port;

    /**
     * Базовый url для автозаполнения
     */
    const SUGGEST_URL = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest';

    /**
     * Базовый url для геокодирования
     */
    const GEOCODE_URL = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest';

    /**
     * Базовый url для обратного геокодирования
     */
    const REVERSE_URL = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/geolocate';

    /**
     * DaData constructor.
     *
     * @param $token
     * @param $proxy
     * @param $proxy_port
     */
    public function __construct($token, $proxy = null, $proxy_port = 80)
    {
        $this->token = $token;
        $this->proxy = $proxy;
        $this->proxy_port = $proxy_port;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'DaData.ru';
    }

    /**
     * All empty results will be rejected
     *
     * @param BatchQuery $batch
     * @return Collection
     */
    public function batch(BatchQuery $batch): Collection
    {
        $queries = $batch->getQueries();
        if ($queries->count() === 0) {
            return collect([]);
        }

        $result = collect([]);
        $queries->each(function (Query $query) use ($result) {
            if ($query instanceof GeocodeQuery) {
                $result->push($this->geocode($query));
            }
            if ($query instanceof ReverseQuery) {
                $result->push($this->reverse($query));
            }
        });

        return $result->reject(function ($value) { return empty($value); });
    }

    /**
     * @param GeocodeQuery $query
     * @return Address|null
     */
    public function geocode(GeocodeQuery $query): ?Address
    {
        $data = $this->executeQuery($this->buildFinalUrl($query, self::GEOCODE_URL), $query);

        if ($data->count() && $data->first()->getLatitude()) {
            return $data->first();
        }

        return null;
    }

    /**
     * @param ReverseQuery $query
     * @return Address|null
     */
    public function reverse(ReverseQuery $query): ?Address
    {
        $data = $this->executeQuery($this->buildFinalUrl($query, self::REVERSE_URL), $query);

        if ($data->count() && $data->first()->getLatitude()) {
            return $data->first();
        }

        return null;
    }

    /**
     * @param SuggestQuery $query
     * @return Collection
     */
    public function suggest(SuggestQuery $query): Collection
    {
        $data = $this->executeQuery($this->buildFinalUrl($query, self::SUGGEST_URL), $query);

        if ($data->count() === 0) {
            return collect([]);
        }

        return $data->map(function ($item) {
            return $item->getAddress();
        });
    }

    /**
     * @param Query $query
     * @param string $url
     * @return Collection
     */
    private function executeQuery(string $url, Query $query): Collection
    {
        try {
            $response = (new Client())->post($url, $this->buildRequestData($query));
            $data = json_decode((string)$response->getBody(), true);
        } catch (\Exception $e) {
            throw InvalidServerResponse::create('Provider "' . $this->getName() . '" could not geocode address: "' . $query->getText() . '".');
        }

        if (empty($data['suggestions']) || \count($data['suggestions']) === 0) {
            return collect([]);
        }

        $result = [];
        foreach ($data['suggestions'] as $address) {
            $builder = new Address($this->getName());

            $builder->setProvidedBy($this->getName());
            $builder->setLatitude($address['data']['geo_lat']);
            $builder->setLongitude($address['data']['geo_lon']);
            $builder->setAddress($address['unrestricted_value']);

            $result[] = $builder;
        }

        return collect($result);
    }

    /**
     * @param Query $query
     * @param $base_url
     * @return string
     */
    private function buildFinalUrl(Query $query, string $base_url)
    {
        $result = $base_url;

        switch ($query->getGroupBy()) {
            case QueryGroup::GROUP_BY_ADDRESS:
                $result .= '/address';
                break;

            case QueryGroup::GROUP_BY_CITY:
                $result .= '/address';
                break;
        }

        return $result;
    }

    /**
     * @param Query $query
     * @return array
     */
    private function buildRequestData(Query $query): array
    {
        $result = [
            'headers' => [
                'Authorization' => 'Token '.$this->token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ],
            'json' => [
                'count' => $query->getLimit()
            ],
            'proxy' => !empty($this->proxy) ? $this->proxy . ':' . $this->proxy_port : null
        ];

        if ($query instanceof ReverseQuery) {
            $result['json']['lat'] = $query->getLatitude();
            $result['json']['lon'] = $query->getLongitude();
        } else {
            $result['json']['query'] = $query->getText();
        }

        if ($query->getGroupBy() === QueryGroup::GROUP_BY_CITY) {
            $result['json']['from_bound'] = [
                'value' => 'city'
            ];
            $result['json']['to_bound'] = [
                'value' => 'city'
            ];
        }

        return $result;
    }
}
