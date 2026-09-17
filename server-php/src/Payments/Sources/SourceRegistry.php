<?php

declare(strict_types=1);

namespace Aicountly\Api\Payments\Sources;

/**
 * Which adapter handles which source app.
 *
 * THE FALLBACK IS THE FEATURE. An unknown `source_app` does not fail — it gets
 * the generic adapter. That is what lets a product Pay has never heard of raise
 * a payment request today and be given a first-class adapter later without any
 * of its earlier payments needing migration: the rows already carry its name,
 * and the day a real adapter is registered here, they start resolving to it.
 *
 * The consequence is deliberate: `SourceRegistry::for('APPOINTMENTS')` works
 * before anybody writes an AppointmentsAdapter.
 */
final class SourceRegistry
{
    /** @var array<string, class-string<SourceAppAdapterInterface>> */
    private const ADAPTERS = [
        'BOOKS'   => BooksAdapter::class,
        'BILLING' => BillingAdapter::class,
        'SALES'   => SalesAdapter::class,
        'POS'     => PosAdapter::class,
    ];

    /** @var array<string, SourceAppAdapterInterface> */
    private static array $built = [];

    public static function for(string $sourceApp): SourceAppAdapterInterface
    {
        $app = strtoupper(trim($sourceApp));
        if ($app === '') {
            $app = 'EXTERNAL';
        }

        if (isset(self::$built[$app])) {
            return self::$built[$app];
        }

        $class = self::ADAPTERS[$app] ?? null;

        return self::$built[$app] = $class === null
            ? new GenericExternalAdapter($app)
            : new $class();
    }

    /** Whether this app has a real adapter, or is being handled generically. */
    public static function isFirstParty(string $sourceApp): bool
    {
        return isset(self::ADAPTERS[strtoupper(trim($sourceApp))]);
    }

    /**
     * The fleet products this deployment can talk to.
     *
     * Used by the request form's source picker and by the Collections
     * dashboard, which groups by source. A product that is switched off is
     * listed as unavailable rather than hidden, so a merchant whose Sales
     * requests stopped appearing can see why.
     *
     * @return list<array{app:string, name:string, available:bool, first_party:bool}>
     */
    public static function catalog(): array
    {
        $out = [];
        foreach (array_keys(self::ADAPTERS) as $app) {
            $adapter = self::for($app);
            $out[] = [
                'app'         => $app,
                'name'        => $adapter->displayName(),
                'available'   => $adapter->isAvailable(),
                'first_party' => true,
            ];
        }

        // Always last, and always available: somebody is always able to raise a
        // request in Pay itself.
        $out[] = ['app' => 'PAY', 'name' => 'Pay', 'available' => true, 'first_party' => true];

        return $out;
    }

    /** Test seam. */
    public static function forget(): void
    {
        self::$built = [];
    }
}
