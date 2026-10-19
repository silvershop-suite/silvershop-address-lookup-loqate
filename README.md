# silvershop-suite/address-lookup-loqate

A **satellite provider** for [`silvershop-suite/address-lookup`](https://github.com/silvershop/silvershop-address-lookup)
(CMS 6) that resolves addresses through **[Loqate (GBG) Capture](https://www.loqate.com/developers/api/capture/)** —
the **global fallback** for the suite.

It registers one provider (`loqate`) and makes it the registry's **global `default`**: every country *without* a
`by_country` entry — New Zealand, the US, and the rest of the world — autofills through Loqate, while the base
module's regional mappings (`NL → pdok`, `DE → openplz`, `BE → myparcel`) stay exactly as they are.

> **Loqate is PAID (per lookup) and CONSENT-GATED.** Installing this module makes all otherwise-unmapped countries
> resolve via a paid, third-party global service that only runs once the shopper has granted consent. See
> [Cost](#cost) and [Consent](#consent).

## Capabilities

Loqate Capture is a **two-step autocomplete**, so every supported country uses `autocomplete` mode:

| Step | Endpoint | Returns |
| --- | --- | --- |
| **Find** (typeahead) | `GET /Capture/Interactive/Find/v1.10/json3.ws?Key=&Text=&Countries=&Limit=&Container=` | `{Items:[{Id, Type, Text, Description}]}` — `Type=Address` is retrievable; `Type=Container` (a postcode/street grouping) is drilled by re-sending its `Id` as `Container` |
| **Retrieve** (full address) | `GET /Capture/Interactive/Retrieve/v1.20/json3.ws?Key=&Id=` | the full address: `Street`, `BuildingNumber`, `SubBuilding`, `City`, `Province`/`ProvinceName`, `PostalCode`, `CountryIso2`, geo when present |

| Method | Behaviour |
| --- | --- |
| `lookup(AddressQuery)` | **Find** (`Text` from the postcode/number/term, `Countries=<iso>`) → pick the first `Type=Address`, drilling containers → **Retrieve** by `Id` → a single `ResolvedAddress` |
| `suggest(term, iso)` | **Find** → the candidate labels as partial `ResolvedAddress` rows (no per-row Retrieve); each row carries the Loqate `Id` in `providerRef` for a follow-up Retrieve |
| `validate(ResolvedAddress)` | a best-effort **Find-match** (Capture has no boolean verify); a precise address match scores `1.0`, an area match `0.5`, a clean miss or fault soft-passes |

**Coverage:** Loqate Capture resolves **250+ countries**; the provider accepts any well-formed ISO 3166-1 alpha-2
code and lets a genuine miss fall back to the manual form.

## Installation

```bash
composer require silvershop-suite/address-lookup-loqate
```

Then run `dev/build?flush=all`.

### API key

Authentication is the Loqate **`Key` query parameter**, read from the environment variable **`SS_LOQATE_API_KEY`**.
The key is attached only to the **server-side** request (the base module's lookup controller proxies every call), so
it is **never exposed to the browser**.

```dotenv
# .env
SS_LOQATE_API_KEY="your-loqate-api-key"
```

Without a key the provider is **inert**: the registry skips it and unmapped countries stay on the manual form, so the
module is safe to install before credentials are supplied.

## Configuration

Registration and the global-fallback wiring are applied automatically by `_config/address-lookup-loqate.yml`:

```yaml
SilverShop\AddressLookup\Provider\AddressLookupProviderRegistry:
  providers:
    loqate: '%$SilverShop\AddressLookup\Loqate\LoqateProvider'
  default: loqate          # GLOBAL FALLBACK: every country without a by_country entry resolves via Loqate
```

The module orders itself `After: #silvershop-address-lookup`, so its `default: loqate` overrides the base's empty
`default: ~`. The base's `by_country` map (`NL → pdok`, `DE → openplz`, `BE → myparcel`) is **not** touched — Loqate
only ever handles the countries nothing else claims.

To route a *specific* country to Loqate instead of its regional provider, map it in your project config:

```yaml
SilverShop\AddressLookup\Provider\AddressLookupProviderRegistry:
  by_country:
    GB: loqate
```

### Provider options

```yaml
SilverShop\AddressLookup\Loqate\LoqateProvider:
  endpoint: 'https://api.addressy.com'   # Capture host (override to pin api.loqate.com or a regional endpoint)
  api_key_env: 'SS_LOQATE_API_KEY'       # the env var holding the key
  result_limit: 7                        # max Find candidates (the `Limit` param)
  max_container_drills: 3                # how deep lookup() will drill into containers before giving up
  consent_category: 'marketing'          # the consent category gating the lookup (see below)
  timeout: 5                             # upstream timeout, seconds (inherited from the HTTP base)
```

## Consent

**Loqate is consent-gated** — the key difference from the bundled providers and the MyParcel satellite. Those are
functional/necessary (EU government / own-logistics processors), but Loqate is a **global third-party processor
(GBG)** whose fallback routing may leave the EU, so the provider declares a consent category (default **`marketing`**)
and only runs once [`silvershop-suite/cookieconsent`](https://github.com/silvershop) confirms that category is granted:

- **cookieconsent installed** → the lookup runs only after the shopper grants the category (fail-closed before a
  decision).
- **cookieconsent absent** → default-**deny** (set `ConsentResolver.assume_consent_without_module: true` to run it
  regardless, only if your own consent mechanism already covers it).

Change the category to fit your cookie taxonomy — e.g. a shop with a dedicated external-services bucket:

```yaml
SilverShop\AddressLookup\Loqate\LoqateProvider:
  consent_category: 'preferences'
```

(Setting it to `null` makes the provider functional/ungated — **not** recommended for a non-EU-capable processor.)

## Cost

Loqate bills **per lookup**. Because this module sets `default: loqate`, **every otherwise-unmapped country** will
issue paid Find/Retrieve calls once consent is granted. Keep the free regional providers (PDOK, OpenPLZ) mapped for
the countries they cover, and treat Loqate as the paid safety net for everywhere else.

## Hardening

Inherited from the base module's `AbstractHttpProvider`: **SSL verify ON**, a short upstream timeout, and a graceful
empty answer on any miss, fault, or Loqate `Error` payload, so a slow or failing address API never blocks checkout.
The lookup endpoint itself (POST-only, same-origin + CSRF, rate-limited) lives in the base module.

## Requirements

- PHP ^8.3
- silverstripe/framework ^6
- silvershop/core ^6
- silvershop-suite/address-lookup ^1
- A Loqate API key (`SS_LOQATE_API_KEY`)

## License

BSD-3-Clause.
