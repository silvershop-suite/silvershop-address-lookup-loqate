<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\Loqate;

use SilverShop\AddressLookup\Provider\AbstractHttpProvider;
use SilverShop\AddressLookup\ValueObject\AddressQuery;
use SilverShop\AddressLookup\ValueObject\AddressValidationResult;
use SilverShop\AddressLookup\ValueObject\ResolvedAddress;
use SilverStripe\Core\Environment;

/**
 * Loqate (GBG) Capture provider — the GLOBAL satellite for
 * {@see \SilverShop\AddressLookup\Provider\AddressLookupProvider}. Loqate Capture covers 250+ countries, so this
 * module installs itself as the registry's global `default`: every country without a `by_country` entry (NZ, US,
 * rest-of-world) resolves through Loqate, while the base's regional mappings (NL→pdok, DE→openplz, BE→myparcel) stay
 * untouched.
 *
 * Host `https://api.addressy.com` (the current, live Capture host — api.loqate.com is the newer alias, addressy.com
 * is what the docs and SDKs still target), HTTPS only. Authentication is the **`Key` query parameter**
 * (configurable via {@see self::$api_key_env}, default `SS_LOQATE_API_KEY`); the key rides in the query string of the
 * SERVER-SIDE request only (the base controller proxies the lookup) and is never exposed to the browser.
 *
 * Capture is a **two-step autocomplete** (hence {@see self::MODE_AUTOCOMPLETE} for every supported country):
 *  1. **Find** — `GET /Capture/Interactive/Find/v1.10/json3.ws?Key=&Text=&Countries=&Limit=&Container=` returns
 *     `{Items:[{Id,Type,Text,Highlight,Description}]}`. `Type=Address` is directly retrievable; `Type=Container`
 *     (a postcode / street / locality grouping) is drilled by re-issuing Find with its `Id` as `Container`.
 *  2. **Retrieve** — `GET /Capture/Interactive/Retrieve/v1.20/json3.ws?Key=&Id=` returns the full address
 *     (`{Items:[{Street,BuildingNumber,SubBuilding,City,Province/ProvinceName,PostalCode,CountryIso2,Line1…,…}]}`).
 *
 * {@see self::lookup()} runs Find (drilling containers up to {@see self::$max_container_drills}) then Retrieve on the
 * first address; {@see self::suggest()} returns the Find labels directly (no N retrieves), carrying each candidate's
 * Loqate `Id` in {@see ResolvedAddress::$providerRef} for a follow-up Retrieve; {@see self::validate()} is a
 * best-effort Find-match (Capture exposes no boolean verify) that soft-passes on anything but a clean miss.
 *
 * **Consent: GATED.** Unlike the functional/necessary bundled providers and the own-logistics MyParcel satellite,
 * Loqate is a global third-party processor (GBG) that may route outside the EU, so it declares a consent category
 * (default `marketing`, overridable in config) and only runs once the base module's ConsentResolver confirms that
 * category is granted — i.e. gated via silvershop/cookieconsent when installed, default-deny otherwise.
 *
 * Inherits the base's hardened Guzzle client (SSL verify ON, short timeout, graceful empty on any fault) and the
 * `http_options` / constructor MockHandler test seam; because Loqate authenticates by query param, no header override
 * is needed.
 *
 * @see https://www.loqate.com/developers/api/capture/
 */
class LoqateProvider extends AbstractHttpProvider
{
    /**
     * Capture API base host. Overridable in config (e.g. to pin api.loqate.com or a regional endpoint).
     */
    private static string $endpoint = 'https://api.addressy.com';

    /**
     * Find (typeahead) request path, including version and the json3 response layout.
     */
    private static string $find_path = 'Capture/Interactive/Find/v1.10/json3.ws';

    /**
     * Retrieve (full address by Id) request path, including version and the json3 response layout.
     */
    private static string $retrieve_path = 'Capture/Interactive/Retrieve/v1.20/json3.ws';

    /**
     * Environment variable holding the Loqate API key. No key → the provider is inert (the registry skips it and the
     * form stays manual), so the module is safe to install before credentials are supplied.
     */
    private static string $api_key_env = 'SS_LOQATE_API_KEY';

    /**
     * Max candidates requested from Find (the `Limit` param); also caps how many {@see self::suggest()} rows return.
     */
    private static int $result_limit = 7;

    /**
     * How many times {@see self::lookup()} will drill into a container before giving up (a bare postcode often
     * returns a container first). Kept low to bound the number of upstream Find calls.
     */
    private static int $max_container_drills = 3;

    /**
     * Consent category gating this provider. GATED by default (`marketing`) because Loqate is a global third-party
     * (GBG) processor — the key difference from the functional/necessary bundled providers and the MyParcel
     * satellite. Override in config to slot it into a shop's own cookieconsent taxonomy, or set to null to make it
     * functional (NOT recommended for a non-EU-capable processor).
     */
    private static ?string $consent_category = 'marketing';

    public function getKey(): string
    {
        return 'loqate';
    }

    public function getName(): string
    {
        return 'Loqate (GBG) Capture';
    }

    /**
     * Configured once the API key is present in the environment.
     */
    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    /**
     * Global coverage. Loqate Capture resolves 250+ countries and exposes no country-enumeration endpoint, so any
     * well-formed ISO 3166-1 alpha-2 code is accepted and a genuine miss resolves to null (the manual form). The
     * registry only ever routes a country here when loqate is its `by_country` entry or the global `default`.
     */
    public function supportsCountry(string $countryCode): bool
    {
        return (bool) preg_match('/^[A-Za-z]{2}$/', trim($countryCode));
    }

    /**
     * Loqate Capture is a typeahead (Find → Retrieve), so every supported country uses free-text autocomplete.
     */
    public function lookupMode(string $countryCode): string
    {
        return $this->supportsCountry($countryCode) ? self::MODE_AUTOCOMPLETE : self::MODE_NONE;
    }

    public function lookup(AddressQuery $query): ?ResolvedAddress
    {
        $code = $this->normaliseCountry($query->countryCode);
        if (!$this->supportsCountry($code)) {
            return null;
        }

        $text = $this->composeText($query);
        if ($text === '') {
            return null;
        }

        $id = $this->findFirstAddressId($text, $code);
        if ($id === '') {
            return null;
        }

        return $this->retrieve($id, $code);
    }

    /**
     * Free-text typeahead: return the Find labels as partial addresses WITHOUT a Retrieve per row (Retrieve is the
     * expensive, per-selection call). Each row's {@see ResolvedAddress::$providerRef} carries the Loqate `Id` so a
     * follow-up {@see self::lookup()}-style Retrieve (for an Address) or drill (for a Container) can resolve it.
     *
     * @return array<int, ResolvedAddress>
     */
    public function suggest(string $term, string $countryCode): array
    {
        $code = $this->normaliseCountry($countryCode);
        $term = trim($term);
        if (!$this->supportsCountry($code) || $term === '') {
            return [];
        }

        $out = [];
        foreach ($this->find($term, $code, '') as $item) {
            $id = (string) ($item['Id'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[] = new ResolvedAddress(
                street: trim((string) ($item['Text'] ?? '')),
                postalCode: '',
                city: '',
                state: '',
                countryCode: $code,
                exact: false,
                // The Loqate Id is this candidate's retrievable reference; the label stays in `street`.
                providerRef: $id,
            );
        }

        return $out;
    }

    /**
     * Best-effort validation via a Find-match (Loqate Capture has no boolean verify endpoint). A clean address match
     * scores 1.0, an area-only (container) match 0.5, and anything else — including a fault — soft-passes as
     * {@see AddressValidationResult::unknown()} so a flaky API never blocks checkout.
     */
    public function validate(ResolvedAddress $address): AddressValidationResult
    {
        $code = $this->normaliseCountry($address->countryCode);
        if (!$this->supportsCountry($code)) {
            return AddressValidationResult::unknown();
        }

        $parts = array_filter([
            $address->street,
            $address->houseNumber,
            $address->suffix,
            $address->postalCode,
            $address->city,
        ], static fn (string $v): bool => trim($v) !== '');
        $text = trim((string) preg_replace('/\s+/', ' ', implode(' ', $parts)));
        if ($text === '') {
            return AddressValidationResult::unknown();
        }

        $items = $this->find($text, $code, '');
        if ($items === []) {
            return AddressValidationResult::unknown();
        }

        foreach ($items as $item) {
            if ((string) ($item['Type'] ?? '') === 'Address') {
                return new AddressValidationResult(true, 1.0, null);
            }
        }

        // Matched something (a container / locality) but not a precise address.
        return new AddressValidationResult(true, 0.5, null);
    }

    /**
     * Compose the Find `Text` from the query: an explicit free-text term wins, otherwise the structured parts
     * (house number + suffix, then postcode) — enough for a bare-postcode global lookup (e.g. "6011") or a
     * number+postcode search.
     */
    private function composeText(AddressQuery $query): string
    {
        $term = trim($query->term);
        if ($term !== '') {
            return $term;
        }

        $number = trim($query->houseNumber . $query->suffix);
        $composed = trim($number . ' ' . trim($query->postalCode));

        return trim((string) preg_replace('/\s+/', ' ', $composed));
    }

    /**
     * Run Find, drilling into containers until an address appears or the drill budget is spent, and return the first
     * retrievable address Id (or '' for a miss).
     */
    private function findFirstAddressId(string $text, string $code): string
    {
        $container = '';
        $maxDrills = max(0, (int) static::config()->get('max_container_drills'));

        for ($depth = 0; $depth <= $maxDrills; $depth++) {
            $items = $this->find($text, $code, $container);
            if ($items === []) {
                return '';
            }

            $nextContainer = '';
            foreach ($items as $item) {
                $type = (string) ($item['Type'] ?? '');
                $id = (string) ($item['Id'] ?? '');
                if ($type === 'Address' && $id !== '') {
                    return $id;
                }
                if ($nextContainer === '' && $type === 'Container' && $id !== '') {
                    $nextContainer = $id;
                }
            }

            if ($nextContainer === '') {
                return '';
            }
            $container = $nextContainer;
        }

        return '';
    }

    /**
     * Issue one Find request and return its `Items` (addresses + containers), or [] on a fault / Loqate error row.
     *
     * @return array<int, array<string, mixed>>
     */
    private function find(string $text, string $code, string $container): array
    {
        $query = [
            'Key' => $this->apiKey(),
            'Text' => $text,
            'Countries' => $code,
            'Limit' => max(1, (int) static::config()->get('result_limit')),
        ];
        if ($container !== '') {
            $query['Container'] = $container;
        }

        return $this->items($this->getJson($this->url('find_path'), $query));
    }

    /**
     * Retrieve a full address by Id and map it onto the normalised {@see ResolvedAddress}, or null on a fault / miss.
     */
    private function retrieve(string $id, string $fallbackCode): ?ResolvedAddress
    {
        $items = $this->items($this->getJson($this->url('retrieve_path'), [
            'Key' => $this->apiKey(),
            'Id' => $id,
        ]));
        // One Id can return several items (one per language); the first is the primary record.
        $row = $items[0] ?? null;
        if (!is_array($row)) {
            return null;
        }

        return $this->mapAddress($row, $fallbackCode);
    }

    /**
     * Map one Loqate Retrieve row onto {@see ResolvedAddress}. `Street` falls back to `Line1` for datasets that only
     * populate the formatted line; `SubBuilding` (flat / unit) lands in the VO's single suffix slot;
     * `ProvinceName`→`Province`→`ProvinceCode` fills state; geo is mapped only when the row carries it.
     *
     * @param array<string, mixed> $row
     */
    private function mapAddress(array $row, string $fallbackCode): ResolvedAddress
    {
        $street = (string) ($row['Street'] ?? '');
        if ($street === '') {
            $street = (string) ($row['Line1'] ?? '');
        }

        $state = (string) ($row['ProvinceName'] ?? '');
        if ($state === '') {
            $state = (string) ($row['Province'] ?? $row['ProvinceCode'] ?? '');
        }

        $country = strtoupper((string) ($row['CountryIso2'] ?? ''));
        if ($country === '') {
            $country = $fallbackCode;
        }

        $lat = isset($row['Latitude']) && $row['Latitude'] !== '' ? (float) $row['Latitude'] : null;
        $lng = isset($row['Longitude']) && $row['Longitude'] !== '' ? (float) $row['Longitude'] : null;

        return new ResolvedAddress(
            street: $street,
            houseNumber: (string) ($row['BuildingNumber'] ?? ''),
            suffix: (string) ($row['SubBuilding'] ?? ''),
            postalCode: (string) ($row['PostalCode'] ?? ''),
            city: (string) ($row['City'] ?? ''),
            municipality: '',
            state: $state,
            countryCode: $country,
            lat: $lat,
            lng: $lng,
            exact: $street !== '',
            providerRef: $this->getKey(),
        );
    }

    /**
     * The `Items` rows of a Capture response. Loqate signals a fault (bad key, over quota, …) as a single item with
     * an `Error` field — treated here as an empty result (soft miss), never surfaced to the shopper.
     *
     * @param array<mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function items(array $data): array
    {
        $items = $data['Items'] ?? null;
        if (!is_array($items) || $items === []) {
            return [];
        }

        $rows = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (array_key_exists('Error', $row)) {
                // Loqate error payload (e.g. {"Error":"2","Description":"Unknown key"}) — degrade to a soft miss.
                return [];
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Build a full request URL from the configured host and a path config key.
     */
    private function url(string $pathConfigKey): string
    {
        $base = rtrim((string) static::config()->get('endpoint'), '/');
        $path = ltrim((string) static::config()->get($pathConfigKey), '/');

        return $base . '/' . $path;
    }

    private function apiKey(): string
    {
        $env = (string) static::config()->get('api_key_env');

        return $env === '' ? '' : (string) Environment::getEnv($env);
    }

    private function normaliseCountry(string $code): string
    {
        return strtoupper(trim($code));
    }
}
