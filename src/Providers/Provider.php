<?php

declare(strict_types=1);

namespace Geocode\Providers;

use Geocode\Model\Query\GeocodeQuery;
use Illuminate\Support\Collection;

interface Provider
{
    /**
     * @param GeocodeQuery $query
     *
     * @return Collection
     *
     * @throws \Exception
     */
    public function geocodeQuery(GeocodeQuery $query): Collection;

    /**
     * Returns the provider's name.
     *
     * @return string
     */
    public function getName(): string;
}