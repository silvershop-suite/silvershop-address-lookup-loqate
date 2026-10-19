<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\Loqate\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use SilverShop\AddressLookup\Loqate\LoqateProvider;
use SilverShop\AddressLookup\Provider\AddressLookupProvider;
use SilverShop\AddressLookup\ValueObject\AddressQuery;
use SilverShop\AddressLookup\ValueObject\ResolvedAddress;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;

/**
 * Drives the real {@link LoqateProvider} against canned responses with a mocked Guzzle handler (no live calls): the
 * `Key` query param, the Find + Retrieve request/response shapes (including a container-drill), the Retrieve →
 * {@link ResolvedAddress} mapping (Province → state, CountryIso2 → countryCode, SubBuilding → suffix, geo), suggest,
 * the Find-match validate, the consent-GATED posture, and soft-fail on a fault / Loqate error payload.
 */
class LoqateProviderTest extends SapphireTest
{
    protected $usesDatabase = false;

    private const TEST_KEY = 'test-key-123';

    private const FIND_ADDRESS = '{"Items":[{"Id":"NZ|A|1","Type":"Address","Text":"1 Someplace Road",'
        . '"Highlight":"0-1","Description":"Mount Victoria, Wellington 6011"}]}';

    private const FIND_CONTAINER = '{"Items":[{"Id":"NZ|C|6011","Type":"Container","Text":"6011","Highlight":"",'
        . '"Description":"Wellington - 120 Addresses"}]}';

    private const RETRIEVE_BODY = '{"Items":[{"Id":"NZ|A|1","Language":"ENG","Street":"Someplace Road",'
        . '"BuildingNumber":"1","SubBuilding":"Flat 2","City":"Wellington","Province":"WGN",'
        . '"ProvinceName":"Wellington","ProvinceCode":"WGN","PostalCode":"6011","CountryName":"New Zealand",'
        . '"CountryIso2":"NZ","Line1":"Flat 2, 1 Someplace Road","Latitude":"-41.293","Longitude":"174.782"}]}';

    /**
     * @var string|false
     */
    private $originalKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = Environment::getEnv('SS_LOQATE_API_KEY');
        Environment::setEnv('SS_LOQATE_API_KEY', self::TEST_KEY);
    }

    protected function tearDown(): void
    {
        Environment::setEnv('SS_LOQATE_API_KEY', $this->originalKey === false ? '' : $this->originalKey);
        parent::tearDown();
    }

    /**
     * @param array<int, Response> $responses
     * @param array<int, mixed> $transactions
     */
    private function provider(array $responses, array &$transactions = []): LoqateProvider
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($transactions));

        return new LoqateProvider(['handler' => $stack]);
    }

    public function testKeyQueryParamSentOnFindAndRetrieve(): void
    {
        $transactions = [];
        $provider = $this->provider(
            [new Response(200, [], self::FIND_ADDRESS), new Response(200, [], self::RETRIEVE_BODY)],
            $transactions,
        );

        $provider->lookup(new AddressQuery('NZ', '6011'));

        /** @var RequestInterface $find */
        $find = $transactions[0]['request'];
        /** @var RequestInterface $retrieve */
        $retrieve = $transactions[1]['request'];

        parse_str($find->getUri()->getQuery(), $fq);
        parse_str($retrieve->getUri()->getQuery(), $rq);
        $this->assertSame(self::TEST_KEY, $fq['Key'] ?? null, 'Key is sent on Find');
        $this->assertSame(self::TEST_KEY, $rq['Key'] ?? null, 'Key is sent on Retrieve');
        $this->assertSame('application/json', $find->getHeaderLine('Accept'));
    }

    public function testLookupFindThenRetrieveMapsAddressAndRequestShapes(): void
    {
        $transactions = [];
        $provider = $this->provider(
            [new Response(200, [], self::FIND_ADDRESS), new Response(200, [], self::RETRIEVE_BODY)],
            $transactions,
        );

        $address = $provider->lookup(new AddressQuery('NZ', '6011'));

        $this->assertNotNull($address);
        $this->assertSame('Someplace Road', $address->street);
        $this->assertSame('1', $address->houseNumber);
        $this->assertSame('Flat 2', $address->suffix, 'SubBuilding maps onto suffix');
        $this->assertSame('6011', $address->postalCode);
        $this->assertSame('Wellington', $address->city);
        $this->assertSame('Wellington', $address->state, 'ProvinceName preferred for state');
        $this->assertSame('NZ', $address->countryCode, 'CountryIso2 maps onto countryCode');
        $this->assertSame(-41.293, $address->lat);
        $this->assertSame(174.782, $address->lng);
        $this->assertTrue($address->exact);
        $this->assertSame('loqate', $address->providerRef);

        // Find request shape.
        /** @var RequestInterface $find */
        $find = $transactions[0]['request'];
        $this->assertSame('GET', $find->getMethod());
        $this->assertSame('api.addressy.com', $find->getUri()->getHost());
        $this->assertSame('/Capture/Interactive/Find/v1.10/json3.ws', $find->getUri()->getPath());
        parse_str($find->getUri()->getQuery(), $fq);
        $this->assertSame('6011', $fq['Text'] ?? null, 'Text composed from the postcode');
        $this->assertSame('NZ', $fq['Countries'] ?? null);
        $this->assertArrayHasKey('Limit', $fq);
        $this->assertArrayNotHasKey('Container', $fq, 'No Container on the first Find');

        // Retrieve request shape.
        /** @var RequestInterface $retrieve */
        $retrieve = $transactions[1]['request'];
        $this->assertSame('/Capture/Interactive/Retrieve/v1.20/json3.ws', $retrieve->getUri()->getPath());
        parse_str($retrieve->getUri()->getQuery(), $rq);
        $this->assertSame('NZ|A|1', $rq['Id'] ?? null, 'Retrieve uses the Find item Id');
    }

    public function testLookupDrillsContainerThenRetrieves(): void
    {
        $transactions = [];
        $provider = $this->provider(
            [
                new Response(200, [], self::FIND_CONTAINER),
                new Response(200, [], self::FIND_ADDRESS),
                new Response(200, [], self::RETRIEVE_BODY),
            ],
            $transactions,
        );

        $address = $provider->lookup(new AddressQuery('NZ', '6011'));

        $this->assertNotNull($address);
        $this->assertSame('Wellington', $address->city);
        $this->assertCount(3, $transactions, 'Find (container) -> Find (drill) -> Retrieve');

        // The second Find drills into the container returned by the first.
        parse_str($transactions[1]['request']->getUri()->getQuery(), $drill);
        $this->assertSame('NZ|C|6011', $drill['Container'] ?? null, 'Container Id re-sent to drill');
        $this->assertSame('6011', $drill['Text'] ?? null, 'Text preserved while drilling');
    }

    public function testLookupStopsAtDrillBudget(): void
    {
        Config::modify()->set(LoqateProvider::class, 'max_container_drills', 1);
        $transactions = [];
        // Every Find returns only a container: with budget 1 that is at most 2 Find calls, then a soft miss.
        $provider = $this->provider(
            [
                new Response(200, [], self::FIND_CONTAINER),
                new Response(200, [], self::FIND_CONTAINER),
                new Response(200, [], self::FIND_CONTAINER),
            ],
            $transactions,
        );

        $this->assertNull($provider->lookup(new AddressQuery('NZ', '6011')));
        $this->assertCount(2, $transactions, 'Bounded by max_container_drills (1 + the initial Find)');
    }

    public function testSuggestReturnsFindLabelsWithIdInProviderRef(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], self::FIND_ADDRESS)], $transactions);

        $results = $provider->suggest('Someplace', 'NZ');

        $this->assertCount(1, $results);
        $this->assertInstanceOf(ResolvedAddress::class, $results[0]);
        $this->assertSame('1 Someplace Road', $results[0]->street, 'Find Text kept as the label');
        $this->assertSame('NZ', $results[0]->countryCode);
        $this->assertFalse($results[0]->exact);
        $this->assertSame('NZ|A|1', $results[0]->providerRef, 'Loqate Id carried for a follow-up Retrieve');
        $this->assertCount(1, $transactions, 'suggest is a single Find, no per-row Retrieve');

        parse_str($transactions[0]['request']->getUri()->getQuery(), $q);
        $this->assertSame('Someplace', $q['Text'] ?? null);
        $this->assertSame('NZ', $q['Countries'] ?? null);
    }

    public function testSuggestRejectsEmptyTermAndUnsupportedCountryWithoutRequest(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], self::FIND_ADDRESS)], $transactions);

        $this->assertSame([], $provider->suggest('   ', 'NZ'));
        $this->assertSame([], $provider->suggest('anything', 'USA'));
        $this->assertCount(0, $transactions);
    }

    public function testValidateFindMatchScores(): void
    {
        $addressMatch = $this->provider([new Response(200, [], self::FIND_ADDRESS)])
            ->validate(new ResolvedAddress(street: 'Someplace Road', city: 'Wellington', countryCode: 'NZ'));
        $this->assertTrue($addressMatch->valid);
        $this->assertSame(1.0, $addressMatch->score, 'A precise Address match scores 1.0');

        $areaMatch = $this->provider([new Response(200, [], self::FIND_CONTAINER)])
            ->validate(new ResolvedAddress(postalCode: '6011', countryCode: 'NZ'));
        $this->assertTrue($areaMatch->valid);
        $this->assertSame(0.5, $areaMatch->score, 'An area/container-only match scores 0.5');

        $miss = $this->provider([new Response(200, [], '{"Items":[]}')])
            ->validate(new ResolvedAddress(postalCode: '0000', countryCode: 'NZ'));
        $this->assertTrue($miss->valid, 'A clean miss soft-passes (unknown)');
        $this->assertSame(0.0, $miss->score);
    }

    public function testValidateSoftPassesOnFault(): void
    {
        $result = $this->provider([new Response(500, [], 'boom')])
            ->validate(new ResolvedAddress(postalCode: '6011', countryCode: 'NZ'));

        $this->assertTrue($result->valid, 'A fault is a soft pass so checkout stays usable');
        $this->assertSame(0.0, $result->score);
    }

    public function testValidateUnsupportedCountryMakesNoRequest(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], self::FIND_ADDRESS)], $transactions);

        $result = $provider->validate(new ResolvedAddress(countryCode: 'USA'));

        $this->assertTrue($result->valid);
        $this->assertCount(0, $transactions);
    }

    public function testSupportsCountryIsGlobalAndModeIsAutocomplete(): void
    {
        $provider = $this->provider([new Response(200, [], '{"Items":[]}')]);

        $this->assertTrue($provider->supportsCountry('NZ'));
        $this->assertTrue($provider->supportsCountry('US'));
        $this->assertTrue($provider->supportsCountry('gb'), 'Case-insensitive');
        $this->assertTrue($provider->supportsCountry('BR'), 'Global coverage');
        $this->assertFalse($provider->supportsCountry('USA'), 'Not a 2-letter code');
        $this->assertFalse($provider->supportsCountry(''));

        $this->assertSame(AddressLookupProvider::MODE_AUTOCOMPLETE, $provider->lookupMode('NZ'));
        $this->assertSame(AddressLookupProvider::MODE_AUTOCOMPLETE, $provider->lookupMode('US'));
        $this->assertSame(AddressLookupProvider::MODE_NONE, $provider->lookupMode('USA'));
    }

    public function testConsentCategoryIsGatedByDefaultAndConfigurable(): void
    {
        $provider = $this->provider([new Response(200, [], '{"Items":[]}')]);
        $this->assertSame('marketing', $provider->consentCategory(), 'GATED by default (the Loqate difference)');

        Config::modify()->set(LoqateProvider::class, 'consent_category', 'preferences');
        $this->assertSame('preferences', $provider->consentCategory(), 'Configurable');

        Config::modify()->set(LoqateProvider::class, 'consent_category', '');
        $this->assertNull($provider->consentCategory(), 'Empty disables the gate');
    }

    public function testLoqateErrorPayloadIsSoftMiss(): void
    {
        $transactions = [];
        $error = '{"Items":[{"Error":"2","Description":"Unknown key","Cause":"The key is not valid.",'
            . '"Resolution":"Check the key."}]}';
        $provider = $this->provider([new Response(200, [], $error)], $transactions);

        $this->assertNull($provider->lookup(new AddressQuery('NZ', '6011')), 'Loqate Error row -> soft miss');
        $this->assertCount(1, $transactions, 'No Retrieve after an error Find');
    }

    public function testUnsupportedCountryLookupMakesNoRequest(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], self::FIND_ADDRESS)], $transactions);

        $this->assertNull($provider->lookup(new AddressQuery('USA', '6011')));
        $this->assertCount(0, $transactions);
    }

    public function testLookupSoftMissOnFault(): void
    {
        $provider = $this->provider([new Response(500, [], 'boom')]);

        $this->assertNull($provider->lookup(new AddressQuery('NZ', '6011')));
    }

    public function testIsConfiguredReflectsKeyPresence(): void
    {
        $provider = $this->provider([new Response(200, [], '{"Items":[]}')]);
        $this->assertTrue($provider->isConfigured());

        Environment::setEnv('SS_LOQATE_API_KEY', '');
        $this->assertFalse($provider->isConfigured(), 'No key -> inert');
    }

    public function testStreetFallsBackToLine1(): void
    {
        $body = '{"Items":[{"Id":"X|1","Street":"","BuildingNumber":"","City":"Auckland","CountryIso2":"NZ",'
            . '"PostalCode":"1010","Line1":"42 Queen Street"}]}';
        $address = $this->provider([new Response(200, [], self::FIND_ADDRESS), new Response(200, [], $body)])
            ->lookup(new AddressQuery('NZ', '1010'));

        $this->assertNotNull($address);
        $this->assertSame('42 Queen Street', $address->street, 'Street falls back to Line1 when empty');
        $this->assertTrue($address->exact);
    }
}
