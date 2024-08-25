<?php

namespace Zrnik\Exchange;

use DateTime;
use Money\Currency;
use Money\Exception\UnresolvableCurrencyPairException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use RuntimeException;

class ExchangeRateProvider
{
    private const CNB_EXCHANGE_RATE_URL_FORMAT = 'https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/denni_kurz.txt?date=%s';

    public function __construct(
        private CacheItemPoolInterface        $cacheItemPool,
        private ServerRequestFactoryInterface $serverRequestFactory,
        private ClientInterface               $client,
    )
    {
    }

    /**
     * @throws InvalidArgumentException
     * @throws ClientExceptionInterface
     */
    public function currencyRatioBetween(DateTime $dateTime, Currency $baseCurrency, Currency $counterCurrency): float
    {
        $exchangeRates = $this->getExchangeRates($dateTime);

        //Do our currencies exist in CNB results?

        if (!isset($exchangeRates[$baseCurrency->getCode()])) {
            throw new UnresolvableCurrencyPairException(
                "Currency '" . $baseCurrency->getCode() . "' is not defined in CNB exchange rates file!"
            );
        }

        if (!isset($exchangeRates[$counterCurrency->getCode()])) {
            throw new UnresolvableCurrencyPairException(
                "Currency '" . $counterCurrency->getCode() . "' is not defined in CNB exchange rates file!"
            );
        }

        return (float)
            (
                $exchangeRates[$baseCurrency->getCode()][1]
                /
                $exchangeRates[$counterCurrency->getCode()][1]
            )
            *
            (
                $exchangeRates[$counterCurrency->getCode()][0]
                /
                $exchangeRates[$baseCurrency->getCode()][0]
            );
    }

    /**
     * @param DateTime $dateTime
     * @return array<string, array{0: float, 1: float}>
     * @throws InvalidArgumentException
     * @throws ClientExceptionInterface
     */
    public function getExchangeRates(DateTime $dateTime): array
    {
        $dateTimeFormatted = DateConvert::fromDateTime($dateTime);

        $cachedValue = $this->cacheItemPool->getItem($dateTimeFormatted);

        if ($cachedValue->isHit()) {
            /** @var array<string, array{0: float, 1: float}> */
            return $cachedValue->get();
        }

        $todayDateTime = new DateTime();
        $todayDateTimeKey = DateConvert::fromDateTime($todayDateTime);
        if ($dateTime > $todayDateTime && $todayDateTimeKey !== $dateTimeFormatted) {
            throw new RuntimeException('Cannot retrieve future exchange rates!');
        }

        $isToday = $todayDateTimeKey === $dateTimeFormatted;

        // If it's today, cache for 5 minutes, if it's in the past, cache indefinitely...
        $cachedValue->expiresAfter($isToday ? 300 : null);

        $ratios = $this->fetchRatiosByKey($dateTimeFormatted);

        $cachedValue->set($ratios);

        $this->cacheItemPool->save($cachedValue);

        return $ratios;
    }

    /**
     * @param string $cacheKey
     * @return array<string, array{0: float, 1: float}>
     * @throws ClientExceptionInterface
     */
    private function fetchRatiosByKey(string $cacheKey): array
    {
        $request = $this->serverRequestFactory->createServerRequest(
            'GET',
            sprintf(self::CNB_EXCHANGE_RATE_URL_FORMAT, $cacheKey),
        );

        $response = $this->client->sendRequest($request);

        $value = (string)$response->getBody();
        $lines = explode("\n", $value);

        // First row is the date, we do not need it.
        // It also gives us a header, we skip both of those...
        unset($lines[0], $lines[1]);

        $ratios = [
            'CZK' => [1, 1],
        ];

        foreach ($lines as $line) {
            $parts = explode('|', $line);

            if (count($parts) !== 5) {
                continue;
            }

            $currencyCode = $parts[3];
            $amount = (int)$parts[2];
            $price = round((float)str_replace(',', '.', $parts[4]), 4);

            $ratios[$currencyCode] = [$amount, $price];
        }

        return $ratios;
    }
}